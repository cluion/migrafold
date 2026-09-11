<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Migration;

use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
use Cluion\Migrafold\Schema\Definition\IndexDefinition;
use Cluion\Migrafold\Schema\Definition\TableDefinition;

final readonly class TableMigrationRenderer
{
    public function __construct(
        private ColumnBlueprintRenderer $columns = new ColumnBlueprintRenderer(),
    ) {}

    public function render(TableDefinition $table): string
    {
        $this->assertStructureIsSafe($table);
        $this->assertAutoIncrementIsSafe($table);

        $statements = [];

        if ($table->engine !== null) {
            $statements[] = '$table->engine('.$this->export($table->engine).');';
        }

        if ($table->collation !== null) {
            $statements[] = '$table->collation('.$this->export($table->collation).');';
        }

        if ($table->comment !== null) {
            $statements[] = '$table->comment('.$this->export($table->comment).');';
        }

        foreach ($table->columns as $column) {
            $statements[] = $this->columns->render($column);
        }

        foreach ($table->indexes as $index) {
            if ($index->primary && $this->isImplicitAutoIncrementPrimary($table, $index)) {
                continue;
            }

            $statements[] = $this->index($index);
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $statements[] = $this->foreignKey($table, $foreignKey);
        }

        $body = implode("\n", array_map(
            static fn (string $statement): string => '            '.$statement,
            $statements,
        ));
        $usesDb = str_contains($body, 'DB::raw(');
        $dbImport = $usesDb ? "use Illuminate\\Support\\Facades\\DB;\n" : '';
        $tableName = $this->export($table->name);

        $contents = <<<PHP
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
{$dbImport}use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable({$tableName})) {
            return;
        }

        Schema::create({$tableName}, function (Blueprint \$table): void {
{$body}
        });
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'MGF-ROLLBACK-001: Migrafold baselines are irreversible; restore archived history or rebuild explicitly.'
        );
    }
};
PHP;

        return $contents."\n";
    }

    private function index(IndexDefinition $index): string
    {
        if ($index->columns === []) {
            throw UnsupportedMigrationGeneration::forSchema(
                "index [{$index->name}] has no columns.",
            );
        }

        $columns = $this->exportList($index->columns);
        $name = $this->export($index->name);
        $type = strtolower($index->type ?? 'btree');

        if ($index->primary) {
            if ($type !== 'btree') {
                throw UnsupportedMigrationGeneration::forSchema(
                    "primary index [{$index->name}] has unsupported type [{$index->type}].",
                );
            }

            return "\$table->primary({$columns});";
        }

        if ($index->unique) {
            if ($type !== 'btree') {
                throw UnsupportedMigrationGeneration::forSchema(
                    "unique index [{$index->name}] has unsupported type [{$index->type}].",
                );
            }

            return "\$table->unique({$columns}, {$name});";
        }

        $method = match ($type) {
            'btree' => 'index',
            'fulltext' => 'fullText',
            'spatial' => 'spatialIndex',
            default => throw UnsupportedMigrationGeneration::forSchema(
                "index [{$index->name}] has unsupported type [{$index->type}].",
            ),
        };

        return "\$table->{$method}({$columns}, {$name});";
    }

    private function foreignKey(TableDefinition $table, ForeignKeyDefinition $foreignKey): string
    {
        $columns = $this->exportList($foreignKey->columns);
        $name = $foreignKey->name === null ? '' : ', '.$this->export($foreignKey->name);
        $foreignTable = $foreignKey->foreignTable;

        if ($foreignKey->foreignSchema !== null && $foreignKey->foreignSchema !== $table->schema) {
            $foreignTable = $foreignKey->foreignSchema.'.'.$foreignTable;
        }

        $line = "\$table->foreign({$columns}{$name})\n";
        $line .= '                ->references('.$this->exportList($foreignKey->foreignColumns).")\n";
        $line .= '                ->on('.$this->export($foreignTable).')';

        if ($foreignKey->onUpdate !== null) {
            $line .= "\n                ->onUpdate(".$this->export($foreignKey->onUpdate).')';
        }

        if ($foreignKey->onDelete !== null) {
            $line .= "\n                ->onDelete(".$this->export($foreignKey->onDelete).')';
        }

        return $line.';';
    }

    private function assertAutoIncrementIsSafe(TableDefinition $table): void
    {
        $autoIncrement = array_values(array_filter(
            $table->columns,
            static fn ($column): bool => $column->autoIncrement,
        ));

        if ($autoIncrement === []) {
            return;
        }

        if (count($autoIncrement) !== 1) {
            throw UnsupportedMigrationGeneration::forSchema(
                "table [{$table->name}] has multiple auto-increment columns.",
            );
        }

        if ($autoIncrement[0]->nullable || $autoIncrement[0]->default !== null || $autoIncrement[0]->generation !== null) {
            throw UnsupportedMigrationGeneration::forSchema(
                "auto-increment column [{$table->name}.{$autoIncrement[0]->name}] has incompatible modifiers.",
            );
        }

        foreach ($table->indexes as $index) {
            if ($index->primary && $index->columns === [$autoIncrement[0]->name]) {
                return;
            }
        }

        throw UnsupportedMigrationGeneration::forSchema(
            "auto-increment column [{$table->name}.{$autoIncrement[0]->name}] is not the sole primary key.",
        );
    }

    private function assertStructureIsSafe(TableDefinition $table): void
    {
        if ($table->columns === []) {
            throw UnsupportedMigrationGeneration::forSchema(
                "table [{$table->name}] has no columns.",
            );
        }

        $columns = [];

        foreach ($table->columns as $column) {
            $key = strtolower($column->name);

            if (isset($columns[$key])) {
                throw UnsupportedMigrationGeneration::forSchema(
                    "table [{$table->name}] has duplicate column [{$column->name}].",
                );
            }

            $columns[$key] = true;
        }

        foreach ($table->indexes as $index) {
            $this->assertColumnsExist($table, "index [{$index->name}]", $index->columns, $columns);
        }

        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->columns === [] || count($foreignKey->columns) !== count($foreignKey->foreignColumns)) {
                throw UnsupportedMigrationGeneration::forSchema(
                    "foreign key on table [{$table->name}] has mismatched columns.",
                );
            }

            $identity = $foreignKey->name ?? implode('_', $foreignKey->columns);
            $this->assertColumnsExist($table, "foreign key [{$identity}]", $foreignKey->columns, $columns);
        }
    }

    /**
     * @param list<string> $referenced
     * @param array<string, true> $available
     */
    private function assertColumnsExist(
        TableDefinition $table,
        string $owner,
        array $referenced,
        array $available,
    ): void
    {
        foreach ($referenced as $column) {
            if (! isset($available[strtolower($column)])) {
                throw UnsupportedMigrationGeneration::forSchema(
                    "{$owner} references missing column [{$table->name}.{$column}].",
                );
            }
        }
    }

    private function isImplicitAutoIncrementPrimary(TableDefinition $table, IndexDefinition $index): bool
    {
        if (! $index->primary || count($index->columns) !== 1) {
            return false;
        }

        foreach ($table->columns as $column) {
            if ($column->name === $index->columns[0]) {
                return $column->autoIncrement;
            }
        }

        return false;
    }

    /** @param list<string> $values */
    private function exportList(array $values): string
    {
        return '['.implode(', ', array_map($this->export(...), $values)).']';
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }
}
