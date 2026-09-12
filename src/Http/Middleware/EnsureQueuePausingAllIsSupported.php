<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Middleware;

use Closure;
use DevactionLabs\Zenith\Support\FrameworkCapabilities;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureQueuePausingAllIsSupported
{
    public function __construct(
        private FrameworkCapabilities $capabilities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->capabilities->queuePausingAll, 404);

        return $next($request);
    }
}
