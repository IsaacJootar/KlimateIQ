<?php

namespace Tests\Feature;

use App\Models\Region;
use App\Models\RegionScore;
use App\Models\ScoringIndex;
use App\Services\Ai\AiChatClient;
use App\Services\Ai\RegionScoreSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RegionScoreSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_prompt_includes_risk_band_and_true_contribution_figures(): void
    {
        $region = Region::query()->first();
        $index = ScoringIndex::where('code', 'MALARIA_RISK')->firstOrFail();

        $score = RegionScore::create([
            'region_id' => $region->region_id,
            'index_id' => $index->index_id,
            'period_start' => Carbon::parse('2026-08-03'),
            'period_end' => Carbon::parse('2026-08-09'),
            'score' => 74.0,
            'scoring_strategy' => 'formula',
            'breakdown' => [
                [
                    'signal_type_code' => 'RAINFALL',
                    'signal_type_name' => 'Rainfall',
                    'raw_value' => 150,
                    'unit' => 'mm',
                    'normalized_score' => 75.0,
                    'weight' => 0.5,
                    'contribution_to_final_score' => 44.5,
                ],
                [
                    'signal_type_code' => 'STANDING_WATER',
                    'signal_type_name' => 'Standing Water',
                    'status' => 'no_data',
                ],
            ],
            'calculated_at' => now(),
        ]);

        $capturedPrompt = null;
        $fakeClient = new class($capturedPrompt) implements AiChatClient
        {
            public function __construct(public mixed &$captured) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function message(string $system, string $user, int $maxTokens = 1024): string
            {
                $this->captured = $user;

                return 'A test summary.';
            }

            public function model(): string
            {
                return 'test-model';
            }
        };

        $this->app->instance(AiChatClient::class, $fakeClient);

        $result = app(RegionScoreSummaryService::class)->generate($score);

        $this->assertSame('A test summary.', $result['body']);
        $this->assertStringContainsString('Risk band: red', $fakeClient->captured);
        $this->assertStringContainsString('contribution to final score 44.5', $fakeClient->captured);
        // Clarity Pass A1 — the prompt names signals the way a reader would, not by code.
        $this->assertStringContainsString('- Rainfall:', $fakeClient->captured);
        $this->assertStringNotContainsString('RAINFALL', $fakeClient->captured);
    }
}
