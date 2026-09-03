# Decision log

Short, dated notes on the engineering choices that a future team could otherwise waste time
re-deriving — or worse, "clean up" without knowing why they were made. One file per decision,
never edited except to add a **Superseded by** line.

`docs/MODEL.md` and `docs/BUILD_PLAN.md` describe the *current* state; these describe *why it is
that way* and *what we'd change with more time or data*.

Format (keep it to a screen):

```
# NNNN — Title

- **Date:**
- **Status:** accepted | superseded by NNNN

## Context
What forced a choice.

## Decision
What we did.

## Alternatives considered
The options we didn't take, and why.

## Revisit when
The condition that should make someone reopen this.
```

## Index

| # | Decision |
|---|---|
| [0001](0001-transparent-formula-over-trained-model.md) | A transparent weighted formula, not a trained model (for now) |
| [0002](0002-band-cutoffs-are-a-product-choice.md) | The green/amber/red cutoffs (34, 67) are a product choice |
| [0003](0003-calibration-bounds-are-placeholders.md) | Ship uncalibrated bounds, but mark every one honestly |
| [0004](0004-forecast-and-observed-separate-lanes.md) | Forecast and observed data live in separate tables, end to end |
| [0005](0005-river-discharge-return-periods.md) | River-discharge bounds from GloFAS reanalysis return periods |
| [0006](0006-probabilistic-scoring-ensemble.md) | Probabilistic scoring from a forecast ensemble |
| [0007](0007-reach-level-riverine-forecast.md) | Reach-level riverine forecast (Niger–Benue corridor) |
