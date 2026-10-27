<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role, bool $active = true): User
    {
        return User::factory()->create(['staff_id' => 'S' . random_int(1000, 9999), 'role' => $role, 'active' => $active]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/warehouse')->assertRedirect('/login');
        $this->postJson('/warehouse/scan/parse', ['text' => 'x', 'source' => 'qr'])->assertStatus(401);
    }

    public function test_dispatch_cannot_use_warehouse(): void
    {
        $this->actingAs($this->user(Role::Dispatch))->get('/warehouse')->assertForbidden();
    }

    public function test_deactivated_staff_are_blocked(): void
    {
        $this->actingAs($this->user(Role::Staff, false))->get('/warehouse')->assertForbidden();
    }

    public function test_staff_can_parse_a_scan(): void
    {
        $this->actingAs($this->user(Role::Staff))
            ->postJson('/warehouse/scan/parse', ['text' => '{"name":"Ada Obi","phone":"08031234567"}', 'source' => 'qr'])
            ->assertOk()->assertJsonPath('fields.phone', '08031234567');
    }

    public function test_booking_is_idempotent(): void
    {
        $u = $this->user(Role::Staff);
        $payload = [
            'sender' => ['name' => 'A', 'phone' => '08031234567'], 'receiver_name' => 'B', 'receiver_phone' => '08121234567',
            'receiver_address' => '1 Road', 'receiver_city' => 'Kano', 'weight_range' => 'lt1', 'receiver_source' => 'qr', 'description' => 'Documents',
        ];
        $a = $this->actingAs($u)->withHeader('Idempotency-Key', 'k1')->postJson('/warehouse/parcels', $payload)->assertCreated();
        $b = $this->actingAs($u)->withHeader('Idempotency-Key', 'k1')->postJson('/warehouse/parcels', $payload)->assertCreated();
        $this->assertSame($a['tracking_number'], $b['tracking_number']);
        $this->assertDatabaseCount('parcels', 1);
    }
}
