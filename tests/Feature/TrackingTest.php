<?php

namespace Tests\Feature;

use App\Enums\ParcelStatus;
use App\Enums\Role;
use App\Models\User;
use App\Services\ParcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private function bookParcel(): \App\Models\Parcel
    {
        $staff = User::factory()->create(['staff_id' => 'S1', 'role' => Role::Staff]);
        return app(ParcelService::class)->book([
            'sender' => ['name' => 'A', 'phone' => '08031234567'], 'receiver_name' => 'Ada Obi', 'receiver_phone' => '08121234567',
            'receiver_address' => '1 Road', 'receiver_city' => 'Kano', 'receiver_state' => 'Kano', 'weight_range' => 'lt1', 'receiver_source' => 'manual',
        ], $staff, null);
    }

    public function test_homepage_is_the_marketing_site_with_backend_tracker(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('StallionKing Logistics', false)
            ->assertSee('id="tracking-form"', false)
            ->assertSee(route('track.lookup'), false)
            ->assertSee(route('login'), false);
    }

    public function test_tracking_returns_progress_without_personal_details(): void
    {
        $parcel = $this->bookParcel();
        $admin = User::factory()->create(['staff_id' => 'S2', 'role' => Role::Admin]);
        app(ParcelService::class)->advance($parcel->id, ParcelStatus::Received, $admin);

        $res = $this->getJson('/track?tn=' . strtolower($parcel->tracking_number))->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('status', 'received')
            ->assertJsonPath('destination', 'Kano, Kano')
            ->assertJsonPath('steps.0.state', 'done')
            ->assertJsonPath('steps.1.state', 'active')
            ->assertJsonPath('steps.2.state', 'pending');

        $this->assertStringNotContainsString('Ada Obi', $res->getContent());
        $this->assertStringNotContainsString('08121234567', $res->getContent());
    }

    public function test_unknown_tracking_number_is_404(): void
    {
        $this->getJson('/track?tn=STK-NOPE0000')->assertNotFound()->assertJsonPath('found', false);
    }

    public function test_browser_visit_redirects_to_homepage_tracker(): void
    {
        $this->get('/track?tn=STK-ABC')->assertRedirect(route('track', ['tn' => 'STK-ABC']) . '#track');
    }
}
