# The Enterprise Pivot — Plan & Roadmap (draft, for review)

**Status: proposed, not approved.** Nothing here is built or committed to the live roadmap docs
yet. This is the document to mark up, cut, reorder, or reject before anything changes.

## 1. Why

Two real, specific problems with the current business model, not a reaction to any one
competitor:

1. **Individual farmers can't pay for this**, and most don't have the technical fluency to read
   a climate-signal dashboard even if they could. A free tier aimed at them drives usage, not
   revenue.
2. **Nigerian government agencies are slow, reluctant payers.** The Agency tier was already
   priced low in the Year One Plan specifically because of this — a workaround, not a fix.

The fix isn't new data or new engineering — it's a different buyer. [Jupiter Intelligence](https://www.jupiterintel.com/)
proves the model: one scoring engine, sold as several enterprise products to banks, insurers,
and large operators, demo-led and contract-priced (publicly reported $25K–multi-million per
deal), never sold as a self-serve dashboard to an individual.

## 2. What changes, and what doesn't

**Stays exactly as it is — this is the asset, not the problem:**
- Every signal ingestion service, the scoring engine (`WeightedFormulaScoringStrategy`,
  `ForecastScoringStrategy`, `EnsembleForecastScoringService`), all 16 indices (yes, including
  Malaria and Meningitis — see §4 on why that's a strength, not baggage), the forecast lane, the
  calibration-honesty system, the existing read API.
- The threshold/alerting engine (`ThresholdEvaluationService`) — it doesn't disappear, it gets
  **repointed**: instead of notifying a human who logged in to look at a dashboard, it fires a
  webhook or API event an enterprise client's own system consumes. The mechanism Jupiter doesn't
  even have in this form is one KlimateIQ already built.

**Changes — this is the actual pivot:**
- **Who the customer is**: from an individual field officer or a state agency, to an enterprise
  buyer — an insurer's underwriting desk, a bank's credit risk team, a telecom's network-ops
  team, a distributor's planning team.
- **How it's sold**: from self-serve sign-up and a public dashboard, to demo-led, contract-priced
  enterprise products — Jupiter's model exactly.
- **What "the product" is**: from "log in and watch your region" to a licensed data feed, a
  portfolio report, or a compliance document — something that plugs into a buyer's existing
  workflow instead of asking them to adopt a new one.
- **The dashboard itself is not going away — correction from an earlier draft of this plan.**
  Jupiter's own stated delivery model is "a SaaS application, enterprise API, and tailored
  analytics support" — the SaaS application *is* a dashboard. The actual distinction is who it's
  for and what's in it, not whether it exists:
  - **Who gets a login**: provisioned per paying organization (an `Agency` record, which already
    exists in this codebase), not open public self-signup.
  - **What's inside it**: a client's own portfolio — their loan book, their sites, their
    facilities — rolled up in one place, with reports generated inside it, not "watch one LGA."
  - **Pricing**: enterprise-tiered and contract-negotiated, extending the existing Agency tier
    rather than the public Free/Professional self-serve price list.

  The current `Agency` model (`app/Models/Agency.php`) — users belong to one, an agency has a
  `type`, and oversight visibility already rides on the organization rather than the individual
  user (`User::hasFederalOversight()`) — is exactly the right foundation for this. It's a
  tenant concept that's half-built; this pivot makes it the product's spine instead of a side
  feature.

## 3. New positioning

> KlimateIQ is the underlying risk engine for African climate and health exposure — licensed to
> the institutions whose balance sheets, portfolios, and operations are exposed to it, not sold
> as a dashboard to the people living with the weather.

ICP shifts from "a state malaria programme officer" to "an underwriting, credit-risk, compliance,
or network-ops team at an enterprise with Nigerian/African exposure."

## 4. Jupiter's actual intelligence capabilities — scoped to Nigeria, then Africa

Product names aren't the substance — this is the deeper pass, pulled directly from Jupiter's own
product/capability pages ([jupiterintel.com/products](https://www.jupiterintel.com/products),
[jupiterintel.com](https://www.jupiterintel.com/)) and independent reporting, broken into the
actual capabilities underneath, each one honestly scored: **Have it**, **Buildable now** (existing
data, real but scoped engineering), **Needs licensed data** (not an engineering problem, a
budget/licensing one), **Needs a financial-modeling partner** (not a climate-data problem at all),
or **Not relevant to Nigeria** (a real capability for Jupiter's market, a wrong investment for
ours).

### A. Hazard coverage

Jupiter models **nine perils** globally — public sources agree on flood, heat, drought, and
tropical cyclones, with wind, hail, cold, wildfire and subsidence/precipitation reported with
some inconsistency across sources (Jupiter doesn't publish a single authoritative list).

**Status: mostly Have it, by design, not by accident.** Nigeria's actual hazard profile is flood,
drought, extreme heat, bush-fire, and harmattan dust — all five are **already live**. Hail and
cold aren't meaningful Nigerian hazards; Nigeria's Atlantic coast isn't in a tropical-cyclone
belt. These aren't gaps, they're perils that don't apply here. **Subsidence is the one real miss**
— genuinely relevant to Lagos and the Niger Delta, and already named in `docs/ROADMAP_SECTORS.md`
as Coastal Resilience, paused on sourcing coastal elevation and tide data (needs licensed data,
same blocker as before this pivot).

**What Jupiter doesn't have that we do: health.** Malaria, Meningitis, Waterborne Disease,
Respiratory Risk — nine, soon ten, indices with zero equivalent in Jupiter's product. For an
insurer or distributor whose exposure is health-linked (crop insurers tracking post-flood disease
outbreaks, pharma distributors, agri-lenders worried about labour-force health), this is a real
capability Jupiter cannot sell them. This is "even more," not a gap to close.

### B. Granularity — "any point on Earth"

Jupiter claims 22.3 billion locations, any coordinate, with reported "22,000+ metrics per
location."

**Status: partially Have it, partially Buildable now.** LGA-level (774 districts) is coarser than
"any point" but finer than most national systems. For the enterprise use cases in §5 — a specific
bank branch, a specific telecom tower, a specific insured property — **scoring an arbitrary
coordinate, not just an LGA centroid, is a real and currently-missing capability.** This is a
direct refinement to the Entity Modeling build in §6: it needs to accept point coordinates, not
just LGA codes. The "22,000 metrics" figure itself is not worth chasing — Jupiter's own
third-party reviews note the detailed methodology behind that number isn't publicly disclosed.
Our smaller, fully-traceable breakdown per index (every signal, every weight, visible) is more
explainable than a black box of 22,000 numbers, not less powerful.

### C. Scenario & stress testing to 2100

Jupiter's single most "powerful" capability for long-horizon planning: IPCC-aligned scenarios on
CMIP6 models, 5-year increments to 2100, feeding directly into credit and loss metrics.

**Status: Needs licensed data — the single biggest real gap.** This is exactly T6 (Climate
Outlook) in `docs/BUILD_PLAN.md`, already identified and paused: the free CMIP6 source has no
multi-scenario selector. Jupiter's scenario modeling is backed by a commercial climate-data
licence most likely in six figures a year. Worth naming plainly rather than quietly working
around: this is the one Jupiter capability that isn't a build problem, it's a budget problem, and
should be treated as a funded milestone, not an MVP sprint item.

### D. Financial translation layer

Expected loss, avoided loss, adaptation ROI, probability-of-default and loss-given-default
inputs, cashflow impact — turning a hazard score into numbers a credit committee already uses.

**Status: Needs a financial-modeling partner.** This is the hardest item on the whole list, and
it's not a climate-data gap — it needs actuarial/credit-risk modeling expertise KlimateIQ doesn't
currently have in-house. Realistic path is a partnership (an actuarial firm, a reinsurer's own
analytics team) rather than solo engineering. Flagged here as a stretch goal precisely so it
doesn't quietly become a blocker for Phase 1–3, which don't need it.

### E. Portfolio & entity roll-ups

Rolling individual asset/location scores up into one view across a client's book — Jupiter's
Entity Modeling.

**Status: Buildable now.** This is Phase 1 in §6, unchanged — the highest-leverage build on the
whole list, using data that already exists.

### F. Linear-asset / corridor analysis

Jupiter explicitly supports scoring a route — a pipeline, a railway — not just a point or an
administrative area.

**Status: Buildable now, and a refinement worth calling out.** This is exactly what Sector #5
(Logistics & Supply-Chain Routing, §5) needs: scoring a road corridor, not a single LGA. Add this
explicitly to the Entity Modeling spec in §6 — it needs to accept a *route* (an ordered list of
LGAs/coordinates), not just a flat list.

### G. Disclosure / compliance reporting

**Status: Buildable now.** Phase 3 in §6, unchanged — the HTML→PDF pipeline already exists and
works (it produced the Year One Plan and New Markets documents this session); this is a report
template, not new infrastructure.

### H. Transparency & model-risk validation

Jupiter markets this hard — "MRM-approved by Tier 1 banks," full documentation, validation
against observed data — because a bank's model-risk team won't adopt a black box.

**Status: Have the foundation, needs finishing.** This is the one place KlimateIQ already has real
infrastructure Jupiter has to market its way around: every weight and calibration bound already
carries a structured `calibration_status` (placeholder / admin-tuned / reference / reference
derived / outcome-validated), enforced by an actual test (`CalibrationHonestyTest`) so nothing can
silently claim more rigour than it has. What's missing is volume — most bounds are still
`placeholder`, and the outcome-validation study (already the platform's top-flagged milestone,
independent of this pivot) is what moves them to `outcome_validated`. This pivot makes that study
more urgent, not less — a bank's model-risk team will ask for exactly this, explicitly, not as a
nice-to-have.

### I. Delivery model

SaaS application + enterprise API + tailored analytics support.

**Status: Have the SaaS app and the API; the third is a staffing decision, not a build.** The
dashboard is §6a/§6b. The API already exists (`/api/v1/*`) and needs enterprise auth/SLA
packaging, not new engineering. "Tailored analytics support" is a services layer — someone on the
team producing custom reports/onboarding for a given client — a go-to-market decision, not code.

### Summary — what this means for Africa beyond Nigeria

Every "Buildable now" and "Have it" item above works anywhere by latitude/longitude with zero code
change, the same way the rest of the platform already does (`docs/BUILD_PLAN.md` T9). What doesn't
travel automatically is calibration — a second country's bounds, population data, and admin
boundaries have to be sourced before its scores mean anything, the same caution that already
applies today. The honest sequencing is Nigeria-first on all of §5/§6, pan-African only after
Phase 1–3 are proven here.

## 5. The seven sectors, scoped as MVP-able products

Same seven from the earlier working note, now with an honest "smallest real version" for each.

| # | Sector | MVP scope — the smallest real, sellable version |
|---|---|---|
| 1 | **Parametric Insurance & Reinsurance** | A licensed read-only feed of Flood Risk / Riverine Flood Forecast / Drought Risk for a named set of LGAs, with a signed data-licensing agreement an insurer's payout contract can reference. No new scoring — packaging + a contract. |
| 2 | **Bank & Agri-Lender Portfolio Risk** | The **Entity Modeling** build from §4: client supplies a list of LGAs (their loan book), gets back a ranked report (current + forecast exposure per LGA) on a schedule (weekly/monthly), delivered as a PDF and/or API. |
| 3 | **Climate-Risk Disclosure (ESG/TCFD)** | The **Compliance Hub** build: a formal report template (reusing the existing HTML→PDF pipeline) that turns a client's named facility locations into a disclosure-ready document. |
| 4 | **Telecom & Infrastructure Resilience** | The **Site Intelligence** build: client supplies site coordinates, gets a filtered risk feed/report for just those sites, plus the existing alert engine repointed to notify their ops system instead of a dashboard. |
| 5 | **Logistics & Supply-Chain Routing** | A Riverine Flood Forecast feed scoped to named road corridors/LGAs, forecast-only (this one leans hardest on the forecast lane, which is the newest and least battle-tested part of the engine). |
| 6 | **Public Finance / Govt Budget Planning** | Lowest priority — still has the slow-payer problem from §1 at the state level, though continental/federal bodies (already flagged as a real ICP in the Year One Plan) are a different, viable audience. Revisit after 1–5 prove the model. |
| 7 | **Pharmaceutical & Health Supply Distribution** | Lowest priority, by its own admission the most speculative — needs a distributor willing to act on a forecast rather than a confirmed case count. Worth a conversation before engineering. |

## 6a. UI & workflow — what actually changes on screen

The core shift: today's workflow is *"I log in → I tell the platform what I care about → I
watch."* The enterprise workflow is *"my portfolio was set up when we signed → I log in → it's
already there."* Configuration moves from something every user does, to something done once per
client at provisioning.

| Today | Becomes |
|---|---|
| **Register** (open self-signup) | Not the front door. An `Agency` account is **provisioned** when a contract is signed (the existing `admin/agencies` screen), and the client's team is invited in — not self-registered. |
| **Onboarding wizard** ("pick the sectors you follow") | Repurposed entirely into **defining a portfolio** — upload/select the LGAs or sites that matter to this client (their loan book, their towers, their facilities). This *is* the Entity Modeling build (§4, §6) — the single most important new screen in this pivot. |
| **Coverage page** ("refine your indices/regions") | Mostly disappears as a user chore. Which indices apply comes from the client's **contract/industry template** set at provisioning, not a settings page every user visits. |
| **Dashboard** | Rebuilt around **"your portfolio, ranked by risk,"** not "regions you follow." This is the actual product now. |
| **Region drill-down page** (`regions/{region}`) | Stays almost exactly as-is — reached by clicking a row in the portfolio instead of browsing all 774 LGAs. |
| **Thresholds page** | Stays, but ships with sensible defaults from the industry template rather than a blank form a new client must fill in to get value. Still editable. |
| **Alerts page** | Stays; the bigger change is delivery — also fires a webhook/API event into the client's own systems, not just an in-app list. |
| **Reports** | Becomes central — **scheduled** portfolio/compliance reports, not an occasional manual pull. |
| **`regions/index`** (browse all 774 LGAs) | Likely survives as the public credibility/demo layer (§7), not how a paying client works day to day. |
| **`admin/agencies`** | Becomes the real provisioning tool — already exists (`AgencyManagementController`), needs the portfolio-definition step added. |

Nothing about *configuring your own monitoring* survives as the primary flow — it's replaced by
*onboarding a portfolio once*, with dashboard, reports, and alerts following from it
automatically.

## 6b. Signup & onboarding — Portfolio Type, not a pricing tier

Resolves the first part of §8.1 (how onboarding actually works) and replaces the old tier-gated
model (§2, §7) with something structured around *what kind of company this is*, not *how much
they paid*.

**`Agency.type` already exists** — today it's a cosmetic label on an admin table (`State Ministry
of Health`, `Federal Public Health Agency`, `NGO / Development Partner`, etc.), driving nothing.
This formalizes it into a real mechanism:

1. **Step 1 of onboarding — pick a Portfolio Type** (a constrained list, replacing free-text
   `Agency.type`): Insurance & Reinsurance · Banking & Lending · Climate-Risk Disclosure/ESG ·
   Telecom & Infrastructure · Logistics & Supply Chain · Public Finance · Pharma & Health Supply
   Distribution — plus the two legacy categories, Public Health and Agriculture, so nothing
   already built loses its home.
2. **Each Portfolio Type maps to a default index bundle** — the same mechanism `SectorSeeder`
   already uses to map sectors to indices, applied at the `Agency` level instead of per-user.
   Picking "Insurance & Reinsurance" pre-selects Flood Risk, Riverine Flood Forecast, and
   Drought Risk — no manual index-picking screen for the end user.
3. **Step 2 of onboarding — define the portfolio itself**: the list of LGAs/sites/facilities that
   matter to this company (the Entity Modeling input, §4/§6).
4. **Admin retains override power** via `admin/agencies` — add or remove specific modules for a
   given company regardless of its Portfolio Type default. Covers mixed-purpose clients (part
   insurer, part lender) and bespoke contracts.
5. **Feature access is driven by Portfolio Type, not a payment tier.** Pricing (contract value,
   seats, SLA) becomes a separate axis layered on top, not the thing that unlocks which indices a
   company can see.

## 6. Suggested MVP sequence

Matching the project's own established principle (cheapest, highest-trust wins first —
see `docs/BUILD_PLAN.md`'s own build ladder):

1. **Phase 0 — Reposition, no code.** This document approved and folded into
   `docs/ROADMAP_SECTORS.md` / `docs/BUILD_PLAN.md`; ICP and pricing model updated in the
   business docs; marketing site copy updated to speak to enterprise buyers. *Size: S.*
2. **Phase 1 — Entity Modeling (the portfolio concept).** This single build unlocks sectors
   #2 and #4 at once (a bank's loan-book list and a telecom's site list are the same underlying
   "named list of locations, rolled into one report" feature) and is most of what #1 needs too.
   This is the highest-leverage single build on the list. *Size: M.*
3. **Phase 2 — Sector #1 (Parametric Insurance) end to end.** Mostly packaging/contract work on
   top of Phase 1 — the fastest path to a real, sellable, demo-ready product. *Size: S–M.*
4. **Phase 3 — Sector #3 (Compliance/ESG reports).** Reuses the existing PDF pipeline directly;
   independent of Phase 1, can run in parallel with it. *Size: S–M.*
5. **Phase 4 — Sector #4 (Telecom/Infrastructure) fully wired**, including the alert engine
   repointed to webhooks. *Size: M.*
6. **Phase 5 — Sector #5 (Logistics)**, once the forecast lane has more runway behind it.
   *Size: M.*
7. **Ongoing, not phase-gated — the MRM Accelerator-equivalent (§4) validation dossier.** This
   was already the platform's single biggest credibility gate before this pivot; it matters more
   now, because enterprise risk/compliance teams will ask for it explicitly, the way Jupiter's own
   marketing leads with "MRM-approved by Tier 1 banks."

Sectors #6 and #7 are deliberately not phase-scheduled — revisit once 1–5 are live and there's a
real conversation with a buyer in each, not before.

## 7. What gets deprioritized

**Not the dashboard** — see the correction in §2. What actually steps back:

- **Open public self-signup** as the front door. A login still exists, but it's provisioned when
  an enterprise contract is signed (an `Agency` record created, seats issued to that client's
  team) rather than anyone registering freely.
- The **public Free tier's role** changes from "the product" to a lightweight credibility/demo
  layer — enough for a prospect to see the underlying data is real before they sign, not the
  thing being sold.
- Individual-officer-facing UX polish (the onboarding wizard tuned for a solo field officer,
  etc.) stops being where new engineering effort goes — effort goes into the portfolio/entity
  dashboard (§4, §6) instead.
- Government agency sales at the state level — not abandoned, but no longer the lead motion;
  continental/federal bodies remain a live, different category of buyer (per the Year One Plan).

## 8. Open decisions — only you can make these

1. **Which sector actually goes first?** §6 recommends Entity Modeling → Parametric Insurance as
   the fastest real path to a sellable product, but that's a recommendation, not a decision.
2. ~~Does the public dashboard survive at all~~ — **resolved**: yes, dashboards stay, tiered and
   priced, now built out as the enterprise/`Agency` portfolio view in §2 and §4, not retired.
3. **Pricing** — Jupiter doesn't publish numbers; neither should we yet. What's the actual target
   contract size for a first deal, and do the existing Agency-tier numbers ($2,500–$12,000/yr)
   still hold, or does enterprise/portfolio pricing need to be renegotiated upward given the
   deeper feature set (Entity Modeling, Compliance reports)?
4. **The deadline** — you mentioned a target date. Naming it lets §6 get turned into an actual
   dated plan instead of relative phases.

## 9. What happens after this is approved

Once you've marked this up (cut sectors, reorder phases, answer §8), the next concrete artifact
is a `docs/BUILD_PLAN.md`-style tier spec for Phase 1 (Entity Modeling) — the exact tables,
classes, and migrations it touches, the same level of detail every other shipped feature in this
codebase has. That's the point where this stops being a plan and starts being buildable.
