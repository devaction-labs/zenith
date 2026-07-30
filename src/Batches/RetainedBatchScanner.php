<?php

declare(strict_types=1);

namespace NckRtl\HorizonNewDawn\Batches;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use RuntimeException;

final readonly class RetainedBatchScanner
{
    public function __construct(private BatchRepository $batches) {}

    /**
     * Yield each validated repository page until the retained history is exhausted.
     *
     * @return \Generator<int, list<Batch>>
     */
    public function pages(int $pageSize): \Generator
    {
        $pageSize = max(1, $pageSize);
        $cursor = null;

        while (true) {
            $page = $this->validatedPage(
                $this->batches->get($pageSize, $cursor),
                $pageSize,
            );

            if ($page === []) {
                return;
            }

            yield $page;

            $cursor = $this->advanceCursor($page, $cursor);
        }
    }

    /**
     * @return list<Batch>
     */
    private function validatedPage(mixed $page, int $requestLimit): array
    {
        if (! is_array($page)) {
            throw new RuntimeException('The batch repository returned an invalid page.');
        }

        if (count($page) > $requestLimit) {
            throw new RuntimeException('The batch repository exceeded the requested page size.');
        }

        $batches = [];

        foreach ($page as $batch) {
            if (! $batch instanceof Batch) {
                throw new RuntimeException('The batch repository returned an invalid batch.');
            }

            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param  list<Batch>  $batches
     */
    private function advanceCursor(array $batches, ?string $current): string
    {
        $batch = end($batches);

        if (! $batch instanceof Batch) {
            throw new RuntimeException('The batch repository returned an empty page.');
        }

        $cursor = $batch->id;

        if ($cursor === '' || ($current !== null && strcmp($cursor, $current) >= 0)) {
            throw new RuntimeException('The batch repository did not advance its pagination cursor.');
        }

        return $cursor;
    }
}
