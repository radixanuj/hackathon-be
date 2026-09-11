<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Open Invites: "Anyone interested?" with no date and no logistics.
        // It becomes a real Event only once enough people say yes.
        Schema::create('open_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('other'); // mirrors Event categories
            $table->string('rough_timing')->nullable();   // "some weekend in October"
            $table->string('location')->nullable();
            $table->string('status')->default('open');    // open | converted | closed
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('interested_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        Schema::create('open_invite_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_invite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['open_invite_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_invite_interests');
        Schema::dropIfExists('open_invites');
    }
};
