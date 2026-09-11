<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Blind Meetup round per month; meetup happens on the last Friday.
        Schema::create('meetup_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('period')->unique(); // YYYY-MM
            $table->timestamp('signups_open_at');
            $table->timestamp('signups_close_at');
            $table->date('meetup_date');
            $table->string('status')->default('open'); // draft | open | matched | completed | cancelled
            $table->timestamps();
        });

        Schema::create('meetup_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meetup_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('signed_up'); // signed_up | matched | unmatched | withdrawn
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['meetup_round_id', 'user_id']);
        });

        Schema::create('meetup_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meetup_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            $table->string('match_reason')->nullable();
            $table->string('status')->default('proposed'); // proposed | scheduled | completed | missed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['meetup_round_id', 'user_one_id']);
            $table->index(['meetup_round_id', 'user_two_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetup_pairs');
        Schema::dropIfExists('meetup_signups');
        Schema::dropIfExists('meetup_rounds');
    }
};
