<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Creates the very first Admin, once. Set these in .env (they are read through config/sk.php):
     *   SK_ADMIN_NAME="Juan Dela Cruz"
     *   SK_ADMIN_EMAIL=federation@example.com
     *   SK_ADMIN_PASSWORD=a-temporary-password      (leave empty to get a random one printed here)
     * Then run:  php artisan db:seed --class=AdminSeeder
     *
     * The Admin must choose their own password at the first sign-in, so the .env value is only a starting point.
     * After that the account is managed inside the app (Admin > My profile > handover), and this seeder does nothing
     * while an Admin exists. If nobody can sign in, run:  php artisan sk:reset-admin
     */
    public function run(): void
    {
        if (Admin::query()->exists()) {
            $this->command?->info('An Admin already exists, nothing was changed. Use Admin > My profile, or: php artisan sk:reset-admin');

            return;
        }

        $email = config('sk.admin_seed.email');

        if (! filled($email)) {
            $this->command?->error('Set SK_ADMIN_EMAIL in .env first (and SK_ADMIN_NAME, SK_ADMIN_PASSWORD).');

            return;
        }

        $password = config('sk.admin_seed.password');
        $generated = ! filled($password);

        if ($generated) {
            $password = Admin::temporaryPassword();
        }

        $admin = new Admin();
        $admin->name                 = config('sk.admin_seed.name') ?: 'SK Federation President';
        $admin->email                = $email;
        $admin->password             = $password;   // hashed by the model
        $admin->must_change_password = true;
        $admin->save();

        $this->command?->info("Admin created: {$admin->email}");

        if ($generated) {
            $this->command?->line("Temporary password: {$password}");
        }
    }
}
