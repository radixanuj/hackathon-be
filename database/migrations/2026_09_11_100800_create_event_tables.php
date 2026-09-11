<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do Together: anyone creates an activity, Radix Connect handles discovery + RSVPs.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('interest_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // outdoors | food | film | sports | games | culture | work | other
            $table->string('category')->default('other');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->string('link')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default('published'); // draft | published | cancelled | completed
            $table->unsignedInteger('going_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('going'); // going | maybe | not_going
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('events');
    }
};
