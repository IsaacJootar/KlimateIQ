# 0002 — The green/amber/red cutoffs (34, 67) are a product choice

- **Date:** 2026-08 (backfilled)
- **Status:** accepted

## Context

Every 0–100 score is shown to officers as a colour. Something has to decide where green becomes
amber and amber becomes red. `App\Support\RiskBand` is the single place that does.

## Decision

Even thirds of the scale: green 0–33, amber 34–66, red 67–100. One function, read by region
cards, the dashboard high-risk count, forecast peaks, and the per-band action text — they must
all agree.

The cutoffs are **not derived** from any outcome threshold. They are a legible default so
"amber" and "red" map to how an officer triages.

## Alternatives considered

- **Per-index cutoffs** (e.g. malaria red at 55, flood red at 75). Defensible once each index is
  calibrated, but with uncalibrated scores it would be false precision, and it multiplies the
  places a number can disagree.
- **Percentile bands** ("red = worst 10% of LGAs this week"). Relative, not absolute — a bad
  week everywhere would still show mostly green. Rejected: officers need an absolute signal.

## Revisit when

An index becomes outcome-validated (0003 / 0001). At that point its red line can be tied to a
real event rate, and per-index cutoffs stop being false precision.
