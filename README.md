# KlimateIQ — Climate-Health Surveillance Dashboard

Built for NigComSat Accelerator 3.0, Track C: Public Health Intelligence.

**Live**: [klimateiq.org](https://klimateiq.org) (product site) · [app.klimateiq.org](https://app.klimateiq.org) (the platform itself, deployed on AWS — EC2 + managed RDS Postgres)

**How this actually stands up right now**, not aspirationally:

- **774 real Nigerian LGAs** seeded with real coordinates and real 2020 UNFPA/US Census population figures — not placeholder data
- **8 live signal sources** feeding **6 named risk indices**, with automatic fallback so no single data provider is a point of failure — see [`docs/MODEL.md`](docs/MODEL.md) for the exact formulas and [`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md) for the resilience pattern
- **147 automated tests, all passing** — `php artisan test`
- Real transactional email (Resend), real error tracking (Sentry), real production monitoring (a Pipeline Health dashboard showing queue depth and per-source data freshness) — not just a demo path

## The problem

Pollution and climate readings are widely available; what happens to *people* because of them
usually isn't. A rainfall grid tells you it rained 90mm in Bayelsa this week — it doesn't tell a
malaria programme officer whether that means intervene now or wait, and it doesn't tell an
emergency response coordinator which of five LGAs is closest to flooding first. The gap between
raw environmental signal and an actionable, regionally-specific decision is where budgets get
misdirected and health outcomes suffer.

## The solution

KlimateIQ ingests satellite/reanalysis environmental signals per Nigerian LGA, fuses them into
named, purpose-built risk indices (not one blended score), lets health agencies configure their
own thresholds and alerts per region, and gives every user — state coordinator, LGA malaria
officer, flood response team — a dashboard scoped to what they're actually responsible for, not
the whole country.

**Three independently scalable layers:**

1. **Spatial Processing Layer** — scheduled/queued ingestion jobs pull environmental signals per
   region, normalized into a common `region_signals` table.
2. **Alerts & Notification Layer** — reacts only to `RegionScoreCalculated` /
   `RegionSignalIngested` *events*; it never calls into ingestion or scoring directly, so any
   layer can be deployed, scaled, or replaced independently of the others.
3. **User Interface Layer** — the dashboard, threshold configuration, and a documented
   third-party read API.

### Named indices, not one blended score

| Index | Built from | Useful for |
|---|---|---|
| **Malaria Risk Index** | Rainfall + standing water | Malaria programme officers |
| **Flood Risk Index** | Rainfall + standing water + elevation | Emergency response |
| **Heat Stress Risk Index** | Temperature + vegetation loss | Occupational and public heat-health planning |
| **Drought Risk Index** | Rainfall deficit + vegetation stress | Agricultural and water-security planning |
| **Respiratory Risk Index** | PM2.5 + PM10 particulate matter | Air-quality and respiratory-health planning, especially harmattan/dust season |
| **Composite Climate-Health Pressure Index** | All active signals, weighted | Overall regional snapshot |

Adding another index is a new row in `region_scoring_configs` — no code change. Every score
traces back to exactly which signal drove it (see the breakdown on any region's drill-down page,
or `region_scores.breakdown` directly) — the exact formula, every index's real weights, and
every calibration bound with its source are all written out in full in
[`docs/MODEL.md`](docs/MODEL.md).

### AI, honestly scoped

- **Scoring** — `WeightedFormulaScoringStrategy` is what's live today: transparent, calibrated,
  explainable. `TrainedModelScoringStrategy` is a genuine architectural seam (same interface, same
  input/output shape) — see [`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md) for what
  activating it requires once historical case data is available.
- **Alerting** — thresholds can be a fixed value *or* an anomaly against a region's own rolling
  baseline (mean/stddev over its recent history) — genuinely adaptive, not a fixed rule.
- **Reporting** — an OpenAI-powered summary turns a score's breakdown into a short plain-English
  explanation, restricted to only restating data already computed, cached alongside the score.

## Data sources

| Signal | Source | Status |
|---|---|---|
| Rainfall | NASA POWER, falls back to Open-Meteo (ERA5) | Live |
| Standing water | JRC Global Surface Water | Live |
| Temperature | NASA POWER, falls back to Open-Meteo (ERA5) | Live |
| Vegetation/humidity | MODIS (via NASA Earthdata/AppEEARS) | Live |
| Elevation | SRTM (Open Topo Data) | Live |
| Population exposure | UNFPA/US Census Bureau via HDX (2020 LGA-level projection) | Live, but not a per-request API pull — see [`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md#population-exposure) for what that means |
| Air quality (PM2.5, PM10) | Open-Meteo Air Quality API (CAMS) | Live |

No single provider is the platform's entire data backbone by design — see
[`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md#resilience-no-single-provider-is-a-single-point-of-failure)
for the resilience/fallback pattern and how a new source plugs in without touching scoring or
alerting.

All 774 real Nigerian LGAs are seeded (name, state, coordinates). Ingestion is usage-driven — a
region only gets pulled once someone actually watches it or requests it via Coverage, so the
platform doesn't waste cycles ingesting every LGA nobody's asked about. See
[`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md) for how to plug in an additional signal
source, and how confident you should be in the current scoring bounds.

## Setup

Requires PHP 8.3+, Postgres, Node 18+.

```bash
composer install
npm install
php artisan key:generate
```

Create a `.env` with `DB_CONNECTION=pgsql` and your database credentials. A handful of
integrations are optional and degrade gracefully without a key — email alerts (`RESEND_API_KEY`),
SMS alerts (`TERMII_API_KEY`), AI score summaries (`OPENAI_API_KEY`), Vegetation ingestion
(`NASA_EARTHDATA_USERNAME`/`NASA_EARTHDATA_PASSWORD`, free account at
[urs.earthdata.nasa.gov](https://urs.earthdata.nasa.gov/)), and error tracking
(`SENTRY_LARAVEL_DSN` — see `config/sentry.php`, a free Sentry project supplies this). See
`config/services.php` and `config/ingestion.php` for exactly which variables each one reads.
Then:

```bash
php artisan migrate
php artisan db:seed
npm run build
```

## Run

```bash
php artisan serve
php artisan queue:work    # processes ingestion jobs and alert-evaluation listeners
```

Prove the pipeline end-to-end:

```bash
php artisan signals:ingest --sync   # pulls real rainfall data for all seeded regions, synchronously
php artisan scores:calculate        # computes every index for every region
```

Or on a schedule (already wired in `routes/console.php`):

```bash
php artisan schedule:work
```

## Tests

```bash
php artisan test
```

Uses a dedicated `gano_ai_test` Postgres database (see `phpunit.xml`) rather than SQLite — several
migrations use Postgres-specific constraints.

## Known limitations

- **Agency membership is self-declared, not verified.** Anyone can select any existing agency
  (or type a new one) at signup — there's no check that they actually belong to it. This matters
  because agency membership currently gates "share with my agency" visibility on Saved Views and
  Reports, and will matter more once any cross-agency oversight capability exists. The intended
  fix — matching a user's email domain against a per-agency verified domain, with unverified
  claims held for admin review rather than either silently trusted or blocked — is deferred, not
  forgotten.

- **Scoring calibration bounds are engineering estimates, not clinically validated.** Only
  Vegetation's `-1` to `1` range is a genuine scientific standard (NDVI's own definition); the
  rest are climatologically plausible defaults for Nigeria, not numbers checked against real
  health-outcome data. See
  [`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md#how-trustworthy-are-the-current-bounds) for
  the honest breakdown of what's science-based versus a reasonable guess.

## Third-party API

Token-authenticated (Sanctum) read access to the same scores the dashboard renders — the
integration surface for another agency's dashboard. See
[`docs/INGESTION_GUIDE.md`](docs/INGESTION_GUIDE.md#third-party-api) for endpoints and how to
issue a token.
