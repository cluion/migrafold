<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Migration;

use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;

final class ColumnBlueprintRenderer
{
    public function render(ColumnDefinition $column): string
    {
        $line = '$table->'.$this->typeCall($column);

        if ($column->nullable) {
            $line .= '->nullable()';
        }

        if ($column->default !== null && $column->generation === null) {
            $default = $this->defaultValue($column);

            if ($default !== null) {
                $line .= '->default('.$default.')';
            }
        }

        if ($column->collation !== null) {
            $line .= '->collation('.$this->export($column->collation).')';
        }

        if ($column->comment !== null) {
            $line .= '->comment('.$this->export($column->comment).')';
        }

        if ($column->generation !== null) {
            $method = match ($column->generation->type) {
                'stored' => 'storedAs',
                'virtual' => 'virtualAs',
                default => throw UnsupportedMigrationGeneration::forSchema(
                    "column [{$column->name}] has unsupported generation type [{$column->generation->type}].",
                ),
            };
            $expression = $column->generation->expression;

            if ($expression === null || $expression === '') {
                throw UnsupportedMigrationGeneration::forSchema(
                    "generated column [{$column->name}] has no expression.",
                );
            }

            $line .= "->{$method}(".$this->export($expression).')';
        }

        return $line.';';
    }

    private function typeCall(ColumnDefinition $column): string
    {
        $type = strtolower(trim($column->type));
        $name = $this->export($column->name);

        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)(?:\((\d+)\))?( unsigned)?$/', $type, $matches) === 1) {
            $base = $matches[1];
            $width = ($matches[2] ?? '') === '' ? null : $matches[2];
            $unsigned = ($matches[3] ?? '') !== '';
            $defaultWidths = [
                'tinyint' => '4',
                'smallint' => '6',
                'mediumint' => '9',
                'int' => '11',
                'integer' => '11',
                'bigint' => '20',
            ];

            if ($base === 'tinyint' && $width === '1' && ! $unsigned && ! $column->autoIncrement) {
                return "boolean({$name})";
            }

            if ($width !== null && $width !== $defaultWidths[$base]) {
                throw UnsupportedMigrationGeneration::forSchema(
                    "column [{$column->name}] has unsupported integer display width [{$width}].",
                );
            }

            $method = match ($base) {
                'tinyint' => 'tinyInteger',
                'smallint' => 'smallInteger',
                'mediumint' => 'mediumInteger',
                'bigint' => 'bigInteger',
                default => 'integer',
            };

            if ($column->autoIncrement) {
                if (! $unsigned && $type !== 'integer') {
                    throw UnsupportedMigrationGeneration::forSchema(
                        "auto-increment column [{$column->name}] is not unsigned.",
                    );
                }

                return match ($method) {
                    'tinyInteger' => "tinyIncrements({$name})",
                    'smallInteger' => "smallIncrements({$name})",
                    'mediumInteger' => "mediumIncrements({$name})",
                    'bigInteger' => "bigIncrements({$name})",
                    default => "increments({$name})",
                };
            }

            return ($unsigned ? 'unsigned'.ucfirst($method) : $method)."({$name})";
        }

        if (preg_match('/^varchar(?:\((\d+)\))?$/', $type, $matches) === 1) {
            return isset($matches[1]) ? "string({$name}, {$matches[1]})" : "string({$name})";
        }

        if (preg_match('/^char\((\d+)\)$/', $type, $matches) === 1) {
            return "char({$name}, {$matches[1]})";
        }

        $simple = [
            'tinytext' => 'tinyText',
            'text' => 'text',
            'mediumtext' => 'mediumText',
            'longtext' => 'longText',
            'json' => 'json',
            'date' => 'date',
            'year' => 'year',
        ];

        if (isset($simple[$type])) {
            return $simple[$type]."({$name})";
        }

        if (preg_match('/^(datetime|timestamp|time)(?:\((\d+)\))?$/', $type, $matches) === 1) {
            $method = match ($matches[1]) {
                'datetime' => 'dateTime',
                default => $matches[1],
            };

            return isset($matches[2]) ? "{$method}({$name}, {$matches[2]})" : "{$method}({$name})";
        }

        if (preg_match('/^decimal\((\d+),(\d+)\)( unsigned)?$/', $type, $matches) === 1) {
            $call = "decimal({$name}, {$matches[1]}, {$matches[2]})";

            return ($matches[3] ?? '') === '' ? $call : $call.'->unsigned()';
        }

        if ($type === 'numeric') {
            return "decimal({$name})";
        }

        if (preg_match('/^(float|double)( unsigned)?$/', $type, $matches) === 1) {
            $call = $matches[1] === 'float'
                ? "float({$name}, 24)"
                : "double({$name})";

            return ($matches[2] ?? '') === '' ? $call : $call.'->unsigned()';
        }

        if ($type === 'blob') {
            return "binary({$name})";
        }

        if (preg_match('/^(var)?binary\((\d+)\)$/', $type, $matches) === 1) {
            $fixed = $matches[1] === '' ? ', true' : '';

            return "binary({$name}, {$matches[2]}{$fixed})";
        }

        if (preg_match('/^(enum|set)\((.*)\)$/', $type, $matches) === 1) {
            $values = $this->parseQuotedList($matches[2], $column->name);

            return $matches[1]."({$name}, ".$this->exportList($values).')';
        }

        throw UnsupportedMigrationGeneration::forSchema(
            "column [{$column->name}] has unsupported type [{$column->type}].",
        );
    }

    private function defaultValue(ColumnDefinition $column): ?string
    {
        $default = $column->default;

        if (! is_string($default)) {
            return var_export($default, true);
        }

        if (strcasecmp($default, 'null') === 0) {
            return null;
        }

        if (preg_match("/^'(.*)'$/s", $default, $matches) === 1) {
            return $this->export(str_replace("''", "'", $matches[1]));
        }

        if ($this->isNumericType($column->type) && is_numeric($default)) {
            return $default;
        }

        if (preg_match('/^(?:current_(?:date|time|timestamp)(?:\(\))?|localtime(?:stamp)?(?:\(\))?)$/i', $default) === 1) {
            return 'DB::raw('.$this->export($default).')';
        }

        return $this->export($default);
    }

    private function isNumericType(string $type): bool
    {
        return preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/i', $type) === 1;
    }

    /** @return list<string> */
    private function parseQuotedList(string $value, string $column): array
    {
        if (preg_match_all("/'((?:''|[^'])*)'(?:,|$)/", $value, $matches) === false
            || implode('', $matches[0]) !== $value) {
            throw UnsupportedMigrationGeneration::forSchema(
                "column [{$column}] contains an unsupported enum or set definition.",
            );
        }

        return array_map(
            static fn (string $item): string => str_replace("''", "'", $item),
            $matches[1],
        );
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
