# Jupiter Intelligence benchmark — gap analysis

Requested by Isaac: benchmark KlimateIQ against Jupiter Intelligence (jupiterintel.com) —
the climate-risk-intelligence vendor cited as sitting inside ~20% of Fortune 500 companies —
map which of their capabilities we already have, flag the gaps, and recommend what to build
to be stronger than them, not just at parity.

Source: public marketing pages at jupiterintel.com (product pages are not behind a login;
their actual platform UI is — this benchmark is necessarily based on what they publish about
themselves, not a hands-on trial).

## What Jupiter actually sells

One underlying model, **ClimateScore Global**, exposed through several named front-ends:

| Jupiter product | What it is |
|---|---|
| **ClimateScore Global** | The core engine: asset-level, multi-peril climate projections, now through 2100 in 5-year steps, "22,000+ data values per location," downscaled from 100km → 30km → 90m resolution, using "dozens of" debiased global climate models. |
| **Jupiter RiskSignal** | The customer-facing portfolio/asset risk product — "map what's at risk, then price it." Links peril exposure to financial metrics (credit, loss, cashflow, collateral quality, insurance availability). |
| **Jupiter Adaptation Hub** | Adaptation planning and ROI — what mitigation is worth, not just what the risk is. |
| **Jupiter Entity Modeling** | Models a company/counterparty as an entity (not just a pin on a map) — aggregates asset-level risk up to the corporate or borrower level. |
| **Jupiter MetricEngine** | Turns raw peril scores into the financial metrics a credit/underwriting team actually uses. |
| **Jupiter Compliance Hub** | Regulatory disclosure outputs — ISSB/IFRS S2, CSRD, TCFD-aligned reporting. |
| **Jupiter MRM Accelerator** | Model-risk-management documentation — audit trail for *why* a score is what it is, built for bank/insurer model-validation teams. |
| **Jupiter Site Intelligence** | A separate, external-linked product (single-site due-diligence, by the look of it — not deeply documented on the main site). |

Sold by industry vertical: **Banking & lending, Asset management & private equity,
Infrastructure & industrial, Real estate & REITs** — notably wider than our current two-sector
scope (Insurance & Reinsurance, Banking & Lending).

Sold by journey stage: **Assess → Evaluate → Decide** — identify exposure, then price/compete
on it, then act (adapt, divest, reprice). This is a deliberate narrative arc across their
product line, not just a features list.

## Side-by-side

| Capability | Jupiter | KlimateIQ today |
|---|---|---|
| Asset-level multi-peril scoring | Yes — 22k data values/location, 90m resolution | Yes — per-LGA (not per-asset-coordinate) scoring across 14 indices; coarser resolution |
| Time horizon | Now → 2100, 5-year steps | Current/near-term only — no long-horizon projection |
| Portfolio roll-up | Yes (RiskSignal) | Yes (`PortfolioRollupService`) — directly comparable |
| Financial translation (value at risk) | Yes — credit/loss/cashflow metrics (MetricEngine) | Partial — Protected/At-Risk Naira or Dollar totals; no credit/loss/cashflow translation |
| Stress / scenario testing | Yes — named scenarios across time horizon | Yes (Module 13 MVP) — single-point % stress preset, no time dimension |
| Entity-level modeling (counterparty, not just location) | Yes (Entity Modeling) | No — we roll up to portfolio, not to a borrower/counterparty entity |
| Adaptation planning / ROI | Yes (Adaptation Hub) | No |
| Regulatory disclosure output (ISSB/IFRS S2, CSRD, TCFD) | Yes (Compliance Hub) | No — Reports page exists but isn't framework-aligned |
| Model-risk documentation / audit trail | Yes (MRM Accelerator) | No — scoring methodology lives in code/docs, not as a customer-facing audit artifact |
| Alerts / monitoring | Not advertised on their site (may exist behind login) | Yes — Signals & Alerts, threshold-based, real-time |
| API access | Not advertised on their site | Yes — per-portfolio scoped tokens, live now |
| Named climate perils per index | Not published publicly | Yes, and transparently named (Flood Risk, Drought Risk, Agriculture Stress, Riverine Flood Forecast, Wildfire Risk, Dust Storm Risk, Heat Stress, Malaria Risk, Waterborne Disease Risk, Respiratory Risk, etc.) |
| Industry verticals served | 4 (Banking, Asset Mgmt/PE, Infrastructure, Real Estate) | 2 (Insurance & Reinsurance, Banking & Lending) — by design, deliberately scoped for now |

## Where we're already ahead

- **Alerts/Signals and API access are live, working features** for us; Jupiter doesn't
  advertise either publicly. If their platform lacks real-time threshold alerting, that's a
  genuine differentiator worth saying out loud in the pitch, not just matching them.
- **Named, specific perils.** Jupiter's public marketing stays abstract ("multi-peril," "physical
  risk") — ours names exact indices per sector. For a Nigerian/African context where buyers may
  not trust a black-box global score, naming the actual signal (Flood Risk Index, Agriculture
  Stress Index) is a trust advantage, not just a UX nicety.
- **Faster time-to-value.** Their product requires entity modeling and long integration cycles
  implied by "MRM Accelerator" / "Compliance Hub" being separate SKUs. Ours is portfolio-create
  → see risk in minutes.

## The real gaps, ranked by what's buildable soon

1. **Financial-metric translation beyond Protected/At-Risk totals.** Jupiter's MetricEngine
   turns peril scores into credit/loss/cashflow numbers a bank's risk committee already
   speaks in. Our Protected/At-Risk totals are a good start but stop at "value exposed," not
   "expected loss" or "provisioning impact." This is the single highest-leverage gap — it's
   what turns a risk *score* into a number a CFO/CRO can act on.
2. **Long-horizon projection.** Everything we show is current-state. Jupiter's whole pitch is
   "now through 2100." Scenario Testing (Module 13) gives us a stress dimension but not a time
   dimension. A simple "what does this look like in 10/20/30 years under a moderate-warming
   path" view — even coarse — would close a visible gap in any side-by-side demo.
3. **Entity/counterparty roll-up, not just portfolio roll-up.** A bank cares about "how exposed
   is Borrower X across all their collateral," not only "how exposed is Loan Book Y." Portfolio
   Exposure already aggregates across portfolios by currency; the next step is aggregating by
   counterparty/entity, which Jupiter sells as its own product line.
4. **Regulatory-disclosure-shaped reports.** Jupiter's Compliance Hub exists because ISSB/IFRS
   S2, CSRD, and TCFD disclosure is now a buying trigger for banks/insurers globally (and
   increasingly referenced in Nigerian/African regulatory conversations too). Our Reports page
   should grow a disclosure-framework-aligned export, even a simple one, as its own named
   feature — this is a sales-enablement gap, not just a nice-to-have.
5. **Adaptation / "what would reduce this" guidance.** We already have `IndexActionRecommendation`
   text per index/band — that's the seed of an Adaptation Hub equivalent. The gap is that it's
   advisory text today, not tied to an ROI/cost number. Don't need to match Jupiter's product
   depth here, but naming this pillar explicitly (even as "Recommended Actions" elevated to its
   own place) closes a visible naming gap in any side-by-side.

## What NOT to chase

- **Model-risk-management documentation as its own product** (MRM Accelerator) — that's a
  multi-year, bank-model-validation-team-facing product. Not worth building before the
  financial-metric and long-horizon gaps above are closed.
- **Matching their 4-vertical breadth.** The 2-sector scope (Insurance & Reinsurance, Banking &
  Lending) was a deliberate, approved cut for the NIGCOMSAT presentation. Expanding verticals is
  a go-to-market decision, not an engineering gap — don't build Real Estate/Infrastructure
  features speculatively.

## Bottom line

Jupiter's actual moat is model depth (90m resolution, 2100 horizon, "22k data values/location")
and financial translation — things that take real climate-science investment, not UI work. We
won't out-model them by redesigning a page. But we're already ahead on two concrete, demoable
features (live alerting, live API access) that they don't publicly claim, and the fastest way to
look *more* capable than them in a side-by-side demo is closing gap #1 (financial-metric
translation) and #2 (a long-horizon view) — both buildable on top of services that already exist
(`PortfolioRollupService`, `LatestScore`), not a new model.
