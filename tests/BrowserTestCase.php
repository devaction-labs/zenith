<?php

declare(strict_types=1);

namespace DevactionLabs\HorizonNewDawn\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use RuntimeException;

use function Orchestra\Testbench\default_skeleton_path;

abstract class BrowserTestCase extends TestCase
{
    use RefreshDatabase, WithWorkbench;

    public static function setUpBeforeClass(): void
    {
        // Create database.sqlite before Testbench boots so LoadConfiguration keeps
        // database.default on the named sqlite connection. Otherwise the first
        // browser test falls back to in-memory "testing" while package migrations
        // still write through queue.batching.database (sqlite file), and later
        // tests re-migrate into a file that already has tables.
        self::initializeSqliteDatabaseFile();

        parent::setUpBeforeClass();
    }

    /**
     * Mirror `php vendor/bin/testbench package:create-sqlite-db` for browser
     * suites. Workbench build steps do this for interactive workbench; PHPUnit
     * does not, and CI runners have no pre-existing sqlite file.
     */
    private static function initializeSqliteDatabaseFile(): void
    {
        $directory = default_skeleton_path('database');

        if ($directory === false) {
            throw new RuntimeException('Unable to resolve the Testbench skeleton database directory.');
        }

        $database = $directory.DIRECTORY_SEPARATOR.'database.sqlite';

        if (is_file($database)) {
            return;
        }

        $example = $directory.DIRECTORY_SEPARATOR.'database.sqlite.example';

        if (is_file($example)) {
            copy($example, $database);

            return;
        }

        touch($database);
    }
}
