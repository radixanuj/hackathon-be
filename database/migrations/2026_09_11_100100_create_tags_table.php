<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('skill'); // skill | interest
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index('type');
        });

        // A user's profile "why should I talk to this person" signals.
        Schema::create('user_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            // can_talk_about | can_help_with | want_to_learn | interest
            $table->string('kind');
            $table->timestamps();

            $table->unique(['user_id', 'tag_id', 'kind']);
            $table->index(['tag_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tag');
        Schema::dropIfExists('tags');
    }
};
