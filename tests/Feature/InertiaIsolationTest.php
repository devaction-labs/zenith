<?php

declare(strict_types=1);

use DevactionLabs\Zenith\Assets\AssetPath;
use DevactionLabs\Zenith\Http\Middleware\HandleInertiaRequests;
use DevactionLabs\Zenith\ZenithServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Inertia\Inertia;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response as SsrResponse;

use function Pest\Laravel\get;

describe('Inertia isolation', function (): void {
    it('applies package Inertia middleware only to Zenith page routes', function (): void {
        $pageRoute = Route::getRoutes()->getByName('zenith.dashboard');
        $fallbackRoute = Route::getRoutes()->getByName('horizon.index');
        $apiRoute = Route::getRoutes()->getByName('horizon.stats.index');

        expect($pageRoute)->not->toBeNull()
            ->and($fallbackRoute)->not->toBeNull()
            ->and($apiRoute)->not->toBeNull()
            ->and($pageRoute?->gatherMiddleware())->toContain(HandleInertiaRequests::class)
            ->and($fallbackRoute?->gatherMiddleware())->not->toContain(HandleInertiaRequests::class)
            ->and($apiRoute?->gatherMiddleware())->not->toContain(HandleInertiaRequests::class);
    });

    it('does not share Zenith runtime data with host Inertia routes', function (): void {
        Route::get('/host-inertia', fn () => Inertia::render('Host'));

        get('/host-inertia', ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertJsonPath('component', 'Host')
            ->assertJsonMissingPath('props.horizon')
            ->assertJsonMissingPath('props.navigationCounts')
            ->assertJsonMissingPath('props.meta');
    });

    it('excludes the configured Horizon path from host Inertia SSR', function (): void {
        config()->set('horizon.path', 'operations/horizon');
        config()->set('inertia.ssr.enabled', true);
        config()->set('inertia.ssr.ensure_bundle_exists', false);

        (new ZenithServiceProvider(app()))->boot(app(AssetPath::class));

        Http::fake([
            '*' => Http::response([
                'head' => [],
                'body' => '<main>Server-rendered host page</main>',
            ]),
        ]);

        $gateway = app(Gateway::class);
        $page = [
            'component' => 'Dashboard',
            'props' => [],
            'url' => '/operations/horizon',
            'version' => null,
            'clearHistory' => false,
            'encryptHistory' => false,
        ];

        expect($gateway->dispatch(
            $page,
            Request::create('/operations/horizon/dashboard'),
        ))->toBeNull()
            ->and($gateway->dispatch(
                [...$page, 'component' => 'Host', 'url' => '/account'],
                Request::create('/account'),
            ))->not->toBeNull();

        Http::assertSentCount(1);
    });

    it('keeps unrelated host routes eligible for SSR when Horizon owns the root path', function (): void {
        config()->set('horizon.path', '');
        config()->set('inertia.ssr.enabled', true);
        config()->set('inertia.ssr.ensure_bundle_exists', false);

        (new ZenithServiceProvider(app()))->boot(app(AssetPath::class));

        Http::fake([
            '*' => Http::response([
                'head' => [],
                'body' => '<main>Server-rendered host page</main>',
            ]),
        ]);

        $gateway = app(Gateway::class);
        $page = [
            'component' => 'Dashboard',
            'props' => [],
            'url' => '/dashboard',
            'version' => null,
            'clearHistory' => false,
            'encryptHistory' => false,
        ];

        expect($gateway->dispatch(
            $page,
            Request::create('/dashboard'),
        ))->toBeNull()
            ->and($gateway->dispatch(
                [...$page, 'component' => 'Host', 'url' => '/account'],
                Request::create('/account'),
            ))->not->toBeNull();

        Http::assertSentCount(1);
    });

    it('boots with a host SSR gateway that cannot exclude paths', function (): void {
        $gateway = new class implements Gateway
        {
            public function dispatch(array $page): ?SsrResponse
            {
                return null;
            }
        };

        app()->instance(Gateway::class, $gateway);

        (new ZenithServiceProvider(app()))->boot(app(AssetPath::class));

        expect(app(Gateway::class))->toBe($gateway);
    });

    it('adds the host Vite CSP nonce to both package scripts', function (): void {
        Vite::useCspNonce('zenith-csp-nonce');

        Route::get(
            '/zenith-csp',
            fn () => Inertia::render('Test')->rootView('zenith::app'),
        );

        $content = get('/zenith-csp')
            ->assertOk()
            ->getContent();

        if ($content === false) {
            throw new RuntimeException('The CSP test response content could not be read.');
        }

        expect($content)->toContain('<script nonce="zenith-csp-nonce">')
            ->and($content)->toContain('<meta name="csp-nonce" content="zenith-csp-nonce">')
            ->and($content)->toContain('type="module"')
            ->and($content)->toContain('/vendor/zenith/build/assets/')
            ->and($content)->toContain('data-horizon-favicon')
            ->and($content)->toContain('nonce="zenith-csp-nonce"')
            ->and(substr_count($content, 'nonce="zenith-csp-nonce"'))->toBeGreaterThanOrEqual(2)
            ->and(preg_match(
                '/<script[^>]*type="module"[^>]*nonce="zenith-csp-nonce"|<script[^>]*nonce="zenith-csp-nonce"[^>]*type="module"/',
                $content,
            ))->toBe(1)
            ->and($content)->not->toContain('/vendor/zenith/build/favicon.svg');
    });

    it('versions Zenith requests from the published package asset manifest', function (): void {
        $filesystem = app(Filesystem::class);
        $originalPublicPath = public_path();
        $publicPath = sys_get_temp_dir().'/zenith-version-test-'.uniqid('', true);
        $buildDirectory = $publicPath.'/vendor/zenith/build';
        $manifestPath = $buildDirectory.'/manifest.json';

        $filesystem->ensureDirectoryExists($buildDirectory);
        app()->usePublicPath($publicPath);

        try {
            $firstManifest = json_encode([
                'resources/js/app.tsx' => [
                    'file' => 'assets/app-first.js',
                    'css' => [],
                    'isEntry' => true,
                    'src' => 'resources/js/app.tsx',
                ],
            ], JSON_THROW_ON_ERROR);
            $secondManifest = json_encode([
                'resources/js/app.tsx' => [
                    'file' => 'assets/app-second.js',
                    'css' => [],
                    'isEntry' => true,
                    'src' => 'resources/js/app.tsx',
                ],
            ], JSON_THROW_ON_ERROR);

            $filesystem->put($manifestPath, $firstManifest);

            $middleware = app(HandleInertiaRequests::class);
            $request = Request::create('/horizon');

            expect($middleware->version($request))->toBe(md5($firstManifest));

            $filesystem->put($manifestPath, $secondManifest);

            expect($middleware->version($request))->toBe(md5($secondManifest));
        } finally {
            app()->usePublicPath($originalPublicPath);
            $filesystem->deleteDirectory($publicPath);
        }
    });

    it('shares empty flash props for requests without a session', function (): void {
        $request = Request::create('/horizon');
        $shared = app(HandleInertiaRequests::class)->share($request);

        expect($request->hasSession())->toBeFalse()
            ->and(($shared['flash']['success'])())->toBeNull()
            ->and(($shared['flash']['error'])())->toBeNull();
    });

    it('shares string flash props for requests with a session', function (): void {
        $request = Request::create('/horizon');
        $session = app('session.store');
        $session->put('toast.success', 'Saved.');
        $session->put('toast.error', 'Try again.');
        $request->setLaravelSession($session);

        $shared = app(HandleInertiaRequests::class)->share($request);

        expect(($shared['flash']['success'])())->toBe('Saved.')
            ->and(($shared['flash']['error'])())->toBe('Try again.');
    });
});
