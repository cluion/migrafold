<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\PostgresSchemaInspector;
use Tests\TestCase;

final class PostgresSchemaInspectorTest extends TestCase
{
    public function test_it_refuses_non_postgres_connections_before_inspection(): void
    {
        $this->expectException(UnsupportedDatabaseDriver::class);
        $this->expectExceptionMessage(
            'MGF-SCHEMA-001: Database driver [sqlite] is not supported by the PostgreSQL inspector.',
        );

        (new PostgresSchemaInspector())->inspect($this->connection());
    }
}
