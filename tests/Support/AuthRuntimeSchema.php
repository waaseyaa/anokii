<?php

declare(strict_types=1);

namespace Anokii\Tests\Support;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Applies the published auth runtime schema so tests that construct
 * {@see \Waaseyaa\Auth\DatabaseRateLimiter} on an empty SQLite file match
 * Framework 297's fail-closed SchemaRequirement.
 */
final class AuthRuntimeSchema
{
    public static function install(DBALDatabase $database): void
    {
        $migration = require dirname(__DIR__, 2) . '/vendor/waaseyaa/auth/migrations/2026_08_12_000001_auth_runtime_schema.php';
        if (!$migration instanceof Migration) {
            throw new \LogicException('The auth runtime schema migration is invalid.');
        }

        $migration->up(new SchemaBuilder($database->getConnection()));
    }
}
