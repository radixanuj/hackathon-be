<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Celebrate & Discover: "I didn't know this about that person."
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            // sport | travel | learning | making | milestone | other
            $table->string('category')->default('other');
            $table->string('media_url')->nullable();
            $table->foreignId('ama_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('reactions_count')->default(0);
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // clap | heart | mind_blown | inspired
            $table->string('reaction')->default('clap');
            $table->timestamps();

            $table->unique(['story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_reactions');
        Schema::dropIfExists('stories');
    }
};
