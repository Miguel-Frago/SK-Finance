<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Each account keeps its own colour theme (sidebar colour, accent colour) as JSON.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'theme')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('theme')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'theme')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('theme');
            });
        }
    }
};
