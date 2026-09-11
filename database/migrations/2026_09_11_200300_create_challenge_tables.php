<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Communities: running, reading, photography or sports challenges.
        // A challenge counts one thing - the unit says what - and people log against it.
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('interest_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->default('other'); // running | reading | photography | sports | learning | other
            $table->string('unit')->default('entries');   // km, books, photos, sessions...
            $table->unsignedInteger('goal_value')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('open');    // draft | open | completed | cancelled
            $table->unsignedInteger('participants_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'ends_on']);
        });

        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_value')->default(0);
            $table->timestamps();

            $table->unique(['challenge_id', 'user_id']);
            $table->index(['challenge_id', 'total_value']);
        });

        Schema::create('challenge_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_participant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('value');
            $table->string('note')->nullable();
            $table->date('logged_on');
            $table->timestamps();

            $table->index('challenge_participant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_logs');
        Schema::dropIfExists('challenge_participants');
        Schema::dropIfExists('challenges');
    }
};
