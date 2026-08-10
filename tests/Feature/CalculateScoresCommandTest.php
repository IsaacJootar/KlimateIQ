<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\RegionSignal;
use App\Models\ScoringIndex;
use App\Models\SignalType;
use App\Models\User;
use App\Models\UserRegionSubscription;
use App\Support\IngestionWindow;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage: scores:calculate used to compute its own period window inline instead
 * of sharing IngestionWindow::lastComplete() with ingestion — harmless when both commands ran
 * the same day, but a backlog of ingestion jobs processed a day or two late (their period baked
 * in at dispatch time) could land signals for a period scores:calculate would never look at on
 * a same-day run, silently producing "no data" for regions that actually had fresh signals.
 * Reported live: every previously-scored active region showed a newer null score shadowing an
 * older real one after a delayed queue backlog was drained.
 */
class CalculateScoresCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    private function activate(): Region
    {
        $region = Region::query()->first();
        UserRegionSubscription::create(['user_id' => User::factory()->create()->id, 'region_id' => $region->region_id]);

        return $region->fresh();
    }

    public function test_with_no_period_option_it_uses_the_shared_ingestion_window(): void
    {
        $region = $this->activate();
        $index = ScoringIndex::where('code', 'MALARIA_RISK')->firstOrFail();
        [$periodStart, $periodEnd] = IngestionWindow::lastComplete();

        foreach (['RAINFALL', 'STANDING_WATER'] as $code) {
            $signalType = SignalType::where('code', $code)->firstOrFail();
            RegionSignal::create([
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'value' => 50,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'ingested_at' => now(),
                'source' => 'test',
            ]);
        }

        $this->artisan('scores:calculate', ['--index' => 'MALARIA_RISK', '--region' => $region->region_id])->assertSuccessful();

        $score = RegionScore::where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->where('period_start', $periodStart->toDateString())
            ->first();

        $this->assertNotNull($score);
        $this->assertNotNull($score->score);
    }

    public function test_an_explicit_period_recalculates_that_period_even_if_its_not_current(): void
    {
        $region = $this->activate();
        $index = ScoringIndex::where('code', 'MALARIA_RISK')->firstOrFail();
        $stalePeriodStart = now()->subDays(20)->startOfDay();
        $stalePeriodEnd = $stalePeriodStart->copy()->addDays(6);

        foreach (['RAINFALL', 'STANDING_WATER'] as $code) {
            $signalType = SignalType::where('code', $code)->firstOrFail();
            RegionSignal::create([
                'region_id' => $region->region_id,
                'signal_type_id' => $signalType->signal_type_id,
                'value' => 50,
                'period_start' => $stalePeriodStart,
                'period_end' => $stalePeriodEnd,
                'ingested_at' => now(),
                'source' => 'test',
            ]);
        }

        $this->artisan('scores:calculate', [
            '--index' => 'MALARIA_RISK',
            '--region' => $region->region_id,
            '--period-start' => $stalePeriodStart->toDateString(),
            '--period-end' => $stalePeriodEnd->toDateString(),
        ])->assertSuccessful();

        $score = RegionScore::where('region_id', $region->region_id)
            ->where('index_id', $index->index_id)
            ->where('period_start', $stalePeriodStart->toDateString())
            ->first();

        $this->assertNotNull($score);
        $this->assertNotNull($score->score);
    }

    public function test_period_start_without_period_end_is_rejected(): void
    {
        $this->artisan('scores:calculate', ['--period-start' => '2026-01-01'])->assertFailed();
    }
}
