<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('email');
            $table->string('team')->nullable()->after('job_title');
            $table->string('location')->nullable()->after('team');
            $table->string('timezone')->default('Asia/Kolkata')->after('location');
            $table->date('joined_at')->nullable()->after('timezone');
            $table->text('intro')->nullable()->after('joined_at');
            $table->string('avatar_url')->nullable()->after('intro');
            $table->string('pronouns')->nullable()->after('avatar_url');
            $table->string('role')->default('employee')->after('pronouns'); // employee | admin
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('open_to_mentoring')->default(true)->after('is_active');
            $table->boolean('open_to_blind_meetups')->default(true)->after('open_to_mentoring');

            $table->index('team');
            $table->index('location');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['team']);
            $table->dropIndex(['location']);
            $table->dropColumn([
                'job_title', 'team', 'location', 'timezone', 'joined_at', 'intro',
                'avatar_url', 'pronouns', 'role', 'is_active',
                'open_to_mentoring', 'open_to_blind_meetups',
            ]);
        });
    }
};
