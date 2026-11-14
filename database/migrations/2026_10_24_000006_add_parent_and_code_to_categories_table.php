<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Categories can now sit inside other categories (SK YDEP > Sports Development > ...)
            $table->foreignId('parent_id')->nullable()->after('budget_id')
                ->constrained('categories')->cascadeOnDelete();
            $table->string('code')->nullable()->after('name');
            $table->unsignedInteger('sort_order')->default(0)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['code', 'sort_order']);
        });
    }
};