<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Http\Middleware;

use Closure;
use DevactionLabs\HorizonNewDawn\Authorization\HorizonAbility;
use DevactionLabs\HorizonNewDawn\Authorization\HorizonAbilityAuthorizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthorizeHorizonAbility
{
    public function __construct(
        private HorizonAbilityAuthorizer $authorizer,
    ) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $this->authorizer->authorize(HorizonAbility::from($ability));

        return $next($request);
    }
}
