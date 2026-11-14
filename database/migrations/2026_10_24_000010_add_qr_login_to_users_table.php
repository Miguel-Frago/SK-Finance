<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// QR "Access ID" sign-in. Only a HASH of the card's secret token is stored (never the token itself),
// plus a hashed 6-digit PIN that must be typed after scanning the card.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'qr_token_hash')) {
                $table->string('qr_token_hash', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'qr_pin')) {
                $table->string('qr_pin')->nullable();
            }
            if (! Schema::hasColumn('users', 'qr_issued_at')) {
                $table->timestamp('qr_issued_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'qr_token_hash')) {
                $table->dropUnique(['qr_token_hash']);
                $table->dropColumn('qr_token_hash');
            }
            foreach (['qr_pin', 'qr_issued_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
