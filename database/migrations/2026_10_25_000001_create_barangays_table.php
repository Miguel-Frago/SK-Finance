<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// The fixed list of the 40 barangays of the Municipality of Luna, La Union. Every SK Finance portal belongs to one of them.
// The district is left empty on purpose: the Admin (SK Federation President) will assign districts later.
return new class extends Migration
{
    private const NAMES = [
        'Alcala (Poblacion)', 'Magallanes (Poblacion)', 'Salcedo (Poblacion)', 'Victoria (Poblacion)',
        'Ayaoan', 'Barangobong', 'Barrientos', 'Bungro', 'Busel-Busel', 'Cabalitocan',
        'Cantoria No. 1', 'Cantoria No. 2', 'Cantoria No. 3', 'Cantoria No. 4',
        'Carisquis', 'Darigayos', 'Magsiping', 'Mamay', 'Nagrebcan',
        'Nalvo Norte', 'Nalvo Sur', 'Napaset',
        'Oaqui No. 1', 'Oaqui No. 2', 'Oaqui No. 3', 'Oaqui No. 4',
        'Pila', 'Pitpitac',
        'Rimos No. 1', 'Rimos No. 2', 'Rimos No. 3', 'Rimos No. 4', 'Rimos No. 5',
        'Rissing', 'Santo Domingo Norte', 'Santo Domingo Sur', 'Sucoc Norte', 'Sucoc Sur',
        'Suyo', 'Tallaoen',
    ];

    public function up(): void
    {
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('district', 60)->nullable();
            // The SK Treasurer can switch this on so the SK Chairman may add and edit finance records too
            $table->boolean('chairman_can_edit')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('barangays')->insert(array_map(fn (string $name) => [
            'name'       => $name,
            'slug'       => Str::slug($name),
            'created_at' => $now,
            'updated_at' => $now,
        ], self::NAMES));
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
