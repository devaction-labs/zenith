<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Jobs;

use Illuminate\Support\Str;

/**
 * Parses a minimal `tag:` qualifier out of the free-text job search box.
 * Everything else in the search string falls through unchanged to the
 * existing partial job-class or exact-ID search.
 */
final readonly class JobSearchQualifiers
{
    private const string TAG_PREFIX = 'tag:';

    public function __construct(
        public ?string $tag,
        public ?string $remainder,
    ) {}

    public static function parse(?string $search): self
    {
        $search = trim($search ?? '');

        if ($search === '') {
            return new self(null, null);
        }

        $tokens = preg_split('/\s+/', $search);

        if ($tokens === false) {
            return new self(null, $search);
        }

        $tag = null;
        $remainingTokens = [];

        foreach ($tokens as $token) {
            if ($tag === null && self::isTagToken($token)) {
                $tag = Str::after($token, self::TAG_PREFIX);

                continue;
            }

            $remainingTokens[] = $token;
        }

        $remainder = trim(implode(' ', $remainingTokens));

        return new self($tag, $remainder === '' ? null : $remainder);
    }

    private static function isTagToken(string $token): bool
    {
        return Str::startsWith($token, self::TAG_PREFIX)
            && $token !== self::TAG_PREFIX;
    }
}
