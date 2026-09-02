# 0003 — Ship uncalibrated bounds, but mark every one honestly

- **Date:** 2026-09
- **Status:** accepted

## Context

A score needs a min/max range per signal to normalise against, and a weight per signal. We have
no outcome data to fit either. We still need the platform to work today. The risk: a future
engineer treats a placeholder range as validated and builds on it, or a new index ships
implying a rigour it doesn't have.

## Decision

- Ship plausible ranges and judgement-based weights, and **say so in three places**: `docs/MODEL.md`,
  a per-row status in the admin Scoring config UI, and a one-line caveat on the score itself
  (`App\Support\IndexCalibration`).
- Every weight and bound carries a `calibration_status` (`App\Support\CalibrationStatus`):
  `placeholder` (default) · `admin_tuned` · `reference` (a cited public source — PM/ozone/NO₂/dust
  use WHO/EPA points) · `reference_derived` (computed from real data — river discharge, see 0005)
  · `outcome_validated` (fitted to health/damage outcomes — none yet).
- A test (`CalibrationHonestyTest`) fails if a new bound claims better than `placeholder` without
  a `source_reference`, so the honesty keeps happening.

## Alternatives considered

- **Prose only in `docs/MODEL.md`.** Drifts from the code; a reader has to *choose* to look.
- **Block the feature until calibrated.** Would mean shipping nothing for months while outcome
  data is sourced — the platform is useful as a prioritisation aid before it's validated.
- **A single "uncalibrated" flag per index.** Too coarse — misses that PM bounds *are* cited
  while the malaria weights are not.

## Revisit when

Outcome data lands. Move the relevant rows to `outcome_validated`, update the test's
`test_no_bound_claims_outcome_validation_yet` guard, and reconsider 0002.
