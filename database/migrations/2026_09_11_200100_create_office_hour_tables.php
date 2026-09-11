<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Office Hours: an employee publishes open slots, anyone books one.
        // No request-and-approve dance - that is what Mentoring already covers.
        Schema::create('office_hour_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('location')->nullable();
            $table->string('link')->nullable();
            $table->string('status')->default('open'); // open | cancelled
            $table->unsignedSmallInteger('bookings_count')->default(0);
            $table->timestamps();

            $table->index(['host_id', 'starts_at']);
            $table->index('starts_at');
        });

        Schema::create('office_hour_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_hour_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic')->nullable();
            $table->string('status')->default('booked'); // booked | cancelled
            $table->timestamps();

            $table->unique(['office_hour_slot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_hour_bookings');
        Schema::dropIfExists('office_hour_slots');
    }
};
