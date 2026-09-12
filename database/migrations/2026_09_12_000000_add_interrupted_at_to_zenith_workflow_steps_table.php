<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zenith_workflow_steps', static function (Blueprint $table): void {
            $table->timestamp('interrupted_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('zenith_workflow_steps', static function (Blueprint $table): void {
            $table->dropColumn('interrupted_at');
        });
    }
};
