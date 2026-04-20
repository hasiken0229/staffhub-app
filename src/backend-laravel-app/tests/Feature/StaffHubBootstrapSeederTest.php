<?php

namespace Tests\Feature;

use Database\Seeders\StaffHubBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StaffHubBootstrapSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_seeder_skips_admin_and_device_when_credentials_are_not_provided(): void
    {
        putenv('STAFFHUB_ADMIN_EMAIL=');
        putenv('STAFFHUB_ADMIN_PASSWORD=');
        putenv('STAFFHUB_DEVICE_CODE=');
        putenv('STAFFHUB_DEVICE_SECRET=');

        Artisan::call('db:seed', [
            '--class' => StaffHubBootstrapSeeder::class,
        ]);

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('attendance_devices')->count());
    }
}
