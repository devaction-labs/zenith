<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Console;

use DevactionLabs\Zenith\Outbox\Outbox;
use Illuminate\Console\Command;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'zenith:relay-outbox {--grace-seconds=30}';

    protected $description = 'Push due transactional outbox rows onto the real queue';

    public function handle(): int
    {
        $graceSeconds = (int) $this->option('grace-seconds');

        $relayed = Outbox::relayDue($graceSeconds);

        $this->components->info("Relayed {$relayed} outbox row(s).");

        return self::SUCCESS;
    }
}
