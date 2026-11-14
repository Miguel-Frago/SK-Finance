<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app used to run in UTC, so every saved date-and-time is 8 hours behind Philippine time.
 * Now that config/app.php uses Asia/Manila, move those old values forward 8 hours once so they
 * keep showing the same real moment. Plain "date" columns (like a transaction's date) are not touched.
 */
return new class extends Migration
{
    private const HOURS = 8;

    /** table => its date-and-time columns */
    private const COLUMNS = [
        'users'           => ['email_verified_at', 'created_at', 'updated_at', 'qr_issued_at', 'deactivated_at', 'approved_at'],
        'password_reset_tokens' => ['created_at'],
        'failed_jobs'     => ['failed_at'],
        'budgets'         => ['created_at', 'updated_at'],
        'categories'      => ['created_at', 'updated_at'],
        'subcategories'   => ['created_at', 'updated_at'],
        'transactions'    => ['created_at', 'updated_at'],
        'fund_movements'  => ['created_at', 'updated_at'],
        'report_settings' => ['created_at', 'updated_at'],
        'barangays'       => ['created_at', 'updated_at'],
        'activity_logs'   => ['created_at'],
        'admins'          => ['created_at', 'updated_at', 'password_changed_at'],
        'access_requests' => ['code_expires_at', 'code_used_at', 'last_message_at', 'reviewed_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift(self::HOURS);
    }

    public function down(): void
    {
        $this->shift(-self::HOURS);
    }

    private function shift(int $hours): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $t = DB::getQueryGrammar()->wrapTable($table);
                $c = DB::getQueryGrammar()->wrap($column);
                $sign = $hours >= 0 ? '+' : '-';
                $abs  = abs($hours);

                $expr = match ($driver) {
                    'sqlite' => "datetime({$c}, '{$sign}{$abs} hours')",
                    'pgsql'  => "{$c} {$sign} interval '{$abs} hours'",
                    'sqlsrv' => "DATEADD(hour, {$hours}, {$c})",
                    default  => "DATE_ADD({$c}, INTERVAL {$hours} HOUR)",   // mysql / mariadb
                };

                DB::statement("UPDATE {$t} SET {$c} = {$expr} WHERE {$c} IS NOT NULL");
            }
        }
    }
};
