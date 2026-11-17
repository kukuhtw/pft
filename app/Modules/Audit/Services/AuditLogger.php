<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class AuditLogger
{
    /** Keys that must never be written to the audit trail. */
    private const array REDACT = ['password', 'token', 'secret', 'api_key', 'account_number', 'card_number', 'pin'];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(string $event, ?User $owner, ?Model $subject = null, array $properties = []): AuditLog
    {
        $request = app()->bound('request') ? request() : null;
        $actorId = Auth::id() ?? $owner?->id;

        return AuditLog::create([
            'user_id' => $owner?->id,
            'actor_id' => $actorId,
            'event' => $event,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'properties' => $this->redact($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (Str::contains(strtolower((string) $key), self::REDACT)) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }
}
