<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('staff_id', 32)->nullable()->unique()->after('id');
            $t->string('role', 20)->default('staff')->index()->after('password');
            $t->boolean('active')->default(true)->after('role');
            $t->timestamp('last_login_at')->nullable();
        });

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 20)->index();
            $t->string('email')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('parcels', function (Blueprint $t) {
            $t->id();
            $t->string('tracking_number', 20)->unique();
            $t->string('idempotency_key', 64)->nullable()->unique(); // blocks double-submits
            $t->string('status', 20)->default('booked');
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->json('sender');
            $t->string('receiver_name');
            $t->string('receiver_phone', 20)->index();
            $t->string('receiver_email')->nullable();
            $t->string('receiver_address', 500);
            $t->string('receiver_city');
            $t->string('receiver_state')->nullable();
            $t->string('receiver_landmark')->nullable();
            $t->string('weight_range', 20);
            $t->string('receiver_source', 10)->default('manual'); // manual | qr | ocr
            $t->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('booked_by')->constrained('users');
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });

        // Append-only audit trail: the app never updates or deletes these rows.
        Schema::create('parcel_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parcel_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_role', 20)->nullable();
            $t->string('note', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['parcel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_events');
        Schema::dropIfExists('parcels');
        Schema::dropIfExists('clients');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['staff_id', 'role', 'active', 'last_login_at']);
        });
    }
};
