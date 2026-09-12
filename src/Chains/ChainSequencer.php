<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Chains;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * A per-key FIFO ticket sequencer backed by the cache store named by
 * zenith.chains.store (Redis or another lock-capable store shared by every
 * worker), or the default store when it is null.
 *
 * Every dispatch of a chained job is assigned a strictly increasing ticket
 * number for its key. A ticket may run once it equals the key's current
 * position, which starts at 1 and advances by one only when the job holding
 * the current ticket finishes (success or terminal failure) and calls
 * advance(). Ticket assignment and advancing are both guarded by the same
 * per-key lock, so concurrent dispatches or finishes never corrupt the
 * sequence.
 */
final class ChainSequencer
{
    private const string TICKET_PREFIX = 'zenith:chain-ticket:';

    private const string CURSOR_PREFIX = 'zenith:chain-cursor:';

    private const string LOCK_PREFIX = 'zenith:chain-lock:';

    private const int LOCK_SECONDS = 10;

    /**
     * Assign the next ticket in the key's sequence. The first ticket for a
     * key is 1, matching the position a chain starts at before anything runs.
     */
    public static function assignTicket(string $key): int
    {
        return self::exclusively($key, static function () use ($key): int {
            $store = self::store();
            $current = $store->get(self::TICKET_PREFIX.$key);
            $ticket = (is_numeric($current) ? (int) $current : 0) + 1;
            $store->forever(self::TICKET_PREFIX.$key, $ticket);

            return $ticket;
        });
    }

    /**
     * Whether $ticket currently holds the key's position and may run.
     */
    public static function isNext(string $key, int $ticket): bool
    {
        return self::currentPosition($key) === $ticket;
    }

    /**
     * Advance the key's position past $ticket, so the next ticket may run.
     * Ignored unless $ticket currently holds the position, which makes the
     * call safe to repeat.
     */
    public static function advance(string $key, int $ticket): void
    {
        self::exclusively($key, static function () use ($key, $ticket): void {
            if (self::currentPosition($key) === $ticket) {
                self::store()->forever(self::CURSOR_PREFIX.$key, $ticket + 1);
            }
        });
    }

    private static function currentPosition(string $key): int
    {
        $cursor = self::store()->get(self::CURSOR_PREFIX.$key);

        return is_numeric($cursor) ? (int) $cursor : 1;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws LogicException
     */
    private static function exclusively(string $key, Closure $callback): mixed
    {
        $locks = self::store()->getStore();

        if (! $locks instanceof LockProvider) {
            throw new LogicException('Zenith chains require a cache store that supports atomic locks.');
        }

        return $locks->lock(self::LOCK_PREFIX.$key, self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $callback);
    }

    private static function store(): Repository
    {
        $store = config('zenith.chains.store');

        return app(Factory::class)->store(is_string($store) ? $store : null);
    }
}
