<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A queued job that failed for good (Laravel's failed_jobs table), read for the System health pages. Jobs span every
 * client, so only Super Admins see them.
 */
class FailedJob extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_at' => 'datetime',
        ];
    }

    public function jobName(): string
    {
        return class_basename((string) ($this->payload['displayName'] ?? 'Unknown job'));
    }

    /**
     * The exception's first line, without the stack trace.
     */
    public function summary(): string
    {
        return Str::limit(Str::before((string) $this->exception, "\n"), 300);
    }
}
