<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs\Actions;

use Illuminate\Bus\DebounceLock;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Throwable;

final readonly class ReleaseCancelledJobLocks
{
    public function __construct(
        private CacheFactory $cache,
        private Encrypter $encrypter,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $this->releaseUniqueLockFromContext($payload);

        $command = $this->command($payload);

        if (! is_object($command)) {
            return;
        }

        if ($command instanceof ShouldBeUnique) {
            (new UniqueLock($this->cache->store()))->release($command);
        }

        $owner = $command->debounceOwner ?? null;

        if (is_string($owner) && $owner !== '') {
            (new DebounceLock($this->cache->store()))->release($command, $owner);
        }
    }

    /** @param array<string, mixed> $payload */
    private function releaseUniqueLockFromContext(array $payload): void
    {
        $context = $payload['illuminate:log:context'] ?? null;
        $hidden = is_array($context) ? ($context['hidden'] ?? null) : null;

        if (! is_array($hidden)) {
            return;
        }

        $store = $this->serializedString($hidden['laravel_unique_job_cache_store'] ?? null);
        $key = $this->serializedString($hidden['laravel_unique_job_key'] ?? null);

        if ($key === null || $key === '') {
            return;
        }

        $lockProvider = $this->cache->store($store)->getStore();

        if ($lockProvider instanceof LockProvider) {
            $lockProvider->lock($key)->forceRelease();
        }
    }

    private function serializedString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $restored = @unserialize($value, ['allowed_classes' => false]);

            return is_string($restored) ? $restored : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function command(array $payload): ?object
    {
        $data = $payload['data'] ?? null;
        $serialized = is_array($data) ? ($data['command'] ?? null) : null;
        $allowedClasses = $this->allowedClasses();

        if (! is_string($serialized) || $serialized === '' || $allowedClasses === []) {
            return null;
        }

        try {
            $command = $this->unserializeCommand($serialized, $allowedClasses);

            if ($command !== null) {
                return $command;
            }
        } catch (Throwable) {
            // The payload may contain an encrypted command.
        }

        try {
            $decrypted = $this->encrypter->decrypt($serialized, false);

            if (! is_string($decrypted)) {
                return null;
            }

            $wrapped = @unserialize($decrypted, ['allowed_classes' => false]);
            $decryptedCommand = is_string($wrapped) ? $wrapped : $decrypted;

            return $this->unserializeCommand($decryptedCommand, $allowedClasses);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function allowedClasses(): array
    {
        $configured = config('zenith.job_payload_allowed_classes', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $configured,
            static fn (mixed $class): bool => is_string($class) && class_exists($class),
        )));
    }

    /**
     * @param  array<int, class-string>  $allowedClasses
     */
    private function unserializeCommand(string $serialized, array $allowedClasses): ?object
    {
        $command = @unserialize($serialized, ['allowed_classes' => $allowedClasses]);

        if (! is_object($command) || ! in_array($command::class, $allowedClasses, true)) {
            return null;
        }

        return $command;
    }
}
