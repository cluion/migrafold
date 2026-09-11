<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

use JsonException;

final readonly class SchemaSnapshot
{
    /**
     * @param list<TableDefinition> $tables
     */
    public function __construct(
        public string $formatVersion,
        public string $driver,
        public CapabilityReport $capabilities,
        public array $tables,
    ) {}

    /**
     * @return array{
     *     format_version: string,
     *     driver: string,
     *     capabilities: array{supported: list<string>, unsupported: list<string>},
     *     tables: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'driver' => $this->driver,
            'capabilities' => $this->capabilities->toArray(),
            'tables' => array_map(
                static fn (TableDefinition $table): array => $table->toArray(),
                $this->tables,
            ),
        ];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', $this->toJson());
    }
}
