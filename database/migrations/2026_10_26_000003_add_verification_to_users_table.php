<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Step 2 of joining: the account made with the secret code waits as "pending" until the Admin approves it.
// Every account that exists today counts as approved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('approved');
            }
            if (! Schema::hasColumn('users', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'access_request_id')) {
                $table->foreignId('access_request_id')->nullable()->constrained('access_requests')->nullOnDelete();
            }
        });

        DB::table('users')->whereNull('approved_at')->update(['approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'access_request_id')) {
                $table->dropConstrainedForeignId('access_request_id');
            }
            foreach (['approved_at', 'status'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
