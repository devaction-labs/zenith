<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function migrateWorkflowTables(): void
{
    Schema::dropIfExists('zenith_workflow_steps');
    Schema::dropIfExists('zenith_workflows');

    Schema::create('zenith_workflows', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->string('unique_key')->nullable()->unique();
        $table->string('status', 32);
        $table->uuid('parent_id')->nullable();
        $table->string('parent_step')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();
        $table->timestamp('finished_at')->nullable();
    });

    Schema::create('zenith_workflow_steps', function (Blueprint $table): void {
        $table->id();
        $table->uuid('workflow_id');
        $table->string('name');
        $table->string('job_class');
        $table->json('payload')->nullable();
        $table->json('deps')->nullable();
        $table->boolean('cascade')->default(false);
        $table->string('compensate_job')->nullable();
        $table->string('status', 32);
        $table->json('output')->nullable();
        $table->text('error')->nullable();
        $table->string('job_uuid')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamps();
        $table->timestamp('finished_at')->nullable();
        $table->unique(['workflow_id', 'name']);
    });
}
