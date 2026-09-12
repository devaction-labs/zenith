<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Telemetry;

/**
 * The worker process a telemetry event is attributed to.
 *
 * `horizon:work` always receives `--supervisor=<name>` (see
 * `Laravel\Horizon\QueueCommandString`), so the supervisor name is read
 * straight from the running process's command line rather than through a
 * dedicated event listener. The node identity defaults to the local
 * hostname and can be overridden with `zenith.telemetry.node` for
 * deployments where the hostname is not a meaningful identifier.
 */
final readonly class WorkerIdentity
{
    public function __construct(
        public string $node,
        public ?string $supervisor,
    ) {}

    public static function current(): self
    {
        return new self(
            node: self::resolveNode(),
            supervisor: self::argvOption('supervisor'),
        );
    }

    private static function resolveNode(): string
    {
        $configured = config('zenith.telemetry.node');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        $hostname = gethostname();

        return $hostname !== false && $hostname !== '' ? $hostname : 'unknown';
    }

    private static function argvOption(string $name): ?string
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv)) {
            return null;
        }

        $needle = "--{$name}=";

        foreach ($argv as $argument) {
            if (is_string($argument) && str_starts_with($argument, $needle)) {
                $value = substr($argument, strlen($needle));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }
}
