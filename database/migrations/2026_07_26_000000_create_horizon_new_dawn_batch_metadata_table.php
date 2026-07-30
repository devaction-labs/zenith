<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->batchConnection())->create(
            'horizon_new_dawn_batch_metadata',
            function (Blueprint $table): void {
                $table->string('batch_id')->primary();
                $table->string('queue')->nullable();
                $table->string('connection')->nullable();
                $table->boolean('queue_is_explicit');
                $table->boolean('connection_is_explicit');

                $table->index('queue', 'hnd_batch_metadata_queue_index');
                $table->index('connection', 'hnd_batch_metadata_connection_index');
            },
        );
    }

    public function down(): void
    {
        Schema::connection($this->batchConnection())
            ->dropIfExists('horizon_new_dawn_batch_metadata');
    }

    private function batchConnection(): ?string
    {
        $connection = config('queue.batching.database');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
};
