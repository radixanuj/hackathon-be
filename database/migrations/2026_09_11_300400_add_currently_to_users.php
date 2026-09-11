<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // "Currently into" — the handful of lines that date a profile on
            // purpose: what you're reading, training for, watching this month.
            //
            // A JSON column rather than a table because it is a short ordered
            // list, owned by one person, always read and written whole, and
            // never searched or joined against. The labels are open text — the
            // design's roster has people Shooting, Perfecting and Relearning
            // things — so there is no vocabulary to normalise the way tags have.
            $table->json('currently')->nullable()->after('intro');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('currently');
        });
    }
};
