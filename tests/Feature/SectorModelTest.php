<?php

namespace Tests\Feature;

use App\Models\ScoringIndex;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserSectorSubscription;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M1 of the sector-first workspace: the data model only, no UI. Sectors are a grouping label
 * over the existing indices — see docs/ROADMAP_SECTORS.md and docs/BUILD_PLAN.md.
 */
class SectorModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_seeder_creates_the_expected_sectors_in_order(): void
    {
        $this->assertSame(
            ['OVERVIEW', 'PUBLIC_HEALTH', 'AGRICULTURE', 'EMERGENCY_RESPONSE', 'WATER_SANITATION', 'AIR_ENVIRONMENT'],
            Sector::query()->orderBy('sort_order')->pluck('code')->all(),
        );
    }

    public function test_exactly_one_sector_is_the_default(): void
    {
        $defaults = Sector::query()->where('is_default', true)->pluck('code');

        $this->assertSame(['OVERVIEW'], $defaults->all());
    }

    public function test_sector_maps_to_its_indices(): void
    {
        $health = Sector::query()->where('code', 'PUBLIC_HEALTH')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['MALARIA_RISK', 'WATERBORNE_DISEASE_RISK', 'RESPIRATORY_RISK', 'HEAT_STRESS_RISK'],
            $health->indices->pluck('code')->all(),
        );
    }

    public function test_an_index_can_belong_to_more_than_one_sector(): void
    {
        $flood = ScoringIndex::query()->where('code', 'FLOOD_RISK')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['EMERGENCY_RESPONSE', 'WATER_SANITATION'],
            $flood->sectors->pluck('code')->all(),
        );
    }

    public function test_reseeding_does_not_duplicate_sectors_or_mappings(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(6, Sector::query()->count());
        $this->assertSame(
            4,
            Sector::query()->where('code', 'PUBLIC_HEALTH')->firstOrFail()->indices()->count(),
        );
    }

    public function test_a_user_can_subscribe_to_sectors(): void
    {
        $user = User::factory()->create();
        $sector = Sector::query()->where('code', 'AGRICULTURE')->firstOrFail();

        UserSectorSubscription::create(['user_id' => $user->id, 'sector_id' => $sector->sector_id]);

        $this->assertSame(
            ['AGRICULTURE'],
            $user->sectorSubscriptions()->with('sector')->get()->pluck('sector.code')->all(),
        );
    }

    public function test_users_table_has_a_nullable_onboarded_at_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'onboarded_at'));

        $user = User::factory()->create(['onboarded_at' => null]);

        $this->assertNull($user->fresh()->onboarded_at);
    }
}
