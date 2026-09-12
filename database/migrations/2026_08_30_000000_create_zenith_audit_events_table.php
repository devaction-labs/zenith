<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zenith_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('action', 128)->index();
            $table->string('route', 128);
            $table->string('user_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zenith_audit_events');
    }
};
