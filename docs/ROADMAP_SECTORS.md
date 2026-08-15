# Next Phase: Sector Expansion

KlimateIQ today is 6 live indices (Malaria Risk, Flood Risk, Drought Risk, Heat Stress Risk,
Respiratory Risk, plus the underlying signal set they're built from) sitting on a data spine —
NASA POWER, Open-Meteo, JRC Global Surface Water, MODIS/AppEEARS, Open Topo Data, UNFPA
population — that is already lat/long-driven and works anywhere, not just Nigeria.

The next phase reframes that spine around **sectors**: instead of a user picking regions and
seeing every index, they pick the sector(s) that matter to them (agriculture, emergency
response, ...) and configure what gives them value. `ScoringIndex` and
`UserIndexSubscription` already support this "pick what matters to you" model today — this is
the foundation the reframing builds on, not a rebuild.

This doc is the parking lot for that phase. Nothing here is built yet. It exists so the sector
list and their value cases don't have to be re-derived from scratch when this phase starts.

## The 6 sectors

Sectors 1–4 map directly to indices that are live today. Sectors 5–6 are real, buildable
next-phase proposals — not yet built, called out honestly below.

### 1. Public Health & Epidemiology — live today

**Pain point**: Health officers find out about a malaria surge from confirmed case reports —
meaning people are already sick by the time anyone acts. The 2–4 week lag between
rainfall/standing water and case surges means RDTs and ACTs get shipped reactively, after the
outbreak has started.

**What KlimateIQ does**: Turns satellite rainfall, standing water, and temperature into a
per-LGA score with a specific recommended action, weeks before case data would show anything.

**Result**: An officer pre-positions RDTs and ACTs in the right LGA before the surge, not after
clinics start filling up.

### 2. Agriculture & Food Security — live today via Drought Risk

**Pain point**: Extension officers covering hundreds of LGAs have no forward-looking signal —
they find out a season is bad when crops are already visibly wilting, too late to adjust
irrigation or water-conservation messaging.

**What KlimateIQ does**: Combines real rainfall deficit and vegetation stress (NDVI) into a
Drought Risk score per LGA.

**Result**: An extension officer prioritizes which specific LGAs need water-conservation
support before yields visibly collapse, instead of a uniform, too-late response everywhere.

### 3. Emergency Response & Infrastructure — live today via Flood Risk

**Pain point**: Emergency agencies typically mobilize after displacement has already
happened — evacuation routes get planned mid-crisis because there's no forward signal on which
LGA is closest to flooding.

**What KlimateIQ does**: Combines rainfall, standing water, and elevation into a ranked list of
which regions are closest to flooding right now.

**Result**: Responders pre-position shelter and clean water in the highest-risk LGA first, when
resources are limited, not after the water's already risen.

### 4. Environmental & Air Quality Monitoring — live today via Respiratory Risk

**Pain point**: There's essentially no LGA-level air quality visibility in Nigeria today —
harmattan dust events and urban pollution spikes go unmeasured locally, so nobody knows air
quality got dangerous until people start showing up at clinics.

**What KlimateIQ does**: Real PM2.5/PM10 readings per region, refreshed daily.

**Result**: An advisory (masks, reduced outdoor exposure) goes out timed to actual measured
pollution, not guesswork or hospital-admission lag.

### 5. Water Resources & Sanitation — real, buildable next-phase; not live yet

**Pain point**: Cholera and typhoid outbreaks are strongly tied to contaminated standing water
after flooding, but WASH programs have no early-warning connecting "where is water
accumulating" to "where is disease risk rising" — same reactive lag as malaria, just for a
different disease.

**What KlimateIQ would build**: A dedicated Waterborne Disease Risk index. Genuinely cheap to
add — it reuses the Standing Water + Rainfall signals already collected, just needs its own
calibration (a new `indices` row + weighted `region_scoring_configs` rows, per
`docs/INGESTION_GUIDE.md`'s "Adding a new named index" section — no new ingestion needed).

**Result (once built)**: WASH programs target water treatment to the specific LGAs where
contamination risk is actually rising, instead of spreading limited sanitation resources evenly.

### 6. Coastal Resilience — furthest out; not live, needs new signal sources

**Pain point**: Lagos, the Niger Delta, and Bayelsa face real erosion and storm-surge risk that
the current inland-rainfall-driven Flood Risk doesn't capture at all.

**What KlimateIQ would need**: Genuinely new signal sources — coastal elevation, tide data — not
a relabel of what exists. This is the one sector that shouldn't be named as "done" or
"near-done" in any pitch until it's actually built with coastal-specific data.

## Build tiers (for when this phase starts)

- **Tier 1 — UI reframing**: group the existing 6 (soon 7, with Waterborne Disease) indices
  under sector labels in the coverage/subscription UI. No new data, no new code beyond the
  grouping and copy.
- **Tier 2 — real sector-specific signals**: Sector 5 (Waterborne Disease index) fits here first
  since it needs no new ingestion. Coastal Resilience (Sector 6) and any deeper agriculture
  signal (e.g. NASA SMAP soil moisture) also fit here — some may require paid APIs.
- **Tier 3 — pan-African expansion**: `regions` currently has no `country` field. Most core
  signal sources (NASA POWER, Open-Meteo, JRC, MODIS/AppEEARS, SRTM) are already
  globally-applicable by lat/long with zero code changes — the real work is a `country` column
  plus per-country boundary/population data sourcing (population currently comes from a
  Nigeria-specific UNFPA file, see `docs/INGESTION_GUIDE.md`).

## Explicitly not adding as sectors

Renewable Energy and Biodiversity/Conservation were considered and set aside — the data would
theoretically support them, but they don't fit the "climate → health/livelihood" pain point this
platform is built around, and adding them would dilute that positioning rather than extend it.
