<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * Record an administrative action. Never pass secrets in the properties.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(string $action, ?Model $subject = null, array $properties = [], ?User $user = null): AuditLog
    {
        $user ??= $this->request->user();

        return AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
