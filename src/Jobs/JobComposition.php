<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

use DevactionLabs\Zenith\Jobs\Data\JobChainStepData;
use DevactionLabs\Zenith\Jobs\Data\JobCompositionData;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Throwable;

final class JobComposition
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>|null  $decodedCommand
     */
    public static function fromPayload(array $payload, ?array $decodedCommand): JobCompositionData
    {
        $class = self::commandClass($payload, $decodedCommand);

        return new JobCompositionData(
            unique: self::implements($class, ShouldBeUnique::class),
            encrypted: self::implements($class, ShouldBeEncrypted::class),
            chain: self::chain($decodedCommand),
        );
    }

    /**
     * Resolve the retained job's command class name without instantiating it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>|null  $decodedCommand
     */
    public static function commandClass(array $payload, ?array $decodedCommand): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $commandName = $data['commandName'] ?? $decodedCommand['class'] ?? null;

        return is_string($commandName) && $commandName !== '' ? $commandName : null;
    }

    /**
     * @param  array<array-key, mixed>|null  $decodedCommand
     * @return list<JobChainStepData>
     */
    private static function chain(?array $decodedCommand): array
    {
        $chained = $decodedCommand['chained'] ?? null;

        if (! is_array($chained)) {
            return [];
        }

        $steps = [];

        foreach ($chained as $item) {
            $class = self::serializedClass($item);

            if ($class === null) {
                continue;
            }

            $steps[] = new JobChainStepData($class);
        }

        return $steps;
    }

    private static function serializedClass(mixed $value): ?string
    {
        if (is_array($value)) {
            $class = $value['class'] ?? $value['commandName'] ?? null;

            return is_string($class) && $class !== '' ? $class : null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/^O:\d+:"([^"]+)"/', $value, $matches) === 1) {
            return $matches[1];
        }

        try {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        if (is_object($decoded)) {
            $vars = get_object_vars($decoded);
            $class = $vars['__PHP_Incomplete_Class_Name'] ?? null;

            return is_string($class) && $class !== '' ? $class : $decoded::class;
        }

        if (is_array($decoded)) {
            $class = $decoded['class'] ?? $decoded['__PHP_Incomplete_Class_Name'] ?? null;

            return is_string($class) && $class !== '' ? $class : null;
        }

        return null;
    }

    /**
     * @param  class-string  $contract
     */
    private static function implements(?string $class, string $contract): bool
    {
        return is_string($class)
            && class_exists($class)
            && is_a($class, $contract, true);
    }
}
