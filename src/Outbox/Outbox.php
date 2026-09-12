<?php

declare(strict_types=1);

namespace DevactionLabs\Zenith\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A transactional outbox for job dispatch.
 *
 * `Outbox::dispatch()` writes a row inside the caller's own database
 * transaction, so the row and the application data it describes commit or
 * roll back together. Delivery always happens later, through `relayDue()`,
 * scheduled on `zenith:relay-outbox`: a crash between the commit and a push
 * to the real queue is recovered by the next sweep instead of losing the job.
 * This is at-least-once, the same guarantee Oban's own outbox gives: a crash
 * between a successful push and marking the row sent redelivers it, so jobs
 * dispatched through the outbox should stay idempotent or use
 * `ShouldBeUnique`.
 */
final class Outbox
{
    public static function dispatch(object $job, ?string $connection = null, ?string $queue = null): int
    {
        return DB::table('zenith_outbox')->insertGetId([
            'connection' => $connection,
            'queue' => $queue,
            'job' => base64_encode(serialize($job)),
            'created_at' => Date::now(),
            'sent_at' => null,
        ]);
    }

    public static function relayDue(int $graceSeconds = 0): int
    {
        $rows = DB::table('zenith_outbox')
            ->whereNull('sent_at')
            ->where('created_at', '<=', Date::now()->subSeconds($graceSeconds))
            ->orderBy('id')
            ->get();

        $relayed = 0;

        foreach ($rows as $row) {
            if (self::relayRow((array) $row)) {
                $relayed++;
            }
        }

        return $relayed;
    }

    /**
     * @return array{count: int, oldestPendingSeconds: ?int}
     */
    public static function backlog(): array
    {
        $count = DB::table('zenith_outbox')->whereNull('sent_at')->count();

        if ($count === 0) {
            return ['count' => 0, 'oldestPendingSeconds' => null];
        }

        $oldestCreatedAt = DB::table('zenith_outbox')
            ->whereNull('sent_at')
            ->orderBy('created_at')
            ->value('created_at');

        return [
            'count' => $count,
            'oldestPendingSeconds' => is_string($oldestCreatedAt) || is_int($oldestCreatedAt)
                ? (int) round(Date::now()->diffInSeconds(Date::parse($oldestCreatedAt), absolute: true))
                : null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function relayRow(array $row): bool
    {
        $id = $row['id'] ?? null;
        $encoded = $row['job'] ?? null;

        if (! is_int($id) || ! is_string($encoded)) {
            return false;
        }

        try {
            $job = self::decode($encoded);
        } catch (Throwable $exception) {
            report($exception);
            DB::table('zenith_outbox')->where('id', $id)->delete();

            return false;
        }

        $connection = $row['connection'] ?? null;
        $queue = $row['queue'] ?? null;

        if (is_string($connection) && $connection !== '' && method_exists($job, 'onConnection')) {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '' && method_exists($job, 'onQueue')) {
            $job->onQueue($queue);
        }

        app(Dispatcher::class)->dispatch($job);

        DB::table('zenith_outbox')->where('id', $id)->delete();

        return true;
    }

    private static function decode(string $encoded): object
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new RuntimeException('The outbox row could not be base64-decoded.');
        }

        $job = unserialize($decoded);

        if (! is_object($job)) {
            throw new RuntimeException('The outbox row did not unserialize to a job object.');
        }

        return $job;
    }
}
