# 0006 — Probabilistic scoring from a forecast ensemble

- **Date:** 2026-09
- **Status:** accepted

## Context

T4 gave every forecast a single number: the deterministic model run, normalised and peaked, e.g.
"forecast to reach 74 in about 5 days". A deterministic river or rainfall forecast a week or more
out is routinely wrong by a band, and the product gave the reader no way to tell a near-certain
crossing from a coin-flip. Emergency and water agencies act on odds, not point estimates —
"warn me when it's more likely than not" is the real ask.

## Decision

Ingest **ensembles** — the same model re-run 30-50 times from perturbed initial conditions —
score every member through the unchanged index formula, and report the distribution:

- **River discharge:** GloFAS's own 50-member ensemble (Open-Meteo Flood API, `&ensemble=true`).
- **Rainfall / temperature:** a **pooled multi-model** ensemble — GFS (GEFS) + ECMWF + ICON,
  one call per model, members pooled (`gfs-05`, `ecmwf-23`, `icon-15`). Multiple independent
  models give a better-calibrated spread than one model's perturbations and remove single-model
  bias.
- Members live in `region_forecast_signals` tagged with a `member` column (`'control'` = the T4
  deterministic run, unchanged). The distribution — `p10 / p50 / p90`, `exceedance_probability`
  (share of members whose peak reaches 67, the red cutoff), and the sorted per-member peak array
  — is folded into the **same** `region_forecast_scores` row. `score` stays the control peak, so
  every T4 reader is byte-identical.
- **Horizon stays 14 days.** Ensemble skill past two weeks is poor; a soft "20% chance at day 19"
  would mislead more than it informs.
- A new threshold rule, `forecast_probability`: fire when `P(peak ≥ level within horizon) ≥
  percent`, read straight off the stored member array.
- The region page shows the one-line probability ("≈62% chance of crossing 67 in the next 14
  days") and a p10-p90 fan band; the dashboard and region list show an "≈62%" chip.

## Alternatives considered

- **Single-model ensemble** (GFS only). Simplest, one call. Rejected for weather: one model's
  perturbation scheme underspreads, and its biases go unchecked. Kept for discharge only because
  GloFAS *is* the one hydrological model and has no peer to pool with.
- **A parametric spread** — fit a normal/lognormal to the deterministic value ± a climatological
  error. Cheap, no extra ingestion. Rejected: the error grows non-linearly with lead time and is
  regime-dependent (a river near bankfull behaves nothing like one in recession); a real ensemble
  captures that, a fitted σ doesn't.
- **A per-member table** (`region_forecast_members`). Rejected: the sorted peak array in
  `breakdown` answers every query T5 needs (any-threshold exceedance, percentiles, the fan). A
  table is only worth it for member-trajectory forensics, which nothing needs yet.
- **Writing the percentiles to `region_scores`** (as the original BUILD_PLAN line said).
  Rejected — it predates decision [0004](0004-forecast-and-observed-separate-lanes.md). The
  ensemble is forecast data; it stays in the forecast lane.
- **Peak-over-horizon vs. per-lead-day probabilities.** We report the peak ("crosses at some
  point in the window") because that is the planning question. The per-day p10/p50/p90 fan is
  stored too, for the chart.

## Revisit when

- Sub-seasonal / seasonal (S2S) ensembles are wired in (T6) — the 14-day cap can lift with them.
- Enough observed outcomes exist to **calibrate the probability itself** (reliability diagrams —
  does "70%" actually verify 70% of the time). Same data gate as the T8 validation study; until
  then the probability inherits the same "not validated against outcomes" caveat as the score
  ([0003](0003-calibration-bounds-are-placeholders.md)).
