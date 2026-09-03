# 0007 — Reach-level riverine forecast

- **Date:** 2026-09
- **Status:** accepted (Niger–Benue pilot, then extended to 8 rivers)

## Context

The Riverine Flood Forecast index sampled GloFAS at one point — the LGA centroid — and produced
one number per LGA. For a confluence LGA that is wrong: Lokoja sits where the Niger and the
Benue meet, and the centroid reading only ever sees one arm. A Benue flood and a Niger flood are
different operational problems (different banks, different upstream warning, different
communities). "Lokoja: 74" can't carry that.

## Decision

Score the index **per named river reach** for the LGAs on the Niger and the Benue.

- **`database/seeders/data/nigeria_river_reaches.json`** — a sample point per LGA per river.
  Built at implementation time from OpenStreetMap `waterway=river` relation geometry (Niger,
  Benue*, Kaduna, Katsina-Ala, Donga, Gongola, Yobe/Komadugu, Cross), intersected with
  geoBoundaries NGA ADM2 (CC-BY 4.0), the representative on-channel point taken nearest each
  LGA seat, then **snapped to the GloFAS modelled channel** — GloFAS runs on a ~5 km network, so
  each point is moved to the highest-discharge cell in a ±0.07–0.1° grid, and dropped if the
  channel there carries less than a per-river floor (~100–400 m³/s). **87 reaches, 79 LGAs**;
  Lokoja, Bassa and other confluence/valley LGAs carry two rivers. (*Benue: a waypoint polyline
  anchored on the published confluence 7.7533,6.7567 and Makurdi gauge 7.7306,8.5361 — OSM's
  Benue tagging is fragmentary.)
- **Sokoto–Rima was excluded**: GloFAS models it at ~20 m³/s (heavily dammed — Bakolori,
  Goronyo — so the natural-flow model under-represents the regulated river). Revisit if a
  reservoir-aware discharge source becomes available.
- A `reach` dimension on `region_forecast_signals` and `scoring_calibration_parameters`
  (`'centroid'` / `null` = the pre-existing single-point behaviour). `river_reaches` table.
- `calibrate:river-discharge`, the forecast + ensemble ingestion, and `ForecastScoringStrategy`
  all iterate reaches. The index score is the **worst** reach; `breakdown['reaches']` names them
  all; the region page shows a "By river" panel and says which river is driving.
- An LGA with no `river_reaches` rows is unchanged.

## Alternatives considered

- **Keep the centroid.** One number, no curation. Rejected — it's the failure the user raised.
- **Automatic ring-sampling** — sample GloFAS on a ring around every river LGA, keep the distinct
  reaches. No curation, instant nationwide coverage. Rejected for the pilot: fuzzier (no river
  names), ~6× the API and calibration load, and it still needs a snap-to-channel step. It's the
  natural way to *expand* once the pattern is proven.
- **A first-class river/reach entity** (users follow "the Benue" across every LGA it threatens).
  The right long-term model — how flood agencies think — but a tier of its own. The per-reach
  data this pilot produces is exactly what it would build on.
- **Bundle LGA polygons + PostGIS for runtime reach discovery.** No GIS tooling on the box, and
  PostGIS is a bigger infra change. The polygons were used at *build* time only; the output is
  committed JSON.

## Revisit when

- Demand appears for following a river across LGAs → the river-entity model (alternative 3).
- LGA polygons + PostGIS land → automatic reach discovery, nationwide.
- More rivers are wanted (Sokoto–Rima with a dam-aware source, Anambra, Imo, Kwa Ibo, Ogun,
  Osun, delta distributaries) → add the OSM relation, re-run the intersect + snap step,
  calibrate.
