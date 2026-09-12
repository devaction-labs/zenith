<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zenith_workflows', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable()->index();
            $table->string('unique_key')->nullable()->unique();
            $table->string('status', 32)->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('parent_step')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->timestamp('finished_at')->nullable();
        });

        Schema::create('zenith_workflow_steps', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('workflow_id')->index();
            $table->string('name');
            $table->string('job_class');
            $table->json('payload')->nullable();
            $table->json('deps')->nullable();
            $table->boolean('cascade')->default(false);
            $table->string('compensate_job')->nullable();
            $table->string('status', 32)->index();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->string('job_uuid')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->timestamp('finished_at')->nullable();

            $table->unique(['workflow_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zenith_workflow_steps');
        Schema::dropIfExists('zenith_workflows');
    }
};
