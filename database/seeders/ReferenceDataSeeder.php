<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\IndexActionRecommendation;
use App\Models\Region;
use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\SignalType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSignalTypes();
        $this->seedIndices();
        $this->seedSectors();
        $this->seedActionRecommendations();
        $this->seedRegions();
        $this->seedCalibrationParameters();
        $this->seedScoringConfigs();
        $this->seedAgencies();
    }

    private function seedSignalTypes(): void
    {
        $types = [
            ['code' => 'RAINFALL', 'name' => 'Rainfall', 'unit' => 'mm', 'source' => 'NASA POWER / CHIRPS', 'higher_is_worse' => true],
            ['code' => 'STANDING_WATER', 'name' => 'Standing Water', 'unit' => '%', 'source' => 'JRC Global Surface Water / Sentinel-2 NDWI', 'higher_is_worse' => true],
            ['code' => 'TEMPERATURE', 'name' => 'Temperature', 'unit' => '°C', 'source' => 'NASA POWER / ERA5', 'higher_is_worse' => true],
            ['code' => 'VEGETATION', 'name' => 'Vegetation / Humidity', 'unit' => 'NDVI', 'source' => 'MODIS', 'higher_is_worse' => true],
            ['code' => 'POPULATION_EXPOSURE', 'name' => 'Population Exposure', 'unit' => 'people', 'source' => 'UNFPA/US Census Bureau via HDX (2020 LGA-level projection)', 'higher_is_worse' => true],
            // Lower ground floods first — the only inverted signal in the catalogue.
            ['code' => 'ELEVATION', 'name' => 'Elevation / Terrain', 'unit' => 'm', 'source' => 'SRTM', 'higher_is_worse' => false],
            ['code' => 'AIR_QUALITY_PM25', 'name' => 'Fine Particulate Matter (PM2.5)', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
            ['code' => 'AIR_QUALITY_PM10', 'name' => 'Coarse Particulate Matter (PM10)', 'unit' => 'µg/m³', 'source' => 'Open-Meteo Air Quality API (CAMS)', 'higher_is_worse' => true],
        ];

        foreach ($types as $type) {
            SignalType::query()->updateOrCreate(['code' => $type['code']], $type);
        }
    }

    private function seedIndices(): void
    {
        $indices = [
            [
                'code' => 'MALARIA_RISK',
                'name' => 'Malaria Risk Index',
                'description' => 'Rainfall + standing water, weighted for malaria programme officers.',
            ],
            [
                'code' => 'FLOOD_RISK',
                'name' => 'Flood Risk Index',
                'description' => 'Rainfall + standing water + elevation/terrain, for emergency response.',
            ],
            [
                'code' => 'COMPOSITE_PRESSURE',
                'name' => 'Composite Climate-Health Pressure Index',
                'description' => 'All active signals, weighted, for an overall regional snapshot.',
            ],
            [
                'code' => 'HEAT_STRESS_RISK',
                'name' => 'Heat Stress Risk Index',
                'description' => 'Temperature + vegetation loss, for occupational and public heat-health planning.',
            ],
            [
                'code' => 'DROUGHT_RISK',
                'name' => 'Drought Risk Index',
                'description' => 'Rainfall deficit + vegetation stress, for agricultural and water-security planning.',
            ],
            [
                'code' => 'RESPIRATORY_RISK',
                'name' => 'Respiratory Risk Index',
                'description' => 'Fine and coarse particulate matter (PM2.5 + PM10), for air-quality and respiratory-health planning — especially relevant during harmattan/dust season.',
            ],
        ];

        foreach ($indices as $index) {
            ScoringIndex::query()->updateOrCreate(['code' => $index['code']], $index);
        }
    }

    /**
     * Grouping labels over the indices — a user picks the sector(s) matching their job and it
     * expands to the underlying index subscriptions. An index can sit in more than one sector
     * (Flood Risk is relevant to both Water and Emergency Response). New roadmap indices
     * (docs/BUILD_PLAN.md) attach here as they ship — this seed only maps what's live today.
     */
    private function seedSectors(): void
    {
        $sectors = [
            [
                'code' => 'OVERVIEW',
                'name' => 'Overview',
                'description' => 'The at-a-glance cross-cutting snapshot. Pre-selected for everyone.',
                'is_default' => true,
                'indices' => ['COMPOSITE_PRESSURE'],
            ],
            [
                'code' => 'PUBLIC_HEALTH',
                'name' => 'Public Health & Epidemiology',
                'description' => 'Climate-linked disease risk — malaria, respiratory illness, heat-health.',
                'indices' => ['MALARIA_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK'],
            ],
            [
                'code' => 'AGRICULTURE',
                'name' => 'Agriculture & Food Security',
                'description' => 'Rainfall deficit and vegetation stress ahead of a bad season.',
                'indices' => ['DROUGHT_RISK'],
            ],
            [
                'code' => 'EMERGENCY_RESPONSE',
                'name' => 'Emergency Response & Infrastructure',
                'description' => 'Forward signal on which areas are closest to flooding or dangerous heat.',
                'indices' => ['FLOOD_RISK', 'HEAT_STRESS_RISK'],
            ],
            [
                'code' => 'WATER_SANITATION',
                'name' => 'Water, Sanitation & Flooding',
                'description' => 'Standing water and flood risk for WASH and flood-response planning.',
                'indices' => ['FLOOD_RISK'],
            ],
            [
                'code' => 'AIR_ENVIRONMENT',
                'name' => 'Environment & Air Quality',
                'description' => 'Particulate pollution and harmattan dust, refreshed daily.',
                'indices' => ['RESPIRATORY_RISK'],
            ],
        ];

        foreach ($sectors as $order => $definition) {
            $indexCodes = $definition['indices'];
            unset($definition['indices']);

            $sector = Sector::query()->updateOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'sort_order' => $order]
            );

            $pivot = [];
            foreach (array_values($indexCodes) as $indexOrder => $indexCode) {
                $index = ScoringIndex::query()->where('code', $indexCode)->first();
                if ($index) {
                    $pivot[$index->index_id] = ['sort_order' => $indexOrder];
                }
            }

            $sector->indices()->syncWithoutDetaching($pivot);
        }
    }

    /**
     * Rule-based, not AI — a lookup table an officer can act on immediately.
     * Same 34/67 bands as App\Support\RiskBand, applied per index.
     */
    private function seedActionRecommendations(): void
    {
        $actions = [
            'MALARIA_RISK' => [
                'green' => 'No action needed. Continue routine surveillance.',
                'amber' => 'Increase vector surveillance and pre-position rapid diagnostic tests (RDTs) at nearby health facilities.',
                'red' => 'Distribute RDTs and pre-position ACTs (artemisinin-based combination therapies) in this LGA. Notify the state malaria programme within 48 hours.',
            ],
            'FLOOD_RISK' => [
                'green' => 'No action needed. Continue routine monitoring.',
                'amber' => 'Alert the LGA emergency management focal point. Review evacuation routes and shelter capacity.',
                'red' => 'Activate the flood emergency response plan. Pre-position emergency shelter and clean water supplies, and issue a public evacuation advisory.',
            ],
            'COMPOSITE_PRESSURE' => [
                'green' => 'No cross-cutting concern. Continue routine monitoring across all indices.',
                'amber' => 'Review the index breakdown for this region — one or more signals are elevated. Brief the LGA health officer.',
                'red' => 'Convene a multi-sectoral response review (health, water, emergency management) for this LGA within the week.',
            ],
            'HEAT_STRESS_RISK' => [
                'green' => 'No action needed.',
                'amber' => 'Issue a heat advisory to outdoor workers and schools. Confirm health facilities are stocked for heat-related illness.',
                'red' => 'Issue a public heat warning. Open cooling centres where available and suspend non-essential outdoor activity during peak hours.',
            ],
            'DROUGHT_RISK' => [
                'green' => 'No action needed. Continue routine monitoring.',
                'amber' => 'Alert agricultural extension officers and water resource managers. Begin water-conservation messaging.',
                'red' => 'Activate the drought contingency plan: prioritise water rationing for vulnerable communities and notify the state agriculture/water ministry.',
            ],
            'RESPIRATORY_RISK' => [
                'green' => 'No action needed. Continue routine monitoring.',
                'amber' => 'Issue an air-quality advisory for outdoor workers, schools, and people with respiratory conditions. Confirm health facilities are stocked for respiratory complaints.',
                'red' => 'Issue a public air-quality warning. Recommend masks and reduced outdoor exposure, and brief the state ministry of health on the respiratory-case trend to watch for.',
            ],
        ];

        foreach ($actions as $indexCode => $bands) {
            $index = ScoringIndex::query()->where('code', $indexCode)->first();

            if (! $index) {
                continue;
            }

            foreach ($bands as $band => $text) {
                IndexActionRecommendation::query()->updateOrCreate(
                    ['index_id' => $index->index_id, 'risk_band' => $band],
                    ['action_text' => $text]
                );
            }
        }
    }

    private function seedRegions(): void
    {
        // 2006 census (NPC) population figures, rounded. Coordinates are LGA-seat
        // centroids, not administrative boundary centroids — accurate enough for
        // point-based signal ingestion, not for polygon geometry.
        $regions = [
            ['name' => 'Ikeja', 'state' => 'Lagos', 'lga_code' => 'NG-LA-IKJ', 'latitude' => 6.6018, 'longitude' => 3.3515, 'population' => 313196],
            ['name' => 'Yenagoa', 'state' => 'Bayelsa', 'lga_code' => 'NG-BY-YNG', 'latitude' => 4.9247, 'longitude' => 6.2642, 'population' => 352285],
            ['name' => 'Ibadan North', 'state' => 'Oyo', 'lga_code' => 'NG-OY-IBN', 'latitude' => 7.3964, 'longitude' => 3.9167, 'population' => 308119],
            ['name' => 'Kano Municipal', 'state' => 'Kano', 'lga_code' => 'NG-KN-KNM', 'latitude' => 12.0022, 'longitude' => 8.5920, 'population' => 365525],
            ['name' => 'Port Harcourt', 'state' => 'Rivers', 'lga_code' => 'NG-RV-PHC', 'latitude' => 4.8156, 'longitude' => 7.0498, 'population' => 541115],
            ['name' => 'Maiduguri', 'state' => 'Borno', 'lga_code' => 'NG-BO-MDG', 'latitude' => 11.8333, 'longitude' => 13.1500, 'population' => 540016],
            ['name' => 'Sokoto North', 'state' => 'Sokoto', 'lga_code' => 'NG-SK-SKN', 'latitude' => 13.0627, 'longitude' => 5.2433, 'population' => 227847],
            ['name' => 'Abuja Municipal', 'state' => 'FCT', 'lga_code' => 'NG-FC-AMC', 'latitude' => 9.0765, 'longitude' => 7.3986, 'population' => 778567],
        ];

        foreach ($regions as $region) {
            Region::query()->updateOrCreate(['lga_code' => $region['lga_code']], $region);
        }

        $this->seedRemainingNigerianLgas();
    }

    /**
     * The other 766 of Nigeria's 774 LGAs, from a public open dataset (name, state,
     * coordinates only — no per-LGA population figure is freely available at this
     * granularity, so it's left null rather than fabricated; the UI shows "—" for it).
     * Skips any name+state already seeded above so the 8 hand-curated ones — which do
     * have real population — are never overwritten.
     *
     * These start dormant: ingestion only runs for a region once a user actually adds
     * it to their coverage (see IngestSignalsCommand), so seeding all 774 doesn't mean
     * pulling live data for all 774 every week.
     */
    private function seedRemainingNigerianLgas(): void
    {
        $path = __DIR__.'/data/nigeria_lgas.json';

        if (! file_exists($path)) {
            return;
        }

        $existing = Region::query()->get(['name', 'state'])
            ->map(fn ($r) => strtolower($r->name.'|'.$r->state))
            ->flip();

        // The dataset's own naming doesn't match what's already hand-seeded above: it uses
        // "Federal Capital Territory" where we use "FCT", and just "Abuja" for the area
        // council we seeded as "Abuja Municipal".
        $stateAliases = ['Federal Capital Territory' => 'FCT'];
        $nameAliases = ['Abuja' => 'Abuja Municipal'];

        $lgas = json_decode(file_get_contents($path), true);
        $now = Carbon::now();
        $rows = [];

        foreach ($lgas as $lga) {
            $state = $stateAliases[$lga['state']] ?? $lga['state'];
            $name = $nameAliases[$lga['name']] ?? $lga['name'];
            $key = strtolower($name.'|'.$state);

            if (isset($existing[$key])) {
                continue;
            }

            $rows[] = [
                'lga_code' => "NG-{$lga['state_code']}-{$lga['id']}",
                'name' => $lga['name'],
                'state' => $state,
                'latitude' => $lga['latitude'],
                'longitude' => $lga['longitude'],
                'population' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One bulk upsert instead of ~766 individual queries — this runs on every test
        // that seeds reference data, so query count here isn't free.
        if ($rows !== []) {
            DB::table('regions')->upsert($rows, ['lga_code'], ['name', 'state', 'latitude', 'longitude', 'updated_at']);
        }
    }

    /**
     * System-wide (region_id = null) normalization bounds, one pair per signal per index.
     * Uncalibrated placeholders — the brief asks these be tunable without code changes once
     * real historical data is available, which is exactly what this table is for.
     *
     * None of these are sourced from an epidemiological study or a health-outcomes dataset —
     * be honest about that rather than implying clinical rigor that doesn't exist yet. What
     * grounds each one, specifically:
     *   - VEGETATION (-1 to 1): a genuine scientific standard — NDVI is mathematically defined
     *     on that range, this isn't a guess.
     *   - RAINFALL, TEMPERATURE, ELEVATION: climatologically/geographically plausible for
     *     Nigeria (realistic weekly rainfall, realistic temperature swing, realistic terrain
     *     height) — sane engineering defaults, not empirically validated against case data.
     *   - STANDING_WATER (0-100): just the natural range of a percentage, not a chosen bound.
     *   - POPULATION_EXPOSURE (0-3,500,000): grounded in the real imported data, not a guess —
     *     set just above the actual observed max across all 774 LGAs (Alimosho, ~3.5M) once
     *     population:import ran. Still not epidemiologically calibrated (a bigger population
     *     isn't linearly "worse" the way this normalizes it), just an honest range instead of
     *     an arbitrary one.
     * Real calibration means correlating these signals against historical case data (Malaria
     * Atlas Project, DHS/MIS, NEMA flood records, ...) and is still to be done — see
     * docs/INGESTION_GUIDE.md.
     */
    private function seedCalibrationParameters(): void
    {
        $bounds = [
            'RAINFALL' => ['min' => 0, 'max' => 200],
            'STANDING_WATER' => ['min' => 0, 'max' => 100],
            'TEMPERATURE' => ['min' => 15, 'max' => 45],
            'VEGETATION' => ['min' => -1, 'max' => 1],
            'POPULATION_EXPOSURE' => ['min' => 0, 'max' => 3500000],
            'ELEVATION' => ['min' => 0, 'max' => 500],
            // US EPA AQI "Hazardous" ceiling (AQI 500) for each pollutant — a real, citable
            // reference point rather than an arbitrary number.
            'AIR_QUALITY_PM25' => ['min' => 0, 'max' => 500.4],
            'AIR_QUALITY_PM10' => ['min' => 0, 'max' => 604],
        ];

        foreach (ScoringIndex::all() as $index) {
            foreach ($bounds as $signalCode => $range) {
                foreach (['MIN' => $range['min'], 'MAX' => $range['max']] as $suffix => $value) {
                    ScoringCalibrationParameter::query()->updateOrCreate(
                        ['index_id' => $index->index_id, 'region_id' => null, 'parameter_key' => "{$signalCode}_{$suffix}"],
                        ['parameter_value' => $value, 'source_reference' => 'Uncalibrated placeholder — tune once historical case data is available.']
                    );
                }
            }
        }
    }

    /**
     * System-wide (region_id = null) signal weightings per named index. A region can override
     * any of these by inserting its own row with the same index_id/signal_type_id and a
     * non-null region_id — no code change required.
     *
     * Each signal entry is either a bare weight (use the signal's default direction from
     * signal_types.higher_is_worse) or ['weight' => ..., 'higher_is_worse' => ...] to override
     * direction for this index specifically — e.g. rainfall is bad-when-high for Flood/Malaria
     * but bad-when-low for Drought.
     */
    private function seedScoringConfigs(): void
    {
        $weights = [
            'MALARIA_RISK' => ['RAINFALL' => 0.5, 'STANDING_WATER' => 0.5],
            'FLOOD_RISK' => ['RAINFALL' => 0.4, 'STANDING_WATER' => 0.4, 'ELEVATION' => 0.2],
            'COMPOSITE_PRESSURE' => [
                'RAINFALL' => 0.25,
                'STANDING_WATER' => 0.25,
                'TEMPERATURE' => 0.2,
                'VEGETATION' => 0.15,
                'POPULATION_EXPOSURE' => 0.15,
            ],
            'HEAT_STRESS_RISK' => [
                'TEMPERATURE' => 0.7,
                'VEGETATION' => ['weight' => 0.3, 'higher_is_worse' => false], // less vegetation, less shade/cooling
            ],
            'DROUGHT_RISK' => [
                'RAINFALL' => ['weight' => 0.5, 'higher_is_worse' => false], // less rain, more drought
                'VEGETATION' => ['weight' => 0.5, 'higher_is_worse' => false], // lower NDVI, more vegetation stress
            ],
            // PM2.5 weighted higher — finer particulate, the more health-critical of the two per
            // WHO guidance.
            'RESPIRATORY_RISK' => ['AIR_QUALITY_PM25' => 0.6, 'AIR_QUALITY_PM10' => 0.4],
        ];

        foreach ($weights as $indexCode => $signalWeights) {
            $index = ScoringIndex::query()->where('code', $indexCode)->firstOrFail();

            foreach ($signalWeights as $signalCode => $config) {
                $signalType = SignalType::query()->where('code', $signalCode)->firstOrFail();
                $weight = is_array($config) ? $config['weight'] : $config;
                $higherIsWorse = is_array($config) ? $config['higher_is_worse'] : null;

                RegionScoringConfig::query()->updateOrCreate(
                    ['index_id' => $index->index_id, 'region_id' => null, 'signal_type_id' => $signalType->signal_type_id],
                    ['weight' => $weight, 'higher_is_worse' => $higherIsWorse, 'enabled' => true]
                );
            }
        }
    }

    private function seedAgencies(): void
    {
        $agencies = [
            // State ministries of health — one per region actually seeded with hand-curated
            // data above, not all 36+FCT, so the list stays relevant rather than generic.
            ['name' => 'Lagos State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'Kano State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'Rivers State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'Oyo State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'Borno State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'Sokoto State Ministry of Health', 'type' => 'State Ministry of Health'],
            ['name' => 'FCT Health and Human Services Secretariat', 'type' => 'State Ministry of Health'],

            // Emergency response
            ['name' => 'Bayelsa State Emergency Management Agency', 'type' => 'Emergency Response'],
            ['name' => 'Lagos State Emergency Management Agency (LASEMA)', 'type' => 'Emergency Response'],
            ['name' => 'National Emergency Management Agency (NEMA)', 'type' => 'Emergency Response'],

            // Federal public health / environment
            // NCDC gets federal_oversight => true as the reference example for the
            // Cross-agency oversight view — a national body genuinely needs to see across
            // every agency, not just its own configured regions. Any other agency can be
            // flipped on the same way from Admin: Agencies.
            ['name' => 'Nigeria Centre for Disease Control (NCDC)', 'type' => 'Federal Public Health Agency', 'federal_oversight' => true],
            ['name' => 'Federal Ministry of Health and Social Welfare', 'type' => 'Federal Public Health Agency'],
            ['name' => 'National Primary Health Care Development Agency (NPHCDA)', 'type' => 'Federal Public Health Agency'],
            ['name' => 'Federal Ministry of Environment', 'type' => 'Federal Public Health Agency'],
            ['name' => 'Nigerian Meteorological Agency (NiMet)', 'type' => 'Federal Public Health Agency'],

            // NGOs / development partners
            ['name' => 'World Health Organization (WHO) Nigeria', 'type' => 'NGO / Development Partner'],
            ['name' => 'UNICEF Nigeria', 'type' => 'NGO / Development Partner'],
            ['name' => 'Malaria Consortium Nigeria', 'type' => 'NGO / Development Partner'],
            ['name' => 'Society for Family Health (SFH)', 'type' => 'NGO / Development Partner'],
            ['name' => 'Nigerian Red Cross Society', 'type' => 'NGO / Development Partner'],
            ['name' => 'eHealth Africa', 'type' => 'NGO / Development Partner'],

            // Research
            ['name' => 'Nigerian Institute of Medical Research (NIMR)', 'type' => 'Research Institution'],
            ['name' => 'Nigerian Institute for Trypanosomiasis and Onchocerciasis Research (NITOR)', 'type' => 'Research Institution'],
        ];

        foreach ($agencies as $agency) {
            Agency::query()->firstOrCreate(['name' => $agency['name']], $agency);
        }
    }
}
