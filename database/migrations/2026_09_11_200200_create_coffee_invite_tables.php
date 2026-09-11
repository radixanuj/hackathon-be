<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Open Coffee / Lunch Invites: "I am free Thursday lunch, anyone want to join?"
        // Deliberately lighter than an Event - a time, a place, a couple of seats.
        Schema::create('coffee_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind')->default('coffee'); // coffee | lunch | walk
            $table->text('note')->nullable();
            $table->timestamp('starts_at');
            $table->string('location')->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->string('link')->nullable();
            $table->unsignedSmallInteger('capacity')->default(3);
            $table->string('status')->default('open'); // open | cancelled
            $table->unsignedSmallInteger('joins_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('coffee_invite_joins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coffee_invite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['coffee_invite_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coffee_invite_joins');
        Schema::dropIfExists('coffee_invites');
    }
};
