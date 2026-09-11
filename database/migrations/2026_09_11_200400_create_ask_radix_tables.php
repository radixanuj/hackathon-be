<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ask Radix: post a question when you do not know who can help.
        // Tags exist so the question can be surfaced to relevant people, and
        // anyone can volunteer to help rather than only answering in writing.
        Schema::create('radix_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('open'); // open | answered | closed
            $table->unsignedInteger('answers_count')->default(0);
            $table->unsignedInteger('volunteers_count')->default(0);
            $table->foreignId('accepted_answer_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('radix_question_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radix_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['radix_question_id', 'tag_id']);
        });

        Schema::create('radix_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radix_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_accepted')->default(false);
            $table->timestamps();

            $table->index('radix_question_id');
        });

        // "I have not written an answer, but I have done this - come talk to me."
        Schema::create('radix_volunteers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radix_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['radix_question_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radix_volunteers');
        Schema::dropIfExists('radix_answers');
        Schema::dropIfExists('radix_question_tag');
        Schema::dropIfExists('radix_questions');
    }
};
