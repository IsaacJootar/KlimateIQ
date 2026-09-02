# 0004 — Forecast and observed data live in separate tables, end to end

- **Date:** 2026-09
- **Status:** accepted

## Context

T4 made the pipeline score a *future* period, not just a completed one. The failure mode: a
forecast value silently treated as an observation — in an anomaly baseline, a backtest, or the
dashboard's "this week" — producing a wrong number nobody notices.

## Decision

`region_forecast_signals` and `region_forecast_scores` are separate tables from `region_signals`
and `region_scores`, sharing the column contract. **No existing observed-data query was
touched.** Every forecast read is new, explicit code (`scores:forecast`, `App\Support\LatestScore`
when `is_forecast`, the region-page forecast branch). Forecast indices carry `indices.is_forecast`;
`scores:calculate` skips them, `scores:forecast` owns them.

v1 keeps only the latest forecast issuance per region/signal — issuance history (needed to
backtest forecast skill) is deferred to T5.

## Alternatives considered

- **A discriminator column** (`is_forecast` / `forecast_issued_at`) on the existing tables. Less
  schema, but ~10 observed-data queries would each need auditing to exclude forecasts — miss one
  and it's a silent data-quality bug. Not worth the risk.

## Revisit when

T5 (probabilistic / ensemble scoring) needs multiple forecast members and issuance history per
period — that's an additive schema change on the forecast tables, still isolated from observed.
