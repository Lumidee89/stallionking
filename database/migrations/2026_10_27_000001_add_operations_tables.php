<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Riders are delivery people, not platform logins.
        Schema::create('riders', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('phone', 20);
            $t->string('vehicle', 40)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('parcels', function (Blueprint $t) {
            $t->dropForeign(['rider_id']);
        });
        Schema::table('parcels', function (Blueprint $t) {
            $t->foreign('rider_id')->references('id')->on('riders')->nullOnDelete();
            $t->string('description', 200)->nullable()->after('weight_range');
            $t->string('service_type', 20)->default('Standard')->after('description');
            $t->json('tracking_emails')->nullable();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->string('value', 200)->nullable();
            $t->timestamps();
        });

        Schema::create('deletion_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parcel_id')->nullable()->constrained()->nullOnDelete();
            $t->string('tracking_number', 20)->index();
            $t->string('reason', 500);
            $t->string('status', 10)->default('pending')->index(); // pending | approved | rejected
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution_note', 500)->nullable();
            $t->timestamps();
        });

        // Who did what. Actor details are copied so entries survive account deletion.
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_name', 120)->nullable();
            $t->string('actor_staff_id', 32)->nullable();
            $t->string('actor_role', 20)->nullable();
            $t->string('action', 40)->index();
            $t->string('summary', 500);
            $t->string('target_type', 20)->nullable();
            $t->string('target_id', 40)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('deletion_requests');
        Schema::dropIfExists('settings');
        Schema::table('parcels', function (Blueprint $t) {
            $t->dropForeign(['rider_id']);
            $t->dropColumn(['description', 'service_type', 'tracking_emails']);
        });
        Schema::table('parcels', function (Blueprint $t) {
            $t->foreign('rider_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::dropIfExists('riders');
    }
};
