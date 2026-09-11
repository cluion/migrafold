<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;

final readonly class SourceMigration
{
    public function __construct(
        public string $path,
        public string $sha256,
    ) {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        if (
            $path === ''
            || str_contains($path, "\0")
            || $normalized !== $path
            || str_starts_with($path, '/')
            || preg_match('/^[a-zA-Z]:\//', $path) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw UnsafeOutputOperation::because(
                "source migration path [{$path}] must be a normalized project-relative path.",
            );
        }

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw UnsafeOutputOperation::because(
                "source migration [{$path}] has an invalid SHA-256 fingerprint.",
            );
        }
    }

    public static function fromFile(string $projectRoot, string $file): self
    {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw UnsafeOutputOperation::because("project root [{$projectRoot}] is not a readable directory.");
        }

        $portable = str_replace('\\', '/', $file);
        $isAbsolute = str_starts_with($portable, '/') || preg_match('/^[a-zA-Z]:\//', $portable) === 1;
        $candidate = $isAbsolute ? $file : $root.DIRECTORY_SEPARATOR.$file;

        if (is_link($candidate)) {
            throw UnsafeOutputOperation::because("source migration [{$file}] must not be a symbolic link.");
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw UnsafeOutputOperation::because("source migration [{$file}] is not a readable file.");
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($resolved, $prefix)) {
            throw UnsafeOutputOperation::because(
                "source migration [{$file}] is outside project root [{$projectRoot}].",
            );
        }

        $fingerprint = hash_file('sha256', $resolved);

        if ($fingerprint === false) {
            throw UnsafeOutputOperation::because("source migration [{$file}] could not be fingerprinted.");
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($prefix)));

        return new self($relative, $fingerprint);
    }

    /** @return array{path: string, sha256: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'sha256' => $this->sha256,
        ];
    }
}
