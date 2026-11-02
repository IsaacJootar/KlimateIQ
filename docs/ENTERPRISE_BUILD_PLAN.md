# Enterprise Pivot — Build Plan, Module by Module

Companion to [`docs/ENTERPRISE_PIVOT_PLAN.md`](ENTERPRISE_PIVOT_PLAN.md) (the why/what) — this is
the how, in the same format as [`docs/BUILD_PLAN.md`](BUILD_PLAN.md): one module at a time, build,
test, commit, push, mark done, move to the next.

Status tags: **Not started** · **In progress** · **Done** · **Deferred**. This file is the source
of truth for that status — update it at the end of each module, not just in chat.

**2026-11-02 — demo-driven re-scope.** With a demo presentation coming up and limited time before
it, the live focus narrows to the two sectors already fully supported end-to-end — **Insurance &
Reinsurance (#1)** and **Banking & Lending / agri-lending (#2)** — rather than building toward all
seven. Modules 6–10 (ESG disclosure, Telecom webhooks, Logistics corridors, the financial layer,
the validation dossier) are **deferred**, not dropped: nothing built so far is sector-specific, so
revisiting them later costs nothing extra now. See `ENTERPRISE_PIVOT_PLAN.md` §5 — both focus
sectors' MVP scope ("a ranked report... delivered as PDF and/or API" for lending; "a licensed
read-only feed... packaging + a contract" for insurance) is already covered by Modules 1–5: the
portfolio dashboard (Module 4) and the licensed API feed (Module 5) are sector-agnostic, driven
entirely by `PortfolioType`.

## Build order

| # | Module | Size | Status | Unlocks |
|---|---|---|---|---|
| 1 | Portfolio Type | M | **Done** | Everything below |
| 2 | Entity Modeling (portfolios of locations) | L | **Done** | Sectors #1, #2, #4 |
| 3 | Point / coordinate scoring | M–L | **Done** | Sectors #2, #4 (scoring an exact site, not just an LGA) |
| 4 | Enterprise dashboard rebuild | M | **Done** | The actual product surface |
| 5 | Parametric Insurance data-feed packaging (Sector #1) | S | **Done** | First sellable product |
| 6 | Compliance / ESG report generator (Sector #3) | M | Deferred (post-demo) | Second sellable product |
| 7 | Webhook delivery + site feed (Sector #4, Telecom) | M | Deferred (post-demo) | Third sellable product |
| 8 | Corridor / route scoring (Sector #5, Logistics) | M | Deferred (post-demo) | Fourth sellable product |
| 9 | "Bring your own model" financial layer | M | Deferred (post-demo) | The Jupiter-parity endpoint — see note below |
| 10 | Validation dossier (MRM-equivalent) | ongoing | Deferred (post-demo) | Credibility with regulated buyers |
| — | Sectors #6/#7 (Public Finance, Pharma) | — | Deliberately unscheduled | Revisit after 1–9 |

---

### Module 1 — Portfolio Type · Done

**What it is:** Formalize `Agency.type` from a free-text label into a real, constrained field
that drives default index access.

**Shipped:** `portfolio_type` added to `agencies` (new column, `type` left untouched) +
`agency_index_overrides` table · `App\Support\PortfolioType` enum (9 cases, default index bundle
per case) · `App\Support\AgencyIndexCoverage::resolve()` (type default + overrides, falls back to
"show everything" when neither is set) · admin override UI on `admin/agencies` (Portfolio Type
select + per-index toggle panel) · agency merge now carries overrides across instead of losing
them · registration requires a Portfolio Type when creating a new agency. 17 tests (41
assertions) across `PortfolioTypeTest`, `RegistrationTest`, `AgencyManagementTest`. Full suite:
433/433 green.

**Found and fixed along the way:**
- `OnboardingGateTest::test_registration_sends_a_new_user_into_the_wizard` — broke because of
  this module (didn't supply the now-required Portfolio Type); fixed as part of this commit.
- `ProbabilityThresholdAlertTest` (3 tests) — a pre-existing, unrelated bug (same hardcoded-date
  pattern as the `ForecastAlertTest`/`ReachLevelForecastTest` fix from earlier — confirmed via
  `git stash` to already exist on `origin/main` before this module). Fixed opportunistically
  while getting the full suite green; not caused by Module 1.

**Touches:**
- Migration: constrain `agencies.type` (or add `portfolio_type`) to an enum-backed column.
- New `App\Support\PortfolioType` (same pattern as `App\Support\CalibrationStatus`) — the 9
  categories from `ENTERPRISE_PIVOT_PLAN.md` §6b.
- New seeder/mapping (same shape as `SectorSeeder`) — Portfolio Type → default index bundle.
- `Admin\AgencyManagementController` — add per-agency module override (add/remove indices
  regardless of the type default).
- Registration/onboarding flow — Portfolio Type becomes step one.
- Tests: type→index-bundle mapping, override behaviour, idempotency.

**Depends on:** nothing. First module, builds on existing `Agency`/`Sector`/`ScoringIndex`
infrastructure only.

---

### Module 2 — Entity Modeling (portfolios of locations) · Done

**What it is:** The core new concept — a named list of locations belonging to an Agency, scored
and rolled up as one view. This is the single highest-leverage module; #1, #2, #4 all need it.

**Shipped:** `portfolios` + `portfolio_locations` tables (a location is either a seeded Region or
a raw coordinate — a DB check constraint enforces it's never neither) · `Portfolio` /
`PortfolioLocation` models · `App\Services\Portfolio\PortfolioRollupService` — pure aggregation
over the existing `App\Support\LatestScore` and `App\Support\AgencyIndexCoverage` readers, no new
scoring logic, ranks worst-to-best, sinks (never drops) locations with no score yet · full
CRUD UI at `/portfolios` (list, create, the rollup view, add/remove locations by LGA or raw
coordinate) scoped to the logged-in user's own agency. A coordinate-only location is honestly
marked "not scorable yet" rather than silently guessing a nearby LGA's score — that's Module 3.
31 tests (79 assertions) across `PortfolioRollupTest` and `PortfolioControllerTest`, including
real page renders (not just redirect/403 checks) to catch Blade errors a status-code assertion
alone would miss. Full suite: 450/450 green.

Not wired into the dashboard yet — that's Module 4.

**Touches:**
- New `Portfolio` model + migration (`agency_id`, name, type: point-list or corridor).
- New `PortfolioLocation` model + migration — each row either a `region_id` (existing LGA) or raw
  `latitude`/`longitude` (see Module 3), plus an `order` column for corridor sequencing (Module 8).
- Roll-up query service — given a `Portfolio`, return every location's current + forecast score
  per relevant index, ranked worst-to-best. Reuses `RegionScoringService` / `RegionForecastScoringService`
  as-is; this is a new aggregation layer, not new scoring logic.
- Portfolio definition UI — upload a list (CSV of LGA names, or coordinates) or pick from the
  existing region list.
- Tests: roll-up correctness, mixed LGA+coordinate portfolios, empty/partial-data handling
  (reuse the existing "skip missing data" pattern, don't invent a new one).

**Depends on:** Module 1 (a Portfolio belongs to an Agency with a Portfolio Type, which determines
which indices the roll-up shows by default).

---

### Module 3 — Point / coordinate scoring · Done

**What it is:** Score an arbitrary lat/long, not just a seeded `Region` row — needed for "this
specific bank branch" or "this specific tower," not just "this LGA."

**Investigation finding (scope shrank, as flagged it might):** `region_signals` and
`region_scores` both require a real, persisted `region_id` — not nullable, foreign-key
constrained. True point-level ingestion for an ad-hoc coordinate would mean creating synthetic
`Region` rows mixed into the same table that backs "774 real Nigerian LGAs" everywhere else in
the app (the public API, sector rollups, the README's own claims) — too invasive for what this
needs to do. Went with nearest-LGA snapping instead: reuses the entire existing scoring pipeline
with zero new ingestion, as long as the result is never presented as an exact point score.

**Shipped:** `App\Support\NearestRegion::find()` — plain-PHP Haversine distance over the 774
seeded regions (no PostGIS or DB-side geospatial function anywhere else in this codebase to
build on), returning the closest region and its distance, or `null` beyond
`MAX_USEFUL_DISTANCE_KM` (50 km, an engineering judgement call, documented as such) rather than
approximating against something irrelevant. `PortfolioRollupService` now scores a
coordinate-only location against its nearest match, marked `approximated => true` with the
distance shown — in the UI, "≈ nearest LGA: X (12.3 km away)" rather than a bare number. Beyond
the threshold, a location is honestly `scorable => false` with a clear reason, same discipline as
Module 2's original fail-safe. 10 tests (28 assertions) across `NearestRegionTest` and updated
`PortfolioRollupTest`/`PortfolioControllerTest` cases. Full suite: 455/455 green.

**Depends on:** Module 2 (a `PortfolioLocation` needs somewhere to put a coordinate before this
matters).

---

### Module 4 — Enterprise dashboard rebuild · Done

**What it is:** The actual screen change from `ENTERPRISE_PIVOT_PLAN.md` §6a — `dashboard` becomes
"your portfolio, ranked by risk" instead of "regions you follow."

**Shipped:** `DashboardController::index()` is now a dispatcher — a user whose agency has at least
one Portfolio gets `portfolioDashboard()` (new `dashboard-portfolio.blade.php`, built on Module
2/3's `PortfolioRollupService::rollup()`: red/amber/unscored counts, open alerts, and the
portfolio's locations ranked worst-to-best with the same approximated/not-scorable honesty as the
portfolio management page). Everyone else — any agency or individual user without a Portfolio yet
— falls through unchanged into `legacyDashboard()`, the exact pre-Module-4 logic moved verbatim
into its own method; nothing about it was touched, per §7's "steps back to a secondary path, not
deleted" framing. 5 new tests (`DashboardPortfolioTest`: portfolio users see the new view,
agency-less and portfolio-less users see the legacy view unchanged, red/amber/unscored counts are
correct, the most recently created portfolio is the one shown) plus the pre-existing
`DashboardDefaultIndexTest` re-run unmodified to confirm the legacy path's regression coverage
still holds. Full suite: 460/460 green.

**Depends on:** Modules 1–2.

---

### Module 5 — Parametric Insurance data-feed packaging (Sector #1) · Done

**What it is:** The first sellable enterprise product — mostly packaging, per
`ENTERPRISE_PIVOT_PLAN.md` §5.

**Shipped:** `GET /api/v1/portfolios/{id}/feed` — a token scoped to exactly one Portfolio via a
single Sanctum ability (`Portfolio::feedAbility()`), issued and revoked by the client themselves
from their own portfolio page's new "API access" panel (not `/admin/api-tokens`, which stays the
platform-wide tool). No new scoring — the feed is `PortfolioRollupService`'s existing output,
reshaped into a stable JSON contract. `docs/INGESTION_GUIDE.md` documents the endpoint;
`docs/PARAMETRIC_INSURANCE_DATA_LICENSE_TEMPLATE.md` is the business-side licensing agreement
template a real contract would be drafted from. **Found along the way:** Sanctum's `auth:sanctum`
guard checks the web session before a bearer token, and a session hit gets a full-access
`TransientToken` — harmless for the platform's other read endpoints, but it would have let a
logged-in agency user bypass this feed's per-portfolio scoping entirely by just browsing to
another agency's URL. Fixed by having the controller explicitly reject session auth and require a
real scoped token. 13 new tests (`PortfolioFeedApiTest`, `PortfolioApiTokenTest`). Full suite:
474/474 green.

**Depends on:** Modules 1–2. The smallest, fastest module after the foundation — deliberately
sequenced right after Entity Modeling for a fast first "real product" milestone.

**Also shipped alongside this module (demo-critical, not its own numbered module):** the main nav
(`resources/views/layouts/navigation.blade.php`) had never been touched by Modules 1–4 — it still
showed the legacy "Regions · Workspace · Saved Views" links and a "Climate Intelligence" tagline,
with no "Portfolios" link at all, for every user including enterprise ones. Fixed: an agency with
a Portfolio Type now gets a dedicated nav (Dashboard · Portfolios · Alerts · Reports · Thresholds,
no legacy sector-switcher), and the tagline is now "Climate Risk Intelligence." **Found along the
way:** Blade's `@php(expr)` parenthesized shorthand mis-parses an expression that itself contains
parentheses (e.g. `Auth::user()->agency?->portfolio_type`) — it matches only up to the first
closing paren, leaving the `<?php` tag unclosed and corrupting the rest of the compiled view. Not
specific to this codebase; a real Blade compiler limitation. Fixed by using the block
`@php ... @endphp` form instead.

---

### Module 6 — Compliance / ESG report generator (Sector #3)

**What it is:** A disclosure-style PDF built from a client's Portfolio.

**Touches:**
- New report type on the existing `ReportRequestController` / `reports` feature.
- New HTML template, rendered through the same Puppeteer/Chrome pipeline already proven this
  session (the Year One Plan and New Markets PDFs).

**Depends on:** Module 2. Independent of Modules 3–5 — can run in parallel.

---

### Module 7 — Webhook delivery + site feed (Sector #4, Telecom)

**What it is:** Repoint alerting to push to a client's own systems, not just in-app/email/SMS.

**Touches:**
- New notification channel (alongside the existing in-app/email/SMS ones) that POSTs to a
  client-configured webhook URL.
- `ThresholdEvaluationService` — no scoring change, just an additional delivery target.
- Portfolio-scoped site feed, reusing Module 2's roll-up filtered to one Agency's sites.

**Depends on:** Modules 1–2, and Module 3 if sites are coordinates rather than LGAs.

---

### Module 8 — Corridor / route scoring (Sector #5, Logistics)

**What it is:** Score a route, not just a list — "worst point along this road corridor this
week," leaning on the forecast lane specifically.

**Touches:**
- `PortfolioLocation.order` (from Module 2) becomes meaningful — a corridor-type Portfolio reads
  its locations in sequence.
- Roll-up query gains a "worst point on the route, forecast-aware" mode alongside the flat
  ranking Module 2 already has.

**Depends on:** Module 2, benefits from Module 3 (real road coordinates, not just LGA centroids).

---

### Module 9 — "Bring your own model" financial layer

**What it is:** The honest version of Jupiter's MetricEngine/financial-translation layer, agreed
in conversation: KlimateIQ doesn't invent a credit-risk model (no real default data to calibrate
one against) — instead, a client enters their own loss assumption (e.g. "we estimate X% impact
per loan in an LGA once Flood Risk crosses Y"), and the platform applies that to its own
real-time scores. KlimateIQ supplies the trusted risk number; the client supplies the financial
judgment — never the other way around.

**Touches:**
- A simple per-Portfolio (or per-Agency) coefficient/threshold config — reuses the existing
  `ThresholdConfig` shape conceptually, extended to also carry a numeric "impact per point"
  value.
- A clearly-labelled "estimated exposure, based on your own assumptions" output — never presented
  as a KlimateIQ-asserted financial fact. The label matters as much as the number.

**Why this is on the list even though it's explicitly the limited version:** this is the module
that makes the roadmap *look* like it reaches Jupiter-parity even while most of it is still being
built — showing a funder or prospect "here's the endpoint we're building toward, and here's a
real, honest, working version of it today" is worth having early, not last.

**Depends on:** Module 2.

---

### Module 10 — Validation dossier (MRM-equivalent), ongoing

**What it is:** Not a sprint item — the existing outcome-validation milestone (already the
platform's top-flagged gap before this pivot), formalized into the kind of documentation a bank's
model-risk team expects: methodology writeup, backtest results, `calibration_status` breakdown
per index.

**Touches:** `docs/decisions/`, `docs/MODEL.md`, plus whatever the actual backtest produces.
Runs in parallel with everything else, not gated on it.

---

## How we'll work through this

One module at a time: build it, test it, commit, push (and deploy, for anything that touches the
live app), come back here and flip its status to **Done**, then move to the next. This file gets
updated at the end of every module — if it says Done, it shipped and was verified the same way
every other feature in this codebase has been (tests green, Pint clean, confirmed on production
where relevant).

**Suggested starting point: Module 1.** Say "go" and I'll start there.
