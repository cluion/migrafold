<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;

final readonly class ModuarkMigrationSourceAdapter implements MigrationSourceAdapter
{
    /**
     * @param list<MigrationOwner> $owners
     */
    private function __construct(
        private string $projectRoot,
        private array $owners,
        private FilesystemMigrationScanner $scanner = new FilesystemMigrationScanner(),
    ) {}

    public static function fromRuntime(
        string $projectRoot,
        object $registry,
        object $resourceManifest,
        object $tableOwnership,
    ): self {
        return self::fromPayloads(
            $projectRoot,
            self::arrayMethod($registry, 'toArray'),
            self::arrayMethod($resourceManifest, 'toArray'),
            self::arrayMethod($tableOwnership, 'all'),
        );
    }

    /**
     * @param array<mixed> $registry
     * @param array<mixed> $resourceManifest
     * @param array<mixed> $tableOwnership
     */
    public static function fromPayloads(
        string $projectRoot,
        array $registry,
        array $resourceManifest,
        array $tableOwnership,
    ): self {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw MigrationDiscoveryFailed::because("project root [{$projectRoot}] is not a readable directory.");
        }

        if (! array_is_list($registry)) {
            throw MigrationDiscoveryFailed::because('Moduark registry payload must be a list.');
        }

        $modules = [];

        foreach ($registry as $entry) {
            if (! is_array($entry)) {
                throw MigrationDiscoveryFailed::because('Moduark registry contains an invalid Module entry.');
            }

            $name = $entry['name'] ?? null;
            $class = $entry['class'] ?? null;
            $path = $entry['path'] ?? null;

            if (! is_string($name) || ! is_string($class) || ! is_string($path) || $name === '' || $class === '') {
                throw MigrationDiscoveryFailed::because('Moduark registry contains incomplete Module identity.');
            }

            $classKey = strtolower($class);

            if (isset($modules[$classKey])) {
                throw MigrationDiscoveryFailed::because("Moduark Module class [{$class}] is duplicated.");
            }

            $moduleFile = self::moduleFile($root, $path);
            $moduleRoot = self::moduleRoot($moduleFile);
            self::assertProjectOwned($root, $moduleRoot, $name);
            $modules[$classKey] = [
                'name' => $name,
                'class' => $class,
                'root' => $moduleRoot,
                'tables' => [],
            ];
        }

        foreach ($tableOwnership as $table => $class) {
            if (! is_string($table) || ! is_string($class) || $table === '' || $class === '') {
                throw MigrationDiscoveryFailed::because('Moduark table ownership payload is invalid.');
            }

            $classKey = strtolower($class);

            if (! isset($modules[$classKey])) {
                throw MigrationDiscoveryFailed::because(
                    "Moduark table [{$table}] references inactive or unknown owner [{$class}].",
                );
            }

            $modules[$classKey]['tables'][] = $table;
        }

        $resources = $resourceManifest['resources'] ?? null;

        if (! is_array($resources) || ! array_is_list($resources)) {
            throw MigrationDiscoveryFailed::because('Moduark resource manifest payload is invalid.');
        }

        $migrationDirectories = [];

        foreach ($resources as $resource) {
            if (! is_array($resource) || ($resource['plugin'] ?? null) !== 'migrations') {
                continue;
            }

            $class = $resource['module'] ?? null;
            $source = $resource['source'] ?? null;

            if (! is_string($class) || ! is_string($source) || $class === '' || $source === '') {
                throw MigrationDiscoveryFailed::because('Moduark migration resource is invalid.');
            }

            $classKey = strtolower($class);

            if (! isset($modules[$classKey])) {
                throw MigrationDiscoveryFailed::because(
                    "Moduark migration resource references inactive or unknown owner [{$class}].",
                );
            }

            if (isset($migrationDirectories[$classKey])) {
                throw MigrationDiscoveryFailed::because(
                    "Moduark Module [{$modules[$classKey]['name']}] exposes multiple migration directories.",
                );
            }

            $migrationDirectories[$classKey] = self::migrationDirectory(
                $root,
                $modules[$classKey]['root'],
                $source,
                $modules[$classKey]['name'],
            );
        }

        $owners = [];

        foreach ($modules as $classKey => $module) {
            $directory = $migrationDirectories[$classKey]
                ?? self::conventionalMigrationDirectory($module['root']);
            $owners[] = new MigrationOwner(
                id: 'moduark:'.$module['name'],
                name: $module['name'],
                migrationDirectory: $directory,
                tables: $module['tables'],
            );
        }

        return new self($root, $owners);
    }

    public function discover(): AdapterDiscovery
    {
        $migrations = [];

        foreach ($this->owners as $owner) {
            array_push($migrations, ...$this->scanner->scan($this->projectRoot, $owner));
        }

        return new AdapterDiscovery($this->owners, $migrations);
    }

    /** @return array<mixed> */
    private static function arrayMethod(object $object, string $method): array
    {
        if (! is_callable([$object, $method])) {
            throw MigrationDiscoveryFailed::because(
                'Moduark runtime object ['.$object::class."] does not expose [{$method}()].",
            );
        }

        $value = call_user_func([$object, $method]);

        if (! is_array($value)) {
            throw MigrationDiscoveryFailed::because(
                'Moduark runtime method ['.$object::class."::{$method}()] did not return an array.",
            );
        }

        return $value;
    }

    private static function moduleFile(string $root, string $path): string
    {
        $portable = str_replace('\\', '/', $path);
        $absolute = str_starts_with($portable, '/') || preg_match('/\A[A-Za-z]:\//', $portable) === 1;
        $candidate = $absolute ? $path : $root.DIRECTORY_SEPARATOR.$path;

        if (is_link($candidate)) {
            throw MigrationDiscoveryFailed::because("Moduark Module file [{$path}] must not be a symbolic link.");
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_file($resolved) || ! self::within($root, $resolved)) {
            throw MigrationDiscoveryFailed::because(
                "Moduark Module file [{$path}] is missing or outside the project root.",
            );
        }

        return $resolved;
    }

    private static function moduleRoot(string $moduleFile): string
    {
        $entryRoot = dirname($moduleFile);

        if (basename($entryRoot) === 'src' && is_file(dirname($entryRoot).'/composer.json')) {
            return dirname($entryRoot);
        }

        return basename($entryRoot) === 'app' ? dirname($entryRoot) : $entryRoot;
    }

    private static function assertProjectOwned(string $root, string $moduleRoot, string $name): void
    {
        if (! self::within($root, $moduleRoot)) {
            throw MigrationDiscoveryFailed::because("Moduark Module [{$name}] is outside the project root.");
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($moduleRoot, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));

        if ($relative === 'vendor' || str_starts_with($relative, 'vendor/')) {
            throw MigrationDiscoveryFailed::because(
                "Moduark Module [{$name}] is vendor-owned and cannot receive generated migrations.",
            );
        }
    }

    private static function migrationDirectory(
        string $root,
        string $moduleRoot,
        string $source,
        string $name,
    ): string {
        if (is_link($source)) {
            throw MigrationDiscoveryFailed::because(
                "Moduark migration directory for [{$name}] must not be a symbolic link.",
            );
        }

        $resolved = realpath($source);

        if ($resolved === false || ! is_dir($resolved) || ! self::within($root, $resolved)) {
            throw MigrationDiscoveryFailed::because(
                "Moduark migration directory [{$source}] for [{$name}] is missing or outside the project root.",
            );
        }

        $allowed = array_filter([
            realpath($moduleRoot.'/Database/Migrations'),
            realpath($moduleRoot.'/database/migrations'),
        ], static fn (string|false $path): bool => is_string($path));

        if (! in_array($resolved, $allowed, true)) {
            throw MigrationDiscoveryFailed::because(
                "Moduark migration directory [{$source}] for [{$name}] is not a supported conventional path.",
            );
        }

        return $resolved;
    }

    private static function conventionalMigrationDirectory(string $moduleRoot): string
    {
        $upper = $moduleRoot.'/Database/Migrations';
        $lower = $moduleRoot.'/database/migrations';

        if (is_dir($upper)) {
            return $upper;
        }

        return is_dir($lower) ? $lower : $upper;
    }

    private static function within(string $root, string $path): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
