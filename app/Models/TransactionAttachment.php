<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One receipt / proof file (JPG, PNG or PDF) of an expense. An expense can have several.
 * Reached only through its transaction, so the barangay scope of the transaction applies.
 */
class TransactionAttachment extends Model
{
    protected $fillable = ['transaction_id', 'path', 'original_name', 'size'];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        // the file goes when its row goes (deleting one receipt, or the whole transaction)
        static::deleting(function (TransactionAttachment $attachment) {
            Storage::disk('public')->delete($attachment->path);
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The limits from config/sk.php, in the units each place needs.
     * files, file_mb/file_kb/file_bytes/file_label (one file), total_mb/total_bytes/total_label (all files of an expense)
     */
    public static function limits(): array
    {
        $files   = max(1, (int) config('sk.attachments.max_files', 5));
        $fileMb  = max(0.1, (float) config('sk.attachments.max_file_mb', 5));
        $totalMb = max($fileMb, (float) config('sk.attachments.max_total_mb', 15));

        return [
            'files'       => $files,
            'file_mb'     => $fileMb,
            'file_kb'     => (int) round($fileMb * 1024),
            'file_bytes'  => (int) round($fileMb * 1048576),
            'file_label'  => self::mb($fileMb),
            'total_mb'    => $totalMb,
            'total_bytes' => (int) round($totalMb * 1048576),
            'total_label' => self::mb($totalMb),
        ];
    }

    /** 5 -> "5", 2.5 -> "2.5" */
    public static function mb(float $mb): string
    {
        return rtrim(rtrim(number_format($mb, 1), '0'), '.');
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->path));
    }

    protected function isImage(): Attribute
    {
        return Attribute::get(
            fn () => in_array(strtolower(pathinfo($this->path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)
        );
    }

    /** The name the file had when it was uploaded (falls back to the stored name). */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->original_name ?: basename($this->path));
    }

    protected function sizeLabel(): Attribute
    {
        return Attribute::get(function () {
            $bytes = (int) $this->size;

            if ($bytes <= 0) {
                return '';
            }

            return $bytes >= 1048576
                ? number_format($bytes / 1048576, 1) . ' MB'
                : max(1, (int) round($bytes / 1024)) . ' KB';
        });
    }
}
