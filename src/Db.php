<?php

declare(strict_types=1);

namespace Impulse\Db;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\Schema\AbstractTable;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Schema;
use Impulse\Core\Support\Config;
use Impulse\Db\Contracts\DbInterface;
use Impulse\Db\Internal\AppPaths;
use Impulse\Db\Internal\ConnectionFactory;
use Impulse\Db\Internal\CycleSchemaCompiler;
use Impulse\Db\Internal\EntityFingerprint;
use Impulse\Db\Internal\MigrationExecutor;
use Impulse\Db\Internal\MigrationFileRepository;
use Impulse\Db\Internal\MigrationSqlGenerator;
use Impulse\Db\Internal\NotificationManager;
use JsonException;
use PDO;

final class Db implements DbInterface
{
    private readonly AppPaths $paths;
    private readonly NotificationManager $notifications;
    /**
     * @var array<string, mixed>
     */
    private readonly array $config;
    private readonly bool $dev;

    private DatabaseManager $databaseManager;
    private ORMInterface $orm;
    /**
     * @var array<string, array<int, mixed>>
     */
    private array $schema = [];
    private ?PDO $pdo = null;

    /**
     * @throws JsonException
     */
    public function __construct(bool $initialize = true)
    {
        $this->paths = AppPaths::discover();
        $this->notifications = new NotificationManager();
        $this->config = Config::get('database', []);
        $this->dev = Config::get('env', 'prod') === 'dev';

        if ($initialize) {
            $this->initialize();
        }
    }

    public function getDatabase(?string $name = null): CycleDatabaseInterface
    {
        return $this->databaseManager->database($name ?? 'default');
    }

    public function getDatabaseManager(): DatabaseManager
    {
        return $this->databaseManager;
    }

    public function getORM(): ORMInterface
    {
        return $this->orm;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $factory = new ConnectionFactory($this->config, $this->paths, $this->notifications);
            $this->pdo = $factory->createPdo();
        }

        return $this->pdo;
    }

    public function pushErrorNotification(string $message): void
    {
        $this->notifications->error($message);
    }

    /**
     * @throws JsonException
     */
    public function flushNotifications(): void
    {
        $this->notifications->flushToResponse();
    }

    /**
     * @throws JsonException
     */
    private function initialize(): void
    {
        $this->paths->ensureVarDirectory();
        $connectionFactory = new ConnectionFactory($this->config, $this->paths, $this->notifications);

        if ($this->dev) {
            $connectionFactory->prepareDatabaseForDev();
        }

        $this->databaseManager = $connectionFactory->createDatabaseManager();

        if ($this->dev) {
            $this->applyPendingMigrations();
        }

        $schema = $this->loadSchemaFromCacheIfFresh();
        if ($schema === null) {
            $schema = $this->compileAndSynchronize();
        }

        $this->schema = $schema;
        $this->initializeOrm();
    }

    /**
     * @return array<string, array<int, mixed>>|null
     * @throws JsonException
     */
    private function loadSchemaFromCacheIfFresh(): ?array
    {
        if (!$this->dev) {
            $path = $this->paths->schemaCachePath();
            if (is_file($path)) {
                $cached = require $path;
                return is_array($cached) ? $cached : null;
            }

            return null;
        }

        $meta = $this->readJsonFile($this->paths->snapshotMetaPath());
        if (!is_array($meta)) {
            return null;
        }

        $fingerprint = (new EntityFingerprint())->calculate($this->paths->entityPath(), $this->paths->rootPath());
        if (($meta['fingerprint'] ?? null) !== $fingerprint['hash']) {
            return null;
        }

        if (!is_file($this->paths->snapshotPath()) || !is_file($this->paths->schemaCachePath())) {
            return null;
        }

        $cached = require $this->paths->schemaCachePath();

        return is_array($cached) ? $cached : null;
    }

    /**
     * @return array<string, array<int, mixed>>
     * @throws JsonException
     */
    private function compileAndSynchronize(): array
    {
        $fingerprint = (new EntityFingerprint())->calculate($this->paths->entityPath(), $this->paths->rootPath());
        $compiler = new CycleSchemaCompiler($this->paths);
        $result = $compiler->compile($this->databaseManager, $fingerprint['hash'], $fingerprint['files']);

        if ($this->dev) {
            $previousSnapshot = $this->readJsonFile($this->paths->snapshotPath());
            $currentJson = $this->encodeJson($result->snapshot);
            $previousJson = is_array($previousSnapshot) ? $this->encodeJson($previousSnapshot) : null;
            $changes = $this->diffEntityChanges($previousSnapshot, $result->snapshot);
            $migrationName = null;
            $reusedMigration = null;
            $appliedMigrations = [];

            if ($previousJson !== $currentJson) {
                $payload = (new MigrationSqlGenerator())->generate(
                    $result->tables,
                    $this->buildDroppedTables($previousSnapshot, $result->tables),
                );

                if (($payload['summary']['statement_count'] ?? 0) > 0) {
                    $repository = new MigrationFileRepository($this->paths);
                    $shouldReuseMigration = $this->shouldReuseExistingMigration($changes, $repository);
                    $migrationPath = $this->storeMigration($repository, [
                        'generated_at' => date(DATE_ATOM),
                        'up' => $payload['up'],
                        'down' => $payload['down'],
                        'summary' => $payload['summary'],
                    ], $changes);
                    $migrationName = basename($migrationPath);

                    if ($shouldReuseMigration) {
                        $reusedMigration = $migrationName;
                    }

                    $appliedMigrations = $this->applyPendingMigrations($reusedMigration);
                }

                file_put_contents($this->paths->snapshotPath(), $currentJson);
                clearstatcache();
            }

            file_put_contents(
                $this->paths->snapshotMetaPath(),
                $this->encodeJson([
                    'fingerprint' => $fingerprint['hash'],
                    'schema_hash' => sha1(serialize($result->schema)),
                ]),
            );

            file_put_contents(
                $this->paths->schemaCachePath(),
                "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($result->schema, true) . ";\n",
            );

            clearstatcache();

            $this->notifySchemaUpdate($changes, $migrationName, $appliedMigrations, $reusedMigration !== null);
        }

        return $result->schema;
    }

    /**
     * @param array<string, mixed>|null $previousSnapshot
     * @param array<string, AbstractTable> $currentTables
     * @return array<string, AbstractTable>
     */
    private function buildDroppedTables(?array $previousSnapshot, array $currentTables): array
    {
        if (!is_array($previousSnapshot) || !isset($previousSnapshot['tables']) || !is_array($previousSnapshot['tables'])) {
            return [];
        }

        $dropped = [];
        foreach ($previousSnapshot['tables'] as $key => $tableData) {
            if (!is_array($tableData) || isset($currentTables[$key])) {
                continue;
            }

            $tableName = $tableData['name'] ?? null;
            if (!is_string($tableName) || $tableName === '') {
                continue;
            }

            $databaseName = $tableData['database'] ?? 'default';
            try {
                $database = $this->databaseManager->database((string) $databaseName);
                if (!$database->hasTable($tableName)) {
                    continue;
                }

                $table = $database->getDriver()->getSchemaHandler()->getSchema($tableName);
                $table->declareDropped();
                $dropped[$key] = $table;
            } catch (\Throwable) {
                continue;
            }
        }

        return $dropped;
    }

    /**
     * @return list<string>
     */
    private function applyPendingMigrations(?string $reusedMigration = null): array
    {
        try {
            try {
                $this->databaseManager->database('default')->getDriver()->disconnect();
            } catch (\Throwable) {
                // Ignore best-effort disconnects before running migrations.
            }

            $executor = new MigrationExecutor(
                $this->databaseManager,
                $this->config,
                new MigrationFileRepository($this->paths),
            );

            if ($reusedMigration !== null) {
                $executor->forgetAppliedMigration($reusedMigration);
            }

            return $executor->applyPending();
        } catch (\Throwable $e) {
            $this->notifications->error($e->getMessage());

            return [];
        }
    }

    private function initializeOrm(): void
    {
        $factory = new Factory($this->databaseManager);
        $this->orm = new ORM($factory, new Schema($this->schema));
    }

    /**
     * @param array $changes
     */
    private function notifySchemaUpdate(
        array $changes,
        ?string $migrationName = null,
        array $appliedMigrations = [],
        bool $migrationUpdated = false,
    ): void
    {
        $lines = [];

        if ($changes['created'] !== []) {
            $lines[] = 'Entités créées : ' . implode(', ', $changes['created']);
        }

        if ($changes['modified'] !== []) {
            $lines[] = 'Entités modifiées : ' . implode(', ', $changes['modified']);
        }

        if ($changes['deleted'] !== []) {
            $lines[] = 'Entités supprimées : ' . implode(', ', $changes['deleted']);
        }

        if ($migrationName !== null) {
            $lines[] = ($migrationUpdated ? 'Migration mise à jour : ' : 'Migration générée : ') . $migrationName;
        }

        if ($appliedMigrations !== []) {
            $lines[] = 'Migration appliquée : ' . implode(', ', $appliedMigrations);
        }

        if ($lines === []) {
            return;
        }

        $this->notifications->success(\implode("\n", $lines));
    }

    /**
     * @param array<string, mixed>|null $previousSnapshot
     * @param array<string, mixed> $currentSnapshot
     * @return array{created: list<string>, modified: list<string>, deleted: list<string>}
     */
    private function diffEntityChanges(?array $previousSnapshot, array $currentSnapshot): array
    {
        $previousEntities = is_array($previousSnapshot['entities'] ?? null) ? $previousSnapshot['entities'] : [];
        $currentEntities = is_array($currentSnapshot['entities'] ?? null) ? $currentSnapshot['entities'] : [];

        $created = [];
        $modified = [];
        $deleted = [];

        foreach ($currentEntities as $className => $entitySnapshot) {
            if (!is_array($entitySnapshot)) {
                continue;
            }

            if (!isset($previousEntities[$className])) {
                $created[] = $this->entityLabel($className, $entitySnapshot);
                continue;
            }

            if ($previousEntities[$className] !== $entitySnapshot) {
                $modified[] = $this->entityLabel($className, $entitySnapshot);
            }
        }

        foreach ($previousEntities as $className => $entitySnapshot) {
            if (isset($currentEntities[$className]) || !\is_array($entitySnapshot)) {
                continue;
            }

            $deleted[] = $this->entityLabel((string) $className, $entitySnapshot);
        }

        sort($created, SORT_NATURAL | SORT_FLAG_CASE);
        sort($modified, SORT_NATURAL | SORT_FLAG_CASE);
        sort($deleted, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'created' => array_values($created),
            'modified' => array_values($modified),
            'deleted' => array_values($deleted),
        ];
    }

    /**
     * @param array<string, mixed> $entitySnapshot
     */
    private function entityLabel(string $className, array $entitySnapshot): string
    {
        $shortName = $entitySnapshot['short_name'] ?? null;
        if (is_string($shortName) && $shortName !== '') {
            return $shortName;
        }

        $parts = explode('\\', $className);

        return (string) end($parts);
    }

    /**
     * @param array{created: list<string>, modified: list<string>, deleted: list<string>} $changes
     * @param array<string, mixed> $migration
     */
    private function storeMigration(MigrationFileRepository $repository, array $migration, array $changes): string
    {
        if ($this->shouldReuseExistingMigration($changes, $repository)) {
            $latestPath = $repository->latestFile();
            if ($latestPath !== null) {
                return $repository->replace($latestPath, $migration);
            }
        }

        return $repository->write($migration);
    }

    /**
     * @param array{created: list<string>, modified: list<string>, deleted: list<string>} $changes
     */
    private function shouldReuseExistingMigration(array $changes, MigrationFileRepository $repository): bool
    {
        if ($changes['created'] !== []) {
            return false;
        }

        if ($changes['modified'] === [] && $changes['deleted'] === []) {
            return false;
        }

        return $repository->latestFile() !== null;
    }

    /**
     * @return array<string, mixed>|null
     * @throws JsonException
     */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws JsonException
     */
    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
