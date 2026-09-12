<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;

final readonly class NwidartMigrationSourceAdapter implements MigrationSourceAdapter
{
    /** @param list<MigrationOwner> $owners */
    private function __construct(
        private string $projectRoot,
        private array $owners,
        private FilesystemMigrationScanner $scanner = new FilesystemMigrationScanner(),
    ) {}

    /** @param array<mixed> $tableOwnership */
    public static function fromRuntime(
        string $projectRoot,
        object $repository,
        array $tableOwnership,
    ): self {
        $enabled = self::arrayMethod($repository, 'allEnabled');
        $modules = [];

        foreach ($enabled as $module) {
            if (! is_object($module)) {
                throw MigrationDiscoveryFailed::because(
                    'nWidart enabled Module collection contains an invalid entry.',
                );
            }

            $modules[] = [
                'name' => self::stringMethod($module, 'getName'),
                'path' => self::stringMethod($module, 'getPath'),
            ];
        }

        return self::fromPayloads(
            $projectRoot,
            $modules,
            $tableOwnership,
            self::migrationPath($repository),
        );
    }

    /**
     * @param array<mixed> $modules
     * @param array<mixed> $tableOwnership
     */
    public static function fromPayloads(
        string $projectRoot,
        array $modules,
        array $tableOwnership,
        string $relativeMigrationPath = 'database/migrations',
    ): self {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw MigrationDiscoveryFailed::because("project root [{$projectRoot}] is not a readable directory.");
        }

        if (! array_is_list($modules)) {
            throw MigrationDiscoveryFailed::because('nWidart enabled Module payload must be a list.');
        }

        self::assertRelativeMigrationPath($relativeMigrationPath);
        $indexed = [];

        foreach ($modules as $module) {
            if (! is_array($module)) {
                throw MigrationDiscoveryFailed::because('nWidart enabled Module payload contains an invalid entry.');
            }

            $name = $module['name'] ?? null;
            $path = $module['path'] ?? null;

            if (! is_string($name) || ! is_string($path) || $name === '' || trim($name) !== $name) {
                throw MigrationDiscoveryFailed::because('nWidart enabled Module payload contains incomplete identity.');
            }

            $key = strtolower($name);

            if (isset($indexed[$key])) {
                throw MigrationDiscoveryFailed::because("nWidart Module [{$name}] is duplicated.");
            }

            $moduleRoot = self::moduleRoot($root, $path, $name);
            $indexed[$key] = [
                'name' => $name,
                'root' => $moduleRoot,
                'tables' => [],
            ];
        }

        $tables = [];

        foreach ($tableOwnership as $table => $moduleName) {
            if (
                ! is_string($table)
                || ! is_string($moduleName)
                || $table === ''
                || $moduleName === ''
            ) {
                throw MigrationDiscoveryFailed::because('nWidart table ownership configuration is invalid.');
            }

            $tableKey = strtolower($table);
            $moduleKey = strtolower($moduleName);

            if (isset($tables[$tableKey])) {
                throw MigrationDiscoveryFailed::because(
                    "nWidart table [{$table}] is configured more than once with case-conflicting names.",
                );
            }

            if (! isset($indexed[$moduleKey])) {
                throw MigrationDiscoveryFailed::because(
                    "nWidart table [{$table}] references inactive or unknown Module [{$moduleName}].",
                );
            }

            $tables[$tableKey] = true;
            $indexed[$moduleKey]['tables'][] = $table;
        }

        ksort($indexed, SORT_STRING);
        $owners = [];

        foreach ($indexed as $module) {
            $owners[] = new MigrationOwner(
                id: 'nwidart:'.$module['name'],
                name: $module['name'],
                migrationDirectory: self::migrationDirectory(
                    $root,
                    $module['root'],
                    $relativeMigrationPath,
                    $module['name'],
                ),
                tables: $module['tables'],
            );
        }

        return new self($root, $owners);
    }

    public function discover(): AdapterDiscovery
    {
        $migrations = [];

        foreach ($this->owners as $owner) {
            $ownedMigrations = $this->scanner->scan($this->projectRoot, $owner);

            if ($ownedMigrations !== [] && $owner->tables === []) {
                throw MigrationDiscoveryFailed::because(
                    "active nWidart Module [{$owner->name}] has migration sources but no explicit table ownership in [migrafold.nwidart.table_owners].",
                );
            }

            array_push($migrations, ...$ownedMigrations);
        }

        return new AdapterDiscovery($this->owners, $migrations);
    }

    /** @return array<mixed> */
    private static function arrayMethod(object $object, string $method): array
    {
        if (! is_callable([$object, $method])) {
            throw MigrationDiscoveryFailed::because(
                'nWidart runtime object ['.$object::class."] does not expose [{$method}()].",
            );
        }

        $value = call_user_func([$object, $method]);

        if (! is_array($value)) {
            throw MigrationDiscoveryFailed::because(
                'nWidart runtime method ['.$object::class."::{$method}()] did not return an array.",
            );
        }

        return $value;
    }

    private static function stringMethod(object $object, string $method): string
    {
        if (! is_callable([$object, $method])) {
            throw MigrationDiscoveryFailed::because(
                'nWidart Module ['.$object::class."] does not expose [{$method}()].",
            );
        }

        $value = call_user_func([$object, $method]);

        if (! is_string($value) || $value === '') {
            throw MigrationDiscoveryFailed::because(
                'nWidart Module method ['.$object::class."::{$method}()] did not return a non-empty string.",
            );
        }

        return $value;
    }

    private static function migrationPath(object $repository): string
    {
        if (! is_callable([$repository, 'config'])) {
            throw MigrationDiscoveryFailed::because(
                'nWidart runtime repository ['.$repository::class.'] does not expose [config()].',
            );
        }

        $value = call_user_func(
            [$repository, 'config'],
            'paths.generator.migration.path',
            'database/migrations',
        );

        if (! is_string($value) || $value === '') {
            throw MigrationDiscoveryFailed::because(
                'nWidart migration generator path configuration must be a non-empty string.',
            );
        }

        return $value;
    }

    private static function moduleRoot(string $root, string $path, string $name): string
    {
        if (is_link($path)) {
            throw MigrationDiscoveryFailed::because("nWidart Module [{$name}] path must not be a symbolic link.");
        }

        $resolved = realpath($path);

        if (
            $resolved === false
            || ! is_dir($resolved)
            || $resolved === $root
            || ! self::within($root, $resolved)
        ) {
            throw MigrationDiscoveryFailed::because(
                "nWidart Module [{$name}] path [{$path}] is missing or outside the project root.",
            );
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));

        if ($relative === 'vendor' || str_starts_with($relative, 'vendor/')) {
            throw MigrationDiscoveryFailed::because(
                "nWidart Module [{$name}] is vendor-owned and cannot receive generated migrations.",
            );
        }

        return $resolved;
    }

    private static function migrationDirectory(
        string $root,
        string $moduleRoot,
        string $relativePath,
        string $name,
    ): string {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, str_replace('\\', '/', $relativePath));
        $candidate = $moduleRoot.DIRECTORY_SEPARATOR.$relative;

        if (is_link($candidate)) {
            throw MigrationDiscoveryFailed::because(
                "nWidart migration directory for [{$name}] must not be a symbolic link.",
            );
        }

        $resolved = realpath($candidate);

        if ($resolved === false) {
            return $candidate;
        }

        if (! is_dir($resolved) || ! self::within($root, $resolved) || ! self::within($moduleRoot, $resolved)) {
            throw MigrationDiscoveryFailed::because(
                "nWidart migration directory [{$candidate}] for [{$name}] is outside its Module root.",
            );
        }

        return $resolved;
    }

    private static function assertRelativeMigrationPath(string $path): void
    {
        $portable = str_replace('\\', '/', $path);
        $segments = explode('/', $portable);

        if (
            $path === ''
            || str_starts_with($portable, '/')
            || preg_match('/\A[A-Za-z]:\//', $portable) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw MigrationDiscoveryFailed::because(
                "nWidart migration generator path [{$path}] must be Module-relative.",
            );
        }
    }

    private static function within(string $root, string $path): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
