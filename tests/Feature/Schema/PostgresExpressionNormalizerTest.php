<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Cluion\Migrafold\Schema\Support\PostgresExpressionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PostgresExpressionNormalizerTest extends TestCase
{
    #[DataProvider('equivalentExpressions')]
    public function test_postgres_deparser_variants_have_one_canonical_form(
        string $source,
        string $replayed,
        string $expected,
    ): void {
        self::assertSame($expected, PostgresExpressionNormalizer::normalize($source));
        self::assertSame($expected, PostgresExpressionNormalizer::normalize($replayed));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function equivalentExpressions(): iterable
    {
        yield 'varchar array cast' => [
            "((status)::text = ANY ((ARRAY['pending'::character varying, 'completed'::character varying])::text[]))",
            "((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('completed'::character varying)::text]))",
            "((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('completed'::character varying)::text]))",
        ];

        yield 'associative boolean grouping' => [
            '(((recipient_count >= 0) AND (recipient_count <= 5)) AND (source_version > 0))',
            '((recipient_count >= 0) AND (recipient_count <= 5) AND (source_version > 0))',
            '((recipient_count >= 0) AND (recipient_count <= 5) AND (source_version > 0))',
        ];

        yield 'quoted boolean text is not parsed as an operator' => [
            "((state = 'A AND B') OR (state = 'C OR D'))",
            "((state = 'A AND B') OR (state = 'C OR D'))",
            "((state = 'A AND B') OR (state = 'C OR D'))",
        ];
    }
}
