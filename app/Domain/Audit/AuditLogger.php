<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

final class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    public function logUser(
        User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
        bool $includesClearnames = false,
    ): AuditLog {
        return $this->log(
            actorType: 'user',
            actorUserId: $actor->id,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            context: $context,
            includesClearnames: $includesClearnames,
        );
    }

    public function logSystem(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
    ): AuditLog {
        return $this->log(
            actorType: 'system',
            actorUserId: null,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            context: $context,
            includesClearnames: false,
        );
    }

    public function log(
        string $actorType,
        ?int $actorUserId,
        string $action,
        ?string $entityType,
        ?int $entityId,
        array $context,
        bool $includesClearnames,
    ): AuditLog {
        return AuditLog::create([
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'context' => $context,
            'includes_clearnames' => $includesClearnames,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }
}
