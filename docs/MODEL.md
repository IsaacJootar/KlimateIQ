# How KlimateIQ's scoring actually works

This is a plain-language walkthrough of the real formula behind every risk score on the
platform — not a summary, the actual math, so anyone (a health officer, an auditor, a future
engineer) can verify a score by hand. Every number KlimateIQ shows traces back to a real signal
reading through a fixed, auditable calculation. There is no black box: `WeightedFormulaScoringStrategy`
(the class that computes every score today) is under 160 lines and has no training step, no
opaque parameters, and no dependency on data we don't already show you.

## How trustworthy are these numbers (read this first)

Every score on the platform is **transparent and reproducible** — you can always see which
signal drove it and recompute it by hand. That is not the same as **validated**. Two honest
caveats that a future team must not lose:

1. **The weights are engineering defaults, not fitted parameters.** "Rainfall 0.4, Standing
   Water 0.4, Elevation 0.2" for Flood Risk is a sensible starting point chosen by a person, not
   a coefficient derived from flood outcome data. The same is true for every index. They are
   safe to tune from the admin UI; the seeders never overwrite a tuned value.

2. **Almost every calibration bound is an uncalibrated placeholder.** The `min`/`max` a signal
   is normalised against (below) is a climatologically-plausible range, not a threshold tied to
   a measured health or damage outcome. The two exceptions are the PM2.5 / PM10 bounds, which
   use a cited public-health reference (US EPA AQI "Hazardous"). Every bound row in
   `scoring_calibration_parameters` that was auto-generated carries a `source_reference` saying
   so.

Closing both gaps is the **validation workstream** (`docs/BUILD_PLAN.md` T8): train / calibrate
against historical outcome data (Malaria Atlas Project, DHS-MIS, flood records). The engineering
seam for that is already built — see "Two scoring strategies" below — so it is a data exercise,
not a rewrite. Until then, the product presents scores as a **prioritisation aid**, never as a
probability or a guarantee, and says so in the UI.

## The bands

One cut, used everywhere a score becomes green / amber / red (`App\Support\RiskBand`):

| Score | Band |
|---|---|
| `null` (no data) | none |
| 0 – 33 | green |
| 34 – 66 | amber |
| 67 – 100 | red |

These cutoffs are a **product choice**, not derived — even thirds of the 0–100 scale, chosen so
"amber" and "red" line up with how an officer would triage. Region cards, the dashboard's
high-risk count, forecast peaks and the per-band action text all read this one function.

## Why this design, deliberately

Field officers and emergency responders need to be able to defend a decision — "the score is 82
because rainfall hit 90mm against a 200mm ceiling, and standing water is elevated" is something
a health officer can act on and explain to a supervisor. "A model said 82%" is not, especially
with no visibility into why. We chose auditable, explainable scoring over a black-box model for
that reason, not because we couldn't build a model — see "Two scoring strategies" below.

## The formula, step by step

For one region, one index (e.g. Malaria Risk), one 7-day period:

**1. Normalize each signal to a 0–100 scale.**

Every signal (rainfall, temperature, standing water, ...) is measured in its own real-world
unit — millimeters, degrees Celsius, percent. To combine them, each is scaled against a
calibrated min/max range for that signal:

```
ratio = (raw_value − min) / (max − min)      # clamped to [0, 1]
normalized = ratio × 100                      # if higher values mean higher risk
normalized = (1 − ratio) × 100                # if lower values mean higher risk (e.g. elevation)
```

Example: rainfall of 90mm against a calibrated range of 0–200mm → `ratio = 0.45` → normalized
score of 45.

**2. Apply a regional vulnerability multiplier, if configured.**

Some signal/index combinations can be configured to weigh more heavily in regions with a higher
share of children, elderly residents, or outdoor labor (`region_vulnerability_profiles`). Where
configured:

```
multiplier = 1 + (vulnerability_factor × vulnerability_weight)
contribution = min(100, normalized × multiplier)
```

A region with no vulnerability profile yet is treated as average (`vulnerability_factor = 0.5`)
rather than zero, so missing demographic data never silently suppresses this weighting.

**3. Combine every signal by its configured weight.**

```
score = Σ(contribution × weight) / Σ(weight)     # clamped to [0, 100]
```

**4. Missing data is skipped, not treated as zero risk.** If a signal has no reading for that
region/period, it's left out of both sums above — the remaining signals' weights implicitly
renormalize. If *every* signal for that index is missing, the score is `null`, not `0` — "we
don't know yet" is never shown as "no risk."

That's the entire model. No hidden coefficients, no data used that isn't already visible in the
score breakdown shown alongside every score in the product.

## Every index's real weights

The **source of truth** is the seeders — `ReferenceDataSeeder::seedScoringConfigs()` for the
original six, `AdditionalIndicesSeeder` for everything since — plus any per-region override in
`region_scoring_configs`. This table is a snapshot for readers; if it disagrees with the
seeders, the seeders are right.

The original six:

| Index | Signals and weights |
|---|---|
| Malaria Risk | Rainfall 0.5, Standing Water 0.5 |
| Flood Risk | Rainfall 0.4, Standing Water 0.4, Elevation 0.2 (lower ground = higher risk) |
| Composite Climate-Health Pressure | Rainfall 0.25, Standing Water 0.25, Temperature 0.2, Vegetation 0.15, Population Exposure 0.15 |
| Heat Stress Risk | Temperature 0.7, Vegetation 0.3 (less vegetation = less shade/cooling = higher risk) |
| Drought Risk | Rainfall 0.5 (less rain = higher risk), Vegetation 0.5 (lower NDVI = higher risk) |
| Respiratory Risk | PM2.5 0.4, PM10 0.2, Ozone 0.15, NO₂ 0.1, Dust 0.15 (PM rebalanced down by `AdditionalIndicesSeeder::deepenRespiratoryRisk()` to make room for the gaseous pollutants) |

Added since (all via `AdditionalIndicesSeeder`, all **uncalibrated** — same caveat as above):

| Index | Signals and weights | Sector |
|---|---|---|
| Waterborne Disease Risk | Standing Water 0.5, Rainfall 0.5 | Public Health · Water |
| Agriculture Stress | Soil Moisture 0.5 (drier = worse), Rainfall 0.3 (deficit), Evapotranspiration 0.2 | Agriculture |
| Irrigation Need | Evapotranspiration 0.5, Soil Moisture 0.3 (inv), Rainfall 0.2 (inv) | Agriculture |
| Rangeland Stress | Vegetation 0.6 (inv NDVI), Rainfall 0.4 (deficit) | Agriculture |
| Wildfire Risk | Humidity 0.3 (inv), Vegetation 0.3 (dryness), Wind 0.2, Temperature 0.2, Active Fire **0.0** (shown in the breakdown, never scored — a confirmation series) | Emergency Response |
| Dust Storm Risk | Dust 0.6, Wind 0.3, Humidity 0.1 (inv) | Emergency Response · Environment |
| Dry-Season Water Stress | Rainfall 0.35 (inv), Standing Water 0.25 (inv — less surface water is worse here), Soil Moisture 0.2 (inv), Evapotranspiration 0.2 | Water |
| Riverine Flood Forecast | River Discharge 1.0 — **forecast-only**, see "Forward-looking scoring" below | Water · Emergency Response |

## Every signal's calibration bounds, and where they came from

| Signal | Range | Why |
|---|---|---|
| Rainfall | 0–200mm | Weekly-total plausible range for Nigerian climate |
| Standing Water | 0–100% | Surface coverage, already a percentage |
| Temperature | 15–45°C | Plausible daily-mean range for Nigeria |
| Vegetation (NDVI) | −1 to 1 | NDVI's actual mathematical range |
| Population Exposure | 0–3,500,000 | Real max across all 774 seeded LGAs (Alimosho) — see `docs/INGESTION_GUIDE.md` |
| Elevation | 0–500m | Plausible range for Nigerian terrain |
| PM2.5 | 0–500.4 µg/m³ | US EPA Air Quality Index "Hazardous" ceiling — a cited public-health reference, not an arbitrary number |
| PM10 | 0–604 µg/m³ | US EPA Air Quality Index "Hazardous" ceiling |
| Soil Moisture | 0.05–0.40 m³/m³ | ERA5-Land 7–28 cm volumetric water content, dry → near-saturation for Nigeria |
| Evapotranspiration (ET₀) | 0–50 mm | Weekly-total plausible range, FAO-56 reference ET |
| Humidity | 0–100 % | Relative humidity, already a percentage |
| Wind Speed | 0–40 km/h | Daily-max plausible range |
| Dust / Ozone / NO₂ | 0–500 / 0–300 / 0–200 µg/m³ | WHO / EPA "very unhealthy" reference points, **not** epidemiologically calibrated |
| River Discharge | **per-LGA** — see below | rivers span three orders of magnitude of flow; a single bound is meaningless |

Every bound is stored in `scoring_calibration_parameters` and can be overridden per-region
without a code change — a region with a genuinely different climate baseline doesn't need the
same 0–200mm rainfall ceiling as every other region.

### River discharge is calibrated per LGA, from return periods

The Riverine Flood Forecast index measures a forecast discharge against the LGA's *own* flow
distribution, because the Niger at Lokoja and a seasonal stream in the north-east differ by a
factor of a thousand — a shared `RIVER_DISCHARGE_MIN/MAX` would peg every big-river reach at 100
and never discriminate.

`calibrate:river-discharge` (scheduled monthly) pulls **~40 years of GloFAS reanalysis** (Open-Meteo
Flood API, back to the mid-1980s) per reach and computes empirical flood **return levels**
(`App\Services\Hydrology\ReturnPeriodEstimator`):

- the highest flow of each year → the annual-maximum series (~40 values)
- the 2-, 5- and 20-year levels via the Weibull plotting position
- `MIN` = the reach's 10th-percentile daily flow (a dry-season low reads green)
- `MAX` = the 20-year return level (a rare flood lands at the top of red)
- the 2 / 5 / 20-year levels are kept in the `MAX` bound's `parameter_metadata`, status
  `reference_derived`

So a forecast at the 2-year flood level lands around amber, the 20-year level near 100 — the
score *means* something. This **supersedes the earlier `observed max × 1.4` heuristic**
(decision [0005](decisions/0005-river-discharge-return-periods.md)).

**What this still is not:** a gauge-calibrated hydrological model (channel geometry, rating
curves) — that is a national-flood-agency exercise. The return level is only as good as the
record length: ~40 years supports a 2-to-20-year band, not a 100-year one. A state hydrologist
can hand-set a real bound per region and the monthly job leaves it alone.

**Per named reach for confluence LGAs.** A single GloFAS sample at the LGA centroid can't tell a
Niger flood from a Benue flood. For the Niger–Benue corridor (~23 LGAs — `river_reaches`, seeded
from `database/seeders/data/nigeria_river_reaches.json`) the index is scored **per reach**: each
river's forecast discharge against that reach's own return levels, the headline is the worst
reach, and the region page names the river driving it. An uncalibrated reach is dropped from the
score, not measured against a borrowed number; an LGA with no reach data is scored once at its
centroid as before. Decision
[0007](decisions/0007-reach-level-riverine-forecast.md).

**Failing safe.** With no calibrated bound at all (a reach the monthly job hasn't reached, or a
reseed that cleared it) the Riverine Flood Forecast shows "calibration pending for this LGA" and
no number — there is no system-wide `RIVER_DISCHARGE` fallback, because a single shared bound
across rivers that span three orders of magnitude either pegs every real river at 100 or reads
every flood as normal.

## The decision log

`docs/decisions/` records *why* the non-obvious engineering choices were made and *what would
change them* — the transparent-formula choice, the band cutoffs, the calibration-honesty
approach, the separate forecast lane, the return-period calibration. Read it before "cleaning
up" anything in this file.

## Two scoring strategies, by design

The formula above (`formula`) is what's live today. There is a second strategy,
`trained_model` (`App\Services\Scoring\TrainedModelScoringStrategy`), implementing the exact
same interface — same inputs, same output shape — so activating it is a config change, not a
rewrite of scoring, alerting, or the dashboard. It isn't trained yet: `isAvailable()` checks for
a real trained model artifact and safely falls back to the formula strategy when none exists, so
selecting it never breaks anything or serves an untrained prediction. The seam — the interface,
the fallback logic, the exact expected historical-data format — is deliberately built now, so the
growth path to a validated model is engineered, not hypothetical, once real historical case data
(Malaria Atlas Project, DHS-MIS) is available to train against.

## Forward-looking (forecast) scoring

Everything above scores a **completed** 7-day period. A parallel lane scores a **future** one
(`docs/BUILD_PLAN.md` T4). It is deliberately kept in its own tables end to end —
`region_forecast_signals`, `region_forecast_scores` — so a forecast value can never be picked up
by an observed-data query (an anomaly baseline, a backtest, the dashboard's "this week").

**How a forward score is computed** (`ForecastScoringStrategy`):

1. For each day in the forecast horizon (up to 14 days out), normalise that day's forecast
   signal exactly the way the observed engine normalises an observed reading — same
   `min`/`max` bounds, same direction, same `NormalisesSignals` trait.
2. A weighted signal that has **no forecast series of its own** (standing water is a
   near-static occurrence layer, elevation is fixed, vegetation is a 16-day composite) falls
   back to the region's **latest observed reading, held flat** across the horizon. So a
   forward Flood Risk score is the same formula and weights as the observed one with only
   rainfall swapped for its forecast — the two numbers stay directly comparable.
3. Combine by weight into a 0–100 score per day.
4. The index's forecast score is the **peak** of that daily series, plus the day it lands
   (`lead_days_to_peak`). "Flood Risk is forecast to reach 72 in about 4 days", not a single
   flat number.

**Which indices get a forward score:** the dedicated forecast index (Riverine Flood Forecast),
plus every observed index that weights a signal with a forecast source —
`ScoringIndex::scopeForwardScorable()`, currently anything using Rainfall, Temperature or River
Discharge (Flood, Heat Stress, Malaria, Drought, Composite, Dry-Season Water Stress, the
agriculture bundle). `scores:forecast` (scheduled 04:15, after the observed `scores:calculate`)
owns them.

**Forecast data sources:** Open-Meteo Flood API (GloFAS river discharge) and Open-Meteo Forecast
API (rainfall, temperature) — both free. Forecast issuance history (needed to backtest forecast
skill) is **not** kept — only the latest forecast per region/signal — and remains out of scope.

### How confident is the forecast (T5 — the ensemble)

The point forecast above is one deterministic model run. Alongside it the platform ingests an
**ensemble** — the same forecast re-run 30-50 times from perturbed starting conditions — and
scores every member through the *identical* formula. GloFAS supplies its own 50-member discharge
ensemble; rainfall and temperature pool three weather models (GFS + ECMWF + ICON). Members live
in `region_forecast_signals` tagged with a `member` column (`'control'` is the deterministic run,
untouched).

`EnsembleForecastScoringService` reduces the per-member peak scores to `p10 / p50 / p90` and an
**exceedance probability** — the share of members whose peak reaches 67 (the red cutoff) — folded
into the same `region_forecast_scores` row. The headline `score` stays the control peak. Fewer
than 5 members resolving → no distribution, the point forecast stands alone.

This shows up as "≈62% chance of crossing 67 in the next 14 days" on the score, a p10-p90 fan
band on the daily chart, an "≈NN%" chip in the dashboard and region list, and a new threshold
rule — `P(index ≥ level within 14 days) ≥ percent`.

**What the probability is and isn't:** it is a real, calibrated estimate of *forecast*
uncertainty — the models genuinely disagree this much. It is **not** validated against observed
outcomes: whether "70%" verifies 70% of the time needs the same historical outcome data as the
trained-model study (T8). Until then it carries the same caveat as the score itself. The
band-mapping (which discharge = score 67) is still the T4 calibration — decisions
[0003](decisions/0003-calibration-bounds-are-placeholders.md),
[0006](decisions/0006-probabilistic-scoring-ensemble.md).

## Data sources

See [`README.md`](../README.md#data-sources) for the full table and
[`docs/INGESTION_GUIDE.md`](INGESTION_GUIDE.md) for the resilience pattern — no single provider
is this platform's entire data backbone, and every source (including a fallback) plugs into the
same `SignalIngestionService` interface with zero changes needed to scoring or alerting.
