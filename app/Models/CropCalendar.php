<?php

namespace App\Models;

use App\Support\AgroZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Clarity Pass D2 — which crops are in a water-sensitive growth stage in a given place and
 * month. Powers the concrete half of an agriculture recommendation ("millet, sorghum and
 * cowpea are near grain-fill right now").
 *
 * @property string $scope 'zone' | 'state'
 * @property string $scope_key
 * @property string $crop
 * @property string $stage
 * @property array<int> $sensitive_months
 */
class CropCalendar extends Model
{
    protected $table = 'crop_calendar';

    public $timestamps = false;

    protected $fillable = ['scope', 'scope_key', 'crop', 'stage', 'sensitive_months', 'sort_order'];

    protected $casts = ['sensitive_months' => 'array'];

    /**
     * The crops water-sensitive in this state right now — a state-scoped row wins over a
     * zone-scoped one for the same crop. Empty when the state maps to no zone or nothing is in
     * a sensitive window this month.
     *
     * @return Collection<int, array{crop: string, stage: string}>
     */
    public static function exposedNow(?string $state, ?Carbon $when = null): Collection
    {
        $month = ($when ?? Carbon::now())->month;
        $zone = AgroZone::forState($state);

        $rows = static::query()
            ->where(function ($q) use ($state, $zone) {
                $q->where(fn ($q) => $q->where('scope', 'state')->where('scope_key', $state));
                if ($zone !== null) {
                    $q->orWhere(fn ($q) => $q->where('scope', 'zone')->where('scope_key', $zone));
                }
            })
            ->whereJsonContains('sensitive_months', $month)
            ->orderBy('sort_order')
            ->get();

        return $rows
            // state row beats zone row for the same crop
            ->sortByDesc(fn (CropCalendar $r) => $r->scope === 'state' ? 1 : 0)
            ->unique('crop')
            ->sortBy('sort_order')
            ->map(fn (CropCalendar $r) => ['crop' => $r->crop, 'stage' => $r->stage])
            ->values();
    }

    /**
     * A one-line, reader-ready phrase — "millet and maize (filling grain), yam (swelling
     * tubers)" — or null when nothing is in a sensitive window. Each crop carries its growth
     * stage in parentheses so it can't be mistaken for another crop name.
     */
    public static function phraseFor(?string $state, ?Carbon $when = null): ?string
    {
        $byStage = self::exposedNow($state, $when)->groupBy('stage');

        if ($byStage->isEmpty()) {
            return null;
        }

        return $byStage
            ->map(function (Collection $crops, string $stage) {
                $names = $crops->pluck('crop')->map(fn ($c) => mb_strtolower($c))->all();
                $joined = count($names) > 1
                    ? implode(', ', array_slice($names, 0, -1)).' and '.end($names)
                    : $names[0];

                return "{$joined} ({$stage})";
            })
            ->values()
            ->implode(', ');
    }
}
