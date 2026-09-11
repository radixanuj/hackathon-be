<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Interest Groups: discovery only. The conversation stays in WhatsApp/Slack.
        Schema::create('interest_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // sports | books | tech | film | music | outdoors | games | other
            $table->string('emoji', 16)->nullable();
            $table->string('cover_url')->nullable();
            $table->string('external_platform')->nullable(); // whatsapp | slack | teams | discord | other
            $table->string('external_link')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('members_count')->default(0);
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('interest_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interest_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // owner | member
            $table->timestamps();

            $table->unique(['interest_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_group_members');
        Schema::dropIfExists('interest_groups');
    }
};
