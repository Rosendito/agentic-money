<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/**
 * A Laravel-booted test case that migrates the schema once (via RefreshDatabase) but never wraps an
 * individual test in an uncommitted database transaction.
 *
 * docs/tasks/008-posting-kernel-hardening.md finding 4 requires a real two-database-connection
 * concurrency test: a second, independent connection must be able to see this test's fixture rows
 * and observe a real row lock. The default `tests/Pest.php` binding
 * (`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`) wraps every
 * Feature test in a transaction that is rolled back at the end of the test — a second connection
 * would never see any of that uncommitted data, making a genuine cross-connection race impossible to
 * observe rather than merely hard to trigger.
 *
 * This overrides `beginDatabaseTransaction()` rather than `connectionsToTransact()`: an empty
 * `connectionsToTransact()` looks like the right escape hatch (it is Laravel's own supported way to
 * skip the transaction), but `RefreshDatabase::updateLocalCacheOfInMemoryDatabases()` and
 * `beginDatabaseTransaction()` both iterate `connectionsToTransact()` to know which connections'
 * PDO handle to cache in `RefreshDatabaseState::$inMemoryConnections` for SQLite's `:memory:`
 * driver (each fresh application boot otherwise gets a brand new, empty in-memory database).
 * Emptying that list broke every *other* SQLite test that happened to run after this one in the same
 * process: they restored nothing (there was nothing cached) and inherited a schema-less connection.
 * Keeping the normal connection list but skipping only the `beginTransaction()`/rollback pair
 * preserves that caching behavior while still making every write in this test case real and
 * committed.
 */
abstract class NonTransactionalTestCase extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction()
    {
        $database = $this->app->make('db');

        foreach ($this->connectionsToTransact() as $name) {
            $connection = $database->connection($name);

            if ($this->usingInMemoryDatabase($name)) {
                RefreshDatabaseState::$inMemoryConnections[$name] ??= $connection->getPdo();
            }
        }
    }
}
