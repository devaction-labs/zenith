<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Middleware;

use Closure;
use DevactionLabs\Zenith\Audit\HorizonAuditRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecordHorizonMutation
{
    public function __construct(
        private HorizonAuditRecorder $audit,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return;
        }

        $route = $request->route();
        $name = $route?->getName();

        if (! is_string($name) || ! str_starts_with($name, 'zenith.')) {
            return;
        }

        $parameters = $route->parameters();
        $context = [
            'route' => $name,
        ];

        foreach ($parameters as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $context[$key] = $value;
            }
        }

        $this->audit->record($name, $context, $request);
    }
}
