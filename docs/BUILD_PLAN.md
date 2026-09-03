# Build Plan: Sectors, Indices, and the Engineering for Each

This is the code-level companion to [`docs/ROADMAP_SECTORS.md`](ROADMAP_SECTORS.md). That doc
frames *what* sectors are worth building and why; this one is *how* — the exact classes, config
rows, and migrations each addition touches, in the order that keeps the cheap, high-trust wins
first.

Nothing here is built yet beyond the six live indices. Every proposed weight and calibration
bound below is an **engineering starting point that still needs local validation** against
outcome data — the same caveat [`docs/INGESTION_GUIDE.md`](INGESTION_GUIDE.md#how-trustworthy-are-the-current-bounds)
and [`docs/MODEL.md`](MODEL.md) already make for the live indices.

Status tags used throughout:

- **Live** — running in production today.
- **Ready** — build on free / open-licensed data, no new data cost. Engineering only.
- **Needs data** — requires new or paid inputs that don't exist yet.
- **Gated** — waiting on historical case data (the validation workstream).

## 1. How the platform extends

Three facts drive the whole plan — most new value is a *configuration change*, not new plumbing:

1. **A new signal source is one class.** Implement `App\Services\Ingestion\SignalIngestionService`
   (`signalTypeCode()`, `ingestForRegion(Region, Carbon, Carbon): ?RegionSignal`), register it in
   `config/ingestion.php` `'sources'`, add a row to `signal_types`, slot it into the right tier of
   `App\Support\IngestionCadence` (`DAILY` / `WEEKLY` / `ONCE`), and add its published rate limit
   to `App\Support\ApiCapacityLimits`. Nothing downstream changes. See
   [`docs/INGESTION_GUIDE.md`](INGESTION_GUIDE.md#adding-a-new-signal-source).

2. **A new index is rows, not code.** One `ScoringIndex` (`indices` table) plus weighted
   `RegionScoringConfig` rows (`region_scoring_configs`: `index_id`, `signal_type_id`, `weight`,
   `region_id` null = system default). Calibration min/max live in `scoring_calibration_parameters`
   as `{SIGNAL}_MIN` / `{SIGNAL}_MAX`, per-region overridable. `WeightedFormulaScoringStrategy`
   already supports "lower value = higher risk" (elevation uses the `(1 − ratio)` branch today), so
   inverse signals like soil moisture, humidity and NDVI need a per-config flag, not new code.

3. **Alerting is event-driven and already generic.** `RegionSignalIngested` → `EvaluateSignalThresholds`;
   `RegionScoreCalculated` → `EvaluateIndexThresholds`. Any new signal or index automatically becomes
   something a `ThresholdConfig` can watch — fixed value or rolling-baseline anomaly.

The rest of this plan is really about the handful of places where that is *not* enough:
forward-looking (forecast / ensemble) data, decadal climate projections, and the trained-model swap.

## 2. The build ladder

| Tier | What | Depends on |
|---|---|---|
| T1 | Sector grouping in the UI | nothing — ship first |
| T2 | Config-only indices — **Waterborne Disease shipped**; Meningitis pending | T3 signals for Meningitis |
| T3 | New free signals + their indices — **agriculture bundle, fire/dust, FIRMS confirmation, Respiratory depth all shipped** | nothing |
| T4 | Forecast ingestion — store and score *future* periods — **shipped** | T3 (`RIVER_DISCHARGE`) |
| T5 | Probabilistic scoring — ensemble members → a likelihood | T4 |
| T6 | Climate outlook module — CMIP6 decadal projections | nothing (independent path) |
| T7 | Coastal resilience — storm surge / coastal flooding | coastal DEM + tide data |
| T8 | Trained-model scoring — activate `TrainedModelScoringStrategy` | historical case data |
| T9 | Pan-African expansion — `country` field, per-country calibration | one validated NG index |

## 3. New signal sources

All free / open-licensed. Open-Meteo is CC-BY 4.0 (attribution; commercial use needs a paid key
or the self-hosted AGPL server — see the resilience note in
[`docs/INGESTION_GUIDE.md`](INGESTION_GUIDE.md#watching-for-scale-problems-before-theyre-outages)).
Keep the primary + fallback pattern `RainfallIngestionService` already uses, and label the
`RegionSignal.source` honestly when a fallback fires.

| `signal_types` code | Source / API | Cadence | Feeds | Status |
|---|---|---|---|---|
| `SOIL_MOISTURE` | Open-Meteo Archive (ERA5-Land, 7–28 cm) | `DAILY` | agriculture, irrigation, drought depth | **Live** — `SoilMoistureIngestionService` |
| `SOIL_TEMPERATURE` | Open-Meteo (0–54 cm) | `DAILY` | planting window | Ready |
| `EVAPOTRANSPIRATION` | Open-Meteo ET₀ (FAO-56 Penman-Monteith) | `DAILY` | irrigation demand, crop water stress | **Live** — `EvapotranspirationIngestionService` |
| `HUMIDITY` | Open-Meteo relative humidity 2 m | `DAILY` | meningitis, fire, heat index, VPD | **Live** — `HumidityIngestionService` |
| `WIND_SPEED` | Open-Meteo wind 10 m daily max (NASA POWER fallback: not wired yet) | `DAILY` | fire spread, dust transport | **Live** — `WindIngestionService` |
| `DUST` | Open-Meteo Air Quality (CAMS dust) | `DAILY` | respiratory, meningitis, dust-storm | **Live** — `DustIngestionService` |
| `OZONE` / `NO2` | Open-Meteo Air Quality (CAMS) | `DAILY` | Respiratory Risk depth | **Live** — `AirQuality{Ozone,No2}IngestionService` |
| `SO2` / `CO` | Open-Meteo Air Quality (CAMS) | `DAILY` | Respiratory Risk depth (further) | Ready — same API call |
| `UV_INDEX` | Open-Meteo (daily max + clear-sky max) | `DAILY` | occupational / skin-eye advisories | Ready |
| `RIVER_DISCHARGE` | Open-Meteo Flood API (GloFAS) | `DAILY` + forecast | riverine flood forecasting | **Live** — RiverDischargeIngestionService + RiverDischargeForecastService |
| `ACTIVE_FIRE` | NASA FIRMS area API (VIIRS NOAA-20) | `DAILY` | bush-fire confirmation / backtest | **Live** — `ActiveFireIngestionService` (needs `FIRMS_MAP_KEY`; no-op without) |
| `SEA_STATE` | Open-Meteo Marine (wave height, SST) | `DAILY` | coastal (partial) | Needs data (+ elevation/tide) |

Each new source needs an `ApiCapacityLimits::all()` entry with the provider's published free-tier
limit so Pipeline Health flags it past 70%.

## 4. New indices

Each is a `ScoringIndex` row plus `region_scoring_configs` weights and an `IndexActionRecommendation`
row for the recommended-action text. **Weights are proposed defaults** — the real ones come from
calibration against outcome data. Bounds go in `scoring_calibration_parameters`; none are validated.

| Index code | Proposed signals & weights | Sector | Notes |
|---|---|---|---|
| `WATERBORNE_DISEASE_RISK` | `STANDING_WATER` 0.5 · `RAINFALL` 0.5 | Health / Water | **Live** — `AdditionalIndicesSeeder`. Future: a recent-flood boost term. |
| `MENINGITIS_RISK` | `HUMIDITY` 0.4 (inv) · `DUST` 0.4 · `TEMPERATURE` 0.2 | Health | `region_id`-scoped to the Sahel belt, not system-wide |
| `AGRICULTURE_STRESS` | `SOIL_MOISTURE` 0.5 (inv) · `RAINFALL` 0.3 (deficit) · `EVAPOTRANSPIRATION` 0.2 | Agriculture | **Live** — `AdditionalIndicesSeeder`. Distinct from Drought Risk — soil-water focused. Uncalibrated. |
| `IRRIGATION_NEED` | `EVAPOTRANSPIRATION` 0.5 · `SOIL_MOISTURE` 0.3 (inv) · `RAINFALL` 0.2 (inv) | Agriculture | **Live** — `AdditionalIndicesSeeder`. 0–100 targeting score; mm-of-water output still future. |
| `RANGELAND_STRESS` | `VEGETATION` 0.6 (inv NDVI) · `RAINFALL` 0.4 (deficit) | Agriculture | **Live** — `AdditionalIndicesSeeder`. Also an emergency-planning input (pastoralist movement / herder-conflict early warning). |
| `WILDFIRE_RISK` | `HUMIDITY` 0.3 (inv) · `VEGETATION` 0.3 (dryness) · `WIND_SPEED` 0.2 · `TEMPERATURE` 0.2 · `ACTIVE_FIRE` 0.0 | Emergency Response | **Live** — `AdditionalIndicesSeeder`. FIRMS fire detections ride along at weight 0 (breakdown only). |
| `DUST_STORM_RISK` | `DUST` 0.6 · `WIND_SPEED` 0.3 · `HUMIDITY` 0.1 (inv) | Emergency Response + Air & Environment | **Live** — `AdditionalIndicesSeeder`. Harmattan season; pairs with Respiratory Risk. |
| `RIVERINE_FLOOD_FORECAST` | `RIVER_DISCHARGE` forecast percentile vs. local return period | Water | **Live** — is_forecast index, peak forecast day vs per-LGA discharge bounds (T4) |

## 5. Tier specs

### T1 — Sector grouping in the UI · Ready · size S

"Pick the sectors that matter to you" instead of a flat list of nine-plus indices. A `sectors`
table (or config map) of sector → [index codes]. The Coverage page (`CoveragePreferenceController`)
renders sector checkboxes that expand to `UserIndexSubscription` rows. `DashboardController` already
scopes to subscriptions via `IndexCoverage::resolve()`, so this is mostly copy plus a mapping table.
See "The configured workspace" in [`docs/ROADMAP_SECTORS.md`](ROADMAP_SECTORS.md#the-configured-workspace).

Starting mapping: **Public Health** → Malaria, Respiratory, Heat, (Waterborne, Meningitis);
**Agriculture** → Drought, (Agriculture Stress, Irrigation, Rangeland); **Water & Flooding** →
Flood, (Riverine Forecast); **Disaster Response** → Flood, Heat, (Wildfire, Dust Storm, Storm
warning); **Air & Environment** → Respiratory, (Dust Storm).

### T2 — Config-only indices

**`WATERBORNE_DISEASE_RISK` · Live.** Shipped via `Database\Seeders\AdditionalIndicesSeeder`
(kept out of `ReferenceDataSeeder` so prod re-runs don't reset tuned weights). `STANDING_WATER`
0.5 · `RAINFALL` 0.5, attached to the Public Health and Water & Sanitation sectors. Uncalibrated,
same caveat as the original six. `scores:calculate` picks it up automatically for any active
region with those two signals.

**`MENINGITIS_RISK` · Ready · size S–M.** Needs `HUMIDITY` and `DUST` first (T3). Then config rows,
`region_id`-scoped to the meningitis-belt states so it doesn't render nationwide.

### T3 — New free signals and their indices

**Agriculture bundle (`AGRICULTURE_STRESS`, `IRRIGATION_NEED`, `RANGELAND_STRESS`) · Live · size M.**
`SoilMoistureIngestionService` and `EvapotranspirationIngestionService` (Open-Meteo Archive) are
registered, on `IngestionCadence::DAILY`, with `ApiCapacityLimits` entries. All three indices are
seeded via `AdditionalIndicesSeeder` and attached to the Agriculture sector: Agriculture Stress
(soil-water deficit), Irrigation Need (ET₀ demand vs. supply), Rangeland Stress (inverse NDVI +
rainfall deficit, reusing the existing weekly `VEGETATION` signal). This was the widest
value-per-effort jump — it opened a whole sector. A `SoilTemperatureIngestionService` for a
planting-window index is a possible later add.

**Fire + dust (`WILDFIRE_RISK`, `DUST_STORM_RISK`) · Live · size M.** `WindIngestionService`,
`DustIngestionService`, `HumidityIngestionService` and `ActiveFireIngestionService` are all
registered, on `IngestionCadence::DAILY`, with `ApiCapacityLimits` entries; both indices are
seeded via `AdditionalIndicesSeeder`. Wildfire Risk sits in Emergency Response; Dust Storm Risk
in Emergency Response and Air & Environment. FIRMS fire detections are a weight-0 confirmation
series on Wildfire Risk — visible in the score breakdown, never affecting the number; the
service is a no-op when `FIRMS_MAP_KEY` is unset (uses the FIRMS area API, VIIRS NOAA-20, 5-day
window, ~2-month NRT history — a confirmation source, not a backfill one).

**Deepen `RESPIRATORY_RISK` · Live · size S.** `AirQualityOzoneIngestionService` and
`AirQualityNo2IngestionService` pull ground-level ozone and NO₂ from the same Open-Meteo Air
Quality API as the PM series; `DUST` (already live for Dust Storm Risk) is folded in too.
`AdditionalIndicesSeeder::deepenRespiratoryRisk()` rebalances the index to PM2.5 0.4 · PM10 0.2 ·
OZONE 0.15 · NO2 0.1 · DUST 0.15 — and only touches the PM weights when they're still at the
original default, so an admin-tuned value survives. `SO2` / `CO` are the same API call if wanted
later.

### T4 — Forecast ingestion · Shipped · size L

Turned the pipeline from scoring a *completed* 7-day period to also scoring a *future* one.
Shipped in four milestones, forecast and observed data in **fully separate tables** end to end
(the guard against a forecast leaking into a backtest or anomaly baseline):

- **M1 — forecast signal lane.** `region_forecast_signals` (mirrors `region_signals` + issue date /
  target date / lead time), `App\Services\Ingestion\ForecastIngestionService`, `OpenMeteoFloodClient`
  (GloFAS), `RiverDischargeForecastService` (14-day forward series, latest issuance wins, stale
  days pruned) + `RiverDischargeIngestionService` (observed weekly mean → history).
  `signals:ingest-forecast` command, scheduled 03:00. `RIVER_DISCHARGE` signal type.
- **M2 — forecast scoring lane.** `region_forecast_scores` (composite key, DB upsert),
  `ForecastScoringStrategy` (each forecast day normalised the same way the observed engine does —
  shared `NormalisesSignals` trait; score = the PEAK day + its lead time),
  `RegionForecastScoringService`, `RegionForecastScoreCalculated` event. `indices.is_forecast`
  flag: `scores:calculate` skips these, `scores:forecast` (scheduled 04:15) owns them.
- **M3 — `RIVERINE_FLOOD_FORECAST` index** (one `RIVER_DISCHARGE` weight, Water & Emergency
  sectors, via `AdditionalIndicesSeeder`/`SectorSeeder`). `App\Support\LatestScore` — one reader
  that resolves a region+index headline from the right lane. The region page branches to a
  forecast story (trajectory, peak + lead time, the real GloFAS daily curve replacing the linear
  projection, forecast-framed actions). `calibrate:river-discharge` derives per-LGA
  `RIVER_DISCHARGE_MIN/MAX` so big rivers don't all peg at 100 — now from GloFAS return periods
  (see follow-up below).
- **M4 — forecast-breach alerts.** `EvaluateForecastThresholds` → `ThresholdEvaluationService::
  evaluateForForecast`: a threshold on a forecast index fires on the peak, one open alert per
  config that follows the forecast and auto-resolves when it recedes or its target date passes.
  `ThresholdBreachedNotification` gets a forecast voice ("FORECAST: … projected to reach 62 in
  about 8 days … not a current reading"). `threshold_configs.watch_forecast`,
  `alerts.is_forecast` / `forecast_target_date` / `forecast_lead_days`.
- **Follow-up — return-period calibration.** `calibrate:river-discharge` (monthly) now pulls
  ~40 years of GloFAS reanalysis per reach and sets `MIN` = 10th-percentile daily flow, `MAX` =
  the empirical 20-year return level (`App\Services\Hydrology\ReturnPeriodEstimator`, Weibull
  plotting position on the annual-maximum series). The 2/5/20-year levels go in the `MAX` bound's
  metadata; status `reference_derived`. Supersedes the `observed max × 1.4` heuristic — decision
  [0005](decisions/0005-river-discharge-return-periods.md). `signals:backfill-discharge` still
  exists for observed weekly history but calibration no longer depends on it.
- **Follow-up — calibration honesty is now structured data.** Every weight and bound carries a
  `calibration_status` (`App\Support\CalibrationStatus`: placeholder / admin_tuned / reference /
  reference_derived / outcome_validated), shown per-row in the admin Scoring config UI and
  reduced to a one-line caveat on the score itself (`App\Support\IndexCalibration`). A test
  (`CalibrationHonestyTest`) fails if a new index claims rigour without a source. `docs/decisions/`
  now holds the dated ADR log. Decision [0003](decisions/0003-calibration-bounds-are-placeholders.md).
- **Follow-up — forward-scoring the observed indices.** `RainfallForecastService` +
  `TemperatureForecastService` (Open-Meteo Forecast API, `PersistsForecastSeries` trait shared
  with the discharge one) added to `config('ingestion.forecast_sources')`. `ForecastScoringStrategy`
  gains an observed-signal fallback — a weighted signal with no forecast series of its own
  (standing water, elevation, vegetation) is held flat at its latest observed value, so the
  forward score is the same formula and weights as the observed one with only the forecastable
  signal swapped. `ScoringIndex::scopeForwardScorable()` and `scores:forecast` now also score
  every observed index that weights a forecastable signal (Flood Risk, Heat Stress, Malaria,
  Drought, Composite). On the observed region page, "Where it's heading" (step 5) shows that real
  forward forecast — peak, lead time, daily curve — instead of the naive linear projection when a
  forecast row exists; the observed score and 6-step story are untouched.

Data: Open-Meteo Flood API (GloFAS discharge forecast + reanalysis) and Open-Meteo Forecast API
(rainfall / temperature forward series) — both free.

### T5 — Probabilistic scoring · Shipped · size L

"≈62% chance of crossing 67 in the next 14 days" alongside the point forecast, from a forecast
ensemble. Shipped in four milestones, all in the forecast lane (the original "writes to
`region_scores`" line above predated T4's separate-lane decision — corrected here).

- **M1 — ensemble signal lane.** `region_forecast_signals.member` (`'control'` = the T4
  deterministic run, `'glofas-NN'` / `'gfs-NN'` / `'ecmwf-NN'` / `'icon-NN'`). `OpenMeteoEnsembleClient`
  (Ensemble API, one call per weather model), `OpenMeteoFloodClient::ensembleDailyDischarge`
  (Flood API `&ensemble=true`, 50 GloFAS members). `EnsembleForecastIngestionService` +
  RiverDischarge / Rainfall / Temperature impls (`PersistsEnsembleForecastSeries` replaces only
  member rows; `PoolsWeatherEnsemble` pools GFS+ECMWF+ICON). `signals:ingest-ensemble`, scheduled
  03:20.
- **M2 — probabilistic scoring.** `region_forecast_scores` gains nullable `p10` / `p50` / `p90`,
  `exceedance_probability`, `exceedance_reference` (default 67), `member_count`.
  `EnsembleForecastScoringService` scores each member through the shared
  `ForecastScoringStrategy::scoreDailySeries` (pairs members by model+number for a multi-signal
  index, falls back to latest-observed-held-flat for a signal a member lacks), reduces the
  per-member peaks to percentiles + P(peak ≥ 67). < 5 members → no distribution, control score
  stands. Sorted member peaks + a per-day fan live in `breakdown`. One row, one event; `score`
  untouched.
- **M3 — probability-threshold alerts.** `alert_type` `forecast_probability`,
  `threshold_configs.probability_threshold`, `alerts.forecast_probability`.
  `ThresholdEvaluationService::evaluateForForecast` reads the member array and fires when
  `P(peak ≥ level) ≥ percent`; reuses the T4 one-open-alert / follows / auto-resolves machinery.
  Notification: "≈72% chance … an ensemble probability, not a certainty".
- **M4 — the payoff.** Region page (forecast index and observed step 5): the probability line +
  a p10-p90 fan band. Dashboard + region list: an "≈NN%" chip on forecast-index rows. Decision
  [0006](decisions/0006-probabilistic-scoring-ensemble.md).

Horizon stays 14 days (ensemble skill past two weeks is poor). Data: Open-Meteo Ensemble API +
Flood API `&ensemble=true` — both free.

- **Follow-up — calibration safety.** An uncalibrated LGA was showing "100 — severe flooding"
  because scoring fell back to a system-wide `RIVER_DISCHARGE_MAX` (the median 20-year level
  across the calibrated reaches, ≈22 m³/s — most modelled LGA centroids sit on minor streams, so
  any real river pegged at 100). `CalibrateRiverDischargeCommand` no longer writes that fallback;
  `ForecastScoringStrategy` / `EnsembleForecastScoringService` refuse to score a single-signal
  discharge index with no real per-reach bound (`App\Support\IndexCalibration::hasRegionBound`),
  and the region page shows an honest "isn't calibrated yet" state with the raw discharge.
  `RegionForecastScoringService` retracts a stale score when a reach loses calibration.
- **Follow-up — reach-level riverine forecast.** A confluence/valley LGA (Lokoja, Bassa) is
  scored per named river reach — the Niger and the Benue separately — because one centroid sample
  can't tell which river is about to flood. `database/seeders/data/nigeria_river_reaches.json`
  (curated from OSM channel geometry + geoBoundaries LGA polygons, snapped to the GloFAS
  network), `river_reaches` table, a `reach` dimension on `region_forecast_signals` /
  `scoring_calibration_parameters`. Ingestion, `calibrate:river-discharge` and
  `ForecastScoringStrategy` iterate reaches; the index score is the worst reach; the region page
  shows a "By river" panel and names the driving river; the forecast alert says "the Benue at
  Lokoja". 8 rivers, 87 reaches, 79 LGAs (Niger, Benue, Kaduna, Katsina-Ala, Donga, Gongola,
  Yobe/Komadugu, Cross); an LGA with no reaches is unchanged. Decision
  [0007](decisions/0007-reach-level-riverine-forecast.md).

### T6 — Climate outlook module · Ready · size M–L

A multi-decade planning view — how malaria suitability, heat-days and drought frequency shift to
2050 under different scenarios. CMIP6 data is decadal + scenario-based, so it does **not** fit the
7-day grain:

- New `region_climate_projections` table (`region_id`, `scenario`, `decade`, `variable`, `value`).
- A dedicated `climate:project` command, a read model, and a standalone view.
- Sits *outside* the ingestion / scoring / alerting pipeline — no events, no thresholds.

Data: Open-Meteo Climate Change API (CMIP6, ~10 km, multiple SSP scenarios) — free.

### T7 — Coastal resilience · Needs data · size L

Storm-surge and coastal-flood risk for Lagos, the Niger Delta and Bayelsa. A `SeaStateIngestionService`
(Open-Meteo Marine — waves, SST, free) is straightforward. The blocker is the other inputs:
high-resolution **coastal elevation** (SRTM 30 m is too coarse for surge) and **tide-gauge / tidal-model**
data. Do not claim this sector as near-term until those exist — see Sector 6 in
[`docs/ROADMAP_SECTORS.md`](ROADMAP_SECTORS.md).

### T8 — Trained-model scoring · Gated · size M (engineering)

The seam is already built: `App\Services\Scoring\TrainedModelScoringStrategy` implements the same
interface, `ScoringStrategyResolver` checks region override → global config → formula fallback, and
`isAvailable()` guards against serving an untrained prediction. Per
[`docs/INGESTION_GUIDE.md`](INGESTION_GUIDE.md#activating-the-trained-model-scoring-strategy) and
[`docs/MODEL.md`](MODEL.md#two-scoring-strategies-by-design): train against Malaria Atlas Project /
DHS-MIS matched to the `region_id` + period grain, export to `storage/app/models/{INDEX_CODE}.json`,
implement `predict()`, set `SCORING_STRATEGY=trained_model`.

The gate is data, not engineering — the same historical outcome data as the validation study. One
exercise feeds both.

### T9 — Pan-African expansion · Needs data · size L per country

Add `country` to `regions` and scope every query. The signal sources need zero code change. What is
Nigeria-specific today and must be sourced per country: **population data**, **administrative
boundaries**, and **calibration bounds** in `scoring_calibration_parameters` (per country, per
climate zone). See "Tier 3 — pan-African expansion, one country at a time" in
[`docs/ROADMAP_SECTORS.md`](ROADMAP_SECTORS.md). Never switch a country on the moment its signals
return numbers.

## 6. Deliberately held back

- **Paid-API features** — anything needing a commercial data licence beyond Open-Meteo's key. Not
  required for anything above.
- **Coastal (T7)** until the coastal DEM + tide inputs are sourced.
- **Trained model (T8)** until historical case data exists.
- **Renewable-energy and biodiversity indices** — the data (solar radiation, land cover) is free,
  but per [`docs/ROADMAP_SECTORS.md`](ROADMAP_SECTORS.md#explicitly-not-adding-as-sectors) these
  dilute the climate → health / livelihood positioning. Possible, not planned.

## 7. Suggested sequence

1. **T1 sector UI** — days. Clarity win, no risk.
2. ~~**T2 Waterborne Disease**~~ — done. Proved "new index, no new data" end to end.
3. ~~**T3 agriculture bundle**~~ — done. Agriculture Stress, Irrigation Need, Rangeland Stress all
   live; proved "new signal source + new indices" end to end.
4. ~~**T3 fire + dust**~~ — done. Wildfire Risk + Dust Storm Risk live, FIRMS active-fire confirmation wired in.
5. ~~**T4 forecast ingestion**~~ — done. Forecast signal + scoring lanes in separate tables,
   Riverine Flood Forecast index on top, forecast-breach alerts. GloFAS via Open-Meteo Flood API.
6. **T5 probabilistic scoring** — once forecasts flow. **(T4 is now in place.)**
7. **T6 climate outlook** — in parallel; independent code path.
8. **T8 trained model + validation** — as soon as outcome data is in hand.
9. **T9 country #2** — after at least one index is validated in Nigeria.
10. **T7 coastal** — whenever the coastal DEM / tide sourcing lands.
