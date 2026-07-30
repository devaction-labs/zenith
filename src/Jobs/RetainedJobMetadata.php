<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Jobs;

use JsonException;

final readonly class RetainedJobMetadata
{
    /** @param array<int, string> $tags */
    public function __construct(
        public string $id,
        public string $name,
        public string $queue,
        public string $connection,
        public array $tags,
    ) {}

    public static function fromJob(object $job): ?self
    {
        $id = $job->id ?? null;
        $name = $job->name ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
            return null;
        }

        $payload = self::payload($job->payload ?? null);

        return new self(
            id: $id,
            name: $name,
            queue: self::stringValue($job->queue ?? null, 'default'),
            connection: self::stringValue($job->connection ?? null, 'default'),
            tags: self::payloadTags($payload),
        );
    }

    public function encode(): string
    {
        return json_encode([
            $this->name,
            $this->queue,
            $this->connection,
            $this->tags,
        ], JSON_THROW_ON_ERROR);
    }

    public static function decode(string $id, mixed $encoded): ?self
    {
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        try {
            $values = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($values)
            || ! is_string($values[0] ?? null)
            || $values[0] === ''
            || ! is_string($values[1] ?? null)
            || ! is_string($values[2] ?? null)
        ) {
            return null;
        }

        $tags = is_array($values[3] ?? null)
            ? array_values(array_filter($values[3], is_string(...)))
            : [];

        return new self(
            id: $id,
            name: $values[0],
            queue: $values[1],
            connection: $values[2],
            tags: $tags,
        );
    }

    /** @return array<string, array<int, string>> */
    public function facetValues(): array
    {
        return [
            'job' => [$this->name],
            'queue' => [$this->queue],
            'connection' => [$this->connection],
            'tag' => array_values(array_unique($this->tags)),
            'target' => [$this->targetValue()],
        ];
    }

    public function targetValue(): string
    {
        return json_encode(
            [$this->connection, $this->queue],
            JSON_THROW_ON_ERROR,
        );
    }

    private static function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /** @return array<string, mixed> */
    private static function payload(mixed $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private static function payloadTags(array $payload): array
    {
        $tags = $payload['tags'] ?? null;

        return is_array($tags)
            ? array_values(array_filter($tags, is_string(...)))
            : [];
    }
}
