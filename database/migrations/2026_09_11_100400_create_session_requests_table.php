<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mentoring / Knowledge Sessions: 30 minutes with someone, based on their profile.
        Schema::create('session_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic');
            // work_knowledge | career | leadership | people | technical | personal_experience
            $table->string('category')->default('work_knowledge');
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->timestamp('proposed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            // pending | accepted | declined | time_suggested | completed | cancelled
            $table->string('status')->default('pending');
            $table->text('response_message')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_requests');
    }
};
