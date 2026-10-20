<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Tests run on sqlite only (see phpunit.xml). Refuse to migrate anything
     * else so a misconfigured env can never wipe a real database.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = config('database.default');

        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            throw new RuntimeException('Tests must run on sqlite, not ['.$connection.'].');
        }
    }
}
