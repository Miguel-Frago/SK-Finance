<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // receipt    = money coming in (e.g. barangay releases) into `account`
            // withdrawal = bank -> cash on hand
            // deposit    = cash on hand -> bank
            $table->enum('type', ['receipt', 'withdrawal', 'deposit']);
            $table->enum('account', ['bank', 'cash_on_hand'])->nullable(); // used by receipts only
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_movements');
    }
};
