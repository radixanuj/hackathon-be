<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Celebrate & Discover, Phase 2: stories become discoverable through
        // interests, so a story surfaces to the people who care about its subject.
        Schema::create('story_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['story_id', 'tag_id']);
            $table->index('tag_id');
        });

        // "Who Should I Meet?" is computed live, but a dismissal has to stick.
        Schema::create('suggestion_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dismissed_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'dismissed_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestion_dismissals');
        Schema::dropIfExists('story_tag');
    }
};
