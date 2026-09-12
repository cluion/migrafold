<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use Cluion\Migrafold\Migration\ColumnBlueprintRenderer;
use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnBlueprintRendererTest extends TestCase
{
    #[DataProvider('supportedTypes')]
    public function test_it_maps_supported_physical_types_to_blueprint_calls(string $type, string $expected): void
    {
        self::assertSame($expected, (new ColumnBlueprintRenderer())->render($this->column($type)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function supportedTypes(): iterable
    {
        yield 'boolean' => ['tinyint(1)', "\$table->boolean('value');"];
        yield 'tiny integer' => ['tinyint', "\$table->tinyInteger('value');"];
        yield 'unsigned tiny integer' => ['tinyint unsigned', "\$table->unsignedTinyInteger('value');"];
        yield 'small integer' => ['smallint', "\$table->smallInteger('value');"];
        yield 'medium integer' => ['mediumint', "\$table->mediumInteger('value');"];
        yield 'integer' => ['int', "\$table->integer('value');"];
        yield 'big integer' => ['bigint', "\$table->bigInteger('value');"];
        yield 'unsigned big integer' => ['bigint unsigned', "\$table->unsignedBigInteger('value');"];
        yield 'variable string' => ['varchar', "\$table->string('value');"];
        yield 'limited variable string' => ['varchar(80)', "\$table->string('value', 80);"];
        yield 'fixed string' => ['char(36)', "\$table->char('value', 36);"];
        yield 'tiny text' => ['tinytext', "\$table->tinyText('value');"];
        yield 'text' => ['text', "\$table->text('value');"];
        yield 'medium text' => ['mediumtext', "\$table->mediumText('value');"];
        yield 'long text' => ['longtext', "\$table->longText('value');"];
        yield 'json' => ['json', "\$table->json('value');"];
        yield 'date' => ['date', "\$table->date('value');"];
        yield 'year' => ['year', "\$table->year('value');"];
        yield 'datetime' => ['datetime', "\$table->dateTime('value');"];
        yield 'precise datetime' => ['datetime(6)', "\$table->dateTime('value', 6);"];
        yield 'timestamp' => ['timestamp', "\$table->timestamp('value');"];
        yield 'time' => ['time', "\$table->time('value');"];
        yield 'decimal' => ['decimal(10,2)', "\$table->decimal('value', 10, 2);"];
        yield 'unsigned decimal' => ['decimal(10,2) unsigned', "\$table->decimal('value', 10, 2)->unsigned();"];
        yield 'SQLite numeric' => ['numeric', "\$table->decimal('value');"];
        yield 'float' => ['float', "\$table->float('value', 24);"];
        yield 'unsigned float' => ['float unsigned', "\$table->float('value', 24)->unsigned();"];
        yield 'double' => ['double', "\$table->double('value');"];
        yield 'unsigned double' => ['double unsigned', "\$table->double('value')->unsigned();"];
        yield 'blob' => ['blob', "\$table->binary('value');"];
        yield 'fixed binary' => ['binary(16)', "\$table->binary('value', 16, true);"];
        yield 'variable binary' => ['varbinary(32)', "\$table->binary('value', 32);"];
        yield 'enum' => ["enum('active','disabled')", "\$table->enum('value', ['active', 'disabled']);"];
        yield 'set' => ["set('read','write')", "\$table->set('value', ['read', 'write']);"];
    }

    #[DataProvider('unsupportedTypes')]
    public function test_it_refuses_physical_types_without_a_lossless_mapping(string $type): void
    {
        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage("column [value] has unsupported type [{$type}]");

        (new ColumnBlueprintRenderer())->render($this->column($type));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedTypes(): iterable
    {
        yield 'bit field' => ['bit(8)'];
        yield 'precision-bearing SQLite numeric' => ['numeric(10,2)'];
        yield 'ambiguous real' => ['real'];
        yield 'tiny blob' => ['tinyblob'];
        yield 'medium blob' => ['mediumblob'];
        yield 'long blob' => ['longblob'];
        yield 'geometry' => ['geometry'];
    }

    private function column(string $type): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'value',
            type: $type,
            typeName: strtok($type, '( ') ?: $type,
            nullable: false,
            default: null,
            autoIncrement: false,
            collation: null,
            comment: null,
            generation: null,
        );
    }
}
