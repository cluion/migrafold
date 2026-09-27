<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Support;

final class PostgresExpressionNormalizer
{
    public static function normalize(string $expression): string
    {
        $expression = self::normalizeTextArrayCasts($expression);
        $node = self::parseBooleanExpression($expression);

        return self::render($node, true);
    }

    private static function normalizeTextArrayCasts(string $expression): string
    {
        return preg_replace_callback(
            <<<'REGEX'
~\(\(ARRAY\[(?<items>'(?:''|[^'])*'::character varying(?:, '(?:''|[^'])*'::character varying)*)\]\)::text\[\]\)~
REGEX,
            static function (array $matches): string {
                $items = preg_replace_callback(
                    "~'((?:''|[^'])*)'::character varying~",
                    static fn (array $item): string => "('{$item[1]}'::character varying)::text",
                    $matches['items'],
                );

                return '(ARRAY['.$items.'])';
            },
            $expression,
        ) ?? $expression;
    }

    private static function parseBooleanExpression(string $expression): PostgresBooleanNode
    {
        $expression = self::stripEnclosingParentheses(trim($expression));

        foreach (['OR', 'AND'] as $operator) {
            $parts = self::splitAtTopLevel($expression, $operator);

            if (count($parts) < 2) {
                continue;
            }

            $nodes = [];

            foreach ($parts as $part) {
                $node = self::parseBooleanExpression($part);

                if ($node->operator === $operator) {
                    array_push($nodes, ...$node->parts);
                } else {
                    $nodes[] = $node;
                }
            }

            return PostgresBooleanNode::group($operator, $nodes);
        }

        return PostgresBooleanNode::leaf($expression);
    }

    private static function stripEnclosingParentheses(string $expression): string
    {
        while (str_starts_with($expression, '(')
            && self::matchingParenthesis($expression, 0) === strlen($expression) - 1) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    /** @return list<string> */
    private static function splitAtTopLevel(string $expression, string $operator): array
    {
        $separator = ' '.$operator.' ';
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $expression[$offset];

            if ($quote !== null) {
                if ($character === $quote) {
                    if ($offset + 1 < $length && $expression[$offset + 1] === $quote) {
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

                continue;
            }

            if ($character === ')') {
                $depth--;

                continue;
            }

            if ($depth === 0 && substr($expression, $offset, strlen($separator)) === $separator) {
                $parts[] = trim(substr($expression, $start, $offset - $start));
                $offset += strlen($separator) - 1;
                $start = $offset + 1;
            }
        }

        if ($parts === []) {
            return [$expression];
        }

        $parts[] = trim(substr($expression, $start));

        return $parts;
    }

    private static function matchingParenthesis(string $expression, int $opening): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($expression);

        for ($offset = $opening; $offset < $length; $offset++) {
            $character = $expression[$offset];

            if ($quote !== null) {
                if ($character === $quote) {
                    if ($offset + 1 < $length && $expression[$offset + 1] === $quote) {
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

    private static function render(PostgresBooleanNode $node, bool $root = false): string
    {
        if ($node->operator === null) {
            $sql = $node->sql;

            return $root ? '('.$sql.')' : $sql;
        }

        $parts = array_map(
            static fn (PostgresBooleanNode $part): string => $part->operator === null
                ? '('.self::render($part).')'
                : self::render($part),
            $node->parts,
        );

        return '('.implode(' '.$node->operator.' ', $parts).')';
    }
}

/** @internal */
final readonly class PostgresBooleanNode
{
    /**
     * @param 'AND'|'OR'|null $operator
     * @param list<self> $parts
     */
    private function __construct(
        public ?string $operator,
        public string $sql,
        public array $parts,
    ) {}

    public static function leaf(string $sql): self
    {
        return new self(null, $sql, []);
    }

    /**
     * @param 'AND'|'OR' $operator
     * @param list<self> $parts
     */
    public static function group(string $operator, array $parts): self
    {
        return new self($operator, '', $parts);
    }
}
