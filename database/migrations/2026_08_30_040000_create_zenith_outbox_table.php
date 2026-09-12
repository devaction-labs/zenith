<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zenith_outbox', static function (Blueprint $table): void {
            $table->id();
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->text('job');
            $table->timestamp('created_at');
            $table->timestamp('sent_at')->nullable();

            $table->index(['sent_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zenith_outbox');
    }
};
