<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Every account and every finance record now belongs to one barangay portal.
// Everything that exists today (the original single SK Finance) is moved into Barangay Cantoria No. 4.
return new class extends Migration
{
    private const TABLES = [
        'users', 'budgets', 'categories', 'subcategories', 'transactions', 'fund_movements', 'report_settings',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'barangay_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('barangay_id')->nullable()->constrained('barangays')->restrictOnDelete();
            });
        }

        $cantoria = DB::table('barangays')->where('slug', 'cantoria-no-4')->value('id');

        foreach (self::TABLES as $name) {
            DB::table($name)->whereNull('barangay_id')->update(['barangay_id' => $cantoria]);
        }

        // One regular and one supplemental budget per year, PER BARANGAY (it used to be one per year in total)
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['year', 'type']);
            $table->unique(['barangay_id', 'year', 'type']);
        });

        // The report names and signatures are kept per barangay too
        Schema::table('report_settings', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['barangay_id', 'key']);
        });

        // The free-text barangay on the profile is replaced by the real barangay link
        if (Schema::hasColumn('users', 'barangay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('barangay');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'barangay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('barangay', 120)->nullable();
            });
        }

        Schema::table('report_settings', function (Blueprint $table) {
            $table->dropUnique(['barangay_id', 'key']);
            $table->unique('key');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['barangay_id', 'year', 'type']);
            $table->unique(['year', 'type']);
        });

        foreach (array_reverse(self::TABLES) as $name) {
            if (Schema::hasColumn($name, 'barangay_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('barangay_id');
                });
            }
        }
    }
};
