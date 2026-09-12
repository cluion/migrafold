<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

use Cluion\Migrafold\Analysis\Exception\MigrationAnalysisFailed;
use Cluion\Migrafold\Discovery\DiscoveredMigration;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final readonly class MigrationAnalyzer
{
    private const SCHEMA_FACADE = 'Illuminate\\Support\\Facades\\Schema';

    private const DB_FACADE = 'Illuminate\\Support\\Facades\\DB';

    /** @var list<string> */
    private const LITERAL_SCHEMA_METHODS = ['create', 'drop', 'dropIfExists', 'table'];

    /** @var list<string> */
    private const STATE_SCHEMA_METHODS = [
        'disableForeignKeyConstraints',
        'dropAllTables',
        'dropAllViews',
        'enableForeignKeyConstraints',
        'withoutForeignKeyConstraints',
    ];

    /** @var list<string> */
    private const DYNAMIC_SCHEMA_METHODS = [
        'connection',
        'getConnection',
        'hasColumn',
        'hasColumns',
        'hasTable',
        'whenTableDoesntHaveColumn',
        'whenTableHasColumn',
    ];

    /** @var list<string> */
    private const DATA_WRITE_METHODS = [
        'create',
        'decrement',
        'delete',
        'destroy',
        'forceCreate',
        'forceDelete',
        'increment',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'restore',
        'save',
        'saveOrFail',
        'truncate',
        'update',
        'updateOrCreate',
        'upsert',
    ];

    /** @var list<string> */
    private const DATA_QUERY_METHODS = [
        'cursor',
        'exists',
        'first',
        'firstOrFail',
        'get',
        'join',
        'leftJoin',
        'orderBy',
        'pluck',
        'query',
        'rightJoin',
        'select',
        'table',
        'value',
        'where',
        'whereIn',
    ];

    /** @var list<string> */
    private const BLUEPRINT_METHODS = [
        'after',
        'always',
        'autoIncrement',
        'bigIncrements',
        'bigInteger',
        'binary',
        'boolean',
        'cascadeOnDelete',
        'cascadeOnUpdate',
        'change',
        'char',
        'charset',
        'collation',
        'comment',
        'computed',
        'date',
        'dateTime',
        'dateTimeTz',
        'decimal',
        'default',
        'deferrable',
        'double',
        'dropColumn',
        'dropConstrainedForeignId',
        'dropConstrainedForeignIdFor',
        'dropForeign',
        'dropForeignIdFor',
        'dropFullText',
        'dropIndex',
        'dropMorphs',
        'dropPrimary',
        'dropRememberToken',
        'dropSoftDeletes',
        'dropSoftDeletesTz',
        'dropSpatialIndex',
        'dropTimestamps',
        'dropTimestampsTz',
        'dropUnique',
        'dropVectorIndex',
        'enum',
        'first',
        'float',
        'foreign',
        'foreignId',
        'foreignIdFor',
        'foreignUlid',
        'foreignUlidFor',
        'foreignUuid',
        'foreignUuidFor',
        'from',
        'fullText',
        'generatedAs',
        'geography',
        'geometry',
        'id',
        'increments',
        'index',
        'initiallyImmediate',
        'integer',
        'integerIncrements',
        'invisible',
        'ipAddress',
        'json',
        'jsonb',
        'longText',
        'macAddress',
        'mediumIncrements',
        'mediumInteger',
        'mediumText',
        'morphs',
        'noActionOnDelete',
        'noActionOnUpdate',
        'nullOnDelete',
        'nullOnUpdate',
        'nullable',
        'nullableMorphs',
        'nullableNumericMorphs',
        'nullableTimestamps',
        'nullableTimestampsTz',
        'nullableUlidMorphs',
        'nullableUuidMorphs',
        'numericMorphs',
        'on',
        'onDelete',
        'onUpdate',
        'primary',
        'rawColumn',
        'rawIndex',
        'references',
        'rememberToken',
        'renameColumn',
        'renameIndex',
        'restrictOnDelete',
        'restrictOnUpdate',
        'set',
        'smallIncrements',
        'smallInteger',
        'softDeletes',
        'softDeletesDatetime',
        'softDeletesTz',
        'spatialIndex',
        'startingValue',
        'storedAs',
        'string',
        'temporary',
        'text',
        'time',
        'timeTz',
        'timestamp',
        'timestampTz',
        'timestamps',
        'timestampsTz',
        'tinyIncrements',
        'tinyInteger',
        'tinyText',
        'tsvector',
        'ulid',
        'ulidMorphs',
        'unique',
        'unsigned',
        'unsignedBigInteger',
        'unsignedInteger',
        'unsignedMediumInteger',
        'unsignedSmallInteger',
        'unsignedTinyInteger',
        'useCurrent',
        'useCurrentOnUpdate',
        'uuid',
        'uuidMorphs',
        'vector',
        'vectorIndex',
        'virtualAs',
        'year',
    ];

    private Parser $parser;

    public function __construct(
        ?Parser $parser = null,
        private NodeFinder $nodes = new NodeFinder(),
    ) {
        $this->parser = $parser ?? (new ParserFactory())->createForHostVersion();
    }

    public function analyze(DiscoveredMigration $migration): MigrationAnalysis
    {
        $contents = $this->readSource($migration);

        try {
            $statements = $this->parser->parse($contents);
        } catch (Error $error) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] contains invalid PHP syntax at line {$error->getStartLine()}.",
                $error,
            );
        }

        if ($statements === null) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] did not produce a PHP syntax tree.",
            );
        }

        $traverser = new NodeTraverser(new NameResolver());
        $statements = $this->statementList(
            $traverser->traverse($statements),
            $migration,
        );
        $method = $this->upMethod($migration, $statements);
        $signals = $this->detectSignals($method);

        return new MigrationAnalysis(
            migration: $migration->name,
            ownerId: $migration->ownerId,
            sourcePath: $migration->source->path,
            classification: $this->classification($signals),
            signals: $signals['signals'],
        );
    }

    private function readSource(DiscoveredMigration $migration): string
    {
        if (is_link($migration->absolutePath)) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] became a symbolic link after discovery.",
            );
        }

        $resolved = realpath($migration->absolutePath);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] is no longer a readable file.",
            );
        }

        $fingerprint = hash_file('sha256', $resolved);

        if (! is_string($fingerprint) || ! hash_equals($migration->source->sha256, $fingerprint)) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] changed after discovery.",
            );
        }

        $contents = file_get_contents($resolved);

        if (! is_string($contents)) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] could not be read.",
            );
        }

        return $contents;
    }

    /**
     * @param list<Stmt> $statements
     */
    private function upMethod(DiscoveredMigration $migration, array $statements): ClassMethod
    {
        $classes = array_values(array_filter(
            $this->nodes->findInstanceOf($statements, Class_::class),
            static fn (Class_ $class): bool => $class->extends instanceof Name
                && ltrim($class->extends->toString(), '\\') === 'Illuminate\\Database\\Migrations\\Migration',
        ));

        if (count($classes) !== 1) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] must declare exactly one class extending Laravel Migration.",
            );
        }

        $method = $classes[0]->getMethod('up');

        if (! $method instanceof ClassMethod || ! $method->isPublic() || $method->stmts === null) {
            throw MigrationAnalysisFailed::because(
                "migration [{$migration->source->path}] must declare one concrete public up method.",
            );
        }

        return $method;
    }

    /**
     * @param array<Node> $nodes
     * @return list<Stmt>
     */
    private function statementList(array $nodes, DiscoveredMigration $migration): array
    {
        $statements = [];

        foreach ($nodes as $node) {
            if (! $node instanceof Stmt) {
                throw MigrationAnalysisFailed::because(
                    "migration [{$migration->source->path}] produced an invalid top-level syntax node.",
                );
            }

            $statements[] = $node;
        }

        return $statements;
    }

    /**
     * @return array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>}
     */
    private function detectSignals(ClassMethod $method): array
    {
        if ($method->stmts === null) {
            throw MigrationAnalysisFailed::because('migration up method has no executable body.');
        }

        $nodes = array_values($this->nodes->find(
            $method->stmts,
            static fn (Node $node): bool => true,
        ));
        $blueprints = $this->blueprintVariables($nodes);
        $state = [
            'schema' => false,
            'data' => false,
            'raw' => false,
            'dynamic' => false,
            'unsupported' => false,
            'signals' => [],
        ];
        $hasControlFlow = false;

        foreach ($nodes as $node) {
            if ($node instanceof StaticCall) {
                $this->detectStaticCall($node, $state);
            } elseif ($node instanceof MethodCall) {
                $this->detectMethodCall($node, $blueprints, $state);
            } elseif ($node instanceof FuncCall) {
                $this->mark($state, 'unsupported', 'unsupported.function_call', $node);
            } elseif ($node instanceof Expr\Include_) {
                $this->mark($state, 'unsupported', 'unsupported.include', $node);
            } elseif ($node instanceof Expr\New_) {
                $this->mark($state, 'unsupported', 'unsupported.object_construction', $node);
            }

            if ($this->isControlFlow($node)) {
                $hasControlFlow = true;
            }
        }

        if ($hasControlFlow && ($state['schema'] || $state['raw'])) {
            $this->mark($state, 'dynamic', 'dynamic.control_flow', $method);
        }

        return $state;
    }

    /**
     * @param list<Node> $nodes
     * @return array<string, true>
     */
    private function blueprintVariables(array $nodes): array
    {
        $variables = [];

        foreach ($nodes as $node) {
            if (! $node instanceof StaticCall || $this->staticClass($node) !== self::SCHEMA_FACADE) {
                continue;
            }

            $method = $this->methodName($node);

            if (! in_array($method, ['create', 'table'], true)) {
                continue;
            }

            foreach ($node->args as $argument) {
                if (! $argument instanceof Arg || ! $argument->value instanceof Closure) {
                    continue;
                }

                $parameter = $argument->value->params[0] ?? null;

                if ($parameter !== null && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                    $variables[$parameter->var->name] = true;
                }
            }
        }

        return $variables;
    }

    /**
     * @param array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>} $state
     */
    private function detectStaticCall(StaticCall $call, array &$state): void
    {
        $class = $this->staticClass($call);
        $method = $this->methodName($call);

        if ($class === null || $method === null) {
            $this->mark($state, 'unsupported', 'unsupported.dynamic_static_call', $call);

            return;
        }

        if ($class === self::SCHEMA_FACADE) {
            $this->detectSchemaCall($call, $method, $state);

            return;
        }

        if ($class === self::DB_FACADE) {
            $this->detectDatabaseCall($call, $method, $state);

            return;
        }

        if (in_array($method, self::DATA_WRITE_METHODS, true)
            || in_array($method, self::DATA_QUERY_METHODS, true)) {
            $this->mark($state, 'data', 'data.model_'.$method, $call);

            return;
        }

        $this->mark($state, 'unsupported', 'unsupported.static_call', $call);
    }

    /**
     * @param array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>} $state
     */
    private function detectSchemaCall(StaticCall $call, string $method, array &$state): void
    {
        if (in_array($method, self::LITERAL_SCHEMA_METHODS, true)) {
            $this->mark($state, 'schema', 'schema.'.$method, $call);

            if (! $this->hasLiteralArguments($call->args, 1)) {
                $this->mark($state, 'dynamic', 'dynamic.schema_argument', $call);
            }

            return;
        }

        if ($method === 'rename') {
            $this->mark($state, 'schema', 'schema.rename', $call);

            if (! $this->hasLiteralArguments($call->args, 2)) {
                $this->mark($state, 'dynamic', 'dynamic.schema_argument', $call);
            }

            return;
        }

        if (in_array($method, self::STATE_SCHEMA_METHODS, true)) {
            $this->mark($state, 'schema', 'schema.'.$method, $call);

            return;
        }

        if (in_array($method, self::DYNAMIC_SCHEMA_METHODS, true)) {
            $this->mark($state, 'schema', 'schema.'.$method, $call);
            $this->mark($state, 'dynamic', 'dynamic.schema_introspection', $call);

            return;
        }

        $this->mark($state, 'unsupported', 'unsupported.schema_method', $call);
    }

    /**
     * @param array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>} $state
     */
    private function detectDatabaseCall(StaticCall $call, string $method, array &$state): void
    {
        if ($method === 'raw') {
            $this->mark($state, 'raw', 'raw.db_expression', $call);

            return;
        }

        if (in_array($method, ['statement', 'unprepared', 'affectingStatement'], true)) {
            $sql = $call->args[0]->value ?? null;

            if (! $sql instanceof String_) {
                $this->mark($state, 'unsupported', 'unsupported.dynamic_sql', $call);

                return;
            }

            $verb = $this->sqlVerb($sql->value);

            if (in_array($verb, ['alter', 'create', 'drop', 'rename', 'truncate'], true)) {
                $this->mark($state, 'raw', 'raw.db_'.$method, $call);
            } elseif (in_array($verb, ['delete', 'insert', 'merge', 'replace', 'update'], true)) {
                $this->mark($state, 'data', 'data.db_'.$method, $call);
            } else {
                $this->mark($state, 'unsupported', 'unsupported.sql_statement', $call);
            }

            return;
        }

        if ($method === 'table' || in_array($method, self::DATA_WRITE_METHODS, true)
            || in_array($method, self::DATA_QUERY_METHODS, true)) {
            $this->mark($state, 'data', 'data.db_'.$method, $call);

            return;
        }

        $this->mark($state, 'unsupported', 'unsupported.db_method', $call);
    }

    /**
     * @param array<string, true> $blueprints
     * @param array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>} $state
     */
    private function detectMethodCall(MethodCall $call, array $blueprints, array &$state): void
    {
        $method = $this->methodName($call);

        if ($method === null) {
            $this->mark($state, 'unsupported', 'unsupported.dynamic_method_call', $call);

            return;
        }

        $root = $this->rootVariable($call);

        if ($root !== null && isset($blueprints[$root])) {
            if (in_array($method, ['rawColumn', 'rawIndex'], true)) {
                $this->mark($state, 'raw', 'raw.blueprint_'.$method, $call);
            } elseif (in_array($method, self::BLUEPRINT_METHODS, true)) {
                $this->mark($state, 'schema', 'schema.blueprint_'.$method, $call);
            } else {
                $this->mark($state, 'unsupported', 'unsupported.blueprint_method', $call);
            }

            return;
        }

        if (in_array($method, self::DATA_WRITE_METHODS, true)
            || in_array($method, self::DATA_QUERY_METHODS, true)) {
            $this->mark($state, 'data', 'data.query_'.$method, $call);

            return;
        }

        $this->mark($state, 'unsupported', 'unsupported.method_call', $call);
    }

    /**
     * @param array<Arg|VariadicPlaceholder> $arguments
     */
    private function hasLiteralArguments(array $arguments, int $count): bool
    {
        for ($index = 0; $index < $count; $index++) {
            if (
                ! isset($arguments[$index])
                || ! $arguments[$index] instanceof Arg
                || ! $arguments[$index]->value instanceof String_
            ) {
                return false;
            }
        }

        return true;
    }

    private function staticClass(StaticCall $call): ?string
    {
        return $call->class instanceof Name
            ? ltrim($call->class->toString(), '\\')
            : null;
    }

    private function methodName(StaticCall|MethodCall $call): ?string
    {
        return $call->name instanceof Identifier ? $call->name->toString() : null;
    }

    private function rootVariable(MethodCall $call): ?string
    {
        $expression = $call->var;

        while ($expression instanceof MethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof Variable && is_string($expression->name)
            ? $expression->name
            : null;
    }

    private function sqlVerb(string $sql): string
    {
        if (preg_match('/\A\s*(?:\/\*.*?\*\/\s*)*([a-z]+)/is', $sql, $matches) !== 1) {
            return '';
        }

        return strtolower($matches[1]);
    }

    private function isControlFlow(Node $node): bool
    {
        return $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Expr\Match_
            || $node instanceof Expr\Ternary;
    }

    /**
     * @param array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>} $state
     * @param 'schema'|'data'|'raw'|'dynamic'|'unsupported' $kind
     */
    private function mark(array &$state, string $kind, string $signal, Node $node): void
    {
        $state[$kind] = true;
        $state['signals'][] = $signal.'@'.$node->getStartLine();
    }

    /**
     * @param array{schema: bool, data: bool, raw: bool, dynamic: bool, unsupported: bool, signals: list<string>} $signals
     */
    private function classification(array $signals): MigrationClassification
    {
        if ($signals['unsupported']) {
            return MigrationClassification::Unsupported;
        }

        if ($signals['dynamic']) {
            return MigrationClassification::DynamicSchema;
        }

        if ($signals['data'] && ($signals['schema'] || $signals['raw'])) {
            return MigrationClassification::Mixed;
        }

        if ($signals['raw']) {
            return MigrationClassification::RawSchema;
        }

        if ($signals['schema']) {
            return MigrationClassification::SchemaOnly;
        }

        if ($signals['data']) {
            return MigrationClassification::DataOnly;
        }

        return MigrationClassification::NonSchema;
    }
}
