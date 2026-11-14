<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Seen" receipts (who has looked at which message) and the history of an edited message.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            // What the person has actually LOOKED at. Separate from last_read_message_id, which "hide this chat" also moves:
            // hiding a chat must never tell the other person "seen".
            if (! Schema::hasColumn('conversation_participants', 'seen_message_id')) {
                $table->unsignedBigInteger('seen_message_id')->nullable();
            }
            if (! Schema::hasColumn('conversation_participants', 'seen_at')) {
                $table->timestamp('seen_at')->nullable();
            }
        });

        // chats that were already read keep showing as seen
        DB::table('conversation_participants')
            ->whereNotNull('last_read_message_id')->whereNull('hidden_at')->whereNull('seen_message_id')
            ->update(['seen_message_id' => DB::raw('last_read_message_id'), 'seen_at' => DB::raw('updated_at')]);

        if (! Schema::hasTable('message_edits')) {
            Schema::create('message_edits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
                $table->text('body');                       // the text as it was BEFORE this edit
                $table->timestamp('created_at')->nullable(); // when it was changed
                $table->index('message_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_edits');

        Schema::table('conversation_participants', function (Blueprint $table) {
            foreach (['seen_message_id', 'seen_at'] as $column) {
                if (Schema::hasColumn('conversation_participants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
