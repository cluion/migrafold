<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use Cluion\Migrafold\Analysis\Exception\MigrationAnalysisFailed;
use Cluion\Migrafold\Analysis\MigrationAnalyzer;
use Cluion\Migrafold\Analysis\MigrationClassification;
use Cluion\Migrafold\Analysis\MigrationCompactionAction;
use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Output\SourceMigration;
use PHPUnit\Framework\TestCase;

final class MigrationAnalyzerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $file = $root.'/database/migrations/2020_01_01_000000_fixture.php';

            if (is_file($file)) {
                unlink($file);
            }

            rmdir(dirname($file));
            rmdir(dirname(dirname($file)));
            rmdir($root);
        }

        parent::tearDown();
    }

    public function test_literal_schema_operations_are_compactable(): void
    {
        $analysis = $this->analyze(<<<'PHP'
Schema::create('users', static function (Blueprint $table): void {
    $table->id();
    $table->string('email')->unique();
});

Schema::table('users', static function (Blueprint $table): void {
    $table->string('nickname')->nullable();
});
PHP);

        self::assertSame(MigrationClassification::SchemaOnly, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Compact, $analysis->action());
        $this->assertSignal($analysis->signals, 'schema.create@');
        $this->assertSignal($analysis->signals, 'schema.blueprint_string@');
        $this->assertSignal($analysis->signals, 'schema.table@');
    }

    public function test_query_builder_writes_are_preserved_as_data_only(): void
    {
        $analysis = $this->analyze(<<<'PHP'
DB::table('users')->where('active', false)->update(['active' => true]);
PHP);

        self::assertSame(MigrationClassification::DataOnly, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Preserve, $analysis->action());
        $this->assertSignal($analysis->signals, 'data.db_table@');
        $this->assertSignal($analysis->signals, 'data.query_update@');
    }

    public function test_schema_and_data_operations_block_as_mixed(): void
    {
        $analysis = $this->analyze(<<<'PHP'
Schema::table('users', static function (Blueprint $table): void {
    $table->boolean('active')->default(false);
});

DB::table('users')->update(['active' => true]);
PHP);

        self::assertSame(MigrationClassification::Mixed, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Block, $analysis->action());
    }

    public function test_literal_ddl_statement_blocks_as_raw_schema(): void
    {
        $analysis = $this->analyze(<<<'PHP'
DB::statement('ALTER TABLE users ADD COLUMN nickname VARCHAR(255)');
PHP);

        self::assertSame(MigrationClassification::RawSchema, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Block, $analysis->action());
        $this->assertSignal($analysis->signals, 'raw.db_statement@');
    }

    public function test_variable_table_name_blocks_as_dynamic_schema(): void
    {
        $analysis = $this->analyze(<<<'PHP'
$tableName = 'users';

Schema::table($tableName, static function (Blueprint $table): void {
    $table->string('nickname')->nullable();
});
PHP);

        self::assertSame(MigrationClassification::DynamicSchema, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Block, $analysis->action());
        $this->assertSignal($analysis->signals, 'dynamic.schema_argument@');
    }

    public function test_conditional_schema_blocks_as_dynamic_schema(): void
    {
        $analysis = $this->analyze(<<<'PHP'
if (Schema::hasTable('users')) {
    Schema::table('users', static function (Blueprint $table): void {
        $table->string('nickname')->nullable();
    });
}
PHP);

        self::assertSame(MigrationClassification::DynamicSchema, $analysis->classification);
        $this->assertSignal($analysis->signals, 'dynamic.control_flow@');
        $this->assertSignal($analysis->signals, 'dynamic.schema_introspection@');
    }

    public function test_unknown_application_calls_block_as_unsupported(): void
    {
        $analysis = $this->analyze(<<<'PHP'
MigrationHelper::synchronize();
PHP);

        self::assertSame(MigrationClassification::Unsupported, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Block, $analysis->action());
        $this->assertSignal($analysis->signals, 'unsupported.static_call@');
    }

    public function test_unregistered_blueprint_macro_blocks_as_unsupported(): void
    {
        $analysis = $this->analyze(<<<'PHP'
Schema::table('users', static function (Blueprint $table): void {
    $table->tenantKey();
});
PHP);

        self::assertSame(MigrationClassification::Unsupported, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Block, $analysis->action());
        $this->assertSignal($analysis->signals, 'unsupported.blueprint_method@');
    }

    public function test_empty_up_method_is_preserved_as_non_schema(): void
    {
        $analysis = $this->analyze('');

        self::assertSame(MigrationClassification::NonSchema, $analysis->classification);
        self::assertSame(MigrationCompactionAction::Preserve, $analysis->action());
        self::assertSame([], $analysis->signals);
    }

    public function test_post_discovery_fingerprint_drift_is_rejected(): void
    {
        $migration = $this->migration("Schema::dropIfExists('users');");

        if (file_put_contents($migration->absolutePath, "\n// changed\n", FILE_APPEND) === false) {
            self::fail('Unable to mutate analyzer fixture.');
        }

        $this->expectException(MigrationAnalysisFailed::class);
        $this->expectExceptionMessage('changed after discovery');

        (new MigrationAnalyzer())->analyze($migration);
    }

    public function test_invalid_php_is_rejected_without_exposing_source_contents(): void
    {
        $migration = $this->migration('Schema::create(}');

        $this->expectException(MigrationAnalysisFailed::class);
        $this->expectExceptionMessage('contains invalid PHP syntax at line');

        (new MigrationAnalyzer())->analyze($migration);
    }

    private function analyze(string $body): \Cluion\Migrafold\Analysis\MigrationAnalysis
    {
        return (new MigrationAnalyzer())->analyze($this->migration($body));
    }

    private function migration(string $body): DiscoveredMigration
    {
        $root = sys_get_temp_dir().'/migrafold-analysis-'.bin2hex(random_bytes(8));
        $directory = $root.'/database/migrations';

        if (! mkdir($directory, 0700, true)) {
            self::fail("Unable to create analyzer fixture [{$directory}].");
        }

        $this->roots[] = $root;
        $name = '2020_01_01_000000_fixture';
        $path = $directory.'/'.$name.'.php';
        $contents = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
{$body}
    }

    public function down(): void {}
};
PHP;

        if (file_put_contents($path, $contents) === false) {
            self::fail("Unable to write analyzer fixture [{$path}].");
        }

        return new DiscoveredMigration(
            name: $name,
            absolutePath: $path,
            ownerId: 'laravel:application',
            source: SourceMigration::fromFile($root, $path),
        );
    }

    /**
     * @param list<string> $signals
     */
    private function assertSignal(array $signals, string $prefix): void
    {
        $matching = array_values(array_filter(
            $signals,
            static fn (string $signal): bool => str_starts_with($signal, $prefix),
        ));

        self::assertNotSame(
            [],
            $matching,
            "Expected signal prefix [{$prefix}] was not present.",
        );
    }
}
