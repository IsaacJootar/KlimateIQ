# Developer Guide: Ingestion, Scoring, and the Third-Party API

This is the guide a third party (another agency, another team) needs to plug a new environmental
signal into KlimateIQ, or to consume its risk scores without rebuilding ingestion themselves.

## Architecture in one picture

```
 Signal source (NASA POWER, CHIRPS, ERA5, ...)
        │
        ▼
 SignalIngestionService::ingestForRegion()  ──►  region_signals table
        │
        │  fires RegionSignalIngested event
        ▼
 EvaluateSignalThresholds listener  ──►  per-signal alerts (e.g. "standing water > 70")

 RegionScoringService::calculate()  ──►  region_scores table
        │
        │  fires RegionScoreCalculated event
        ▼
 EvaluateIndexThresholds listener  ──►  per-index alerts (e.g. "composite > 80")
```

Ingestion and scoring never call into the alerts layer directly — they only fire events. The
alerts layer only ever reacts to those events. This means you can add a new signal source, or
even move alerts to a separate deployment, without touching the other two layers.

## Adding a new signal source

1. Implement `App\Services\Ingestion\SignalIngestionService`:

   ```php
   class TemperatureIngestionService implements SignalIngestionService
   {
       public function signalTypeCode(): string
       {
           return 'TEMPERATURE'; // must exist in the signal_types table
       }

       public function ingestForRegion(Region $region, Carbon $periodStart, Carbon $periodEnd): ?RegionSignal
       {
           // Fetch from your source, normalize to one number for the period.
           // Return null (not an exception) if the source has no data for this
           // region/period — a gap in one source must not abort a run covering many.
       }
   }
   ```

2. Register it in `config/ingestion.php`:

   ```php
   'sources' => [
       RainfallIngestionService::class,
       TemperatureIngestionService::class, // ← new
   ],
   ```

That's it. `php artisan signals:ingest` and the weekly schedule (`routes/console.php`) both pick
it up automatically — no other file changes.

If your source needs outbound HTTPS and you're on the same class of dev machine as this project
(XAMPP/Windows with an unconfigured `curl.cainfo`), use the
`App\Services\Ingestion\Concerns\ResolvesCaBundle` trait already used by
`RainfallIngestionService` — it resolves a CA bundle so requests don't silently fail.

## Adding a new named index

A named index is just a row in `indices` plus weighted rows in `region_scoring_configs` — no code
change:

```php
$index = ScoringIndex::create(['code' => 'HEAT_STRESS_RISK', 'name' => 'Heat Stress Risk Index']);

RegionScoringConfig::create([
    'index_id' => $index->index_id,
    'region_id' => null, // null = system-wide default; a region can override with its own row
    'signal_type_id' => SignalType::where('code', 'TEMPERATURE')->value('signal_type_id'),
    'weight' => 1.0,
]);
```

Normalization bounds (min/max per signal) live in `scoring_calibration_parameters`, keyed by
`{SIGNAL_CODE}_MIN` / `{SIGNAL_CODE}_MAX`. Tune them without touching code as real historical data
becomes available — see `ScoringCalibrationParameter`.

### How trustworthy are the current bounds?

Worth being direct about this: the seeded bounds are **not** derived from a health-outcomes
study. They're a mix of:

- **A real scientific standard** — Vegetation's `-1` to `1` is NDVI's actual defined range.
- **Climatologically/geographically plausible defaults** — Rainfall, Temperature, and Elevation
  are set to realistic ranges for Nigeria, not empirically validated against case data.
- **Arbitrary** — Population Exposure's `0` to `1,000,000` is a round-number guess, and moot
  anyway since that signal has no ingestion source yet (see below).

Genuine calibration — checking whether, say, 90mm of weekly rainfall actually correlates with a
malaria case spike in a given range — requires historical case data (Malaria Atlas Project,
DHS/MIS, NEMA flood records, ...) matched against `region_signals` by region and period. That
hasn't been done. Until it is, treat every score this platform produces as "a transparent,
reproducible combination of real environmental readings," not as a clinically validated risk
probability.

### Population Exposure — pending, not live

`POPULATION_EXPOSURE` exists as a `signal_type`, has calibration bounds, and is weighted into the
Composite Climate-Health Pressure Index (15%) — but **no ingestion service implements it**. It's
not in `config('ingestion.sources')`, so it never actually collects data; `region_signals` has no
rows for it. The planned source is WorldPop / GRID3 Nigeria. Because
`WeightedFormulaScoringStrategy` renormalizes over only the signals that actually have data (see
its docblock), this doesn't bias any score toward zero — it's just silently excluded from the
math until someone builds `PopulationExposureIngestionService` following the pattern in
"Adding a new signal source" above.

## Activating the trained-model scoring strategy

`App\Services\Scoring\TrainedModelScoringStrategy` is the real seam, not a promise. To activate it:

1. Train against historical case data (Malaria Atlas Project, DHS/MIS) matched to the same
   `region_id` + `period_start`/`period_end` grain as `region_signals`.
2. Export the model to `storage/app/models/{INDEX_CODE}.json` (or adapt the loader in
   `TrainedModelScoringStrategy::modelPath()` for your framework's native format).
3. Implement `TrainedModelScoringStrategy::predict()` to load that artifact and score.
4. Set `SCORING_STRATEGY=trained_model` in `.env`, or set a specific region's
   `regions.preferred_scoring_strategy` column — `ScoringStrategyResolver` checks the region
   override first, then the global config, and falls back to the formula strategy automatically
   if the model file isn't present.

Nothing else — ingestion, alerts, the dashboard — needs to change.

## Third-party API

Token-authenticated via Sanctum. Issue a token for an external agency:

```bash
php artisan tinker
>>> $user = App\Models\User::first(); // or whichever account should own the token
>>> $user->createToken('partner-agency-name')->plainTextToken
```

Then call with `Authorization: Bearer <token>`:

| Endpoint | Returns |
|---|---|
| `GET /api/v1/indices` | Every named index (id, code, name, description) |
| `GET /api/v1/regions` | Every monitored region (id, name, state, lat/lng, population) |
| `GET /api/v1/indices/{code}/scores` | Latest score per region for that index, with full breakdown |
| `GET /api/v1/regions/{id}/scores?index={code}` | Full score history for one region/index |

Example:

```bash
curl -H "Authorization: Bearer <token>" \
     https://your-domain/api/v1/indices/MALARIA_RISK/scores
```

Every score in the response carries the same `breakdown` the dashboard drill-down shows — which
signal, its raw value, its normalized contribution, and its weight — so a third party gets the
same auditability a KlimateIQ user gets, not a black-box number.
