<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zenith_dynamic_crons', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('expression');
            $table->string('job_class');
            $table->json('payload')->nullable();
            $table->boolean('paused')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zenith_dynamic_crons');
    }
};
