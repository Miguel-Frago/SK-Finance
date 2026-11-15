<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sk_fund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('allocation_amount', 15, 2)->default(0);
            $table->decimal('monthly_allocation', 15, 2)->nullable();
            $table->string('remarks', 1000)->nullable();
            $table->timestamps();
            $table->unique(['barangay_id', 'year']);
        });

        Schema::create('sk_fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained()->restrictOnDelete();
            $table->foreignId('sk_fund_allocation_id')->constrained('sk_fund_allocations')->restrictOnDelete();
            $table->date('transfer_date');
            $table->enum('source', ['sk_fund', 'sk_surplus']);
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->string('remarks', 1000)->nullable();
            $table->timestamps();
            $table->index(['barangay_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sk_fund_transfers');
        Schema::dropIfExists('sk_fund_allocations');
    }
};
