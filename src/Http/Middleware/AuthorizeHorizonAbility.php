<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Http\Middleware;

use Closure;
use DevactionLabs\Zenith\Authorization\HorizonAbility;
use DevactionLabs\Zenith\Authorization\HorizonAbilityAuthorizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthorizeHorizonAbility
{
    public function __construct(
        private HorizonAbilityAuthorizer $authorizer,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $this->authorizer->authorize(HorizonAbility::from($ability));

        return $next($request);
    }
}
