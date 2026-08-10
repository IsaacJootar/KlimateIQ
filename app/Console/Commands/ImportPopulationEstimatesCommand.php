<?php

namespace App\Console\Commands;

use App\Models\Region;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Fills in regions.population from a real, sourced dataset instead of leaving it null for the
 * 766 LGAs that weren't hand-curated at launch (see ReferenceDataSeeder).
 *
 * Source: UNFPA / US Census Bureau (PEPFAR program), "Nigeria - Subnational Population
 * Statistics," published via the UN's Humanitarian Data Exchange (data.humdata.org/dataset/
 * cod-ps-nga). The admin-2 (LGA) breakdown is only available in that dataset's 2020 file — the
 * newer 2022 release only goes down to state level — so this is a 2020 projection, not the
 * newest number that exists anywhere. It's the most granular, sourced, freely-downloadable
 * figure found after checking WorldPop's stats API (broken when tested), a CIESIN GRID3 image
 * service (unreachable), and every LGA-level GRID3 feature layer on ArcGIS (none carry a raw
 * population field, only derived risk scores) — see docs/INGESTION_GUIDE.md for the full trail.
 *
 * Not a live per-request pull like the other 5 signal sources — population doesn't change on a
 * weekly cadence the way weather does, so re-running this command manually when a newer dataset
 * shows up is the right refresh model, not hitting an external API every week.
 */
class ImportPopulationEstimatesCommand extends Command
{
    protected $signature = 'population:import
        {file : Path to the nga_admpop_2020.xlsx file (sheet nga_admpop_adm2_2020)}
        {--dry-run : Report matches/misses without writing anything}';

    protected $description = 'Import LGA-level population estimates from the UNFPA/HDX Nigeria dataset.';

    private const STATE_ALIASES = [
        'FCT' => 'FCT',
        'FEDERAL CAPITAL TERRITORY' => 'FCT',
    ];

    /**
     * Genuine spelling differences or renamed LGAs — real discrepancies between this dataset's
     * ADM2_NAME and ours, not typos on this side. Every entry here was verified by pulling the
     * actual seeded LGA list for that state and comparing by hand; nothing here is a guess.
     * Punctuation/spacing-only differences (hyphens, slashes, extra spaces) don't need an entry
     * — normalize() below handles those generically.
     */
    private const NAME_ALIASES_BY_STATE = [
        'ABIA' => ['ISIUKWUATO' => 'Isuikwuato', 'OSISIOMA NGWA' => 'Osisioma'],
        'BAYELSA' => ['YENEGOA' => 'Yenagoa'],
        'BENUE' => ['OTURKPO' => 'Otukpo'],
        'CROSS RIVER' => ['BEKWARA' => 'Bekwarra'],
        'EKITI' => ['AIYEKIRE (GBONYIN)' => 'Gbonyin', 'ILEJEMEJI' => 'Ilejemeje'],
        'GOMBE' => ['SHOMGOM' => 'Shongom'],
        'IMO' => ['EZINIHITTE' => 'Ezinihitte Mbaise', 'UNUIMO' => 'Onuimo'],
        'JIGAWA' => ['BIRNI KUDU' => 'Birnin Kudu', 'KIRI KASAMMA' => 'Kiri Kasama'],
        'KADUNA' => ['MARKAFI' => 'Makarfi', 'ZANGO-KATAF' => 'Zangon Kataf'],
        'KANO' => ['GARUM MALLAM' => 'Garun Malam', 'NASSARAWA' => 'Nasarawa'],
        'KEBBI' => ['ALEIRO' => 'Aliero', 'WASAGU/DANKO' => 'Danko-Wasagu'],
        'KOGI' => ['OLAMABOLO' => 'Olamaboro'],
        'LAGOS' => ['IFAKO-IJAYE' => 'Ifako-Ijaiye', 'SHOMOLU' => 'Somolu'],
        'NASARAWA' => ['NASARAWA-EGGON' => 'Nasarawa Egon'],
        'NIGER' => ['MUYA' => 'Munya'],
        'OGUN' => ['EGBADO NORTH' => 'Yewa North', 'EGBADO SOUTH' => 'Yewa South'],
        'OSUN' => ['AIYEDADE' => 'Ayedaade', 'ATAKUMOSA EAST' => 'Atakunmosa East', 'ATAKUMOSA WEST' => 'Atakunmosa West', 'ILESHA EAST' => 'Ilesa East', 'ILESHA WEST' => 'Ilesa West'],
        'OYO' => ['ATIGBO' => 'Atisbo', 'ORELOPE' => 'Oorelope'],
        'PLATEAU' => ['BARIKIN LADI' => 'Barkin Ladi'],
        'RIVERS' => ['OBIA/AKPOR' => 'Obio/Akpor', 'OMUMMA' => 'Omuma'],
        'YOBE' => ['TARMUA' => 'Tarmuwa'],
        'ZAMFARA' => ['BIRNIN MAGAJI' => 'Birnin Magaji/Kiyaw'],
    ];

    /** Lowercase, hyphens/slashes/parens collapsed to spaces, whitespace collapsed — a fallback
     *  match for pure punctuation differences (e.g. "Isiala-Ngwa North" vs "Isiala Ngwa North")
     *  that don't need an explicit alias. */
    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[-\/()]/', ' ', strtolower($value))));
    }

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['nga_admpop_adm2_2020']);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $header = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $header[$col] = $sheet->getCell([$col, 1])->getValue();
        }
        $nameCol = array_search('ADM2_NAME', $header, true);
        $stateCol = array_search('ADM1_NAME', $header, true);
        $totalCol = array_search('T_TL', $header, true);

        if ($nameCol === false || $stateCol === false || $totalCol === false) {
            $this->error('Expected columns (ADM2_NAME, ADM1_NAME, T_TL) not found in the sheet.');

            return self::FAILURE;
        }

        $regions = Region::query()->get(['region_id', 'name', 'state']);
        $exact = $regions->keyBy(fn (Region $r) => strtolower($r->name.'|'.$r->state));
        $normalized = $regions->keyBy(fn (Region $r) => $this->normalize($r->name).'|'.strtolower($r->state));

        $matched = 0;
        $unmatched = [];
        $updates = [];
        $highestRow = $sheet->getHighestRow();

        for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
            $lgaName = trim((string) $sheet->getCell([$nameCol, $rowNum])->getValue());
            $stateName = trim((string) $sheet->getCell([$stateCol, $rowNum])->getValue());
            $total = $sheet->getCell([$totalCol, $rowNum])->getValue();

            if ($lgaName === '' || $total === null) {
                continue;
            }

            $stateKey = strtoupper($stateName);
            $normalizedState = self::STATE_ALIASES[$stateKey] ?? ucwords(strtolower($stateName));
            $aliasedName = self::NAME_ALIASES_BY_STATE[$stateKey][strtoupper($lgaName)] ?? null;

            $region = $aliasedName !== null
                ? $exact->get(strtolower($aliasedName.'|'.$normalizedState))
                : ($exact->get(strtolower($lgaName.'|'.$normalizedState))
                    ?? $normalized->get($this->normalize($lgaName).'|'.strtolower($normalizedState)));

            if ($region === null) {
                $unmatched[] = "{$lgaName} ({$stateName})";

                continue;
            }

            $matched++;
            $updates[$region->region_id] = (int) round((float) $total);
        }

        $this->info("Matched {$matched} of ".($matched + count($unmatched))." dataset rows to seeded regions.");

        if ($unmatched !== []) {
            $this->warn(count($unmatched).' unmatched (left untouched, not overwritten with a guess):');
            foreach ($unmatched as $line) {
                $this->line("  - {$line}");
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        foreach ($updates as $regionId => $population) {
            Region::query()->where('region_id', $regionId)->update(['population' => $population]);
        }

        $this->info("Updated population for {$matched} regions.");

        return self::SUCCESS;
    }
}
