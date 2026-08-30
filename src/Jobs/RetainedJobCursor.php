<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Jobs;

use JsonException;
use RuntimeException;

final class RetainedJobCursor
{
    public function encode(
        RetainedJobType $type,
        string $querySignature,
        RetainedJobPosition $position,
    ): string {
        return $this->encodeState(
            $type,
            $querySignature,
            new RetainedJobCursorState($position, RetainedJobCursorState::PHASE_DEFAULT),
        );
    }

    public function encodeState(
        RetainedJobType $type,
        string $querySignature,
        RetainedJobCursorState $state,
    ): string {
        $phase = $state->phase;
        $position = $state->position;

        if (
            ! in_array($phase, [
                RetainedJobCursorState::PHASE_DEFAULT,
                RetainedJobCursorState::PHASE_RESERVED,
                RetainedJobCursorState::PHASE_REST,
            ], true)
        ) {
            throw new RuntimeException('The retained job cursor phase is invalid.');
        }

        if ($phase === RetainedJobCursorState::PHASE_DEFAULT && $position === null) {
            throw new RuntimeException('The retained job cursor position is required.');
        }

        if (
            $position !== null
            && (
                $position->id === ''
                || $position->offset < 0
                || $position->score === null
            )
        ) {
            throw new RuntimeException('The retained job cursor position is invalid.');
        }

        try {
            $payload = [
                'version' => $phase === RetainedJobCursorState::PHASE_DEFAULT ? 4 : 5,
                'type' => $type->value,
                'query' => $querySignature,
                'score' => $position === null
                    ? null
                    : sprintf('%.17g', $position->score),
                'id' => $position === null ? null : $position->id,
                'offset' => $position === null ? 0 : $position->offset,
            ];

            if ($phase !== RetainedJobCursorState::PHASE_DEFAULT) {
                $payload['phase'] = $phase;
            }

            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The retained job cursor could not be encoded.',
                previous: $exception,
            );
        }

        $encoded = $this->base64UrlEncode($encodedPayload);
        $signature = hash_hmac('sha256', $encoded, $this->signingKey(), true);

        return $encoded.'.'.$this->base64UrlEncode($signature);
    }

    public function decode(
        int|string|null $cursor,
        RetainedJobType $type,
        string $querySignature,
    ): ?RetainedJobPosition {
        $state = $this->decodeState($cursor, $type, $querySignature);

        return $state?->position;
    }

    public function decodeState(
        int|string|null $cursor,
        RetainedJobType $type,
        string $querySignature,
    ): ?RetainedJobCursorState {
        if ($cursor === null || $cursor === -1 || $cursor === '-1' || $cursor === '') {
            return null;
        }

        if (! is_string($cursor)) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        $segments = explode('.', $cursor, 2);

        if (count($segments) !== 2) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        [$encoded, $encodedSignature] = $segments;
        $signature = $this->base64UrlDecode($encodedSignature);
        $expected = hash_hmac('sha256', $encoded, $this->signingKey(), true);

        if ($signature === null || ! hash_equals($expected, $signature)) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        $payload = $this->decodePayload($encoded);
        $version = $payload['version'] ?? null;
        $phase = $payload['phase'] ?? RetainedJobCursorState::PHASE_DEFAULT;

        if (
            ! in_array($version, [4, 5], true)
            || ($payload['type'] ?? null) !== $type->value
            || ($payload['query'] ?? null) !== $querySignature
            || ! is_string($phase)
            || ! in_array($phase, [
                RetainedJobCursorState::PHASE_DEFAULT,
                RetainedJobCursorState::PHASE_RESERVED,
                RetainedJobCursorState::PHASE_REST,
            ], true)
            || ($version === 4 && $phase !== RetainedJobCursorState::PHASE_DEFAULT)
            || ($version === 5 && $phase === RetainedJobCursorState::PHASE_DEFAULT)
            || ! is_int($payload['offset'] ?? null)
            || $payload['offset'] < 0
        ) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        $score = $payload['score'] ?? null;
        $id = $payload['id'] ?? null;

        if ($score === null && $id === null) {
            if ($phase === RetainedJobCursorState::PHASE_DEFAULT) {
                throw new RuntimeException('The retained job cursor is invalid.');
            }

            return new RetainedJobCursorState(null, $phase);
        }

        if (
            ! is_numeric($score)
            || ! is_string($id)
            || $id === ''
        ) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        return new RetainedJobCursorState(
            new RetainedJobPosition(
                score: (float) $score,
                id: $id,
                offset: $payload['offset'],
            ),
            $phase,
        );
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $encoded): array
    {
        $payload = $this->base64UrlDecode($encoded);

        if ($payload === null) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The retained job cursor is invalid.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The retained job cursor is invalid.');
        }

        return $decoded;
    }

    private function signingKey(): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'An application key is required for retained job pagination.',
            );
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
