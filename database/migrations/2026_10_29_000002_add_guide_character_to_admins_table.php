<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which SK Guide character the Admin picked: "boy" or "girl". Empty means the default (girl).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'guide_character')) {
                $table->string('guide_character', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (Schema::hasColumn('admins', 'guide_character')) {
                $table->dropColumn('guide_character');
            }
        });
    }
};
