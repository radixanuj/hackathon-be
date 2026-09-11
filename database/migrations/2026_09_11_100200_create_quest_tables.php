<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New Joiner Quest: five people to meet in the first month.
        Schema::create('quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active | completed | expired
            $table->timestamp('starts_at');
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('quest_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason');
            $table->string('status')->default('pending'); // pending | met | skipped
            $table->timestamp('met_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['quest_id', 'target_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_targets');
        Schema::dropIfExists('quests');
    }
};
