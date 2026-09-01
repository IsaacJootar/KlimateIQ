<?php

namespace App\Support;

/**
 * What each external data source's own documented free-tier limit actually is — the answer to
 * "at what point does 'this is a non-issue at our current scale' stop being true, and what do we
 * do about it." Sourced from each provider's own published docs, not estimated:
 *
 *   - Open-Meteo (archive + air quality): 10,000 calls/day, free tier, no signup —
 *     https://open-meteo.com/en/pricing
 *   - Open Topo Data: 1 call/sec, 1,000 calls/day, public instance —
 *     https://www.opentopodata.org/
 *   - NASA POWER: no published hard daily cap (fair-use); tracked here for visibility, not
 *     compared against a limit.
 *   - NASA AppEEARS: task-based (submit/poll/download), not a simple per-day call count — its
 *     real constraint is concurrent running tasks per account, not shown as a percentage here.
 */
class ApiCapacityLimits
{
    /**
     * Signal type code => [provider label, calls/day limit, recommendation if approaching it].
     * Null limit means "no published hard cap" — shown as informational, never flagged red.
     *
     * @return array<string, array{provider: string, dailyLimit: ?int, recommendation: string}>
     */
    public static function all(): array
    {
        return [
            'RAINFALL' => [
                'provider' => 'NASA POWER (primary), Open-Meteo (fallback)',
                'dailyLimit' => 10000,
                'recommendation' => 'Fallback traffic only hits Open-Meteo\'s 10,000/day limit when NASA POWER is down — approaching it means NASA POWER itself has been failing a lot, not a scale problem. Self-hosting Open-Meteo (AGPLv3, open-source) removes the ceiling entirely once real usage justifies it — see docs/INGESTION_GUIDE.md.',
            ],
            'TEMPERATURE' => [
                'provider' => 'NASA POWER (primary), Open-Meteo (fallback)',
                'dailyLimit' => 10000,
                'recommendation' => 'Same fallback path as Rainfall — see that recommendation.',
            ],
            'AIR_QUALITY_PM25' => [
                'provider' => 'Open-Meteo Air Quality API',
                'dailyLimit' => 10000,
                'recommendation' => 'This is the primary source, not a fallback, so it scales directly with active regions (1 call/region/day). At 10,000/day, that ceiling supports roughly 10,000 active regions before self-hosting Open-Meteo becomes necessary — far beyond Nigeria\'s 774 LGAs.',
            ],
            'AIR_QUALITY_PM10' => [
                'provider' => 'Open-Meteo Air Quality API',
                'dailyLimit' => 10000,
                'recommendation' => 'Same as PM2.5 — shares the same daily quota.',
            ],
            'OZONE' => [
                'provider' => 'Open-Meteo Air Quality API (CAMS)',
                'dailyLimit' => 10000,
                'recommendation' => 'Shares the Open-Meteo Air Quality quota with the PM, NO2 and dust pulls — 1 call/region/day. Not a scale concern at LGA granularity.',
            ],
            'NO2' => [
                'provider' => 'Open-Meteo Air Quality API (CAMS)',
                'dailyLimit' => 10000,
                'recommendation' => 'Same shared Open-Meteo Air Quality quota as ozone and the PM series.',
            ],
            'SOIL_MOISTURE' => [
                'provider' => 'Open-Meteo Archive API (ERA5-Land)',
                'dailyLimit' => 10000,
                'recommendation' => 'Primary source, 1 call/region/day — shares Open-Meteo\'s 10,000/day free-tier quota with the other archive and air-quality pulls. Well clear of Nigeria\'s 774 LGAs; self-hosting Open-Meteo (AGPLv3) lifts the ceiling if the platform ever needs it.',
            ],
            'EVAPOTRANSPIRATION' => [
                'provider' => 'Open-Meteo Archive API',
                'dailyLimit' => 10000,
                'recommendation' => 'Same Open-Meteo free-tier quota as Soil Moisture and the archive fallbacks — 1 call/region/day. Not a scale concern at LGA granularity.',
            ],
            'HUMIDITY' => [
                'provider' => 'Open-Meteo Archive API (ERA5)',
                'dailyLimit' => 10000,
                'recommendation' => 'Shares Open-Meteo\'s 10,000/day free tier with the other archive and air-quality pulls — 1 call/region/day. Self-hosting Open-Meteo (AGPLv3) lifts the ceiling if ever needed.',
            ],
            'WIND_SPEED' => [
                'provider' => 'Open-Meteo Archive API (ERA5)',
                'dailyLimit' => 10000,
                'recommendation' => 'Same Open-Meteo free-tier quota — 1 call/region/day. BUILD_PLAN notes NASA POWER as a possible fallback if Open-Meteo wind coverage ever proves patchy; not wired up yet.',
            ],
            'DUST' => [
                'provider' => 'Open-Meteo Air Quality API (CAMS)',
                'dailyLimit' => 10000,
                'recommendation' => 'Shares the Open-Meteo Air Quality quota with PM2.5 / PM10 — 1 call/region/day. CAMS dust has a shorter historical window than the ERA5 archive; the ingestion window (last complete period) sits well inside it.',
            ],
            'ACTIVE_FIRE' => [
                'provider' => 'NASA FIRMS area API',
                'dailyLimit' => null,
                'recommendation' => 'FIRMS meters by transaction count (~5,000 per 10-minute window on a free map key), not a daily cap — 1 call/region/day is nowhere near it. If ingestion starts erroring with a rate message, spread the fire pull onto its own schedule slot. NRT data only goes back ~2 months and serves 5 days per request, so this is a confirmation series, not a backfill source.',
            ],
            'RIVER_DISCHARGE' => [
                'provider' => 'Open-Meteo Flood API (GloFAS)',
                'dailyLimit' => 10000,
                'recommendation' => 'Two calls/region/day at most — one observed (past week), one forecast (14-day horizon) — sharing Open-Meteo\'s 10,000/day free-tier quota with the archive and air-quality pulls. GloFAS only models mapped river reaches, so a large share of LGAs return no data; that is coverage, not a rate problem. Self-hosting Open-Meteo (AGPLv3) lifts the ceiling if ever needed.',
            ],
            'STANDING_WATER' => [
                'provider' => 'JRC Global Surface Water',
                'dailyLimit' => null,
                'recommendation' => 'No published hard limit found; monitor the failure rate in Recent Failures below as the practical early warning instead of a call count.',
            ],
            'VEGETATION' => [
                'provider' => 'NASA AppEEARS',
                'dailyLimit' => null,
                'recommendation' => 'Task-based, not a simple daily call count — the real constraint is concurrent running tasks per account. If ingestion runs start timing out or queuing up, that\'s the signal to reduce concurrency or request a higher AppEEARS task quota from NASA, not a raw call count.',
            ],
            'ELEVATION' => [
                'provider' => 'Open Topo Data',
                'dailyLimit' => 1000,
                'recommendation' => 'Pulled once per region on activation only (see IngestionCadence::ONCE) — this should almost never move. If it does, something is re-triggering elevation pulls unexpectedly; check for repeated manual "Run ingestion now" clicks rather than assuming it needs a higher limit.',
            ],
            'POPULATION_EXPOSURE' => [
                'provider' => 'Local database (no external API)',
                'dailyLimit' => null,
                'recommendation' => 'Reads regions.population directly — no external quota exists to exhaust.',
            ],
        ];
    }

    /**
     * Above this fraction of a known daily limit, flag it — not at the ceiling itself, so there's
     * runway to act before a real outage.
     */
    public const WARNING_THRESHOLD = 0.7;
}
