<?php

declare(strict_types=1);

namespace Tests\Feature\Planning;

use Cluion\Migrafold\Planning\SchemaInspectorResolver;
use Cluion\Migrafold\Schema\PostgresSchemaInspector;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SchemaInspectorResolverTest extends TestCase
{
    public function test_it_resolves_the_postgres_inspector(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');

        self::assertInstanceOf(
            PostgresSchemaInspector::class,
            (new SchemaInspectorResolver())->resolve($connection),
        );
    }
}
