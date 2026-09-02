# 0001 — A transparent weighted formula, not a trained model (for now)

- **Date:** 2026-08 (backfilled)
- **Status:** accepted

## Context

Every risk score drives a real resource decision by a field officer or emergency planner. A
score they can't explain to a supervisor is a score they can't act on. We also had no
historical outcome data to train a model against.

## Decision

Score with `App\Services\Scoring\WeightedFormulaScoringStrategy`: each signal normalised 0–100
against a calibrated range, combined by a configured weight, missing signals skipped and
renormalised. Under 160 lines, no training step, every number traceable to a signal reading —
shown in the breakdown next to every score.

A second strategy, `TrainedModelScoringStrategy`, implements the identical interface and is
wired into `ScoringStrategyResolver` with a safe fallback, so switching is a config change, not
a rewrite of scoring / alerting / the dashboard.

## Alternatives considered

- **Train a model now.** No matched outcome data (Malaria Atlas, DHS-MIS, flood records) to
  train or validate against — it would be a black box with unknown accuracy.
- **Buy a commercial risk API.** Cost, and the same explainability problem plus vendor lock-in.

## Revisit when

Matched historical outcome data is in hand. Then train against the `region_id` + period grain,
export to `storage/app/models/{INDEX}.json`, implement `predict()`, flip `SCORING_STRATEGY`.
This is `docs/BUILD_PLAN.md` T8 — the same data exercise as the validation study.
