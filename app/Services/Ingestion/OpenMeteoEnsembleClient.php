<?php

namespace App\Services\Ingestion;

use App\Models\Region;
use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Open-Meteo's Ensemble API (https://ensemble-api.open-meteo.com/v1/ensemble) — the same weather
 * model re-run from perturbed initial conditions, 30-51 members depending on the model. The
 * member spread is a calibrated estimate of forecast uncertainty; scoring every member through
 * the index formula yields a distribution of outcomes (BUILD_PLAN.md T5).
 *
 * One HTTP call per model per variable (not a combined multi-model call): the response then
 * carries `{variable}_memberNN` columns for that one model, which we re-tag as `{short}-NN`
 * (gfs-05, ecmwf-23, …) so the caller can pool members across models without depending on how
 * Open-Meteo namespaces a multi-model response. Free tier, no key.
 */
class OpenMeteoEnsembleClient
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://ensemble-api.open-meteo.com/v1/ensemble';

    /** Open-Meteo model id => the short prefix used in the member id we store. */
    private const MODEL_SHORT = [
        'gfs_seamless' => 'gfs',
        'ecmwf_ifs04' => 'ecmwf',
        'icon_seamless' => 'icon',
    ];

    /**
     * The daily forecast series for one variable, per ensemble member, for one model. Returns
     * `memberId => (date => value)` (memberId e.g. "gfs-05"), or [] when the region has no
     * coordinates, the request failed, or the model returned nothing usable.
     *
     * @return array<string, array<string, float>>
     */
    public function memberSeries(Region $region, Carbon $start, Carbon $end, string $variable, string $model): array
    {
        if ($region->latitude === null || $region->longitude === null) {
            return [];
        }

        $short = self::MODEL_SHORT[$model] ?? $model;

        $response = Http::withOptions(['verify' => $this->caBundle()])
            ->timeout(45)
            ->retry(2, 2000, throw: false)
            ->get(self::BASE_URL, [
                'latitude' => (float) $region->latitude,
                'longitude' => (float) $region->longitude,
                'daily' => $variable,
                'models' => $model,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'timezone' => 'Africa/Lagos',
            ]);

        if ($response->failed()) {
            return [];
        }

        $daily = $response->json('daily', []);
        $dates = $daily['time'] ?? [];
        if ($dates === []) {
            return [];
        }

        $members = [];
        foreach ($daily as $column => $values) {
            if (! preg_match('/^'.preg_quote($variable, '/').'_member(\d+)$/', (string) $column, $m)) {
                continue;
            }

            $memberId = $short.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $series = [];
            foreach ($dates as $i => $date) {
                $value = $values[$i] ?? null;
                if (is_numeric($value)) {
                    $series[$date] = (float) $value;
                }
            }

            if ($series !== []) {
                $members[$memberId] = $series;
            }
        }

        return $members;
    }
}
