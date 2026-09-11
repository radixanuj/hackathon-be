<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('creator')->nullable(); // author, host, director...
            // book | podcast | article | show | film | course | tool
            $table->string('type');
            $table->string('stream'); // work | leisure
            $table->string('url')->nullable();
            $table->text('why'); // "Why I recommend this"
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestamps();

            $table->index(['stream', 'type']);
        });

        Schema::create('recommendation_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['recommendation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_likes');
        Schema::dropIfExists('recommendations');
    }
};
