<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

it('loads the compiled dashboard under a nonce-only content security policy', function (): void {
    Vite::useCspNonce('zenith-browser-nonce');
    app(Kernel::class)->pushMiddleware(ZenithBrowserCsp::class);

    $page = visit('/horizon');

    $page
        ->assertSee('Dashboard')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    expect($page->script(<<<'JS'
        () => Array.from(document.head.querySelectorAll('style'))
            .filter((style) => !style.nonce)
            .map((style) => style.textContent?.slice(0, 80) ?? '')
    JS))->toBe([]);
});

final class ZenithBrowserCsp
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $nonce = 'zenith-browser-nonce';

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; "
            ."script-src 'self' 'nonce-{$nonce}'; "
            ."style-src 'self' 'nonce-{$nonce}'; "
            ."font-src 'self'; img-src 'self' data:; connect-src 'self'",
        );

        return $response;
    }
}
