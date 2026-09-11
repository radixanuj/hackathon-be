<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cross-location Buddy: an ongoing pairing with someone in another office,
        // rather than the one-off monthly format the Blind Meetups use.
        Schema::create('buddy_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('waiting'); // waiting | paired | withdrawn
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
        });

        Schema::create('buddy_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            $table->string('match_reason')->nullable();
            $table->string('status')->default('active'); // active | ended
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_one_id', 'status']);
            $table->index(['user_two_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_pairings');
        Schema::dropIfExists('buddy_signups');
    }
};
