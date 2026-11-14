<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * For the day nobody can sign in as Admin (forgotten password, or the old president is gone):
 * whoever runs the server resets the Admin account from the command line. Everything else stays.
 */
class ResetAdmin extends Command
{
    protected $signature = 'sk:reset-admin
                            {email? : The Admin email (only needed when there is no Admin yet, or to change it)}
                            {--name= : The Admin name}';

    protected $description = 'Reset the Admin account to a new random temporary password (changed at the next sign-in). Creates the Admin if there is none.';

    public function handle(): int
    {
        $admin = Admin::query()->orderBy('id')->first();
        $email = $this->argument('email');

        if (! $admin && ! filled($email)) {
            $this->error('There is no Admin yet. Run:  php artisan sk:reset-admin someone@example.com');

            return self::FAILURE;
        }

        if (filled($email)) {
            $taken = Admin::where('email', Str::lower($email))
                ->when($admin, fn ($q) => $q->where('id', '!=', $admin->id))
                ->exists();

            if ($taken) {
                $this->error('That email already belongs to another Admin account.');

                return self::FAILURE;
            }
        }

        $admin ??= new Admin();
        $temp    = Admin::temporaryPassword();

        $admin->name = $this->option('name') ?: ($admin->name ?: 'SK Federation President');
        if (filled($email)) {
            $admin->email = Str::lower($email);
        }
        $admin->password             = $temp;   // hashed by the model
        $admin->must_change_password = true;
        $admin->password_changed_at  = null;
        $admin->setRememberToken(Str::random(60));
        $admin->save();

        ActivityLog::record('handover', null, "Admin account reset from the server ({$admin->name}, {$admin->email})");

        $this->info("Admin account ready: {$admin->name} <{$admin->email}>");
        $this->line("Temporary password: {$temp}");
        $this->line('They must choose their own password at the first sign-in at /admin/login.');

        return self::SUCCESS;
    }
}
