# Next Phase: Sector Expansion

KlimateIQ today is 12 live indices — the original six (Malaria Risk, Flood Risk, Drought Risk,
Heat Stress Risk, Respiratory Risk, Composite Climate-Health Pressure) plus Waterborne Disease
Risk, a three-index agriculture bundle (Agriculture Stress, Irrigation Need, Rangeland Stress),
and Wildfire Risk + Dust Storm Risk — built from a 14-source signal set, sitting on a data
spine — NASA POWER, Open-Meteo (archive + air quality), NASA FIRMS, JRC Global Surface Water,
MODIS/AppEEARS, Open Topo Data, UNFPA population — that is already lat/long-driven and works
anywhere, not just Nigeria.

The next phase reframes that spine around **sectors**: instead of a user picking regions and
seeing every index, they pick the sector(s) that matter to them (agriculture, emergency
response, ...) and configure what gives them value. `ScoringIndex` and
`UserIndexSubscription` already support this "pick what matters to you" model today — this is
the foundation the reframing builds on, not a rebuild.

This doc is the parking lot for that phase. Nothing here is built yet. It exists so the sector
list and their value cases don't have to be re-derived from scratch when this phase starts.

For the code-level breakdown — every proposed index with its signals and weights, every new
signal source, and the engineering each addition takes — see the companion
[`docs/BUILD_PLAN.md`](BUILD_PLAN.md). It expands the three build tiers at the bottom of this
doc into a nine-rung ladder.

## The 6 sectors

Sectors 1–5 have at least one index live today. Sector 6 (Coastal Resilience) is a real,
buildable proposal not yet built, called out honestly below. Each sector can carry more than
one index; the additional indices per sector are noted below and specified in
[`docs/BUILD_PLAN.md`](BUILD_PLAN.md#4-new-indices). Almost all of them build on free,
open-licensed data (Open-Meteo is CC-BY 4.0) with no new data cost — the exceptions are called
out explicitly.

### 1. Public Health & Epidemiology — live today

**Pain point**: Health officers find out about a malaria surge from confirmed case reports —
meaning people are already sick by the time anyone acts. The 2–4 week lag between
rainfall/standing water and case surges means RDTs and ACTs get shipped reactively, after the
outbreak has started.

**What KlimateIQ does**: Turns satellite rainfall, standing water, and temperature into a
per-LGA score with a specific recommended action, weeks before case data would show anything.

**Result**: An officer pre-positions RDTs and ACTs in the right LGA before the surge, not after
clinics start filling up.

**Also buildable here**: a **Meningitis Risk** index for the Sahel dry-season belt (driven by
low humidity + airborne dust + heat — all free Open-Meteo/CAMS signals, `region_id`-scoped so it
doesn't render nationwide), plus the Waterborne Disease index from Sector 5. Dengue and Lassa
fever have their own environmental lead indicators and are further-out candidates.

### 2. Agriculture & Food Security — live today via Drought Risk + a three-index agriculture bundle

**Pain point**: Extension officers covering hundreds of LGAs have no forward-looking signal —
they find out a season is bad when crops are already visibly wilting, too late to adjust
irrigation or water-conservation messaging.

**What KlimateIQ does**: Combines real rainfall deficit and vegetation stress (NDVI) into a
Drought Risk score per LGA.

**Result**: An extension officer prioritizes which specific LGAs need water-conservation
support before yields visibly collapse, instead of a uniform, too-late response everywhere.

**Also live here** — three indices on two new free Open-Meteo signals (`SOIL_MOISTURE`,
`EVAPOTRANSPIRATION`), all via `AdditionalIndicesSeeder`, uncalibrated like the rest:

- **Agriculture Stress** (soil moisture 0.5 + rainfall deficit 0.3 + ET₀ demand 0.2) — a
  pre-visible crop-water-stress signal that moves weeks before NDVI does.
- **Irrigation Need** (ET₀ 0.5 + soil moisture 0.3 + rainfall 0.2) — where supplementary
  irrigation would do the most good this week. A 0–100 targeting score; a literal mm-of-water
  output is a future enhancement.
- **Rangeland Stress** (inverse NDVI 0.6 + rainfall deficit 0.4) — pasture failure in the
  grazing belt, also an early indicator of pastoralist movement and farmer–herder pressure.

### 3. Emergency Response & Infrastructure — live today via Flood Risk + Wildfire & Dust Storm Risk

**Pain point**: Emergency agencies typically mobilize after displacement has already
happened — evacuation routes get planned mid-crisis because there's no forward signal on which
LGA is closest to flooding.

**What KlimateIQ does**: Combines rainfall, standing water, and elevation into a ranked list of
which regions are closest to flooding right now.

**Result**: Responders pre-position shelter and clean water in the highest-risk LGA first, when
resources are limited, not after the water's already risen.

**Also live here** — on three new free Open-Meteo signals (`HUMIDITY`, `WIND_SPEED`, `DUST`):
a **Wildfire Risk** index (low humidity 0.3 + dry vegetation 0.3 + wind 0.2 + heat 0.2) and a
**Dust Storm Risk** index (CAMS dust 0.6 + wind 0.3 + low humidity 0.1), the latter also in the
Air & Environment sector. NASA FIRMS satellite fire detections ride along on Wildfire Risk as a
weight-0 confirmation series — shown next to the score, never affecting it.

**Still buildable here**: the bigger step for this sector is forecast ingestion (see
"Cross-cutting capabilities" below) — it turns the current now-cast Flood Risk into a days-ahead
river-flood forecast (GloFAS discharge, free) and adds storm and heatwave early warnings.

### 4. Environmental & Air Quality Monitoring — live today via Respiratory Risk + Dust Storm Risk

**Pain point**: There's essentially no LGA-level air quality visibility in Nigeria today —
harmattan dust events and urban pollution spikes go unmeasured locally, so nobody knows air
quality got dangerous until people start showing up at clinics.

**What KlimateIQ does**: Real PM2.5/PM10 readings per region, refreshed daily.

**Result**: An advisory (masks, reduced outdoor exposure) goes out timed to actual measured
pollution, not guesswork or hospital-admission lag.

**Also buildable here**: Respiratory Risk is PM2.5/PM10 only today. Extending the *same*
Open-Meteo Air Quality pull to ozone, NO₂ and harmattan dust deepens the index with no new
source — it's the same API call.

### 5. Water Resources & Sanitation — live today via Waterborne Disease Risk

**Pain point**: Cholera and typhoid outbreaks are strongly tied to contaminated standing water
after flooding, but WASH programs have no early-warning connecting "where is water
accumulating" to "where is disease risk rising" — same reactive lag as malaria, just for a
different disease.

**What KlimateIQ does**: A dedicated **Waterborne Disease Risk index** (live) — Standing Water
0.5 + Rainfall 0.5, reusing signals already collected, attached to the Water & Sanitation and
Public Health sectors. Shipped via `Database\Seeders\AdditionalIndicesSeeder`. Uncalibrated,
same caveat as the original six.

**Result**: WASH programs target water treatment to the specific LGAs where contamination risk
is actually rising, instead of spreading limited sanitation resources evenly.

**Also buildable here**: a **river-flood forecast** (GloFAS discharge via Open-Meteo's Flood
API — free, but needs forecast ingestion) and a **dry-season water-availability** view (JRC
surface water + rainfall) for WASH resource planning when supply is tightest.

### 6. Coastal Resilience — furthest out; not live, needs new signal sources

**Pain point**: Lagos, the Niger Delta, and Bayelsa face real erosion and storm-surge risk that
the current inland-rainfall-driven Flood Risk doesn't capture at all.

**What KlimateIQ would need**: Genuinely new signal sources — high-resolution coastal elevation
(SRTM's 30 m is too coarse for surge), tide-gauge or tidal-model data — not a relabel of what
exists. The marine data itself (wave height, sea-surface temperature) *is* free via Open-Meteo's
Marine API, so a `SeaStateIngestionService` is trivial; the elevation and tide inputs are the
real sourcing task. This is the one sector that shouldn't be named as "done" or "near-done" in
any pitch until it's actually built with coastal-specific data.

## Cross-cutting capabilities

Three additions aren't sectors — they multiply the value of every sector above. Full specs in
[`docs/BUILD_PLAN.md`](BUILD_PLAN.md#5-tier-specs) (tiers T4–T6).

- **Forecast ingestion.** Today the pipeline scores a *completed* 7-day period. Storing and
  scoring *future* periods (Open-Meteo Forecast + Flood APIs, free) turns Flood Risk into a
  days-ahead river-flood forecast and adds storm and heatwave early warnings. It needs a schema
  change on `region_signals` (a `forecast_issued_at` / `horizon` concept) and a "forecast breach"
  path in `EvaluateIndexThresholds`, with forecast and observed data kept in strictly separate
  lanes. This is the single biggest unlock and blocks the next one.
- **Probabilistic scoring.** Ensemble members (Open-Meteo Ensemble API, up to 51 members, 35-day
  horizon, free) → a likelihood instead of a single number: "68% chance Malaria Risk crosses your
  threshold within 3 weeks." `WeightedFormulaScoringStrategy` runs unchanged per member; a new
  aggregation step writes `p10`/`p50`/`p90` + an exceedance probability to `region_scores`, and
  `ThresholdConfig` gains a `P(index ≥ value) ≥ percent` rule type.
- **Climate outlook.** CMIP6 projections to 2050 (Open-Meteo Climate Change API, free) for
  multi-decade adaptation planning — where malaria suitability, heat-days and drought frequency
  shift under different scenarios. Decadal + scenario-based data doesn't fit the 7-day grain, so
  it's a separate `region_climate_projections` table and view, outside the alerting pipeline.

## The configured workspace

Users pick the sector(s) they care about, and get one dashboard scoped to just that
configuration — not six separate sector apps, one workspace that only shows what they asked
for. This isn't new plumbing to design from scratch: `CoveragePreferenceController` already
lets a user pick specific indices (`UserIndexSubscription`), and `DashboardController` already
scopes everything — high-risk count, top-risk regions, alerts — to whatever a user has picked,
via `IndexCoverage::resolve()`. An empty selection already means "show me everything" today.

What sectors add on top of that existing mechanism is a **grouping label**, not a new
selection system: the Coverage page would offer "Agriculture & Food Security," "Public Health,"
etc. as pickable groups (each mapping to one or more indices under the hood — Agriculture ⇒
Drought Risk, Public Health ⇒ Malaria Risk + Respiratory Risk + Heat Stress), and picking a
sector is really just picking its indices in bulk. The dashboard a user lands on afterward is
the same `DashboardController`/`dashboard.blade.php` that exists today — it already renders
differently per user based on their `UserIndexSubscription` rows, so "a configured dashboard for
my configured sectors" is close to a copy change plus a sector→index mapping table, not a new
dashboard to build.

## Build tiers (for when this phase starts)

These three tiers are the shape of the phase; [`docs/BUILD_PLAN.md`](BUILD_PLAN.md#2-the-build-ladder)
breaks them into nine ordered rungs with a suggested sequence.

- **Tier 1 — UI reframing** *(done)*: the twelve live indices are grouped under sector labels in
  the coverage/subscription UI (see "The configured workspace" above). No new data.
- **Tier 2 — real sector-specific signals** *(in progress)*: Waterborne Disease (no new
  ingestion), the agriculture bundle (Agriculture Stress, Irrigation Need, Rangeland Stress, on
  soil moisture + ET₀), and Wildfire + Dust Storm Risk (on humidity, wind and CAMS dust) are all
  live. Still ahead: the air-quality extension, forecast ingestion, probabilistic scoring, and
  the climate-outlook module — all on free, open-licensed data
  (Open-Meteo CC-BY 4.0, NASA FIRMS). Coastal Resilience (Sector 6) is the one part of this tier
  that genuinely needs new/paid data (coastal elevation, tide).
- **Tier 3 — pan-African expansion, one country at a time**: `regions` currently has no
  `country` field. The core signal sources (NASA POWER, Open-Meteo, JRC, MODIS/AppEEARS, SRTM)
  are already globally-applicable by lat/long with zero code changes — but that's not the same as
  being ready to add a country. Population data, administrative boundaries, and rainfall/
  temperature calibration bounds are all Nigeria-specific today (see `docs/INGESTION_GUIDE.md`
  and "How trustworthy are the current bounds?"), and every one of them varies per country and
  per climate zone. Rather than a single "go pan-African" push, expansion should happen country
  by country: add a `country` field, then only bring a new country fully online once its own
  population source, admin boundaries, and calibration bounds are actually sourced for it — not
  the moment its signals technically start returning numbers. A country with working ingestion
  but Nigeria's calibration bounds would produce scores that look precise but are quietly wrong,
  the exact trap this doc keeps calling out for Nigeria itself.

## Explicitly not adding as sectors

Renewable Energy and Biodiversity/Conservation were considered and set aside — the data would
theoretically support them, but they don't fit the "climate → health/livelihood" pain point this
platform is built around, and adding them would dilute that positioning rather than extend it.
