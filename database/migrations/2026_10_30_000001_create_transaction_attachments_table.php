<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * An expense can now have several receipts. They live in their own table; the single
 * transactions.attachment_path column is moved into it (nothing is lost) and then dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->default(0);   // bytes
            $table->timestamps();
        });

        $disk = Storage::disk('public');

        // each existing receipt becomes the first attachment of its expense
        DB::table('transactions')->whereNotNull('attachment_path')->orderBy('id')->each(function ($row) use ($disk) {
            $size = 0;

            try {
                $size = $disk->exists($row->attachment_path) ? (int) $disk->size($row->attachment_path) : 0;
            } catch (\Throwable $e) {
                $size = 0;
            }

            DB::table('transaction_attachments')->insert([
                'transaction_id' => $row->id,
                'path'           => $row->attachment_path,
                'original_name'  => basename($row->attachment_path),
                'size'           => $size,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('attachment_path')->nullable();
        });

        // the old design holds one receipt per expense: keep the first one
        DB::table('transaction_attachments')->orderBy('id')->each(function ($row) {
            DB::table('transactions')
                ->where('id', $row->transaction_id)
                ->whereNull('attachment_path')
                ->update(['attachment_path' => $row->path]);
        });

        Schema::dropIfExists('transaction_attachments');
    }
};
