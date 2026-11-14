<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Profile details printed on the Access ID: a photo, the barangay / municipality and the position title.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'photo_path')) {
                $table->string('photo_path')->nullable();
            }
            if (! Schema::hasColumn('users', 'barangay')) {
                $table->string('barangay', 120)->nullable();
            }
            if (! Schema::hasColumn('users', 'position')) {
                $table->string('position', 80)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['photo_path', 'barangay', 'position'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
