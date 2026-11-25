<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendancePunchRejectionPersistenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('attendance_devices')->insert([
            'device_code' => 'PC-TEST-01',
            'name' => 'テスト端末',
            'location_name' => '玄関',
            'device_secret_hash' => Hash::make('secret'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unregistered_card_rejection_is_persisted_and_deduplicated(): void
    {
        $payload = [
            'deviceCode' => 'PC-TEST-01',
            'deviceSecret' => 'secret',
            'cardUid' => 'UNREGISTERED-CARD',
            'occurredAt' => '2026-08-30T08:30:00+09:00',
            'dedupeKey' => 'unregistered-card-punch-1',
            'appVersion' => 'test',
        ];

        $this->postJson('/api/attendance/punch', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CARD_NOT_REGISTERED');
        $this->postJson('/api/attendance/punch', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CARD_NOT_REGISTERED');

        $this->assertDatabaseHas('attendance_events', [
            'card_uid' => 'UNREGISTERED-CARD',
            'receive_status' => 'REJECTED',
            'rejection_reason' => 'CARD_NOT_REGISTERED',
            'dedupe_key' => 'unregistered-card-punch-1',
        ]);
        $this->assertSame(1, DB::table('attendance_events')->count());
    }
}
