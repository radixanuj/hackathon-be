<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately narrower than `open_to_mentoring`. That flag says "you
            // may ask me for half an hour", which is most of the company and is
            // what Knowledge Sessions and Coaching run on. Mentoring is a standing
            // commitment a named handful have actually signed up for, so it gets
            // its own column rather than a tenure heuristic over the first one.
            $table->boolean('is_mentor')->default(false)->after('open_to_mentoring');

            $table->index('is_mentor');
        });

        Schema::table('tags', function (Blueprint $table) {
            // Rank rather than a boolean: the topics on Pick a Brain are a curated
            // row in a chosen order, and ordering by usage_count would put whatever
            // the skills export happened to be heavy on at the front instead.
            $table->unsignedSmallInteger('featured_rank')->nullable()->after('usage_count');

            $table->index(['type', 'featured_rank']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_mentor']);
            $table->dropColumn('is_mentor');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex(['type', 'featured_rank']);
            $table->dropColumn('featured_rank');
        });
    }
};
