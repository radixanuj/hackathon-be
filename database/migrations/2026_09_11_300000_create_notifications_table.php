<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person who needs to know about one thing that happened.
        //
        // Nothing here is ever deleted by the app: `archived_at` takes a row out
        // of the inbox and `read_at` marks it seen, so the full history stays
        // readable under Archived for as long as the account exists.
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            // Who is being told.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Who caused it — null for anything the system did on its own.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');      // session_request.received, event.rsvp, ...
            $table->string('category');  // the pillar it belongs to, for filtering
            $table->string('title');
            $table->text('body')->nullable();
            // The thing it is about, so the frontend can deep-link to it.
            $table->nullableMorphs('subject');
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // The inbox query: mine, not archived, newest first.
            $table->index(['user_id', 'archived_at', 'id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
