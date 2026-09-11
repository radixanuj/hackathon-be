<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('format')->default('async'); // async | live
            $table->string('status')->default('open'); // draft | open | scheduled | closed
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('location')->nullable();
            $table->unsignedBigInteger('story_id')->nullable(); // linked once a Story becomes an AMA
            $table->unsignedInteger('questions_count')->default(0);
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('ama_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ama_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('upvotes_count')->default(0);
            $table->timestamps();

            $table->index(['ama_id', 'upvotes_count']);
        });

        Schema::create('ama_question_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ama_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ama_question_id', 'user_id']);
        });

        Schema::create('ama_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ama_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ama_answers');
        Schema::dropIfExists('ama_question_votes');
        Schema::dropIfExists('ama_questions');
        Schema::dropIfExists('amas');
    }
};
