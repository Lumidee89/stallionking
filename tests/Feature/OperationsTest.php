<?php

namespace Tests\Feature;

use App\Enums\ParcelStatus;
use App\Enums\Role;
use App\Mail\ParcelBookedMail;
use App\Mail\TrackingNumberMail;
use App\Models\Parcel;
use App\Models\Rider;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role, array $attrs = []): User
    {
        return User::factory()->create(['staff_id' => 'S' . random_int(1000, 99999), 'role' => $role, 'active' => true] + $attrs);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'sender' => ['name' => 'Amaka Traders', 'phone' => '0803 555 2210', 'email' => 'amaka@example.com', 'city' => 'Lagos'],
            'receiver_name' => 'Bello Musa', 'receiver_phone' => '08121234567', 'receiver_email' => 'bello@example.com',
            'receiver_address' => '22 Zoo Road', 'receiver_city' => 'Kano', 'weight_range' => '1-3',
            'description' => 'Fabrics', 'service_type' => 'Express', 'receiver_source' => 'manual',
        ], $over);
    }

    private function book(User $by, array $over = []): Parcel
    {
        $tn = $this->actingAs($by)->postJson('/warehouse/parcels', $this->payload($over))->assertCreated()['tracking_number'];
        return Parcel::where('tracking_number', $tn)->firstOrFail();
    }

    public function test_admin_adds_staff_who_can_sign_in_and_book_a_parcel(): void
    {
        Mail::fake();
        $admin = $this->user(Role::Admin);

        $this->actingAs($admin)->post('/staff', [
            'name' => 'Tunde Ade', 'staff_id' => 'sk-0002', 'password' => 'a-strong-password', 'role' => 'staff',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/logout');

        $this->post('/login', ['staff_id' => 'SK-0002', 'password' => 'a-strong-password'])->assertRedirect('/warehouse');
        $staff = User::where('staff_id', 'SK-0002')->first();
        $this->assertAuthenticatedAs($staff);

        $res = $this->postJson('/warehouse/parcels', $this->payload(['send_notification' => true]))->assertCreated();
        $res->assertJsonCount(2, 'notifications')->assertJsonPath('notifications.0.delivered', true);
        Mail::assertSent(TrackingNumberMail::class, fn ($m) => $m->hasTo('amaka@example.com'));
        Mail::assertSent(ParcelBookedMail::class, fn ($m) => $m->hasTo('bello@example.com'));
        $parcel = Parcel::first();
        $this->assertSame('Express', $parcel->service_type);
        $this->assertSame('amaka@example.com', $parcel->sender['email']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'parcel_booked', 'actor_id' => $staff->id]);
    }

    public function test_save_only_still_emails_the_receiver_their_parcel_details(): void
    {
        Mail::fake();
        $this->actingAs($this->user(Role::Staff))->postJson('/warehouse/parcels', $this->payload(['send_notification' => false]))
            ->assertCreated()->assertJsonPath('sender_skipped', true)->assertJsonCount(1, 'notifications');
        Mail::assertNotSent(TrackingNumberMail::class);
        Mail::assertSent(ParcelBookedMail::class, 1);
    }

    public function test_no_receiver_email_means_no_receiver_mail(): void
    {
        Mail::fake();
        $this->actingAs($this->user(Role::Staff))->postJson('/warehouse/parcels', $this->payload(['receiver_email' => null, 'send_notification' => false]))
            ->assertCreated()->assertJsonCount(0, 'notifications');
        Mail::assertNothingSent();
    }

    public function test_retried_booking_does_not_email_twice(): void
    {
        Mail::fake();
        $u = $this->user(Role::Staff);
        $this->actingAs($u)->withHeader('Idempotency-Key', 'k9')->postJson('/warehouse/parcels', $this->payload())->assertCreated();
        $this->actingAs($u)->withHeader('Idempotency-Key', 'k9')->postJson('/warehouse/parcels', $this->payload())->assertCreated();
        Mail::assertSent(ParcelBookedMail::class, 1);
    }

    public function test_receiver_email_contains_the_parcel_details(): void
    {
        $parcel = $this->book($this->user(Role::Staff), ['receiver_landmark' => 'Kurmi Market']);
        $html = (new ParcelBookedMail($parcel))->render();
        foreach ([$parcel->tracking_number, 'Bello Musa', '22 Zoo Road, Kano', 'Kurmi Market', 'Fabrics', '1kg', 'Express', 'Amaka Traders, Lagos', 'Track your parcel'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
    }

    public function test_admin_cannot_create_admins_and_staff_cannot_manage_logins(): void
    {
        $admin = $this->user(Role::Admin);
        $this->actingAs($admin)->post('/staff', ['name' => 'X', 'staff_id' => 'SK-9', 'password' => 'a-strong-password', 'role' => 'superadmin'])
            ->assertSessionHasErrors('role');
        $this->actingAs($this->user(Role::Staff))->get('/staff')->assertForbidden();

        $other = $this->user(Role::Admin);
        $this->actingAs($admin)->post("/staff/{$other->id}/toggle")->assertForbidden();
    }

    public function test_deactivated_staff_cannot_sign_in(): void
    {
        $admin = $this->user(Role::Superadmin);
        $staff = $this->user(Role::Staff, ['staff_id' => 'SK-0003', 'password' => 'a-strong-password']);
        $this->actingAs($admin)->post("/staff/{$staff->id}/toggle")->assertRedirect();
        $this->assertFalse($staff->fresh()->active);
        $this->post('/logout');

        $this->post('/login', ['staff_id' => 'SK-0003', 'password' => 'a-strong-password'])->assertSessionHasErrors('staff_id');
        $this->assertGuest();
    }

    public function test_last_superadmin_cannot_be_demoted(): void
    {
        $super = $this->user(Role::Superadmin);
        $this->actingAs($super)->delete("/staff/{$super->id}")->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_full_parcel_journey_with_bulk_dispatch_exception_and_rebook(): void
    {
        Notification::fake();
        $staff = $this->user(Role::Staff);
        $dispatch = $this->user(Role::Dispatch);
        $admin = $this->user(Role::Admin);
        $a = $this->book($staff);
        $b = $this->book($staff);

        $this->actingAs($staff)->post("/warehouse/parcels/{$a->tracking_number}/receive")->assertRedirect();
        $this->actingAs($staff)->post("/warehouse/parcels/{$b->tracking_number}/receive")->assertRedirect();
        $this->assertSame(ParcelStatus::Scanned, $a->fresh()->status);
        $this->assertSame(4, $a->events()->count()); // booked, received, packaged, scanned

        $rider = Rider::create(['name' => 'Musa Bike', 'phone' => '08031112222', 'vehicle' => 'Dispatch Bike']);
        $this->actingAs($dispatch)->post('/dispatch/assign', ['tns' => [$a->tracking_number, $b->tracking_number], 'rider_id' => $rider->id])
            ->assertSessionHas('ok');
        $this->assertSame(ParcelStatus::OutForDelivery, $b->fresh()->status);
        $this->assertSame($rider->id, $b->fresh()->rider_id);

        $this->actingAs($dispatch)->post("/dispatch/parcels/{$a->tracking_number}/delivered")->assertSessionHas('ok');
        $this->actingAs($dispatch)->post("/dispatch/parcels/{$b->tracking_number}/exception", ['reason' => 'Recipient refused delivery', 'detail' => 'Called 0803 at gate'])
            ->assertSessionHas('ok');
        $this->assertSame(ParcelStatus::Exception, $b->fresh()->status);
        Notification::assertSentTo($admin, StaffAlert::class);

        $this->getJson('/track?tn=' . $b->tracking_number)
            ->assertJsonPath('exception_reason', 'Recipient refused delivery')
            ->assertJsonPath('service', 'Express')
            ->assertDontSee('Called 0803');

        $this->actingAs($staff)->post("/parcels/{$b->tracking_number}/rebook")->assertForbidden();
        $this->actingAs($admin)->post("/parcels/{$b->tracking_number}/rebook")->assertRedirect('/warehouse');
        $this->assertSame(ParcelStatus::Booked, $b->fresh()->status);
        $this->assertNull($b->fresh()->rider_id);
    }

    public function test_deletion_needs_admin_approval(): void
    {
        Notification::fake();
        $staff = $this->user(Role::Staff);
        $admin = $this->user(Role::Admin);
        $parcel = $this->book($staff);

        $this->actingAs($staff)->post("/parcels/{$parcel->tracking_number}/deletion-requests", ['reason' => 'Duplicate'])->assertSessionHas('ok');
        $req = \App\Models\DeletionRequest::first();
        $this->actingAs($staff)->post("/deletion-requests/{$req->id}/approve")->assertForbidden();
        $this->assertDatabaseHas('parcels', ['id' => $parcel->id]);

        $this->actingAs($admin)->post("/deletion-requests/{$req->id}/approve")->assertSessionHas('ok');
        $this->assertDatabaseMissing('parcels', ['id' => $parcel->id]);
        $this->assertDatabaseMissing('parcel_events', ['parcel_id' => $parcel->id]);
        Notification::assertSentTo($staff, StaffAlert::class);
    }

    public function test_settings_prefix_is_used_for_new_tracking_numbers(): void
    {
        $admin = $this->user(Role::Admin);
        $this->actingAs($admin)->put('/settings', ['name' => 'StallionKing', 'tagline' => '', 'prefix' => 'skl'])->assertSessionHas('ok');
        $this->assertStringStartsWith('SKL-', $this->book($admin)->tracking_number);
    }

    public function test_every_staff_page_renders(): void
    {
        $super = $this->user(Role::Superadmin);
        $parcel = $this->book($super);
        $other = $this->user(Role::Staff);
        $this->actingAs($super)->post('/clients', ['name' => 'Bello Fabrics', 'phone' => '08052209911', 'city' => 'Kano'])->assertSessionHas('ok');
        Rider::create(['name' => 'Musa Bike', 'phone' => '08031112222', 'vehicle' => 'Dispatch Bike']);

        foreach (['/warehouse', '/clients', '/dispatch', '/parcels', "/parcels/{$parcel->tracking_number}", '/parcels?q=Bello&status=booked',
                  '/staff', "/staff/{$other->id}/edit", '/settings', '/activity'] as $url) {
            $this->actingAs($super)->get($url)->assertOk();
        }
        $this->actingAs($this->user(Role::Dispatch))->get('/clients')->assertForbidden();
        $this->actingAs($this->user(Role::Staff))->get('/dispatch')->assertForbidden();
    }

    public function test_admin_can_decline_a_deletion_without_a_note(): void
    {
        Notification::fake();
        $staff = $this->user(Role::Staff);
        $parcel = $this->book($staff);
        $this->actingAs($staff)->post("/parcels/{$parcel->tracking_number}/deletion-requests", ['reason' => 'Mistake']);
        $req = \App\Models\DeletionRequest::first();

        $this->actingAs($this->user(Role::Admin))->post("/deletion-requests/{$req->id}/reject")->assertSessionHas('ok');
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertDatabaseHas('parcels', ['id' => $parcel->id]);
    }
}
