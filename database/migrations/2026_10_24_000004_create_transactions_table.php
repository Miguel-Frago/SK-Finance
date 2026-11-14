<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description');
            $table->string('payee')->nullable();
            // restrict: a category/subcategory with spending cannot be deleted by accident
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('subcategory_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['bank', 'cash_on_hand']);
            $table->string('voucher_number')->nullable();
            $table->string('check_number')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
