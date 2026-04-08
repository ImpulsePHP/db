<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Cycle\Database\Driver\HandlerInterface;
use Cycle\Database\Driver\MySQL\MySQLDriver;
use Cycle\Database\Driver\Postgres\PostgresDriver;
use Cycle\Database\Driver\SQLite\SQLiteDriver;
use Cycle\Database\Schema\AbstractTable;
use Cycle\Database\Schema\Reflector;
use Impulse\Db\Internal\Recorder\CollectingMySQLHandler;
use Impulse\Db\Internal\Recorder\CollectingPostgresHandler;
use Impulse\Db\Internal\Recorder\CollectingSQLiteHandler;

final class MigrationSqlGenerator
{
    /**
     * @param array<string, AbstractTable> $tables
     * @param array<string, AbstractTable> $droppedTables
     * @return array{
     *     up: list<string>,
     *     down: list<string>,
     *     summary: array<string, mixed>
     * }
     */
    public function generate(array $tables, array $droppedTables = []): array
    {
        $reflector = new Reflector();
        foreach ($tables as $table) {
            $reflector->addTable($table);
        }
        foreach ($droppedTables as $table) {
            $reflector->addTable($table);
        }

        $sorted = $reflector->sortedTables();
        $statements = [];
        $updated = [];
        $summary = [
            'created_tables' => [],
            'updated_tables' => [],
            'dropped_tables' => [],
            'changes' => [],
        ];

        foreach ($sorted as $table) {
            if ($table->getDriver() instanceof SQLiteDriver) {
                continue;
            }

            if (!$table->exists()) {
                continue;
            }

            $collector = $this->handlerFor($table);
            $collector->syncTable($table, HandlerInterface::DROP_FOREIGN_KEYS);
            array_push($statements, ...$collector->releaseStatements());
        }

        foreach ($sorted as $table) {
            if ($table->getDriver() instanceof SQLiteDriver) {
                continue;
            }

            if (!$table->exists()) {
                continue;
            }

            $collector = $this->handlerFor($table);
            $collector->syncTable($table, HandlerInterface::DROP_INDEXES);
            array_push($statements, ...$collector->releaseStatements());
        }

        foreach ($sorted as $table) {
            if ($table->getStatus() === AbstractTable::STATUS_DECLARED_DROPPED) {
                $collector = $this->handlerFor($table);
                $collector->dropTable($table);
                array_push($statements, ...$collector->releaseStatements());
                $summary['dropped_tables'][] = $table->getInitialName();
                $summary['changes'][] = ['table' => $table->getInitialName(), 'action' => 'drop_table'];
                continue;
            }

            if (!$table->exists()) {
                $collector = $this->handlerFor($table);
                if ($table->getDriver() instanceof SQLiteDriver) {
                    $collector->createTable($table);
                } else {
                    $collector->createTable($this->cloneWithoutForeignKeys($table));
                }

                array_push($statements, ...$collector->releaseStatements());
                $updated[] = $table;
                $summary['created_tables'][] = $table->getFullName();
                $summary['changes'][] = ['table' => $table->getFullName(), 'action' => 'create_table'];

                continue;
            }

            if (!$table->getComparator()->hasChanges()) {
                continue;
            }

            $collector = $this->handlerFor($table);
            if ($table->getDriver() instanceof SQLiteDriver) {
                $collector->syncTable($table, HandlerInterface::DO_ALL);
            } else {
                $collector->syncTable(
                    $table,
                    HandlerInterface::DO_ALL
                    ^ HandlerInterface::DROP_FOREIGN_KEYS
                    ^ HandlerInterface::DROP_INDEXES
                    ^ HandlerInterface::CREATE_FOREIGN_KEYS,
                );
            }

            array_push($statements, ...$collector->releaseStatements());
            $updated[] = $table;
            $summary['updated_tables'][] = $table->getFullName();
            $summary['changes'][] = [
                'table' => $table->getFullName(),
                'action' => 'alter_table',
                'added_columns' => array_map(static fn ($column) => $column->getName(), $table->getComparator()->addedColumns()),
                'dropped_columns' => array_map(static fn ($column) => $column->getName(), $table->getComparator()->droppedColumns()),
                'altered_columns' => array_map(static fn (array $pair) => $pair[0]->getName(), $table->getComparator()->alteredColumns()),
            ];
        }

        foreach ($updated as $table) {
            if ($table->getDriver() instanceof SQLiteDriver) {
                continue;
            }

            if (count($table->getComparator()->addedForeignKeys()) === 0) {
                continue;
            }

            $collector = $this->handlerFor($table);
            $collector->syncTable($table, HandlerInterface::CREATE_FOREIGN_KEYS);
            array_push($statements, ...$collector->releaseStatements());
        }

        $summary['statement_count'] = count($statements);

        return [
            'up' => $statements,
            'down' => [],
            'summary' => $summary,
        ];
    }

    private function cloneWithoutForeignKeys(AbstractTable $table): AbstractTable
    {
        $clone = clone $table;
        $foreignKeys = array_values($clone->getForeignKeys());

        foreach ($foreignKeys as $foreignKey) {
            $clone->dropForeignKey($foreignKey->getColumns());
        }

        return $clone;
    }

    private function handlerFor(AbstractTable $table): HandlerInterface
    {
        $driver = $table->getDriver();

        return match (true) {
            $driver instanceof SQLiteDriver => (new CollectingSQLiteHandler())->withDriver($driver),
            $driver instanceof MySQLDriver => (new CollectingMySQLHandler())->withDriver($driver),
            $driver instanceof PostgresDriver => (new CollectingPostgresHandler())->withDriver($driver),
            default => throw new \RuntimeException('Pilote Cycle non supporté pour la génération des migrations.'),
        };
    }
}
