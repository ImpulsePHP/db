<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Cycle\Database\DatabaseManager;

final class MigrationExecutor
{
    private const DEFAULT_TABLE = 'impulse_db_migrations';

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly array $config,
        private readonly MigrationFileRepository $repository,
    ) {}

    /**
     * @return list<string>
     */
    public function applyPending(): array
    {
        $this->ensureTrackingTable();
        $applied = array_flip($this->appliedMigrations());
        $executed = [];

        foreach ($this->repository->listFiles() as $path) {
            $name = basename($path);
            if (isset($applied[$name])) {
                continue;
            }

            $migration = $this->repository->load($path);
            $up = $migration['up'] ?? [];
            if (!is_array($up)) {
                throw new \RuntimeException(sprintf('La migration "%s" a un contenu "up" invalide.', $name));
            }

            $database = $this->databaseManager->database('default');
            $database->begin();

            try {
                foreach ($up as $statement) {
                    $statement = trim((string) $statement);
                    if ($statement === '') {
                        continue;
                    }

                    $database->execute($statement);
                }

                $database->execute(
                    'INSERT INTO ' . $this->migrationTable() . ' (migration) VALUES (?)',
                    [$name],
                );
                $database->commit();
            } catch (\Throwable $e) {
                $database->rollback();
                throw new \RuntimeException(sprintf('Impossible d\'appliquer la migration "%s" : %s', $name, $e->getMessage()), 0, $e);
            }

            $executed[] = $name;
        }

        return $executed;
    }

    public function forgetAppliedMigration(string $name): void
    {
        $this->ensureTrackingTable();
        $this->databaseManager
            ->database('default')
            ->execute('DELETE FROM ' . $this->migrationTable() . ' WHERE migration = ?', [$name]);
    }

    /**
     * @return list<string>
     */
    private function appliedMigrations(): array
    {
        $rows = $this->databaseManager
            ->database('default')
            ->query('SELECT migration FROM ' . $this->migrationTable() . ' ORDER BY migration ASC')
            ->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_map(
            static fn (array $row): string => (string) $row['migration'],
            $rows,
        ));
    }

    private function ensureTrackingTable(): void
    {
        $table = $this->migrationTable();
        $driver = strtolower((string) ($this->config['driver'] ?? ''));
        $sql = match ($driver) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$table} (migration TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'mysql' => "CREATE TABLE IF NOT EXISTS {$table} (migration VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            default => "CREATE TABLE IF NOT EXISTS {$table} (migration VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        };

        $this->databaseManager->database('default')->execute($sql);
    }

    private function migrationTable(): string
    {
        $table = $this->config['migrations']['table'] ?? self::DEFAULT_TABLE;

        return preg_replace('/[^A-Za-z0-9_]/', '_', (string) $table) ?: self::DEFAULT_TABLE;
    }
}
