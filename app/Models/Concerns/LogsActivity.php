<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

/** Writes a line in the activity log whenever the model is created, changed or deleted. */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            ActivityLog::record('created', $model);
        });

        static::updated(function ($model) {
            $changes = [];

            foreach ($model->getChanges() as $field => $new) {
                if ($field === 'updated_at') {
                    continue;
                }

                // raw values, so dates and money are stored as plain text
                $changes[$field] = [$model->getRawOriginal($field), $new];
            }

            if ($changes !== []) {
                ActivityLog::record('updated', $model, null, $changes);
            }
        });

        static::deleted(function ($model) {
            ActivityLog::record('deleted', $model);
        });
    }

    /** How this record is named in the log, e.g. "Transaction #12". */
    public function activityLabel(): string
    {
        return Str::headline(class_basename($this)) . ' #' . $this->getKey();
    }
}
