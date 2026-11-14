<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            // regular = the year's own appropriation; supplemental = unspent funds carried over from a prior year
            $table->enum('type', ['regular', 'supplemental'])->default('regular');
            $table->string('label')->nullable();
            $table->foreignId('carried_from_budget_id')->nullable()
                ->constrained('budgets')->nullOnDelete();
            $table->decimal('total_budget', 15, 2)->default(0);
            $table->decimal('opening_bank_balance', 15, 2)->default(0);
            $table->decimal('opening_cash_balance', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['year', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
