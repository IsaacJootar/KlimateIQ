<?php

namespace Tests;

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the reference dataset (signal types, indices, ~774 LGAs, calibration, sectors, crop
     * calendar, agencies) ONCE per test run rather than in every feature test's setUp(). The
     * RefreshDatabase trait runs this right after migrating; the seed is committed before the
     * per-test transactions begin, so every test sees it for free. Re-seeding it in setUp() was
     * ~1,200 queries × every test method — most of the suite's wall time.
     */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;
}
