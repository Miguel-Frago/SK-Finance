<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ChairmanSeeder extends Seeder
{
    public function run(): void
    {
        // Optional: make the SK chairman account from .env, then run: php artisan db:seed --class=ChairmanSeeder
        //   SK_CHAIRMAN_NAME="Maria Santos"
        //   SK_CHAIRMAN_EMAIL=chairman@example.com
        //   SK_CHAIRMAN_PASSWORD=your-strong-password
        $user = User::firstOrNew(['email' => env('SK_CHAIRMAN_EMAIL')]);
        $user->name     = env('SK_CHAIRMAN_NAME', 'SK Chairman');
        $user->password = Hash::make(env('SK_CHAIRMAN_PASSWORD', 'ChangeMe!2026'));
        $user->role     = User::ROLE_CHAIRMAN;
        $user->barangay_id = Barangay::where('slug', env('SK_BARANGAY', Barangay::DEFAULT_SLUG))->value('id') ?? Barangay::defaultId();
        $user->save();
    }
}
