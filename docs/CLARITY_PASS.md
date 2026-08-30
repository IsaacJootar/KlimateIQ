# The Clarity Pass

Make every screen speak the language of the person reading it — extension officer, health
officer, emergency planner — and name real crops, clinics and places, **before** T4 forecast
ingestion starts.

Companion to [`docs/BUILD_PLAN.md`](BUILD_PLAN.md) and [`docs/ROADMAP_SECTORS.md`](ROADMAP_SECTORS.md).
Those cover the data engine; this one is the presentation layer. **The engine — scoring,
calibration, ingestion — is untouched by this pass.**

Rendered plan (with the worked example and before/after): the "KlimateIQ Clarity Pass" artifact.
Status: **approved 2026-08-30, all 12 items in scope, then T4.**

### Progress

| item | state |
|---|---|
| A1 signal names | ✅ shipped |
| A2 index "what it measures" | ✅ shipped |
| B1 sector in the header | ✅ shipped |
| C region-page rebuild (+ A3, A4) | next |
| B3, D2, D1, D3, B2, B4, E1, E2 | pending |

## Why

The engine is strong (12 indices, 16 free sources, every score traceable). The screens haven't
kept pace. Four gaps, all presentation:

1. **Raw signal codes** shown instead of names (`EVAPOTRANSPIRATION`, not "Evaporation demand") —
   `regions/show.blade.php`, `ScoreDiagnosis`, alerts, activity feed. The names are already in
   `signal_types.name`.
2. **Sectors vanish after setup.** You configure by sector, then every viewing screen is a flat
   row of index tabs with no "you are in Agriculture" frame.
3. **The flow isn't legible.** reading → score → meaning → action is all present, as a table plus
   two prose boxes, not one story read top to bottom.
4. **Recommendations are generic.** No crop, no clinic, no market named.

## Principles

- **Name things the way the reader does.** A person watches *rainfall* and *dry air*, never
  `RAINFALL` and `HUMIDITY`.
- **You are always somewhere** — a sector, a place, a week. The screen says which, at the top.
- **Every number ends in "so what do I do."**
- **Name real things — even as samples.** Which crops, at which growth stage. Which schools and
  health centres in that LGA. Cite source + date; never imply we verified they're open today.

## Decisions (locked 2026-08-30)

| # | Decision | Note |
|---|---|---|
| 1 | Sector reframe → **full sector-home screen** (B3) | header (B1) + grouped tabs (B2) still ship |
| 2 | Facilities data → **GRID3 static import** | **provision for later live source** — see below |
| 3 | Crop calendar → **by agro-ecological zone (~6)** | **schema stays open to per-state** — see below |
| 4 | Recommendation names → **3 inline samples + "see all" link** | |
| 5 | Scope → **all of A–E, then T4** | every item ships before forecast ingestion |

### Decision 2 — facilities data, and the swap path

GRID3 Nigeria (open, CC-BY, LGA-tagged bulk downloads) is imported into a local `facilities`
table and is the only source for now. To keep a later live source (e.g. `healthsites.io`) a
drop-in:

- `App\Services\Facilities\FacilityProvider` interface — `nearbyForRegion(Region, array $types, int $limit): Collection`.
- `Grid3StaticProvider` reads the local `facilities` table. First and only implementation.
- `HealthsitesApiProvider` (future) — new class, one line in `config/facilities.php`, nothing
  downstream changes. The recommendation builder and the "see all" page only ever talk to the
  interface.

### Decision 3 — crop calendar granularity, and the extension path

`crop_calendar` rows key on `(scope, scope_key, crop, ...)` where `scope` is `zone` now and may
be `state` later. Lookup prefers a `state` row over a `zone` row for the same crop, so a
per-state override is data, not a migration. v1 seeds ~6 agro-ecological zones × ~6 principal
crops from public FEWS NET / FAO crop calendars, each with the months the crop is in a
water-sensitive growth stage. Nigerian states map to zones via a static table.

## Where AI fits (and where it doesn't)

The OpenAI API is configured and live (`RegionScoreSummaryService`, the optional "AI Summary"
on the region page). It stays inside one boundary through this whole pass:

- **AI writes language, never facts.** It turns verified structured data — the score breakdown,
  the crop-calendar row, the facility list — into fluent, sector-appropriate prose. The
  "This week in {LGA}" summary (Part C step 1) and richer recommendation phrasing are good uses.
- **Facts stay deterministic and checkable.** Scores come from the engine. Named facilities come
  from GRID3. Crop stages come from published calendars. `ScoreDiagnosis` stays a no-AI function.
  The AI is never asked "which clinics are near here" — the first hallucinated clinic name would
  cost more trust than every summary ever earned.
- **`RegionScoreSummaryService`'s existing rule holds:** *"may only restate what is already in
  the breakdown."* Any new AI use states the same boundary in its prompt and is clearly labelled
  in the UI as the generated layer, sitting below the deterministic one.
- Genuinely dynamic external facts with no dataset (rare) may use a real API or AI+web, cited and
  kept visually separate from the core.

## Part A — Language

| id | item | size | what |
|---|---|---|---|
| A1 | Signal names, not codes | S | Add `name` to the score `breakdown` payload; use it in the breakdown table, `ScoreDiagnosis`, alerts, activity feed. Rewrite a few `signal_types.name` values to be friendlier. |
| A2 | "What this index measures" surfaced | S | Rewrite each `indices.description` in one plain sentence + a "who it's for" clause; show under the index name on every index view and as a tab tooltip. |
| A3 | Plain-language readings + seasonal context | M | New `App\Support\SignalReading` helper + a `signal_context` reference table (normal range per zone + month). Breakdown reading bar gets a "typical" marker. |
| A4 | Rewrite the "what this means" paragraph | S | `ScoreDiagnosis` → 2–3 short sentences: band in plain terms, biggest driver in plain terms, whether the others agree. Still deterministic, still breakdown-only. |

## Part B — Sector as a frame

| id | item | size | what |
|---|---|---|---|
| B1 | Show the sector you're in | S | Dashboard + Regions headers: "Agriculture & Food Security · 3 indices · 14 LGAs". |
| B2 | Group the index tabs by sector | M | `IndexCoverage` returns tabs grouped; the three tab partials render sector labels. |
| B3 | **Sector home page** | M | New `SectorController` + view + route. Every index in the sector as a status card (band, 2-week trend, one plain line), one headline ("4 of your 14 LGAs need attention"), click through to the full index view. |
| B4 | Sector switcher in the top nav | M | Slack-style. `current_sector_id` on the dashboard-preference row; B1–B3 and the dashboard scope to it. Only appears when the user follows >1 sector. |

## Part C — The flow, made visible (centrepiece)

Rebuild `resources/views/regions/show.blade.php` (single-index region page) to read top to bottom
as one story. Same data, resequenced and rewritten. A3 + A4 land here. Size **L**.

1. **This week in {LGA}** — plain-language summary of what the satellites saw.
2. **The score** — big number, band, 2-week direction.
3. **What's pushing it up** — the drivers, named, each with a one-line "what this signal means"
   and its share of the score. A line on whether they agree.
4. **What it means** — the `ScoreDiagnosis` paragraph (A4).
5. **Where it's heading** — the trend, and a plain projection if the pattern holds.
6. **What to do** — the recommendation, now concrete (Part D). AI Summary stays as the optional
   deeper layer below step 6.

## Part D — Name real things

| id | item | size | what |
|---|---|---|---|
| D1 | `facilities` reference table | M | GRID3 import command + `FacilityProvider` interface (Decision 2). Schools, PHCs, markets, major water points: name, type, coords, LGA. Seed the ~8 hand-curated states first. |
| D2 | `crop_calendar` reference | S | `(scope, scope_key)` schema (Decision 3). ~6 zones × ~6 crops + a state→zone map. Seeder only, no API. |
| D3 | Recommendation enrichment, per sector | M | `IndexActionRecommendation::textFor` gains a concrete preface built by a `RecommendationContext` helper, keyed by the index's sector: |

- **Agriculture** — crops exposed now + growth stage (D2); ADP / extension offices & fadama
  sites in the LGA (D1).
- **Public Health / Air** — schools and PHCs in the LGA to notify (D1).
- **Emergency Response** — shelter-capable sites and main roads through the LGA (D1).
- **Water & Sanitation** — water points serving the LGA; downstream settlements (D1).

3 names inline + a "see all facilities in this LGA" link (Decision 4).

## Part E — Small wins alongside

| id | item | size | what |
|---|---|---|---|
| E1 | Dry-season water-availability view | M | JRC surface water + rainfall, both already ingested — no forecasting. Ships as a config-only index in the Water & Sanitation sector via `AdditionalIndicesSeeder`. |
| E2 | Onboarding copy pass | S | Under each sector in the wizard, one line of "you'll get …". Reflects the finished vocabulary. |

## Build sequence

1. A1 — signal names (every later screen inherits it)
2. A2 — index "what it measures"
3. B1 — sector in the header
4. **C** — region-page rebuild (+ A3, A4)
5. B3 — sector home
6. D2 — crop calendar
7. D1 — facilities table (parallelisable with 4–5)
8. D3 — recommendation enrichment (needs D1 + D2 + C)
9. B2 — grouped tabs
10. B4 — sector switcher
11. E1 — dry-season water view (independent)
12. E2 — onboarding copy (last; reflects finished vocabulary)

Then T4.

## Out of scope

- T4 forecast ingestion (next module, straight after).
- Probabilistic alerting (needs T4).
- New indices except E1 (config-only).
- Mobile app / SMS channel redesign — the alert *copy* improves via A1/A4; the channels don't.
- Verifying facility operational status — cite source + date, never claim a named clinic is open.
- Any scoring / calibration change.
