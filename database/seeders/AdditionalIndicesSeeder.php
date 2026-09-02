<?php

namespace Database\Seeders;

use App\Models\IndexActionRecommendation;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use Illuminate\Database\Seeder;

/**
 * Indices added after the original six (docs/BUILD_PLAN.md). Kept out of ReferenceDataSeeder
 * so production can pick them up — `php artisan db:seed --class=AdditionalIndicesSeeder` —
 * without re-running the calibration and scoring-weight seeding that would reset any
 * admin-tuned values.
 *
 * Fully idempotent: signal types are upserted; each index row is upserted; its scoring config,
 * calibration bounds and action text are created only when absent (firstOrCreate), so a re-run
 * never overwrites tuning done from the admin UI.
 *
 * Indices here may depend on signals beyond the original eight — the agriculture bundle needs
 * SOIL_MOISTURE and EVAPOTRANSPIRATION; Wildfire and Dust Storm Risk need HUMIDITY, WIND_SPEED
 * and DUST; Wildfire also carries a weight-0 ACTIVE_FIRE confirmation series (NASA FIRMS, needs
 * a map key — no-op without one); the Respiratory Risk depth pass adds OZONE and NO2. Their
 * ingestion services are registered in config/ingestion.php and pull on the daily cadence / on
 * region activation — the score simply stays partial (renormalized over the signals present)
 * until those readings land.
 *
 * This seeder also runs one in-place edit — rebalancing the Respiratory Risk PM weights — and
 * does it only when they're still at the original default (see deepenRespiratoryRisk()).
 *
 * Every index here ships UNCALIBRATED — the weights and bounds are transparent engineering
 * defaults, not validated against outcome data. Same honest caveat as the original six
 * (see docs/MODEL.md and docs/INGESTION_GUIDE.md).
 */
class AdditionalIndicesSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureSignalTypes();
        $this->waterborneDiseaseRisk();
        $this->agricultureStress();
        $this->irrigationNeed();
        $this->rangelandStress();
        $this->wildfireRisk();
        $this->dustStormRisk();
        $this->drySeasonWaterStress();
        $this->riverineFloodForecast();
        $this->deepenRespiratoryRisk();
    }

    /**
     * Signal types this seeder's indices depend on that aren't in the original catalogue
     * (ReferenceDataSeeder::seedSignalTypes). Upserted here so a production run of just this
     * seeder is self-sufficient. signal_types carries no admin-tuned state, so updateOrCreate
     * is safe on re-run.
     */
    private function ensureSignalTypes(): void
    {
        // Names are the reader-facing labels (Clarity Pass A1 / migration friendly_signal_type_names).
        $types = [
            // Drier soil is worse for crops — inverted, like elevation. Indices override direction as needed.
            ['code' => 'SOIL_MOISTURE', 'name' => 'Soil Moisture (Root Zone)', 'unit' => 'm³/m³', 'source' => 'Open-Meteo Archive API (ERA5-Land, 7–28 cm)', 'higher_is_worse' => false],
            ['code' => 'EVAPOTRANSPIRATION', 'name' => 'Evaporation Demand (ET₀)', 'unit' => 'mm', 'source' => 'Open-Meteo Archive API (FAO-56 Penman-Monteith)', 'higher_is_worse' => true],
            // Dry air drives fire spread, dust lofting and dry-season disease — inverted by default.
            ['code' => 'HUMIDITY', 'name' => 'Air Humidity', 'unit' => '%', 'source' => 'Open-Meteo Archive API (ERA5, 2 m)', 'higher_is_worse' => false],
            ['code' => 'WIND_SPEED', 'name' => 'Wind Speed', 'unit' => 'km/h', 'source' => 'Open-Meteo Archive API (ERA5, daily max)', 'higher_is_worse' => true],
            ['code' => 'DUST', 'name' => 'Airborne Dust', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
            // Confirmation series only — carries weight 0 on Wildfire Risk, never drives a score.
            ['code' => 'ACTIVE_FIRE', 'name' => 'Active Fire Detections', 'unit' => 'detections', 'source' => 'NASA FIRMS (VIIRS NOAA-20)', 'higher_is_worse' => true],
            // Respiratory Risk depth — gaseous pollutants alongside the PM series.
            ['code' => 'OZONE', 'name' => 'Ground-Level Ozone', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
            ['code' => 'NO2', 'name' => 'Nitrogen Dioxide', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
            // Forecast lane (BUILD_PLAN.md T4) — the Riverine Flood Forecast index measures the
            // GloFAS forecast against this signal's own observed history per LGA.
            ['code' => 'RIVER_DISCHARGE', 'name' => 'River Discharge', 'unit' => 'm³/s', 'source' => 'Open-Meteo Flood API (GloFAS)', 'higher_is_worse' => true],
        ];

        foreach ($types as $type) {
            SignalType::query()->updateOrCreate(['code' => $type['code']], $type);
        }
    }

    /**
     * Cholera and typhoid track contaminated standing water after flooding. Reuses the two
     * signals already ingested for Malaria / Flood Risk — no new ingestion. Attached to the
     * Public Health and Water & Sanitation sectors in SectorSeeder.
     */
    private function waterborneDiseaseRisk(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'WATERBORNE_DISEASE_RISK'],
            [
                'name' => 'Waterborne Disease Risk Index',
                'description' => 'Standing water and rainfall weighted for cholera and typhoid risk after flooding — for WASH teams targeting water treatment and safe-water messaging.',
            ]
        );

        $this->seedWeights($index, [
            'STANDING_WATER' => 0.5,
            'RAINFALL' => 0.5,
        ]);

        // Same climatologically-plausible ranges the original indices use for these signals
        // (see ReferenceDataSeeder::seedCalibrationParameters). Not epidemiologically calibrated.
        $this->seedBounds($index, [
            'RAINFALL' => [0, 200],
            'STANDING_WATER' => [0, 100],
        ]);

        $this->seedActions($index, [
            'green' => 'No action needed. Continue routine water-quality monitoring.',
            'amber' => 'Alert the WASH focal point. Pre-position water-treatment supplies (chlorine, ORS) and step up water-source surveillance in this LGA.',
            'red' => 'Activate the waterborne-disease response: treat and protect water sources in this LGA, pre-position ORS and IV fluids at health facilities, and issue a safe-water advisory. Notify the state epidemiologist within 48 hours.',
        ]);
    }

    /**
     * Soil-water focused, and deliberately distinct from Drought Risk (rainfall + NDVI): this
     * one leads with root-zone soil moisture and the crop water balance, a pre-visible signal
     * that moves weeks before vegetation stress shows up. Attached to the Agriculture sector
     * in SectorSeeder.
     */
    private function agricultureStress(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'AGRICULTURE_STRESS'],
            [
                'name' => 'Agriculture Stress Index',
                'description' => 'Soil moisture, rainfall shortfall and evaporation demand as an early crop water-stress score — for extension officers deciding where to send support before crops visibly wilt.',
            ]
        );

        $this->seedWeights($index, [
            'SOIL_MOISTURE' => ['weight' => 0.5, 'higher_is_worse' => false], // drier root zone, more stress
            'RAINFALL' => ['weight' => 0.3, 'higher_is_worse' => false],      // rainfall deficit
            'EVAPOTRANSPIRATION' => 0.2,                                       // higher demand, more stress (signal default)
        ]);

        // ERA5-Land 7–28 cm volumetric water content typically runs ~0.05 (very dry) to ~0.40
        // (near saturation) in Nigeria. ET₀ is a weekly sum of daily mm. Neither is calibrated
        // against yield or crop-loss data.
        $this->seedBounds($index, [
            'SOIL_MOISTURE' => [0.05, 0.40],
            'RAINFALL' => [0, 200],
            'EVAPOTRANSPIRATION' => [0, 50],
        ]);

        $this->seedActions($index, [
            'green' => 'No action needed. Continue routine agronomic monitoring.',
            'amber' => 'Alert agricultural extension officers for this LGA. Begin water-conservation and supplementary-irrigation messaging to farmers, and prioritise field visits here.',
            'red' => 'Activate the crop water-stress response: prioritise irrigation support and drought-tolerant input distribution for this LGA, and brief the state agriculture ministry on the areas most at risk of yield loss this season.',
        ]);
    }

    /**
     * "How much supplementary irrigation does this LGA need right now" — atmospheric water
     * demand (ET₀) against what the soil and recent rain have supplied. Same three signals as
     * Agriculture Stress, re-weighted to lead with demand rather than soil deficit. The 0–100
     * score is a targeting aid, not a literal mm figure — a mm-of-water output is a future
     * enhancement (see docs/BUILD_PLAN.md). Attached to the Agriculture sector in SectorSeeder.
     */
    private function irrigationNeed(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'IRRIGATION_NEED'],
            [
                'name' => 'Irrigation Need Index',
                'description' => 'Evaporation demand against soil moisture and recent rain — where supplementary irrigation would do the most good this week. A targeting score, not a water volume.',
            ]
        );

        $this->seedWeights($index, [
            'EVAPOTRANSPIRATION' => 0.5,                                       // atmospheric water demand (signal default)
            'SOIL_MOISTURE' => ['weight' => 0.3, 'higher_is_worse' => false],  // drier soil, more need
            'RAINFALL' => ['weight' => 0.2, 'higher_is_worse' => false],       // less recent rain, more need
        ]);

        $this->seedBounds($index, [
            'EVAPOTRANSPIRATION' => [0, 50],
            'SOIL_MOISTURE' => [0.05, 0.40],
            'RAINFALL' => [0, 200],
        ]);

        $this->seedActions($index, [
            'green' => 'No supplementary irrigation indicated. Recent rainfall and soil moisture are meeting crop water demand.',
            'amber' => 'Advise farmers in this LGA to plan supplementary irrigation for water-sensitive crops, and check that irrigation infrastructure and water allocations are ready.',
            'red' => 'High irrigation need: prioritise this LGA for irrigation water release and extension support, and warn of likely yield loss on rain-fed plots without intervention.',
        ]);
    }

    /**
     * Grazing-land condition for pastoralist areas — low vegetation against a rainfall deficit.
     * A sustained rangeland-stress signal in the dry-season grazing belt is an early indicator
     * of southward pastoralist movement and the farmer–herder friction that follows it, so this
     * is as much an emergency-planning input as an agricultural one. Attached to the Agriculture
     * sector in SectorSeeder.
     */
    private function rangelandStress(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'RANGELAND_STRESS'],
            [
                'name' => 'Rangeland Stress Index',
                'description' => 'Vegetation loss and rainfall shortfall across grazing land — an early signal of pasture failure and the pastoralist movement that can follow it, for agriculture and security planners.',
            ]
        );

        $this->seedWeights($index, [
            'VEGETATION' => ['weight' => 0.6, 'higher_is_worse' => false], // lower NDVI, more pasture stress
            'RAINFALL' => ['weight' => 0.4, 'higher_is_worse' => false],   // rainfall deficit
        ]);

        $this->seedBounds($index, [
            'VEGETATION' => [-1, 1], // NDVI is mathematically defined on this range
            'RAINFALL' => [0, 200],
        ]);

        $this->seedActions($index, [
            'green' => 'Grazing conditions are adequate. Continue routine rangeland monitoring.',
            'amber' => 'Brief the state agriculture and livestock services and local authorities: pasture is deteriorating in this LGA. Review water-point and fodder contingency, and watch for early pastoralist movement.',
            'red' => 'Rangeland failure risk: activate dry-season grazing contingency (water points, supplementary fodder, designated grazing reserves) and pre-brief security and conflict-prevention focal points on likely herder movement through this area.',
        ]);
    }

    /**
     * Bush-fire weather: dry air + dry vegetation + wind + heat. NASA FIRMS active-fire
     * detections ride along as a weight-0 confirmation series (`ACTIVE_FIRE`) — visible in the
     * score breakdown, never affecting the number. Attached to the Emergency Response sector
     * in SectorSeeder.
     */
    private function wildfireRisk(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'WILDFIRE_RISK'],
            [
                'name' => 'Wildfire Risk Index',
                'description' => 'Dry air, dry vegetation, wind and heat as a bush-fire-weather score, with satellite fire detections shown alongside for confirmation — for land management and fire services in the dry season.',
            ]
        );

        $this->seedWeights($index, [
            'HUMIDITY' => 0.3,                                            // low humidity, more fire risk (signal default)
            'VEGETATION' => ['weight' => 0.3, 'higher_is_worse' => false], // lower NDVI = drier, more fuel-dry risk
            'WIND_SPEED' => 0.2,                                          // stronger wind, faster spread (signal default)
            'TEMPERATURE' => 0.2,                                         // hotter, more fire risk (signal default)
            'ACTIVE_FIRE' => 0.0,                                         // confirmation only — shown, never scored
        ]);

        $this->seedBounds($index, [
            'HUMIDITY' => [0, 100],
            'VEGETATION' => [-1, 1],
            'WIND_SPEED' => [0, 40],
            'TEMPERATURE' => [15, 45],
            'ACTIVE_FIRE' => [0, 50], // only frames the breakdown bar; weight 0 keeps it out of the score
        ]);

        $this->seedActions($index, [
            'green' => 'Low fire-weather risk. Continue routine monitoring.',
            'amber' => 'Elevated fire weather in this LGA: brief land managers and fire services, restrict open burning, and pre-position firefighting resources near high-value assets.',
            'red' => 'Severe fire weather: issue a public burn ban and fire warning for this LGA, stage suppression crews and equipment forward, and alert communities in the wildland–urban interface.',
        ]);
    }

    /**
     * Harmattan dust storms: airborne mineral dust plus the wind carrying it, sharpened when
     * the air is dry. Pairs with Respiratory Risk. Attached to the Emergency Response and
     * Environment & Air Quality sectors in SectorSeeder.
     */
    private function dustStormRisk(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'DUST_STORM_RISK'],
            [
                'name' => 'Dust Storm Risk Index',
                'description' => 'Airborne dust, wind and dry air as a harmattan dust-storm score — for air-quality, road-safety and respiratory-health warnings.',
            ]
        );

        $this->seedWeights($index, [
            'DUST' => 0.6,        // measured dust concentration (signal default)
            'WIND_SPEED' => 0.3,  // wind mobilising and transporting dust (signal default)
            'HUMIDITY' => 0.1,    // drier air, more lofting (signal default: low is worse)
        ]);

        $this->seedBounds($index, [
            'DUST' => [0, 500],
            'WIND_SPEED' => [0, 40],
            'HUMIDITY' => [0, 100],
        ]);

        $this->seedActions($index, [
            'green' => 'No dust-storm concern. Continue routine air-quality monitoring.',
            'amber' => 'Dust levels rising in this LGA: issue an air-quality advisory for outdoor workers, schools and people with respiratory conditions, and warn drivers of possible reduced visibility.',
            'red' => 'Severe dust storm likely: issue a public health warning (masks, stay indoors), advise against non-essential road travel, alert airports and health facilities, and brief the state ministry of health on the expected respiratory-case rise.',
        ]);
    }

    /**
     * Clarity Pass E1 — the dry-season water-availability view. The physical water balance for
     * human water supply, distinct from Drought Risk (rainfall + vegetation, framed for crops):
     * how much surface water an LGA normally has, against how much rain and soil moisture it has
     * now and how hard the atmosphere is pulling water back out. All four signals are already
     * ingested for other indices — no new ingestion, config only. Attached to the Water &
     * Sanitation sector in SectorSeeder.
     *
     * STANDING_WATER and RAINFALL default to "higher is worse" (flood / disease framing); this
     * index inverts both — less water available is worse.
     */
    private function drySeasonWaterStress(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'DRY_SEASON_WATER_STRESS'],
            [
                'name' => 'Dry-Season Water Stress Index',
                'description' => 'Surface water, rainfall, soil moisture and evaporation demand as a dry-season water-availability score — for water boards and WASH planners deciding where boreholes, trucking and rationing will be needed first.',
            ]
        );

        $this->seedWeights($index, [
            'RAINFALL' => ['weight' => 0.35, 'higher_is_worse' => false],        // less recent rain, more stress
            'STANDING_WATER' => ['weight' => 0.25, 'higher_is_worse' => false],  // less surface water, more stress
            'SOIL_MOISTURE' => ['weight' => 0.2, 'higher_is_worse' => false],    // drier ground, less recharge
            'EVAPOTRANSPIRATION' => 0.2,                                          // higher demand pulling water away (signal default)
        ]);

        // Same climatologically-plausible ranges the water and agriculture indices already use
        // for these signals. Not calibrated against measured borehole yields or reservoir levels.
        $this->seedBounds($index, [
            'RAINFALL' => [0, 200],
            'STANDING_WATER' => [0, 100],
            'SOIL_MOISTURE' => [0.05, 0.40],
            'EVAPOTRANSPIRATION' => [0, 50],
        ]);

        $this->seedActions($index, [
            'green' => 'Water availability is adequate. Continue routine borehole and reservoir monitoring.',
            'amber' => 'Dry-season water stress building in this LGA: check borehole functionality and reservoir levels, plan water-trucking routes, and begin water-conservation messaging.',
            'red' => 'Severe dry-season water stress: prioritise this LGA for water trucking and emergency borehole repair, activate rationing where needed, and brief the state water board and WASH cluster on the settlements most at risk.',
        ]);
    }

    /**
     * Clarity Pass / BUILD_PLAN.md T4 — the first forecast index. GloFAS river-discharge
     * forecast (RIVER_DISCHARGE, forecast lane) against each LGA's normal-flow range: a 3-to-14
     * day heads-up on river flooding. `is_forecast` marks it so scores:calculate skips it and
     * scores:forecast owns it (reading region_forecast_signals, writing region_forecast_scores).
     * The score is the PEAK forecast day within the window, with the lead time to it.
     *
     * Not a weighted blend — one signal at weight 1.0, so the score is simply the peak day's
     * discharge normalised against the calibration bounds. Bounds are an uncalibrated system
     * placeholder; per-region bounds derived from each LGA's observed discharge history are the
     * obvious fast-follow (there is no discharge history yet on a fresh install). Attached to
     * Water & Sanitation and Emergency Response in SectorSeeder.
     */
    private function riverineFloodForecast(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'RIVERINE_FLOOD_FORECAST'],
            [
                'name' => 'Riverine Flood Forecast',
                'description' => 'GloFAS river-discharge forecast against each LGA\'s normal flow — a 3-to-14-day heads-up on river flooding, for emergency and water agencies pre-positioning ahead of displacement.',
                'is_forecast' => true,
            ]
        );

        $this->seedWeights($index, [
            'RIVER_DISCHARGE' => 1.0, // higher forecast flow = higher risk (signal default)
        ]);

        // Uncalibrated system placeholder — a big Nigerian river in flood runs into the low
        // thousands of m³/s. Per-region bounds from observed history are the fast-follow.
        $this->seedBounds($index, [
            'RIVER_DISCHARGE' => [0, 4000],
        ]);

        $this->seedActions($index, [
            'green' => 'No river-flood signal. Continue routine monitoring of the river gauge and forecast.',
            'amber' => 'A river-flood warning is forecast for this LGA in the coming days: brief the LGA emergency committee, check evacuation routes and shelter readiness, and warn riverside and low-lying settlements.',
            'red' => 'Severe river flooding is forecast for this LGA: activate the flood-response plan now while there is lead time — pre-position boats, relief materials and shelter, move people and livestock off the floodplain, and brief the state emergency agency and downstream LGAs.',
        ]);
    }

    /**
     * Respiratory Risk started as PM2.5 + PM10 only. This folds in the gaseous pollutants
     * (ground-level ozone, NO₂) and CAMS mineral dust — all from the same Open-Meteo Air
     * Quality pull — and rebalances the PM weights down to make room.
     *
     * The PM rebalance is the one place this seeder edits an existing config, so it does so
     * only when the weight is still exactly the original default (PM2.5 0.6 / PM10 0.4) — an
     * admin-tuned value is left untouched. The new signal rows go in via firstOrCreate like
     * everything else here.
     */
    private function deepenRespiratoryRisk(): void
    {
        $index = ScoringIndex::query()->where('code', 'RESPIRATORY_RISK')->first();

        if (! $index) {
            return;
        }

        // Migrate the original defaults only. On a fresh full seed these rows don't exist yet
        // (ReferenceDataSeeder::seedScoringConfigs runs after this) — it's a no-op then, and
        // that seeder writes the new 0.4 / 0.2 baseline directly.
        foreach (['AIR_QUALITY_PM25' => [0.6, 0.4], 'AIR_QUALITY_PM10' => [0.4, 0.2]] as $signalCode => [$oldDefault, $rebalanced]) {
            $signalTypeId = SignalType::query()->where('code', $signalCode)->value('signal_type_id');

            RegionScoringConfig::query()
                ->where('index_id', $index->index_id)
                ->whereNull('region_id')
                ->where('signal_type_id', $signalTypeId)
                ->where('weight', $oldDefault)
                ->update(['weight' => $rebalanced]);
        }

        $this->seedWeights($index, [
            'OZONE' => 0.15,
            'NO2' => 0.1,
            'DUST' => 0.15,
        ]);

        $this->seedBounds($index, [
            // WHO / EPA "very unhealthy" reference points, not epidemiologically calibrated.
            'OZONE' => [0, 300],
            'NO2' => [0, 200],
            'DUST' => [0, 500],
        ]);
    }

    /**
     * @param  array<string, float|array{weight: float, higher_is_worse: ?bool}>  $weights
     */
    private function seedWeights(ScoringIndex $index, array $weights): void
    {
        foreach ($weights as $signalCode => $config) {
            $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
            $weight = is_array($config) ? $config['weight'] : $config;
            $higherIsWorse = is_array($config) ? ($config['higher_is_worse'] ?? null) : null;

            RegionScoringConfig::query()->firstOrCreate(
                [
                    'index_id' => $index->index_id,
                    'region_id' => null,
                    'signal_type_id' => $signalType->signal_type_id,
                ],
                ['weight' => $weight, 'higher_is_worse' => $higherIsWorse, 'enabled' => true]
            );
        }
    }

    // Bounds set from a cited public reference rather than a plain guess (see docs/MODEL.md).
    private const CITED_BOUNDS = [
        'OZONE' => 'WHO / US EPA "very unhealthy" ozone reference point.',
        'NO2' => 'WHO / US EPA "very unhealthy" NO₂ reference point.',
        'DUST' => 'WHO / US EPA "very unhealthy" particulate reference point.',
    ];

    /**
     * @param  array<string, array{0: int|float, 1: int|float}>  $bounds
     */
    private function seedBounds(ScoringIndex $index, array $bounds): void
    {
        foreach ($bounds as $signalCode => [$min, $max]) {
            $cited = self::CITED_BOUNDS[$signalCode] ?? null;

            foreach (['MIN' => $min, 'MAX' => $max] as $suffix => $value) {
                ScoringCalibrationParameter::query()->firstOrCreate(
                    ['index_id' => $index->index_id, 'region_id' => null, 'parameter_key' => "{$signalCode}_{$suffix}"],
                    [
                        'parameter_value' => $value,
                        'source_reference' => $cited ?? 'Uncalibrated placeholder — tune once historical case data is available.',
                        'calibration_status' => $cited ? 'reference' : 'placeholder',
                    ]
                );
            }
        }
    }

    /**
     * @param  array<string, string>  $bands
     */
    private function seedActions(ScoringIndex $index, array $bands): void
    {
        foreach ($bands as $band => $text) {
            IndexActionRecommendation::query()->firstOrCreate(
                ['index_id' => $index->index_id, 'risk_band' => $band],
                ['action_text' => $text]
            );
        }
    }
}
