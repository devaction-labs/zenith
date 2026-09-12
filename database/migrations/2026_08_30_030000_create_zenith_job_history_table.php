<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zenith_job_history', function (Blueprint $table): void {
            $table->id();
            $table->string('job_class', 255);
            $table->string('queue', 255);
            $table->string('connection', 255);
            $table->string('status', 20);
            $table->unsignedInteger('attempts');
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->json('tags')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->index('completed_at');
            $table->index('failed_at');
            $table->index(['queue', 'completed_at']);
            $table->index(['queue', 'failed_at']);
            $table->index(['job_class', 'completed_at']);
            $table->index(['job_class', 'failed_at']);
            $table->index(['status', 'completed_at']);
            $table->index(['status', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zenith_job_history');
    }
};
