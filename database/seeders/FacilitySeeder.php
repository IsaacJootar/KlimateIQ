<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Clarity Pass D1 — a starter register of well-known real facilities for the eight LGAs
 * KlimateIQ seeds with hand-curated data. Names are drawn from GRID3 Nigeria / widely-known
 * public institutions; the UI always frames them as examples on record, to verify locally.
 * Idempotent (updateOrCreate on region + type + name). More LGAs get added by widening this
 * list or by a proper GRID3 bulk import against the `facilities` table.
 */
class FacilitySeeder extends Seeder
{
    private const SOURCE_YEAR = 2023;

    public function run(): void
    {
        // "LGA name | state" => [ [name, type, category, sort], ... ]
        $data = [
            'Ikeja|Lagos' => [
                ['Lagos State University Teaching Hospital (LASUTH)', 'health', 'Tertiary hospital', 0],
                ['General Hospital, Ikeja', 'health', 'General hospital', 1],
                ['Ojodu Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ['Ikeja Grammar School', 'school', 'Secondary school', 0],
                ['Maryland Comprehensive Secondary School', 'school', 'Secondary school', 1],
                ['Computer Village (Otigba), Ikeja', 'market', 'Trading cluster', 0],
                ['Ikeja Along Market', 'market', 'Open market', 1],
                ['Ikeja Township Stadium', 'shelter', 'Stadium', 0],
                ['Agidingbi Grammar School', 'shelter', 'School (designated shelter)', 1],
                ['Iju Waterworks', 'water_point', 'Water treatment plant', 0],
            ],
            'Kano Municipal|Kano' => [
                ['Murtala Muhammad Specialist Hospital', 'health', 'Specialist hospital', 0],
                ['Kwakwaci Primary Health Centre', 'health', 'Primary Health Centre', 1],
                ['Gwammaja Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ['Rumfa College, Kano', 'school', 'Secondary school', 0],
                ['Government Girls Secondary School, Dala', 'school', 'Secondary school', 1],
                ['Kurmi Market', 'market', 'Central market', 0],
                ['Sabon Gari Market', 'market', 'Central market', 1],
                ['Sani Abacha Stadium, Kofar Mata', 'shelter', 'Stadium', 0],
                ['Challawa Water Treatment Plant', 'water_point', 'Water treatment plant', 0],
                ['Tamburawa Water Works', 'water_point', 'Water treatment plant', 1],
            ],
            'Port Harcourt|Rivers' => [
                ['University of Port Harcourt Teaching Hospital', 'health', 'Tertiary hospital', 0],
                ['Rivers State University Teaching Hospital', 'health', 'Tertiary hospital', 1],
                ['Port Harcourt City Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ['Government Secondary School, Port Harcourt', 'school', 'Secondary school', 0],
                ['Baptist High School, Port Harcourt', 'school', 'Secondary school', 1],
                ['Mile 1 Market', 'market', 'Open market', 0],
                ['Oil Mill Market', 'market', 'Open market', 1],
                ['Civic Centre, Port Harcourt', 'shelter', 'Public hall', 0],
                ['Rumuola Water Scheme', 'water_point', 'Water scheme', 0],
            ],
            'Maiduguri|Borno' => [
                ['University of Maiduguri Teaching Hospital', 'health', 'Tertiary hospital', 0],
                ['State Specialist Hospital, Maiduguri', 'health', 'Specialist hospital', 1],
                ['Bolori Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ["Government Girls' Secondary School, Maiduguri", 'school', 'Secondary school', 0],
                ['Yerwa Central Primary School', 'school', 'Primary school', 1],
                ['Monday Market (Kasuwan Litinin)', 'market', 'Central market', 0],
                ['Baga Road Fish Market', 'market', 'Specialist market', 1],
                ['Bakassi IDP Camp', 'shelter', 'Displacement camp', 0],
                ['Teachers Village IDP Camp', 'shelter', 'Displacement camp', 1],
                ['Alau Dam Water Treatment Plant', 'water_point', 'Water treatment plant', 0],
            ],
            'Ibadan North|Oyo' => [
                ['University College Hospital (UCH), Ibadan', 'health', 'Tertiary hospital', 0],
                ['Adeoyo Maternity Hospital, Yemetu', 'health', 'Maternity hospital', 1],
                ['Sango Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ['Government College, Ibadan', 'school', 'Secondary school', 0],
                ['Loyola College, Ibadan', 'school', 'Secondary school', 1],
                ['Bodija Market', 'market', 'Central market', 0],
                ['Sango Market', 'market', 'Open market', 1],
                ['Lekan Salami Stadium, Adamasingba', 'shelter', 'Stadium', 0],
                ['Eleyele Waterworks', 'water_point', 'Water treatment plant', 0],
            ],
            'Sokoto North|Sokoto' => [
                ['Usmanu Danfodiyo University Teaching Hospital', 'health', 'Tertiary hospital', 0],
                ['Specialist Hospital, Sokoto', 'health', 'Specialist hospital', 1],
                ['Runjin Sambo Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ['Nagarta College, Sokoto', 'school', 'Secondary school', 0],
                ['Sultan Abubakar College, Sokoto', 'school', 'Secondary school', 1],
                ['Sokoto Central Market (Kasuwar Yar Kwara)', 'market', 'Central market', 0],
                ['Giginya Memorial Stadium', 'shelter', 'Stadium', 0],
                ['Sokoto State Water Board Treatment Plant', 'water_point', 'Water treatment plant', 0],
            ],
            'Yenagoa|Bayelsa' => [
                ['Federal Medical Centre, Yenagoa', 'health', 'Tertiary hospital', 0],
                ['Kpansia Primary Health Centre', 'health', 'Primary Health Centre', 1],
                ['Okutukutu Primary Health Centre', 'health', 'Primary Health Centre', 2],
                ['Community Secondary School, Yenagoa', 'school', 'Secondary school', 0],
                ['Bishop Dimieari Grammar School, Yenagoa', 'school', 'Secondary school', 1],
                ['Swali Market', 'market', 'Open market', 0],
                ['Tombia Market', 'market', 'Open market', 1],
                ['Samson Siasia Stadium, Yenagoa', 'shelter', 'Stadium', 0],
                ['Bayelsa State Water Board Scheme', 'water_point', 'Water scheme', 0],
            ],
            'Abuja Municipal|FCT' => [
                ['National Hospital, Abuja', 'health', 'Tertiary hospital', 0],
                ['Asokoro District Hospital', 'health', 'District hospital', 1],
                ['Wuse District Hospital', 'health', 'District hospital', 2],
                ['Government Secondary School, Wuse', 'school', 'Secondary school', 0],
                ['Government Secondary School, Garki', 'school', 'Secondary school', 1],
                ['Wuse Market', 'market', 'Central market', 0],
                ['Garki Market', 'market', 'Central market', 1],
                ['Utako Market', 'market', 'Central market', 2],
                ['Old Parade Ground, Abuja', 'shelter', 'Open assembly ground', 0],
                ['Lower Usuma Dam Water Treatment Plant', 'water_point', 'Water treatment plant', 0],
            ],
        ];

        foreach ($data as $key => $facilities) {
            [$name, $state] = explode('|', $key);
            $region = Region::query()->where('name', $name)->where('state', $state)->first();

            if (! $region) {
                continue;
            }

            foreach ($facilities as [$facilityName, $type, $category, $sort]) {
                Facility::query()->updateOrCreate(
                    ['region_id' => $region->region_id, 'type' => $type, 'name' => $facilityName],
                    [
                        'category' => $category,
                        'state' => $state,
                        'source' => 'GRID3',
                        'source_year' => self::SOURCE_YEAR,
                        'sort_order' => $sort,
                    ]
                );
            }
        }
    }
}
