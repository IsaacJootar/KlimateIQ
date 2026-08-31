<?php

namespace Tests\Feature\Admin;

use App\Models\RegionScoringConfig;
use App\Models\ScoringCalibrationParameter;
use App\Models\ScoringIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringConfigTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => 'PLATFORM_ADMIN'])->save();

        return $user;
    }

    public function test_non_admins_cannot_view_scoring_config(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.scoring.index'))->assertForbidden();
    }

    public function test_admins_see_weights_and_calibration_bounds_for_an_index(): void
    {
        $admin = $this->admin();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('admin.scoring.index', ['index' => $index->index_id]));

        $response->assertOk();
        $response->assertSee('Rainfall');
        $response->assertSee('Standing Water');
        $response->assertSee('RAINFALL');
        // Calibration bounds are seeded for every signal code, including ones this
        // index doesn't weight at all (e.g. Elevation isn't part of Malaria Risk).
        $response->assertSee('ELEVATION');
    }

    public function test_updating_persists_weights_and_enabled_flags(): void
    {
        $admin = $this->admin();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $configs = RegionScoringConfig::query()->where('index_id', $index->index_id)->whereNull('region_id')->get()->keyBy('signal_type_id');

        $payload = [
            'weight' => [],
            'enabled' => [],
            'calibration_min' => [],
            'calibration_max' => [],
        ];

        foreach ($configs as $signalTypeId => $config) {
            $payload['weight'][$signalTypeId] = 0.75;
        }
        // Only mark the first one enabled — the second's absence from the array must
        // translate to enabled = false, the same as an unchecked checkbox would submit.
        $payload['enabled'][$configs->keys()->first()] = '1';

        foreach (ScoringCalibrationParameter::query()->where('index_id', $index->index_id)->whereNull('region_id')->get() as $param) {
            $code = str($param->parameter_key)->beforeLast('_')->value();
            $suffix = str($param->parameter_key)->afterLast('_')->value();
            $payload[$suffix === 'MIN' ? 'calibration_min' : 'calibration_max'][$code] = $suffix === 'MIN' ? 1 : 999;
        }

        $response = $this->actingAs($admin)->put(route('admin.scoring.update', $index), $payload);

        $response->assertRedirect();

        $updated = RegionScoringConfig::query()->where('index_id', $index->index_id)->whereNull('region_id')->get()->keyBy('signal_type_id');
        foreach ($updated as $signalTypeId => $config) {
            $this->assertEquals(0.75, (float) $config->weight);
            $this->assertSame($signalTypeId === $configs->keys()->first(), $config->enabled);
        }

        $rainfallMax = ScoringCalibrationParameter::query()
            ->where('index_id', $index->index_id)
            ->whereNull('region_id')
            ->where('parameter_key', 'RAINFALL_MAX')
            ->firstOrFail();
        $this->assertEquals(999, (float) $rainfallMax->parameter_value);
    }

    public function test_calibration_max_must_be_greater_than_min(): void
    {
        $admin = $this->admin();
        $index = ScoringIndex::query()->where('code', 'MALARIA_RISK')->firstOrFail();
        $configs = RegionScoringConfig::query()->where('index_id', $index->index_id)->whereNull('region_id')->get();

        $payload = [
            'weight' => $configs->mapWithKeys(fn ($c) => [$c->signal_type_id => $c->weight])->all(),
            'enabled' => $configs->mapWithKeys(fn ($c) => [$c->signal_type_id => '1'])->all(),
            'calibration_min' => [],
            'calibration_max' => [],
        ];

        foreach (ScoringCalibrationParameter::query()->where('index_id', $index->index_id)->whereNull('region_id')->get() as $param) {
            $code = str($param->parameter_key)->beforeLast('_')->value();
            $suffix = str($param->parameter_key)->afterLast('_')->value();
            $payload[$suffix === 'MIN' ? 'calibration_min' : 'calibration_max'][$code] = $suffix === 'MIN' ? 100 : 50;
        }

        $this->actingAs($admin)->put(route('admin.scoring.update', $index), $payload)->assertStatus(422);
    }
}
