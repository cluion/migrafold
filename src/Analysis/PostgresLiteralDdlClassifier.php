<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

final class PostgresLiteralDdlClassifier
{
    private const IDENTIFIER = '(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';

    private const QUALIFIED_IDENTIFIER = self::IDENTIFIER.'(?:\s*\.\s*'.self::IDENTIFIER.')?';

    public function classify(string $sql): ?PostgresDdlEffect
    {
        $sql = $this->singleStatement($sql);

        if ($sql === null) {
            return null;
        }

        return $this->addCheckConstraint($sql)
            ?? $this->alterColumnNullability($sql)
            ?? $this->validateConstraint($sql)
            ?? $this->dropConstraint($sql)
            ?? $this->renameConstraint($sql)
            ?? $this->createIndex($sql);
    }

    private function alterColumnNullability(string $sql): ?PostgresDdlEffect
    {
        $pattern = '/\AALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>'.self::QUALIFIED_IDENTIFIER.')'
            .'\s+ALTER\s+COLUMN\s+(?<column>'.self::IDENTIFIER.')'
            .'\s+(?<action>DROP|SET)\s+NOT\s+NULL\z/is';

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $table = $this->publicObjectName($matches['table']);
        $column = $this->identifierName($matches['column']);

        if ($table === null || $column === null) {
            return null;
        }

        $type = strtolower($matches['action']) === 'drop'
            ? PostgresDdlEffectType::DropColumnNotNull
            : PostgresDdlEffectType::SetColumnNotNull;

        return new PostgresDdlEffect($type, $table, $column, 0);
    }

    private function addCheckConstraint(string $sql): ?PostgresDdlEffect
    {
        $pattern = '/\AALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>'.self::QUALIFIED_IDENTIFIER.')'
            .'\s+ADD\s+CONSTRAINT\s+(?<constraint>'.self::IDENTIFIER.')\s+CHECK\s*'
            .'(?<expression>\(.+\))(?:\s+NOT\s+VALID)?\z/is';

        if (preg_match($pattern, $sql, $matches) !== 1
            || ! $this->balancedParentheses($matches['expression'])) {
            return null;
        }

        $table = $this->publicObjectName($matches['table']);
        $constraint = $this->identifierName($matches['constraint']);

        if ($table === null || $constraint === null) {
            return null;
        }

        return new PostgresDdlEffect(
            PostgresDdlEffectType::AddCheckConstraint,
            $table,
            $constraint,
            0,
        );
    }

    private function createIndex(string $sql): ?PostgresDdlEffect
    {
        $pattern = '/\ACREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?'
            .'(?<index>'.self::QUALIFIED_IDENTIFIER.')\s+ON\s+(?:ONLY\s+)?'
            .'(?<table>'.self::QUALIFIED_IDENTIFIER.')'
            .'(?:\s+USING\s+(?<method>'.self::IDENTIFIER.'))?\s*(?<definition>\(.+)\z/is';

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $method = $matches['method'];

        if ($method !== '') {
            $method = $this->identifierName($method);

            if ($method === null || ! in_array(strtolower($method), ['btree', 'gin'], true)) {
                return null;
            }
        }

        $definition = $matches['definition'];
        $closing = $this->matchingParenthesis($definition, 0);

        if ($closing === null || trim(substr($definition, 1, $closing - 1)) === '') {
            return null;
        }

        $suffix = trim(substr($definition, $closing + 1));

        if ($suffix !== '' && preg_match('/\AWHERE\s+.+\z/is', $suffix) !== 1) {
            return null;
        }

        if (! $this->balancedParentheses($definition)) {
            return null;
        }

        $table = $this->publicObjectName($matches['table']);
        $index = $this->publicObjectName($matches['index']);

        if ($table === null || $index === null) {
            return null;
        }

        return new PostgresDdlEffect(
            PostgresDdlEffectType::CreateIndex,
            $table,
            $index,
            0,
        );
    }

    private function validateConstraint(string $sql): ?PostgresDdlEffect
    {
        $pattern = '/\AALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>'.self::QUALIFIED_IDENTIFIER.')'
            .'\s+VALIDATE\s+CONSTRAINT\s+(?<constraint>'.self::IDENTIFIER.')\z/is';

        return $this->constraintEffect($sql, $pattern, PostgresDdlEffectType::ValidateConstraint);
    }

    private function dropConstraint(string $sql): ?PostgresDdlEffect
    {
        $pattern = '/\AALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>'.self::QUALIFIED_IDENTIFIER.')'
            .'\s+DROP\s+CONSTRAINT\s+(?<constraint>'.self::IDENTIFIER.')\z/is';

        return $this->constraintEffect($sql, $pattern, PostgresDdlEffectType::DropConstraint);
    }

    private function renameConstraint(string $sql): ?PostgresDdlEffect
    {
        $pattern = '/\AALTER\s+TABLE\s+(?:ONLY\s+)?(?<table>'.self::QUALIFIED_IDENTIFIER.')'
            .'\s+RENAME\s+CONSTRAINT\s+(?<constraint>'.self::IDENTIFIER.')'
            .'\s+TO\s+(?<target>'.self::IDENTIFIER.')\z/is';

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $table = $this->publicObjectName($matches['table']);
        $constraint = $this->identifierName($matches['constraint']);
        $target = $this->identifierName($matches['target']);

        if ($table === null || $constraint === null || $target === null || $constraint === $target) {
            return null;
        }

        return new PostgresDdlEffect(
            PostgresDdlEffectType::RenameConstraint,
            $table,
            $constraint,
            0,
            $target,
        );
    }

    private function constraintEffect(
        string $sql,
        string $pattern,
        PostgresDdlEffectType $type,
    ): ?PostgresDdlEffect
    {
        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $table = $this->publicObjectName($matches['table']);
        $constraint = $this->identifierName($matches['constraint']);

        if ($table === null || $constraint === null) {
            return null;
        }

        return new PostgresDdlEffect($type, $table, $constraint, 0);
    }

    private function singleStatement(string $sql): ?string
    {
        if (str_contains($sql, "\0")) {
            return null;
        }

        $sql = trim($sql);

        if ($sql === '') {
            return null;
        }

        $quote = null;
        $semicolon = null;
        $length = strlen($sql);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $sql[$offset];

            if ($quote !== null) {
                if ($character === $quote) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                        $offset++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if (($character === '-' && ($sql[$offset + 1] ?? null) === '-')
                || ($character === '/' && ($sql[$offset + 1] ?? null) === '*')
                || ($character === '$' && preg_match('/\G\$[A-Za-z_0-9]*\$/', $sql, $match, 0, $offset) === 1)) {
                return null;
            }

            if ($character === ';') {
                if ($semicolon !== null) {
                    return null;
                }

                $semicolon = $offset;
            }
        }

        if ($quote !== null) {
            return null;
        }

        if ($semicolon !== null) {
            if (trim(substr($sql, $semicolon + 1)) !== '') {
                return null;
            }

            $sql = rtrim(substr($sql, 0, $semicolon));
        }

        return $sql === '' ? null : $sql;
    }

    private function balancedParentheses(string $sql): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($sql);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $sql[$offset];

            if ($quote !== null) {
                if ($character === $quote) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                        $offset++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth < 0) {
                return false;
            }
        }

        return $quote === null && $depth === 0;
    }

    private function matchingParenthesis(string $sql, int $opening): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($sql);

        for ($offset = $opening; $offset < $length; $offset++) {
            $character = $sql[$offset];

            if ($quote !== null) {
                if ($character === $quote) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                        $offset++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $offset;
            }
        }

        return null;
    }

    private function publicObjectName(string $identifier): ?string
    {
        $parts = preg_split('/\s*\.\s*/', trim($identifier));

        if (! is_array($parts) || count($parts) > 2) {
            return null;
        }

        if (count($parts) === 2) {
            $schema = $this->identifierName($parts[0]);

            if ($schema !== 'public') {
                return null;
            }
        }

        return $this->identifierName($parts[count($parts) - 1]);
    }

    private function identifierName(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_$]*\z/', $identifier) === 1) {
            return strtolower($identifier);
        }

        if (preg_match('/\A"((?:[^"]|"")*)"\z/s', $identifier, $matches) === 1) {
            return str_replace('""', '"', $matches[1]);
        }

        return null;
    }
}
