<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which SK Guide character the Treasurer or Chairman picked: "boy" or "girl". Empty means the default (boy, as before).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'guide_character')) {
                $table->string('guide_character', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'guide_character')) {
                $table->dropColumn('guide_character');
            }
        });
    }
};
