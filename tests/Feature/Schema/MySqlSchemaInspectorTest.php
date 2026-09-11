<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\MySqlSchemaInspector;
use Tests\TestCase;

final class MySqlSchemaInspectorTest extends TestCase
{
    public function test_it_refuses_non_mysql_connections_before_inspection(): void
    {
        $this->expectException(UnsupportedDatabaseDriver::class);
        $this->expectExceptionMessage(
            'MGF-SCHEMA-001: Database driver [sqlite] is not supported by the MySQL/MariaDB inspector.',
        );

        (new MySqlSchemaInspector())->inspect($this->connection());
    }
}
