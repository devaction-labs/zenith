<?php

declare(strict_types=1);

use DevactionLabs\HorizonNewDawn\Audit\HorizonAuditEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Horizon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutMiddleware;

beforeEach(function (): void {
    withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
    Horizon::auth(static fn (): bool => true);
    Schema::dropIfExists('horizon_new_dawn_audit_events');
    Schema::create('horizon_new_dawn_audit_events', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('occurred_at')->index();
        $table->string('action', 128);
        $table->string('route', 128);
        $table->string('user_id')->nullable();
        $table->string('ip', 45)->nullable();
        $table->json('context')->nullable();
    });
});

it('records successful mutations in the audit table', function (): void {
    requireQueuePausing();

    post('/horizon/queues/redis/reports/pause')->assertRedirect();

    expect(HorizonAuditEvent::query()->where('route', 'horizon-new-dawn.queues.pause.store')->count())
        ->toBeGreaterThan(0);
});

it('renders the audit page', function (): void {
    get('/horizon/audit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Audit/Index'));
});
