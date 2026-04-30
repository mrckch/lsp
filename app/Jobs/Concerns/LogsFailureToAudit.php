<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Domain\Audit\Models\AuditLog;

/**
 * Schreibt bei einem fehlgeschlagenen Job einen User-sichtbaren Audit-Eintrag
 * (action='job.failed') mit Klassennamen, Fehlertext und Job-spezifischem Context.
 *
 * Erwartet im Job:
 *  - public ?int $userId    (für actor_user_id; null = system)
 *  - failureContext(): array (Job-spezifische Daten wie test_run_id etc.)
 */
trait LogsFailureToAudit
{
    public function failed(\Throwable $exception): void
    {
        AuditLog::create([
            'actor_type' => $this->userId ? 'user' : 'system',
            'actor_user_id' => $this->userId,
            'action' => 'job.failed',
            'entity_type' => 'job',
            'entity_id' => null,
            'context' => array_merge(
                $this->failureContext(),
                [
                    'job_class' => static::class,
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                    'exception' => class_basename($exception),
                ],
            ),
            'includes_clearnames' => false,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function failureContext(): array
    {
        return [];
    }
}
