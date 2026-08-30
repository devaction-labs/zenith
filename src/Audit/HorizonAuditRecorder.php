<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Audit;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class HorizonAuditRecorder
{
    public const string TABLE = 'horizon_new_dawn_audit_events';

    public function __construct(
        private AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, array $context = [], ?Request $request = null): void
    {
        $entry = [
            'action' => $action,
            'route' => is_string($context['route'] ?? null) ? $context['route'] : $action,
            'user_id' => $this->userId(),
            'ip' => $request?->ip(),
            'context' => $this->safeContext($context),
        ];

        Log::info('horizon-new-dawn.audit', $entry);

        if (! $this->tableReady()) {
            return;
        }

        try {
            HorizonAuditEvent::query()->create([
                'occurred_at' => now(),
                'action' => $entry['action'],
                'route' => $entry['route'],
                'user_id' => $entry['user_id'],
                'ip' => $entry['ip'],
                'context' => $entry['context'],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    private function userId(): ?string
    {
        $user = $this->auth->guard()->user();

        if ($user === null) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|null>
     */
    private function safeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (in_array($key, ['payload', 'exception', 'exception_message'], true)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
