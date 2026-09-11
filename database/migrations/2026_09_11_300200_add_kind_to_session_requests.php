<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Three ways in on the Pick a Brain page end up in this one table:
        // a one-off knowledge session, a mentoring ask, or a coaching ask.
        // They differ in intent and in how they read back, not in shape.
        Schema::table('session_requests', function (Blueprint $table) {
            $table->string('kind')->default('knowledge')->after('recipient_id');
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('session_requests', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn('kind');
        });
    }
};
