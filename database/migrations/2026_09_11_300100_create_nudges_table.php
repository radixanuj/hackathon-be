<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People: the old-fashioned poke. No message, no meeting, no agenda —
        // one person telling another they thought of them.
        //
        // One row per nudge, so the whole back-and-forth stays readable. The
        // ball is in the recipient's court until they nudge back, and that is
        // what `returned_at` records: null means outstanding, and an
        // outstanding nudge is what stops the sender sending a second one.
        Schema::create('nudges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            // How deep this exchange has gone: 1 for a fresh nudge, then one
            // higher every time it comes back the other way. Denormalised onto
            // the row so reading a streak never walks the history.
            $table->unsignedInteger('streak')->default(1);
            // When the recipient nudged back. Null while it is their turn.
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            // "Have I already nudged them?" — the check on every send.
            $table->index(['sender_id', 'recipient_id', 'returned_at']);
            // "Who is waiting on me?" — the list, newest first.
            $table->index(['recipient_id', 'returned_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nudges');
    }
};
