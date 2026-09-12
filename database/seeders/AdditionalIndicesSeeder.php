<?php

namespace Database\Seeders;

use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

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
 * SOIL_MOISTURE, SOIL_TEMPERATURE and EVAPOTRANSPIRATION; Wildfire and Dust Storm Risk need
 * HUMIDITY, WIND_SPEED and DUST; Wildfire also carries a weight-0 ACTIVE_FIRE confirmation
 * series (NASA FIRMS, needs a map key — no-op without one); the Respiratory Risk depth passes
 * add OZONE, NO2, SO2 and CO; Heat Stress Risk's depth pass adds UV_INDEX; Meningitis Risk
 * reuses HUMIDITY, DUST and TEMPERATURE but region_id-scopes them to the belt states rather
 * than seeding a system default (see meningitisRisk()). Their ingestion services are registered
 * in config/ingestion.php and pull on the daily cadence / on region activation — the score
 * simply stays partial (renormalized over the signals present) until those readings land.
 *
 * This seeder also runs several in-place edits — rebalancing the Respiratory Risk PM weights
 * (twice, once for the original ozone/NO2/dust depth pass and again for SO2/CO) and the Heat
 * Stress Risk temperature/vegetation weights (for UV) — each one only touching a weight that's
 * still at its exact prior default, so an admin-tuned value is never overwritten either way.
 *
 * Called TWICE from ReferenceDataSeeder::run() — once in its normal position (everything here
 * except meningitisRisk()'s region attachment only needs indices/signal types, not actual
 * Region rows) and once after seedRegions() (meningitisRisk() needs real regions to scope its
 * per-LGA weights to). Fully idempotent either way.
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
        $this->meningitisRisk();
        $this->deepenRespiratoryRiskWithSo2AndCo();
        $this->deepenHeatStressWithUv();
        $this->plantingWindowRisk();
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
            ['code' => 'SO2', 'name' => 'Sulphur Dioxide', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
            ['code' => 'CO', 'name' => 'Carbon Monoxide', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
            ['code' => 'UV_INDEX', 'name' => 'UV Index', 'unit' => 'index', 'source' => 'Open-Meteo Archive API (ERA5, daily max)', 'higher_is_worse' => true],
            ['code' => 'SOIL_TEMPERATURE', 'name' => 'Soil Temperature (Surface)', 'unit' => '°C', 'source' => 'Open-Meteo Archive API (ERA5-Land, 0–7 cm)', 'higher_is_worse' => true],
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
     * Deepens Respiratory Risk a second time with SO2 and CO — traffic/generator-exhaust
     * markers, same Open-Meteo Air Quality pull as the rest of the pollutant series. Rebalances
     * the PM weights down again to make room (0.4/0.2 -> 0.35/0.15), the same "only touch it if
     * it's still at the exact prior default" guard as deepenRespiratoryRisk() above, so an
     * admin-tuned value is left alone either way.
     *
     * Deliberately weighted lower than the other pollutants: ambient SO2 and CO in this
     * platform's data are annual/reanalysis averages, and both are more a near-source /
     * indoor-exposure hazard than a broad-area one at that resolution — a real signal, not the
     * dominant one. Bounds are an engineering placeholder (unlike OZONE/NO2/DUST's cited WHO/EPA
     * points above) — converting the EPA AQI's SO2/CO breakpoints (ppb / ppm) to this API's
     * µg/m³ output needs a temperature/pressure assumption not worth guessing at here.
     */
    private function deepenRespiratoryRiskWithSo2AndCo(): void
    {
        $index = ScoringIndex::query()->where('code', 'RESPIRATORY_RISK')->first();

        if (! $index) {
            return;
        }

        foreach (['AIR_QUALITY_PM25' => [0.4, 0.35], 'AIR_QUALITY_PM10' => [0.2, 0.15]] as $signalCode => [$oldDefault, $rebalanced]) {
            $signalTypeId = SignalType::query()->where('code', $signalCode)->value('signal_type_id');

            RegionScoringConfig::query()
                ->where('index_id', $index->index_id)
                ->whereNull('region_id')
                ->where('signal_type_id', $signalTypeId)
                ->where('weight', $oldDefault)
                ->update(['weight' => $rebalanced]);
        }

        $this->seedWeights($index, [
            'SO2' => 0.05,
            'CO' => 0.05,
        ]);

        $this->seedBounds($index, [
            'SO2' => [0, 350],
            'CO' => [0, 10000],
        ]);
    }

    /**
     * Deepens Heat Stress Risk with UV index — the "occupational / skin-eye advisories" use
     * case BUILD_PLAN.md flags for this signal fits Heat Stress better than a standalone index:
     * both are about outdoor exposure timing, and UV and temperature peak together in Nigeria's
     * dry season. Same rebalance-only-if-still-at-the-prior-default guard as the respiratory
     * deepenings, so an admin-tuned weight is left alone.
     */
    private function deepenHeatStressWithUv(): void
    {
        $index = ScoringIndex::query()->where('code', 'HEAT_STRESS_RISK')->first();

        if (! $index) {
            return;
        }

        foreach (['TEMPERATURE' => [0.7, 0.6], 'VEGETATION' => [0.3, 0.25]] as $signalCode => [$oldDefault, $rebalanced]) {
            $signalTypeId = SignalType::query()->where('code', $signalCode)->value('signal_type_id');

            RegionScoringConfig::query()
                ->where('index_id', $index->index_id)
                ->whereNull('region_id')
                ->where('signal_type_id', $signalTypeId)
                ->where('weight', $oldDefault)
                ->update(['weight' => $rebalanced]);
        }

        $this->seedWeights($index, [
            'UV_INDEX' => 0.15,
        ]);

        $this->seedBounds($index, [
            'UV_INDEX' => [0, 11],
        ]);
    }

    /**
     * BUILD_PLAN.md T3 — "a possible later add" once SOIL_TEMPERATURE existed. Distinct from
     * Agriculture Stress (ongoing crop water-balance stress): this one is about *timing* —
     * whether conditions right now favour germination and seedling survival if a farmer plants
     * this week.
     *
     * Soil temperature is genuinely U-shaped for germination (too cold OR too hot both hurt),
     * but the scoring engine only supports a monotonic higher/lower-is-worse normalisation. In
     * Nigeria's tropical/Sahel climate the cold side of that curve essentially never binds —
     * the real risk is excess heat desiccating a seedling before it establishes — so this
     * deliberately scores hotter soil as worse rather than modelling the full curve. A
     * documented simplification, not an oversight; worth revisiting if this expands to a
     * climate zone where cold-limited germination is a real constraint.
     *
     * Attached to the Agriculture sector in SectorSeeder.
     */
    private function plantingWindowRisk(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'PLANTING_WINDOW_RISK'],
            [
                'name' => 'Planting Window Risk Index',
                'description' => 'Surface soil temperature against rainfall and soil moisture — whether conditions this week favour germination and seedling survival, for extension officers advising on planting timing.',
            ]
        );

        $this->seedWeights($index, [
            'SOIL_TEMPERATURE' => 0.4,                                        // hotter surface soil, more germination/seedling risk (signal default)
            'RAINFALL' => ['weight' => 0.3, 'higher_is_worse' => false],      // less rain, worse for germination
            'SOIL_MOISTURE' => ['weight' => 0.3, 'higher_is_worse' => false], // drier soil, worse for germination
        ]);

        // ERA5-Land surface (0-7cm) soil temperature in Nigeria's growing season plausibly
        // ranges ~20-45°C. Uncalibrated placeholder, like every other bound in this file.
        $this->seedBounds($index, [
            'SOIL_TEMPERATURE' => [20, 45],
            'RAINFALL' => [0, 200],
            'SOIL_MOISTURE' => [0.05, 0.40],
        ]);

        $this->seedActions($index, [
            'green' => 'Conditions favour planting this week. No action needed beyond routine advisory.',
            'amber' => 'Marginal planting conditions in this LGA: advise farmers to monitor soil moisture and consider a short delay if rain is forecast soon.',
            'red' => 'Poor planting conditions: advise farmers to hold off planting in this LGA until soil temperature eases and moisture improves — early planting now risks poor germination and seedling loss.',
        ]);
    }

    /**
     * BUILD_PLAN.md T2 — the last item on the "Ready" list, and the last new index this
     * platform needs before the roadmap moves to workstreams that need outside data
     * (validation, a licensed climate feed, coastal inputs). HUMIDITY and DUST were already
     * live for Wildfire/Dust Storm Risk, TEMPERATURE from day one — no new ingestion.
     *
     * Deliberately region_id-scoped, not a system default: meningitis risk in Nigeria tracks
     * the Sahel dry-season belt, not the whole country, so a region_id=null row here would
     * light this index up nationwide. No config rows for a region means
     * WeightedFormulaScoringStrategy's totalWeight stays at 0 and the index simply doesn't
     * score there — the same "doesn't apply here" behaviour GloFAS-less LGAs already show for
     * Riverine Flood Forecast, not a special case.
     *
     * The belt-state list itself is a placeholder, same honesty caveat as every weight and
     * bound in this file: narrowed to the Sudano-Sahelian core states most consistently cited
     * in WHO's African meningitis belt / NCDC's Nigeria meningitis surveillance classification,
     * rather than the wider set some broader definitions include. Worth a public-health
     * reviewer's sign-off before this is treated as more than an engineering starting point.
     */
    private function meningitisRisk(): void
    {
        $index = ScoringIndex::query()->updateOrCreate(
            ['code' => 'MENINGITIS_RISK'],
            [
                'name' => 'Meningitis Risk Index',
                'description' => 'Dry air, airborne dust and heat across the Sahel dry season — a bacterial-meningitis risk score for the belt states, for timing reactive vaccination campaigns and case-detection readiness.',
            ]
        );

        // Global (region_id null) bounds — resolved for every belt LGA via the same
        // system-wide fallback every other index uses (NormalisesSignals::calibrationBounds).
        $this->seedBounds($index, [
            'HUMIDITY' => [0, 100],
            'DUST' => [0, 500],
            'TEMPERATURE' => [15, 45],
        ]);

        $this->seedActions($index, [
            'green' => 'No action needed. Continue routine dry-season surveillance.',
            'amber' => 'Alert the state epidemiologist and meningitis focal point for this LGA: dry-season conditions favouring meningococcal spread are building. Review reactive-vaccine stock and cold-chain readiness.',
            'red' => 'High meningitis-risk conditions: activate enhanced case-based surveillance in this LGA, confirm reactive-vaccination readiness with the state and NCDC, and brief health facilities to lower their suspicion threshold for meningitis-like presentations.',
        ]);

        $beltStates = [
            'Sokoto', 'Zamfara', 'Katsina', 'Kebbi', 'Jigawa', 'Yobe', 'Borno',
            'Bauchi', 'Gombe', 'Kano', 'Kaduna', 'Niger', 'Plateau', 'Adamawa', 'Taraba', 'FCT',
        ];

        $regionIds = Region::query()->whereIn('state', $beltStates)->pluck('region_id');

        $this->seedRegionWeights($index, $regionIds, [
            'HUMIDITY' => ['weight' => 0.4, 'higher_is_worse' => false], // drier air, higher risk
            'DUST' => 0.4,                                               // signal default: more dust, higher risk
            'TEMPERATURE' => 0.2,                                       // signal default: hotter, higher risk
        ]);
    }

    /**
     * Same contract as seedWeights() — firstOrCreate so a re-run never overwrites an
     * admin-tuned weight — but scoped to a specific list of regions rather than the system
     * default (region_id null). Used where an index deliberately shouldn't score everywhere
     * (see meningitisRisk()).
     *
     * @param  Collection<int, int>  $regionIds
     * @param  array<string, float|array{weight: float, higher_is_worse: ?bool}>  $weights
     */
    private function seedRegionWeights(ScoringIndex $index, Collection $regionIds, array $weights): void
    {
        foreach ($weights as $signalCode => $config) {
            $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
            $weight = is_array($config) ? $config['weight'] : $config;
            $higherIsWorse = is_array($config) ? ($config['higher_is_worse'] ?? null) : null;

            foreach ($regionIds as $regionId) {
                RegionScoringConfig::query()->firstOrCreate(
                    [
                        'index_id' => $index->index_id,
                        'region_id' => $regionId,
                        'signal_type_id' => $signalType->signal_type_id,
                    ],
                    ['weight' => $weight, 'higher_is_worse' => $higherIsWorse, 'enabled' => true]
                );
            }
        }
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
        'UV_INDEX' => 'WHO/WMO Global Solar UV Index scale (0–11+, "Extreme" category).',
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
