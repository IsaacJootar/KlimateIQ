<?php

namespace App\Http\Controllers;

use App\Models\CropCalendar;
use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionForecastScore;
use App\Models\RegionForecastSignal;
use App\Models\RegionScore;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Services\Ai\RegionScoreSummaryService;
use App\Services\Facilities\FacilityProvider;
use App\Support\IndexCalibration;
use App\Support\IndexCoverage;
use App\Support\RiskBand;
use App\Support\ScoreDiagnosis;
use App\Support\SignalReading;
use App\Support\TrendSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegionController extends Controller
{
    public function index(): View
    {
        ['available' => $indices, 'active' => $index, 'groups' => $indexGroups] = IndexCoverage::resolve(Auth::user(), request('index'));

        // A forecast index has one current forecast per region (region_forecast_scores), no
        // history or "two weeks ago" — the trend/sparkline columns just fall to neutral.
        $latestByRegion = $index->is_forecast
            ? RegionForecastScore::query()->where('index_id', $index->index_id)->get()->keyBy('region_id')
            : RegionScore::query()
                ->where('index_id', $index->index_id)
                ->orderByDesc('period_start')
                ->get()
                ->unique('region_id')
                ->keyBy('region_id');

        // Most recent score at or before 2 weeks ago, per region — the "where were we" side
        // of the trend sentence. Same shape query as $latestByRegion, just an older cutoff.
        $priorByRegion = $index->is_forecast ? collect() : RegionScore::query()
            ->where('index_id', $index->index_id)
            ->where('period_start', '<=', now()->subDays(14))
            ->orderByDesc('period_start')
            ->get()
            ->unique('region_id')
            ->keyBy('region_id');

        // Explicit region_ids in the URL (a saved view, or a link scoped to a specific
        // subset) win first. Otherwise this must match the Dashboard: fall back to the
        // user's own /coverage selection, and only then to every active region — this
        // page previously ignored /coverage entirely and always showed all active
        // regions regardless of what a user had actually configured, which silently
        // contradicted the "your dashboard and region list default to these" promise
        // made on the coverage page itself.
        $regionIds = array_filter(explode(',', (string) request('regions', '')));
        $subscribedRegionIds = $regionIds === [] ? Auth::user()->regionSubscriptions()->pluck('region_id')->all() : [];

        // Full ascending history per region, for the sparkline — separate from $latestByRegion/
        // $priorByRegion above, which only ever need single points, not a series.
        $historyByRegion = $index->is_forecast ? collect() : RegionScore::query()
            ->where('index_id', $index->index_id)
            ->orderBy('period_start')
            ->get()
            ->groupBy('region_id');

        $regions = Region::query()
            ->when(
                $regionIds !== [],
                fn ($q) => $q->whereIn('region_id', $regionIds),
                fn ($q) => $q->when(
                    $subscribedRegionIds !== [],
                    fn ($q) => $q->whereIn('region_id', $subscribedRegionIds),
                    fn ($q) => $q->active()
                )
            )
            ->orderBy('name')
            ->get()
            ->map(function (Region $region) use ($latestByRegion, $priorByRegion, $historyByRegion, $index) {
                $score = $latestByRegion->get($region->region_id);
                $prior = $priorByRegion->get($region->region_id);
                $region->setAttribute('current_score', $score?->score);
                $region->setAttribute('risk_band', RiskBand::forScore($score?->score));
                // A forecast index row carries an ensemble exceedance probability (T5).
                $region->setAttribute('forecast_probability',
                    ($index->is_forecast && $score?->exceedance_probability !== null)
                        ? (int) round((float) $score->exceedance_probability * 100)
                        : null);
                $region->setAttribute('trend', TrendSummary::describe(
                    $score?->score !== null ? (float) $score->score : null,
                    $prior?->score !== null ? (float) $prior->score : null,
                ));
                $region->setAttribute('sparkline', $historyByRegion->get($region->region_id, collect())
                    ->pluck('score')
                    ->map(fn ($v) => $v !== null ? (float) $v : null)
                    ->take(-8)
                    ->values()
                    ->all());

                return $region;
            });

        $followedSectors = Auth::user()->sectorSubscriptions()->with('sector')->get()
            ->pluck('sector')->filter()->sortBy('sort_order')->values();

        return view('regions.index', [
            'regions' => $regions,
            'indices' => $indices,
            'indexGroups' => $indexGroups,
            'index' => $index,
            'followedSectors' => $followedSectors,
            // Drives the "Your Regions" vs "Active Regions" label below — the count alone
            // doesn't tell a new user whether they're looking at their own coverage or the
            // platform-wide fallback, which is exactly what caused the "is this a bug?"
            // confusion between this page and the Dashboard.
            'hasCoverage' => $regionIds !== [] || $subscribedRegionIds !== [],
        ]);
    }

    public function show(Region $region): View
    {
        ['available' => $indices, 'active' => $index, 'groups' => $indexGroups] = IndexCoverage::resolve(Auth::user(), request('index'));

        if ($index->is_forecast) {
            return $this->showForecast($region, $index, $indices, $indexGroups);
        }

        $scores = RegionScore::query()
            ->where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->orderBy('period_start')
            ->get();

        $latest = $scores->last();
        $prior = $scores->where('period_start', '<=', now()->subDays(14))->last();
        $signalNames = SignalType::codeToName();

        $trend = TrendSummary::describe(
            $latest?->score !== null ? (float) $latest->score : null,
            $prior?->score !== null ? (float) $prior->score : null,
        );

        $diagnosis = ScoreDiagnosis::forBreakdown(
            $latest?->breakdown ?? [],
            $latest?->score !== null ? (float) $latest->score : null,
            $signalNames,
            $trend['direction'],
        );

        // Each driver, plus its plain-language reading — so step 3 shows both the share and
        // "what was actually measured" without the reader jumping back to step 1.
        $rawByCode = collect($latest?->breakdown ?? [])
            ->reject(fn (array $row) => ($row['status'] ?? null) === 'no_data')
            ->keyBy('signal_type_code');
        $drivers = collect($diagnosis['drivers'])->map(function (array $driver) use ($rawByCode) {
            $row = $rawByCode->get($driver['code']);
            $driver['reading'] = $row !== null
                ? SignalReading::describe($driver['code'], (float) $row['raw_value'])['sentence']
                : null;

            return $driver;
        })->all();

        return view('regions.show', [
            'isForecast' => false,
            'drivers' => $drivers,
            'region' => $region,
            'indices' => $indices,
            'indexGroups' => $indexGroups,
            'index' => $index,
            'scores' => $scores,
            'latest' => $latest,
            'breakdown' => $latest?->breakdown ?? [],
            'signalNames' => $signalNames,
            'aiAvailable' => app(RegionScoreSummaryService::class)->isAvailable(),
            'recommendedAction' => IndexActionRecommendation::textFor($index->index_id, $latest?->score),
            'diagnosis' => $diagnosis,
            'calibrationNote' => IndexCalibration::note($index),
            'trend' => $trend,
            'thisWeek' => $this->thisWeekReadings($region, $latest),
            'projection' => $this->projection($latest?->score !== null ? (float) $latest->score : null, $prior?->score !== null ? (float) $prior->score : null),
            // A real forward forecast for this observed index, when its signals have one
            // (Flood Risk on forecast rainfall, Heat Stress on forecast temperature) — replaces
            // the naive linear projection in "Where it's heading". BUILD_PLAN.md T4.
            'forecastTrajectory' => $this->forecastTrajectory($index, $region, $latest?->score !== null ? (float) $latest->score : null),
            // Concrete crops in a water-sensitive stage here right now — only for the agriculture
            // indices, and only when the score is amber/red (there's nothing to act on below that).
            'cropLine' => $this->cropLineFor($index, $region, $latest?->score !== null ? (float) $latest->score : null),
            // A few named schools / health facilities in this LGA — for the public-health and
            // air-quality indices, same amber/red gate. Examples on record, not a full list.
            'facilities' => $this->facilitiesFor($index, $region, $latest?->score !== null ? (float) $latest->score : null),
        ]);
    }

    /**
     * The region page for a forecast index (BUILD_PLAN.md T4). A different shape from the
     * observed page — the score is a forecast peak with a lead time, "where it's heading" is
     * the real GloFAS daily curve rather than a linear extrapolation of past scores.
     */
    private function showForecast(Region $region, ScoringIndex $index, Collection $indices, Collection $indexGroups): View
    {
        $forecast = RegionForecastScore::query()
            ->where('index_id', $index->index_id)
            ->where('region_id', $region->region_id)
            ->first();

        // Distinguish "GloFAS doesn't model a reach here" from "it does, but this reach's flood
        // thresholds aren't calibrated yet" — the latter shows an honest pending state, not a
        // borrowed number (T4/T5 follow-up).
        $forecastStatus = 'ok';
        $pendingDischarge = collect();
        if ($forecast === null || $forecast->score === null) {
            $dischargeForecast = RegionForecastSignal::query()
                ->where('region_id', $region->region_id)
                ->where('member', 'control')
                ->whereHas('signalType', fn ($q) => $q->where('code', 'RIVER_DISCHARGE'))
                ->orderBy('target_date')
                ->get(['target_date', 'value']);

            if ($dischargeForecast->isNotEmpty() && ! IndexCalibration::hasRegionBound($index, $region, 'RIVER_DISCHARGE')) {
                $forecastStatus = 'calibration_pending';
                $pendingDischarge = $dischargeForecast->map(fn ($r) => (float) $r->value);
            } else {
                $forecastStatus = 'no_coverage';
            }
        }

        $peak = $forecast?->score !== null ? (float) $forecast->score : null;
        $daily = collect($forecast?->breakdown['daily'] ?? [])->map(fn (array $d) => [
            'date' => $d['date'],
            'lead_days' => $d['lead_days'],
            'score' => (float) $d['score'],
            'band' => RiskBand::forScore((float) $d['score']),
            'discharge' => $d['signals']['RIVER_DISCHARGE']['raw_value'] ?? null,
        ]);

        // Per-reach breakdown for a multi-river LGA (T4/T5 follow-up) — one row per named river.
        $forecastReaches = collect($forecast?->breakdown['reaches'] ?? [])
            ->filter(fn (array $r) => ($r['river'] ?? null) !== null)
            ->map(fn (array $r) => [
                'river' => $r['river'],
                'score' => (int) round((float) $r['score']),
                'band' => RiskBand::forScore((float) $r['score']),
                'peak_date' => $r['peak_date'] ?? null,
                'lead_days' => $r['lead_days'] ?? null,
                'daily' => collect($r['daily'] ?? [])->map(fn ($d) => (float) $d['score'])->values()->all(),
            ])->values();

        return view('regions.show', [
            'isForecast' => true,
            'region' => $region,
            'indices' => $indices,
            'indexGroups' => $indexGroups,
            'index' => $index,
            'forecast' => $forecast,
            'forecastDaily' => $daily,
            'forecastProbabilityLine' => $forecast ? $this->probabilityLine($forecast, $index->name) : null,
            'forecastFan' => collect($forecast?->breakdown['member_daily'] ?? [])
                ->map(fn (array $d) => ['date' => $d['date'], 'p10' => (float) $d['p10'], 'p90' => (float) $d['p90']])
                ->values()->all(),
            'calibrationNote' => IndexCalibration::note($index),
            'peakScore' => $peak,
            'forecastStatus' => $forecastStatus,
            'pendingDischarge' => $pendingDischarge,
            'forecastReaches' => $forecastReaches,
            'drivingRiver' => $forecast?->breakdown['driving_river'] ?? null,
            'recommendedAction' => IndexActionRecommendation::textFor($index->index_id, $peak),
            'facilities' => $this->facilitiesFor($index, $region, $peak),
            // Shared shells (tab strip, description, the top @php block) still read these.
            'scores' => collect(),
            'latest' => null,
            'breakdown' => [],
            'signalNames' => SignalType::codeToName(),
            'forecastTrajectory' => null,
        ]);
    }

    public function facilities(Region $region): View
    {
        $provider = app(FacilityProvider::class);

        return view('regions.facilities', [
            'region' => $region,
            'byType' => $provider->allForRegion($region),
            'attribution' => $provider->attribution(),
        ]);
    }

    private function cropLineFor(ScoringIndex $index, Region $region, ?float $score): ?string
    {
        if ($score === null || $score < 34) {
            return null;
        }

        $isAgriculture = $index->sectors()->where('code', 'AGRICULTURE')->exists();

        return $isAgriculture ? CropCalendar::phraseFor($region->state) : null;
    }

    /**
     * @return array{label: string, names: list<string>, attribution: string}|null
     */
    private function facilitiesFor(ScoringIndex $index, Region $region, ?float $score): ?array
    {
        if ($score === null || $score < 34) {
            return null;
        }

        $sectors = $index->sectors()->pluck('code')->all();

        // [types to draw, in priority order, and the label]. Checked most operationally-specific
        // first: a flood is a staging problem, a waterborne outbreak is a water-treatment
        // problem, everything else health is a notify problem.
        [$types, $label] = match (true) {
            in_array('EMERGENCY_RESPONSE', $sectors, true) => [['shelter', 'school'], "Sites in {$region->name} to stage from"],
            in_array('WATER_SANITATION', $sectors, true) => [['water_point', 'health'], "Water points and facilities serving {$region->name}"],
            in_array('PUBLIC_HEALTH', $sectors, true) => [['health', 'school'], "Places in {$region->name} to notify"],
            in_array('AIR_ENVIRONMENT', $sectors, true) => [['school', 'health'], "Places in {$region->name} to notify"],
            default => [null, null],
        };

        if ($types === null) {
            return null;
        }

        $provider = app(FacilityProvider::class);
        $names = $provider->forRegion($region, $types, 3)->pluck('name');

        return $names->isEmpty() ? null : [
            'label' => $label,
            'names' => $names->all(),
            'attribution' => $provider->attribution(),
        ];
    }

    /**
     * The "This week in {LGA}" list — each signal that fed the score, phrased plainly, most
     * important first, with an "up from / down from recent weeks" clause drawn from the
     * region's own signal history (Clarity Pass A3).
     *
     * @return Collection<int, array{sentence: string}>
     */
    private function thisWeekReadings(Region $region, ?RegionScore $latest): Collection
    {
        if ($latest === null || $latest->score === null) {
            return collect();
        }

        $present = collect($latest->breakdown ?? [])
            ->reject(fn (array $row) => ($row['status'] ?? null) === 'no_data')
            ->sortByDesc(fn (array $row) => $row['contribution_to_final_score'] ?? $row['contribution'] ?? 0)
            ->take(4)
            ->values();

        if ($present->isEmpty()) {
            return collect();
        }

        // The region's own mean for each of these signals over the ~6 periods before this one.
        $codes = $present->pluck('signal_type_code')->all();
        $recentMeans = RegionSignal::query()
            ->join('signal_types', 'signal_types.signal_type_id', '=', 'region_signals.signal_type_id')
            ->where('region_signals.region_id', $region->region_id)
            ->whereIn('signal_types.code', $codes)
            ->where('region_signals.period_start', '<', $latest->period_start->toDateString())
            ->where('region_signals.period_start', '>=', $latest->period_start->copy()->subWeeks(7)->toDateString())
            ->groupBy('signal_types.code')
            ->selectRaw('signal_types.code as code, avg(region_signals.value) as mean, count(*) as n')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->code => $r->n >= 2 ? (float) $r->mean : null]);

        return $present->map(function (array $row) use ($recentMeans) {
            $code = $row['signal_type_code'];
            $value = (float) $row['raw_value'];
            $reading = SignalReading::describe($code, $value);
            $versus = SignalReading::versusRecent($code, $value, $recentMeans[$code] ?? null);

            return ['sentence' => $reading['sentence'].($versus !== '' ? ", {$versus}" : '')];
        });
    }

    /**
     * A plain "if the pattern holds…" line for the "Where it's heading" step. Deliberately
     * conservative: only speaks when the last two readings show a clear move toward a band edge.
     */
    private function projection(?float $latest, ?float $prior): ?string
    {
        if ($latest === null || $prior === null) {
            return null;
        }

        $delta = $latest - $prior;

        if (abs($delta) < 3) {
            return null;
        }

        $projected = $latest + $delta; // one more 2-week step
        $edge = $delta > 0 ? ($latest < 67 ? 67 : null) : ($latest >= 34 ? 34 : null);

        if ($edge === null) {
            return null;
        }

        $crosses = $delta > 0 ? $projected >= $edge : $projected <= $edge;

        if (! $crosses) {
            return null;
        }

        $band = $delta > 0 ? ($edge === 67 ? 'high risk (red)' : 'moderate risk (amber)') : ($edge === 34 ? 'low risk (green)' : 'moderate risk (amber)');

        return "If it keeps moving at this rate it reaches {$band} within about two weeks.";
    }

    /**
     * The forward forecast for an observed index whose signals include a forecastable one
     * (region_forecast_scores, written by scores:forecast). Returns a plain sentence + the
     * daily 0–100 score series for a mini-chart, or null when there's no forecast on file.
     *
     * @return array{line: string, daily: Collection<int, array{date: string, lead_days: int, score: float, band: string}>, peak_date: ?string, band: string, probability_line: ?string, fan: list<array{date: string, p10: float, p90: float}>}|null
     */
    private function forecastTrajectory(ScoringIndex $index, Region $region, ?float $currentScore): ?array
    {
        $forecast = RegionForecastScore::query()
            ->where('index_id', $index->index_id)
            ->where('region_id', $region->region_id)
            ->first();

        if ($forecast === null || $forecast->score === null) {
            return null;
        }

        $peak = (int) round((float) $forecast->score);
        $band = RiskBand::forScore((float) $forecast->score);
        $lead = (int) $forecast->lead_days_to_peak;
        $bandPlain = ['green' => 'low risk', 'amber' => 'moderate risk', 'red' => 'high risk', 'none' => 'no forecast'][$band];

        $when = match (true) {
            $lead <= 0 => 'later today',
            $lead === 1 => 'tomorrow',
            default => 'around '.$forecast->peak_date?->format('M j').', about '.$lead.' days out',
        };

        $line = $currentScore !== null && abs($peak - $currentScore) < 6
            ? "Forecast to hold near its current level over the next {$forecast->horizon_days} days."
            : "Forecast to reach {$peak} ({$bandPlain}) {$when}.";

        $daily = collect($forecast->breakdown['daily'] ?? [])->map(fn (array $d) => [
            'date' => $d['date'],
            'lead_days' => $d['lead_days'],
            'score' => (float) $d['score'],
            'band' => RiskBand::forScore((float) $d['score']),
        ])->values();

        return [
            'line' => $line,
            'daily' => $daily,
            'peak_date' => $forecast->peak_date?->toDateString(),
            'band' => $band,
            'probability_line' => $this->probabilityLine($forecast, $index->name),
            'fan' => collect($forecast->breakdown['member_daily'] ?? [])
                ->map(fn (array $d) => ['date' => $d['date'], 'p10' => (float) $d['p10'], 'p90' => (float) $d['p90']])
                ->values()->all(),
        ];
    }

    /**
     * The one-line "how likely is a crossing" sentence from the ensemble distribution
     * (BUILD_PLAN.md T5), or null when this forecast has no ensemble on file.
     */
    private function probabilityLine(RegionForecastScore $forecast, string $indexName): ?string
    {
        if ($forecast->exceedance_probability === null) {
            return null;
        }

        $pct = (int) round((float) $forecast->exceedance_probability * 100);
        $reference = (int) round((float) $forecast->exceedance_reference);
        $horizon = (int) $forecast->horizon_days;
        $members = (int) $forecast->member_count;

        $phrase = match (true) {
            $pct >= 80 => "Very likely ({$pct}%)",
            $pct >= 45 => "About a {$pct}% chance",
            $pct >= 15 => "A {$pct}% chance",
            $pct >= 1 => "Only a {$pct}% chance",
            default => 'Less than a 1% chance',
        };

        return "{$phrase} of crossing {$reference} at some point in the next {$horizon} days "
            ."(across {$members} ensemble forecast members).";
    }

    public function generateSummary(Region $region, RegionScoreSummaryService $summarizer): RedirectResponse
    {
        ['active' => $index] = IndexCoverage::resolve(Auth::user(), request('index'));

        $latest = RegionScore::query()
            ->where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->orderByDesc('period_start')
            ->first();

        if ($latest === null || $latest->score === null) {
            return back()->with('error', 'No score to summarize yet for this index.');
        }

        if (! $summarizer->isAvailable()) {
            return back()->with('error', 'AI summaries are not available yet. They need the OpenAI API key to be configured.');
        }

        try {
            $result = $summarizer->generate($latest);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'The AI summary could not be generated right now. Please try again shortly.');
        }

        RegionScore::query()
            ->where('index_id', $latest->index_id)
            ->where('region_id', $latest->region_id)
            ->where('period_start', $latest->period_start)
            ->update([
                'ai_summary' => $result['body'],
                'ai_summary_model' => $result['model'],
                'ai_summary_generated_at' => now(),
            ]);

        return back()->with('success', 'AI summary generated.');
    }
}
