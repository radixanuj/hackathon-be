<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Teach Radix: the mirror of Ask Radix - offer an informal session and
        // see whether anyone wants it before committing to a date.
        Schema::create('teach_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('format')->default('session'); // session | workshop | walkthrough
            $table->string('level')->default('any');      // any | beginner | intermediate | advanced
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->unsignedSmallInteger('min_interested')->default(3);
            $table->string('preferred_times')->nullable();
            $table->string('status')->default('open');    // open | scheduled | delivered | cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->string('location')->nullable();
            $table->string('link')->nullable();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('interested_count')->default(0);
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('teach_offer_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teach_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['teach_offer_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teach_offer_interests');
        Schema::dropIfExists('teach_offers');
    }
};
