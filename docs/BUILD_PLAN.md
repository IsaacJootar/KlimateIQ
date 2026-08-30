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
| T3 | New free signals + their indices (agriculture, fire, dust, air-quality depth) | nothing |
| T4 | Forecast ingestion — store and score *future* periods | T3 (`RIVER_DISCHARGE`) |
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
| `SOIL_MOISTURE` | Open-Meteo (0–1 … 27–81 cm; use 9–27 cm) | `DAILY` | agriculture, irrigation, drought depth | Ready |
| `SOIL_TEMPERATURE` | Open-Meteo (0–54 cm) | `DAILY` | planting window | Ready |
| `EVAPOTRANSPIRATION` | Open-Meteo ET₀ (FAO-56 Penman-Monteith) | `DAILY` | irrigation demand, crop water stress | Ready |
| `HUMIDITY` | Open-Meteo relative humidity 2 m | `DAILY` | meningitis, fire, heat index, VPD | Ready |
| `WIND_SPEED` | Open-Meteo wind 10 m (fallback: NASA POWER) | `DAILY` | fire spread, dust transport | Ready |
| `DUST` | Open-Meteo Air Quality (CAMS dust / aerosol) | `DAILY` | respiratory, meningitis, dust-storm | Ready |
| `OZONE` / `NO2` / `SO2` / `CO` | Open-Meteo Air Quality (CAMS) | `DAILY` | Respiratory Risk depth | Ready |
| `UV_INDEX` | Open-Meteo (daily max + clear-sky max) | `DAILY` | occupational / skin-eye advisories | Ready |
| `RIVER_DISCHARGE` | Open-Meteo Flood API (GloFAS) | `DAILY` + forecast | riverine flood forecasting | Ready (needs T4) |
| `ACTIVE_FIRE` | NASA FIRMS (VIIRS/MODIS hotspots) | `DAILY` | bush-fire confirmation / backtest | Ready |
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
| `AGRICULTURE_STRESS` | `SOIL_MOISTURE` 0.5 (inv) · `RAINFALL` 0.3 (deficit) · `EVAPOTRANSPIRATION` 0.2 | Agriculture | distinct from Drought Risk — soil-water focused, near-term |
| `IRRIGATION_NEED` | `EVAPOTRANSPIRATION` 0.5 · `SOIL_MOISTURE` 0.3 (inv) · `RAINFALL` 0.2 (inv) | Agriculture | actionable output: mm of water to apply |
| `RANGELAND_STRESS` | `VEGETATION` 0.6 (inv NDVI) · `RAINFALL` 0.4 (deficit) | Agriculture | feeds pastoralist-movement / herder-conflict early warning |
| `WILDFIRE_RISK` | `HUMIDITY` 0.3 (inv) · `VEGETATION` 0.3 (dryness) · `WIND_SPEED` 0.2 · `TEMPERATURE` 0.2 | Disaster | `ACTIVE_FIRE` used for confirmation, not as input |
| `DUST_STORM_RISK` | `DUST` 0.6 · `WIND_SPEED` 0.3 · `HUMIDITY` 0.1 (inv) | Disaster | harmattan season; pairs with Respiratory Risk |
| `RIVERINE_FLOOD_FORECAST` | `RIVER_DISCHARGE` forecast percentile vs. local return period | Water | not a weighted blend — a threshold on forecast discharge; needs T4 |

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

**Agriculture bundle (`AGRICULTURE_STRESS`, `IRRIGATION_NEED`, `RANGELAND_STRESS`) · Ready · size M.**
Add `SoilMoistureIngestionService`, `EvapotranspirationIngestionService`, `SoilTemperatureIngestionService`
(Open-Meteo). Register, add to `IngestionCadence::DAILY`, add `ApiCapacityLimits` entries. Seed three
indices + weights. This is the widest value-per-effort jump — it opens a whole sector.

**Fire + dust (`WILDFIRE_RISK`, `DUST_STORM_RISK`) · Ready · size M.** Add `WindIngestionService`,
`DustIngestionService`, `HumidityIngestionService`, plus `ActiveFireIngestionService` (NASA FIRMS,
stored with weight 0 as a confirmation series). Seed two indices.

**Deepen `RESPIRATORY_RISK` · Ready · size S.** Extend the existing air-quality ingestion to pull
`OZONE`, `NO2`, `DUST`; add them to the `RESPIRATORY_RISK` config with modest weights and re-tune
the PM weights.

### T4 — Forecast ingestion · Ready · size L

The single biggest unlock. Today the pipeline scores a *completed* 7-day period. Forecast ingestion
lets it score a *future* one — turning Flood Risk into "this river is forecast to exceed its banks
in 4 days" and enabling storm, heatwave and seasonal early warnings.

- Add `forecast_issued_at` + `horizon_days` (or an `is_forecast` flag) to `region_signals`, or a
  parallel `region_forecast_signals` table sharing the contract.
- `SignalIngestionService` gains an optional forecast method (or a sibling interface).
- `RegionScoringService` learns to compute a score for a forward period keyed by issue date.
- `EvaluateIndexThresholds` gains a "forecast breach" path so alerts can fire on a predicted
  crossing, **clearly labelled as a forecast** in the notification.

Keep forecast and observed data in clearly separate lanes end-to-end. The failure mode is a
forecast value silently treated as an observation in a backtest or an anomaly baseline — the same
discipline as the fallback-source labelling.

Data: Open-Meteo Forecast API + Flood API (GloFAS discharge forecast) — free.

### T5 — Probabilistic scoring · Ready · size L

"68% chance Malaria Risk crosses your threshold within 3 weeks" instead of a single number.

- Ingest ensemble members (Open-Meteo Ensemble API, up to 51 members, 35-day horizon) as multiple
  forecast rows per period.
- `WeightedFormulaScoringStrategy` runs unchanged per member; a new aggregation step writes
  `p10` / `p50` / `p90` and an `exceedance_probability` to `region_scores` (schema addition).
- `ThresholdConfig` gains a rule type: `P(index ≥ value) ≥ percent`.
- Dashboard renders a band + probability where present, falls back to the point score where not.

Depends on T4.

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
3. **T3 agriculture bundle** — the widest value-per-effort jump; opens a whole sector.
4. **T3 fire + dust** — small; rounds out disaster-response coverage.
5. **T4 forecast ingestion** — the architectural investment. Ship river-flood forecasting on top of
   it as the first payoff.
6. **T5 probabilistic scoring** — once forecasts flow.
7. **T6 climate outlook** — in parallel; independent code path.
8. **T8 trained model + validation** — as soon as outcome data is in hand.
9. **T9 country #2** — after at least one index is validated in Nigeria.
10. **T7 coastal** — whenever the coastal DEM / tide sourcing lands.
