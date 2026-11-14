<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// In-app messaging (Phase 4B): private one-to-one chats between barangay accounts and the Admin.
// A "participant" is either a barangay account (type "user") or the Admin (type "admin"), because they are separate tables.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20)->default('direct');           // direct now; group and announcement come in Phase 4C
                $table->string('direct_key', 60)->nullable()->unique();  // "admin:1|user:5": one chat per pair of people
                $table->unsignedBigInteger('last_message_id')->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('conversation_participants')) {
            Schema::create('conversation_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
                $table->string('participant_type', 10);                  // user | admin
                $table->unsignedBigInteger('participant_id');
                $table->unsignedBigInteger('last_read_message_id')->nullable();
                $table->timestamp('hidden_at')->nullable();              // "hide this chat for me" (a new message brings it back)
                $table->timestamps();

                $table->unique(['conversation_id', 'participant_type', 'participant_id'], 'conv_participant_unique');
                $table->index(['participant_type', 'participant_id']);
            });
        }

        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
                $table->string('sender_type', 10);                       // user | admin
                $table->unsignedBigInteger('sender_id');
                $table->text('body');                                    // plain text only; messages are never edited or deleted
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
