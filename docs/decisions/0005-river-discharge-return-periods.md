# 0005 — River-discharge bounds from GloFAS reanalysis return periods

- **Date:** 2026-09
- **Status:** accepted (supersedes the "observed max × 1.4" heuristic)

## Context

The Riverine Flood Forecast index scores a forecast discharge against a per-LGA range (rivers
span three orders of magnitude of flow — a shared bound pegs every big river at 100). The first
pass set that range from ~1 year of observed history: `MAX = observed_max × 1.4`. Problems: one
year may contain no flood (line too low → false alarms) or a big one (line too high → misses),
and `× 1.4` is arbitrary. It was a placeholder, not a real threshold.

## Decision

`calibrate:river-discharge` now pulls **~40 years of GloFAS reanalysis** (Open-Meteo Flood API,
back to the mid-1980s) per reach and computes empirical flood return levels:

- take the highest flow of each year → the annual-maximum series (~40 values)
- read off the 2-, 5- and 20-year levels by Weibull plotting position (`App\Services\Hydrology\ReturnPeriodEstimator`)
- `MIN` = the reach's 10th-percentile daily flow; `MAX` = the 20-year return level
- the 2/5/20-year levels are stored in the `MAX` bound's metadata, and the status is
  `reference_derived` (0003)

A forecast at the 2-year flood level now lands around amber, the 20-year level near the top of
red — the score *means* something. Runs monthly (return periods barely move); one Flood-API
call per reach; skips reaches already done unless `--refresh`; never overwrites a bound an admin
set.

## Alternatives considered

- **Keep `observed max × 1.4`.** Cheapest, but not a real threshold — see Context.
- **A distribution fit (Gumbel / GEV) on the annual maxima.** More standard for long return
  periods, but for a 2-to-20-year band the empirical plotting position is within noise and has
  no fitting assumptions to get wrong.
- **A calibrated local hydrological model** (channel geometry, gauge records, a rating curve per
  river). The gold standard, and what a national flood agency does — needs field data collection
  and ongoing maintenance. Out of scope for a lean platform; a state hydrologist can hand-set a
  real bound per region and the monthly job leaves it alone.

## Revisit when

A national flood agency's gauge-calibrated thresholds become available, or the platform needs a
100-year return level (which 40 years of record can't support).
