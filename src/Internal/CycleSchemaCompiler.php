<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Cycle\Annotated\Entities;
use Cycle\Annotated\Locator\TokenizerEntityLocator;
use Cycle\Annotated\MergeIndexes;
use Cycle\Annotated\TableInheritance;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Schema\AbstractColumn;
use Cycle\Database\Schema\AbstractForeignKey;
use Cycle\Database\Schema\AbstractIndex;
use Cycle\Database\Schema\AbstractTable;
use Cycle\ORM\Schema as OrmSchema;
use Cycle\ORM\SchemaInterface;
use Cycle\Schema\Compiler;
use Cycle\Schema\Generator\ForeignKeys;
use Cycle\Schema\Generator\GenerateModifiers;
use Cycle\Schema\Generator\GenerateRelations;
use Cycle\Schema\Generator\GenerateTypecast;
use Cycle\Schema\Generator\RenderModifiers;
use Cycle\Schema\Generator\RenderRelations;
use Cycle\Schema\Generator\RenderTables;
use Cycle\Schema\Generator\ResetTables;
use Cycle\Schema\Generator\ResolveInterfaces;
use Cycle\Schema\Generator\ValidateEntities;
use Cycle\Schema\Registry;
use Spiral\Tokenizer\ClassLocator;
use Symfony\Component\Finder\Finder;

final readonly class CycleSchemaCompiler
{
    public function __construct(
        private AppPaths $paths,
    ) {}

    public function compile(DatabaseManager $databaseManager, string $fingerprint, array $files): SchemaBuildResult
    {
        $registry = new Registry($databaseManager);
        $schema = [];

        if ($this->hasEntityFiles()) {
            $locator = new TokenizerEntityLocator($this->createClassLocator());
            $compiler = new Compiler();
            $schema = $compiler->compile($registry, [
                new Entities($locator),
                new TableInheritance(),
                new MergeIndexes(),
                new ResetTables(),
                new GenerateRelations(),
                new GenerateModifiers(),
                new RenderTables(),
                new RenderRelations(),
                new RenderModifiers(),
                new ForeignKeys(),
                new ResolveInterfaces(),
                new GenerateTypecast(),
                new ValidateEntities(),
            ]);
            ksort($schema);
        }

        $tables = [];
        foreach ($registry as $entity) {
            if (!$registry->hasTable($entity)) {
                continue;
            }

            $database = $registry->getDatabase($entity);
            $table = $registry->getTableSchema($entity);
            $tables[$database . ':' . $table->getFullName()] = $table;
        }

        ksort($tables);

        return new SchemaBuildResult(
            registry: $registry,
            schema: $schema,
            tables: $tables,
            snapshot: $this->buildSnapshot($registry, $schema, $fingerprint, $files),
        );
    }

    private function createClassLocator(): ClassLocator
    {
        $finder = (new Finder())
            ->files()
            ->in([$this->paths->entityPath()])
            ->name('*.php');

        return new ClassLocator($finder, false);
    }

    private function hasEntityFiles(): bool
    {
        if (!is_dir($this->paths->entityPath())) {
            return false;
        }

        $finder = (new Finder())
            ->files()
            ->in([$this->paths->entityPath()])
            ->name('*.php')
            ->depth('>= 0');

        return $finder->hasResults();
    }

    /**
     * @param array<string, array<int, mixed>> $schema
     * @param array<int, array{path: string, hash: string, size: int, mtime: int}> $files
     * @return array<string, mixed>
     * @throws \ReflectionException
     */
    private function buildSnapshot(Registry $registry, array $schema, string $fingerprint, array $files): array
    {
        $entities = [];
        $tables = [];

        foreach ($registry as $entity) {
            $className = $entity->getClass();
            if (!is_string($className) || $className === '') {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            $role = ($entity->getRole() ?? $className);
            $compiled = $schema[$role] ?? [];

            $tableSnapshot = null;
            if ($registry->hasTable($entity)) {
                $database = $registry->getDatabase($entity);
                $table = $registry->getTableSchema($entity);
                $tableSnapshot = $this->tableToArray($database, $table);
                $tables[$database . ':' . $table->getFullName()] = $tableSnapshot;
            }

            $filePath = $reflection->getFileName() ?: '';
            $relativePath = $filePath === ''
                ? null
                : ltrim(str_replace($this->paths->rootPath(), '', $filePath), '/');

            $fields = [];
            foreach ($entity->getFields() as $property => $field) {
                $options = $field->getOptions();
                $fields[$property] = [
                    'column' => $field->getColumn(),
                    'type' => $field->getType(),
                    'primary' => $field->isPrimary(),
                    'generated' => $field->getGenerated(),
                    'nullable' => $options->has('nullable') ? $options->get('nullable') : null,
                    'default' => $options->has('default') ? $options->get('default') : null,
                    'entity_class' => $field->getEntityClass(),
                ];
            }

            ksort($fields);

            $relations = $compiled[SchemaInterface::RELATIONS] ?? [];
            $entities[$className] = $this->sortRecursive([
                'class' => $className,
                'role' => $role,
                'short_name' => $reflection->getShortName(),
                'source_path' => $relativePath,
                'source_hash' => $filePath !== '' && is_file($filePath) ? sha1_file($filePath) : null,
                'table' => $tableSnapshot,
                'primary_key' => $compiled[SchemaInterface::PRIMARY_KEY] ?? [],
                'fields' => $fields,
                'relations' => $relations,
            ]);
        }

        ksort($entities);
        ksort($tables);

        return $this->sortRecursive([
            'version' => 1,
            'fingerprint' => $fingerprint,
            'files' => $files,
            'entities' => $entities,
            'tables' => $tables,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tableToArray(string $database, AbstractTable $table): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[$column->getName()] = $this->columnToArray($column);
        }

        ksort($columns);

        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            $indexes[$index->getName()] = $this->indexToArray($index);
        }

        ksort($indexes);

        $foreignKeys = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeys[$foreignKey->getName()] = $this->foreignKeyToArray($foreignKey);
        }

        ksort($foreignKeys);

        return [
            'database' => $database,
            'name' => $table->getFullName(),
            'primary_keys' => \array_values($table->getPrimaryKeys()),
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnToArray(AbstractColumn $column): array
    {
        return $this->sortRecursive([
            'name' => $column->getName(),
            'type' => $column->getAbstractType(),
            'declared_type' => $column->getDeclaredType(),
            'internal_type' => $column->getInternalType(),
            'nullable' => $column->isNullable(),
            'has_default' => $column->hasDefaultValue(),
            'default' => $column->getDefaultValue(),
            'size' => $column->getSize(),
            'precision' => $column->getPrecision(),
            'scale' => $column->getScale(),
            'attributes' => $column->getAttributes(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexToArray(AbstractIndex $index): array
    {
        return [
            'name' => $index->getName(),
            'columns' => array_values($index->getColumnsWithSort()),
            'unique' => $index->isUnique(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foreignKeyToArray(AbstractForeignKey $foreignKey): array
    {
        return [
            'name' => $foreignKey->getName(),
            'columns' => array_values($foreignKey->getColumns()),
            'foreign_table' => $foreignKey->getForeignTable(),
            'foreign_columns' => array_values($foreignKey->getForeignKeys()),
            'on_delete' => $foreignKey->getDeleteRule(),
            'on_update' => $foreignKey->getUpdateRule(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sortRecursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursiveArray($value);
            }
        }

        ksort($payload);

        return $payload;
    }

    /**
     * @param array<int|string, mixed> $payload
     * @return array<int|string, mixed>
     */
    private function sortRecursiveArray(array $payload): array
    {
        $isList = array_is_list($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $isList
                    ? $this->sortRecursiveArray($value)
                    : $this->sortRecursive($value);
            }
        }

        if (!$isList) {
            ksort($payload);
        }

        return $payload;
    }
}
