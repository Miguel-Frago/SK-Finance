<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use Illuminate\Database\Eloquent\Model;

/** One shared setting of the report page, stored as JSON under a key (e.g. "signatories"). */
class ReportSetting extends Model
{
    use BelongsToBarangay;   // names and signatures are kept per barangay

    protected $fillable = ['key', 'value'];

    public static function read(string $key, array $default = []): array
    {
        $row = static::where('key', $key)->first();

        return $row ? (json_decode((string) $row->value, true) ?: $default) : $default;
    }

    public static function write(string $key, array $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }
}
