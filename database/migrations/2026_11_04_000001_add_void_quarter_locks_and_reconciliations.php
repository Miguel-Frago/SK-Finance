<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finance integrity: expenses and fund movements are VOIDED (kept, marked, left out of every total) instead of deleted;
// a finished quarter can be LOCKED; the Treasurer can record a BANK RECONCILIATION against the bank statement.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['transactions', 'fund_movements'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'voided_at')) {
                    $t->timestamp('voided_at')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'voided_by')) {
                    $t->unsignedBigInteger('voided_by')->nullable();
                }
                if (! Schema::hasColumn($table, 'void_reason')) {
                    $t->string('void_reason', 255)->nullable();
                }
            });
        }

        if (! Schema::hasTable('period_locks')) {
            Schema::create('period_locks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('barangay_id')->index();
                $t->unsignedSmallInteger('year');
                $t->unsignedTinyInteger('quarter');                       // 1 to 4
                $t->string('status', 20)->default('open');               // open | lock_requested | locked | unlock_requested
                $t->unsignedBigInteger('requested_by')->nullable();      // the Treasurer who asked for the lock
                $t->timestamp('requested_at')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();       // the Chairman who approved it
                $t->timestamp('approved_at')->nullable();
                $t->text('unlock_reason')->nullable();
                $t->unsignedBigInteger('unlock_requested_by')->nullable();
                $t->timestamp('unlock_requested_at')->nullable();
                $t->unsignedBigInteger('unlocked_by_admin')->nullable(); // the Admin who allowed the unlock
                $t->timestamp('unlocked_at')->nullable();
                $t->text('note')->nullable();                            // why a request was turned down
                $t->timestamps();

                $t->unique(['barangay_id', 'year', 'quarter']);
            });
        }

        if (! Schema::hasTable('bank_reconciliations')) {
            Schema::create('bank_reconciliations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('barangay_id')->index();
                $t->unsignedBigInteger('budget_id')->index();
                $t->date('statement_date');
                $t->decimal('statement_balance', 14, 2);
                $t->decimal('outstanding_checks', 14, 2)->default(0);    // written but not yet cleared by the bank
                $t->decimal('deposits_in_transit', 14, 2)->default(0);   // deposited but not yet on the statement
                $t->decimal('system_balance', 14, 2);                    // what this system said the bank balance was on that date
                $t->decimal('difference', 14, 2);                        // statement, adjusted, minus the system's figure
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('period_locks');
        foreach (['transactions', 'fund_movements'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['voided_at', 'voided_by', 'void_reason'] as $c) {
                    if (Schema::hasColumn($table, $c)) {
                        $t->dropColumn($c);
                    }
                }
            });
        }
    }
};
