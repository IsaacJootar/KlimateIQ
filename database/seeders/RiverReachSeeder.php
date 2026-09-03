<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Curated GloFAS sample points on the Niger and Benue for the confluence-corridor LGAs
 * (T4/T5 follow-up — decision 0007). Bundled JSON, idempotent upsert on (region_id, reach) —
 * the same pattern as ReferenceDataSeeder::seedRemainingNigerianLgas(). An LGA not listed keeps
 * scoring at its centroid. Safe to re-run; run on every deploy (deploy.sh).
 */
class RiverReachSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__.'/data/nigeria_river_reaches.json';
        if (! file_exists($path)) {
            return;
        }

        $regionIdByCode = Region::query()->pluck('region_id', 'lga_code');
        $now = Carbon::now();
        $rows = [];

        foreach (json_decode(file_get_contents($path), true) as $reach) {
            $regionId = $regionIdByCode[$reach['lga_code']] ?? null;
            if ($regionId === null) {
                continue;
            }

            $rows[] = [
                'region_id' => $regionId,
                'reach' => $reach['reach'],
                'river' => $reach['river'],
                'latitude' => $reach['latitude'],
                'longitude' => $reach['longitude'],
                'source' => $reach['source'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('river_reaches')->upsert($rows, ['region_id', 'reach'], ['river', 'latitude', 'longitude', 'source', 'updated_at']);
        }
    }
}
