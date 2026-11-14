<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TreasurerSeeder extends Seeder
{
    public function run(): void
    {
        // Set these in .env, then run: php artisan db:seed --class=TreasurerSeeder
        //   SK_TREASURER_NAME="Juan Dela Cruz"
        //   SK_TREASURER_EMAIL=treasurer@example.com
        //   SK_TREASURER_PASSWORD=your-strong-password
        //   SK_BARANGAY=cantoria-no-4      (the barangay's slug; Cantoria No. 4 if left out)
        $user = User::firstOrNew(['email' => env('SK_TREASURER_EMAIL')]);
        $user->name        = env('SK_TREASURER_NAME', 'SK Treasurer');
        $user->password    = Hash::make(env('SK_TREASURER_PASSWORD', 'ChangeMe!2026'));
        $user->role        = User::ROLE_TREASURER;
        $user->barangay_id = Barangay::where('slug', env('SK_BARANGAY', Barangay::DEFAULT_SLUG))->value('id') ?? Barangay::defaultId();
        $user->save();
    }
}
