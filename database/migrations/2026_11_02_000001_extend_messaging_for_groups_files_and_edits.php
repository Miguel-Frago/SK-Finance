<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 4C: group chats, the Admin's announcements, file attachments, and editing / deleting a message.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'title')) {
                $table->string('title', 80)->nullable();                              // the name of a group
            }
            if (! Schema::hasColumn('conversations', 'barangay_id')) {
                $table->unsignedBigInteger('barangay_id')->nullable()->index();       // set for a Chairman's barangay group
            }
            if (! Schema::hasColumn('conversations', 'created_by_type')) {
                $table->string('created_by_type', 10)->nullable();                    // user | admin
            }
            if (! Schema::hasColumn('conversations', 'created_by_id')) {
                $table->unsignedBigInteger('created_by_id')->nullable();
            }
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('conversation_participants', 'joined_after_message_id')) {
                // a person added to a group later sees the messages from that moment on, not the earlier history
                $table->unsignedBigInteger('joined_after_message_id')->default(0);
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable();
            }
            if (! Schema::hasColumn('messages', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable();                          // a deleted message stays as an empty "deleted" line
            }
            if (! Schema::hasColumn('messages', 'changed_at')) {
                $table->timestamp('changed_at')->nullable()->index();                 // last edit or delete: lets an open chat catch up
            }
        });

        if (! Schema::hasTable('message_attachments')) {
            Schema::create('message_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
                $table->unsignedBigInteger('message_id')->nullable()->index();        // empty while the file is uploaded but not sent yet
                $table->string('uploader_type', 10);
                $table->unsignedBigInteger('uploader_id');
                $table->string('path', 255);                                          // on the private disk, never in public/
                $table->string('original_name', 190);
                $table->string('mime', 120)->default('application/octet-stream');    // detected from the file itself
                $table->unsignedBigInteger('size')->default(0);
                $table->string('kind', 10)->default('file');                          // image | video | file
                $table->timestamps();

                $table->index(['uploader_type', 'uploader_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');

        Schema::table('messages', function (Blueprint $table) {
            foreach (['edited_at', 'deleted_at', 'changed_at'] as $column) {
                if (Schema::hasColumn('messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('conversation_participants', function (Blueprint $table) {
            if (Schema::hasColumn('conversation_participants', 'joined_after_message_id')) {
                $table->dropColumn('joined_after_message_id');
            }
        });
        Schema::table('conversations', function (Blueprint $table) {
            foreach (['title', 'barangay_id', 'created_by_type', 'created_by_id'] as $column) {
                if (Schema::hasColumn('conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
