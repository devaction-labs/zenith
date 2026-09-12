<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Middleware;

use Closure;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureQueuePausingIsSupported
{
    public function __construct(
        private FrameworkCapabilities $capabilities,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->capabilities->queuePausing, 404);

        return $next($request);
    }
}
