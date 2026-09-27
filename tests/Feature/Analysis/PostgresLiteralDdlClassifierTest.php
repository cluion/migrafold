<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use Cluion\Migrafold\Analysis\PostgresDdlEffectType;
use Cluion\Migrafold\Analysis\PostgresLiteralDdlClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgresLiteralDdlClassifierTest extends TestCase
{
    #[DataProvider('supportedStatements')]
    public function test_it_classifies_only_narrow_single_statement_ddl(
        string $sql,
        PostgresDdlEffectType $type,
        string $table,
        string $object,
    ): void {
        $effect = (new PostgresLiteralDdlClassifier())->classify($sql);

        self::assertNotNull($effect);
        self::assertSame($type, $effect->type);
        self::assertSame($table, $effect->table);
        self::assertSame($object, $effect->object);
    }

    /** @return iterable<string, array{string, PostgresDdlEffectType, string, string}> */
    public static function supportedStatements(): iterable
    {
        yield 'check constraint' => [
            "ALTER TABLE users ADD CONSTRAINT users_state_check CHECK (state IN ('active', 'blocked'))",
            PostgresDdlEffectType::AddCheckConstraint,
            'users',
            'users_state_check',
        ];

        yield 'quoted public check with trailing terminator' => [
            'ALTER TABLE "public"."Odd""Users" ADD CONSTRAINT "state""check" CHECK (state <> \';\');',
            PostgresDdlEffectType::AddCheckConstraint,
            'Odd"Users',
            'state"check',
        ];

        yield 'not valid check' => [
            'ALTER TABLE ONLY public.users ADD CONSTRAINT users_id_check CHECK (id > 0) NOT VALID',
            PostgresDdlEffectType::AddCheckConstraint,
            'users',
            'users_id_check',
        ];

        yield 'unique expression index' => [
            'CREATE UNIQUE INDEX users_email_ci ON public.users USING btree (lower(email))',
            PostgresDdlEffectType::CreateIndex,
            'users',
            'users_email_ci',
        ];

        yield 'partial index' => [
            "CREATE INDEX reports_open ON reports (status) WHERE status = 'open'",
            PostgresDdlEffectType::CreateIndex,
            'reports',
            'reports_open',
        ];

        yield 'gin index' => [
            'CREATE INDEX documents_search ON documents USING gin (search_vector)',
            PostgresDdlEffectType::CreateIndex,
            'documents',
            'documents_search',
        ];
    }

    #[DataProvider('unsupportedStatements')]
    public function test_it_rejects_ambiguous_or_unmodeled_sql(string $sql): void
    {
        self::assertNull((new PostgresLiteralDdlClassifier())->classify($sql));
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedStatements(): iterable
    {
        yield 'multiple statements' => ['ALTER TABLE users ADD CONSTRAINT a CHECK (id > 0); DROP TABLE users'];
        yield 'line comment' => ["ALTER TABLE users -- hidden\n ADD CONSTRAINT a CHECK (id > 0)"];
        yield 'block comment' => ['ALTER TABLE users /* hidden */ ADD CONSTRAINT a CHECK (id > 0)'];
        yield 'dollar quoted body' => ['CREATE FUNCTION f() RETURNS void AS $$ BEGIN END $$ LANGUAGE plpgsql'];
        yield 'dollar quoted check expression' => ['ALTER TABLE users ADD CONSTRAINT a CHECK (note <> $$hidden$$)'];
        yield 'non-public schema' => ['ALTER TABLE tenant.users ADD CONSTRAINT a CHECK (id > 0)'];
        yield 'foreign key' => ['ALTER TABLE users ADD CONSTRAINT users_role_fk FOREIGN KEY (role_id) REFERENCES roles (id)'];
        yield 'alter nullability' => ['ALTER TABLE users ALTER COLUMN email DROP NOT NULL'];
        yield 'drop constraint' => ['ALTER TABLE users DROP CONSTRAINT users_state_check'];
        yield 'rename constraint' => ['ALTER TABLE users RENAME CONSTRAINT old_check TO new_check'];
        yield 'validate constraint' => ['ALTER TABLE users VALIDATE CONSTRAINT users_state_check'];
        yield 'create table' => ['CREATE TABLE users (id bigint primary key)'];
        yield 'create view' => ['CREATE VIEW active_users AS SELECT * FROM users'];
        yield 'unsupported index method' => ['CREATE INDEX users_email_hash ON users USING hash (email)'];
        yield 'included columns' => ['CREATE INDEX users_email ON users (email) INCLUDE (name)'];
        yield 'storage parameters' => ['CREATE INDEX users_email ON users (email) WITH (fillfactor = 70)'];
        yield 'tablespace' => ['CREATE INDEX users_email ON users (email) TABLESPACE fast'];
        yield 'empty index keys' => ['CREATE INDEX users_empty ON users ()'];
    }
}
