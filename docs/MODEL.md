# How KlimateIQ's scoring actually works

This is a plain-language walkthrough of the real formula behind every risk score on the
platform — not a summary, the actual math, so anyone (a judge, a health officer, a future
engineer) can verify a score by hand. Every number KlimateIQ shows traces back to a real signal
reading through a fixed, auditable calculation. There is no black box: `WeightedFormulaScoringStrategy`
(the class that computes every score today) is under 160 lines and has no training step, no
opaque parameters, and no dependency on data we don't already show you.

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

| Index | Signals and weights |
|---|---|
| Malaria Risk | Rainfall 0.5, Standing Water 0.5 |
| Flood Risk | Rainfall 0.4, Standing Water 0.4, Elevation 0.2 (lower ground = higher risk) |
| Composite Climate-Health Pressure | Rainfall 0.25, Standing Water 0.25, Temperature 0.2, Vegetation 0.15, Population Exposure 0.15 |
| Heat Stress Risk | Temperature 0.7, Vegetation 0.3 (less vegetation = less shade/cooling = higher risk) |
| Drought Risk | Rainfall 0.5 (less rain = higher risk), Vegetation 0.5 (lower NDVI = higher risk) |
| Respiratory Risk | PM2.5 0.6, PM10 0.4 (PM2.5 weighted higher — the finer, more health-critical particulate per WHO guidance) |

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

Every bound is stored in `scoring_calibration_parameters` and can be overridden per-region
without a code change — a region with a genuinely different climate baseline doesn't need the
same 0–200mm rainfall ceiling as every other region.

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

## Data sources

See [`README.md`](../README.md#data-sources) for the full table and
[`docs/INGESTION_GUIDE.md`](INGESTION_GUIDE.md) for the resilience pattern — no single provider
is this platform's entire data backbone, and every source (including a fallback) plugs into the
same `SignalIngestionService` interface with zero changes needed to scoring or alerting.
