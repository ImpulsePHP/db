<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\MySQL\TcpConnectionConfig as MySQLTcpConnectionConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig as PostgresTcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use PDO;

final class ConnectionFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private readonly AppPaths $paths,
        private readonly NotificationManager $notifications,
    ) {
        $this->config['driver'] = $this->normalizeDriver((string) ($this->config['driver'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function getNormalizedConfig(): array
    {
        $config = $this->config;

        if (($config['driver'] ?? null) === 'sqlite' && isset($config['database']) && is_string($config['database'])) {
            $config['database'] = $this->paths->resolveApplicationPath($config['database']);
        }

        return $config;
    }

    public function prepareDatabaseForDev(): void
    {
        $driver = $this->config['driver'] ?? '';
        if ($driver === '') {
            $this->notifications->error('Configuration invalide : la clé database.driver est obligatoire dans impulse.php.');
            return;
        }

        $logicalName = $this->config['name'] ?? null;
        if (!is_string($logicalName) || trim($logicalName) === '') {
            $this->notifications->error('Création automatique désactivée : la clé database.name est obligatoire dans impulse.php.');
            return;
        }

        if ($driver === 'sqlite') {
            $database = $this->config['database'] ?? null;
            if (!is_string($database) || $database === '') {
                $this->notifications->error('Configuration SQLite invalide : database.database doit pointer vers le fichier de base de données.');
                return;
            }

            $database = $this->paths->resolveApplicationPath($database);
            if ($database === ':memory:') {
                return;
            }

            $directory = dirname($database);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Impossible de créer le répertoire SQLite "%s".', $directory));
            }

            if (!is_file($database)) {
                $handle = @fopen($database, 'c+b');
                if ($handle === false) {
                    throw new \RuntimeException(sprintf('Impossible de créer la base SQLite "%s".', $database));
                }

                fclose($handle);
            }

            $this->config['database'] = $database;

            return;
        }

        $databaseName = $this->config['database'] ?? null;
        $username = $this->config['username'] ?? $this->config['user'] ?? null;
        $password = $this->config['password'] ?? null;
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? ($driver === 'mysql' ? 3306 : 5432);

        if (!is_string($databaseName) || $databaseName === '') {
            $this->notifications->error('Création automatique désactivée : database.database doit contenir le nom de la base cible.');
            return;
        }

        if (!is_string($username) || $username === '') {
            $this->notifications->error('Création automatique désactivée : database.username ou database.user est requis.');
            return;
        }

        try {
            if ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;charset=%s',
                    $host,
                    $port,
                    $this->config['charset'] ?? 'utf8mb4',
                );

                $pdo = new PDO($dsn, $username, is_string($password) ? $password : null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $databaseName) . '`');

                return;
            }

            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port);
            $pdo = new PDO($dsn, $username, is_string($password) ? $password : null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
            $statement->execute(['name' => $databaseName]);

            if ($statement->fetchColumn() === false) {
                $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $databaseName) . '"');
            }
        } catch (\Throwable $e) {
            $this->notifications->error('Impossible de créer automatiquement la base de données : ' . $e->getMessage());
        }
    }

    public function createDatabaseManager(): DatabaseManager
    {
        $config = $this->getNormalizedConfig();
        $driver = $config['driver'] ?? '';

        $connection = match ($driver) {
            'sqlite' => $this->createSqliteDriver($config),
            'mysql' => $this->createMysqlDriver($config),
            'pgsql' => $this->createPostgresDriver($config),
            default => throw new \RuntimeException(sprintf('Pilote de base de données non supporté : "%s".', $driver)),
        };

        return new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => [
                'default' => ['connection' => 'default'],
            ],
            'connections' => [
                'default' => $connection,
            ],
        ]));
    }

    public function createPdo(): PDO
    {
        $config = $this->getNormalizedConfig();
        $driver = $config['driver'] ?? '';

        return match ($driver) {
            'sqlite' => new PDO(
                'sqlite:' . ($config['database'] ?? ':memory:'),
                null,
                null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            ),
            'mysql' => new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 3306,
                    $config['database'] ?? '',
                    $config['charset'] ?? 'utf8mb4',
                ),
                (string) ($config['username'] ?? $config['user'] ?? ''),
                is_string($config['password'] ?? null) ? $config['password'] : null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            ),
            'pgsql' => new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 5432,
                    $config['database'] ?? '',
                ),
                (string) ($config['username'] ?? $config['user'] ?? ''),
                is_string($config['password'] ?? null) ? $config['password'] : null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            ),
            default => throw new \RuntimeException(sprintf('Pilote de base de données non supporté : "%s".', $driver)),
        };
    }

    private function createSqliteDriver(array $config): SQLiteDriverConfig
    {
        $database = $config['database'] ?? ':memory:';
        if ($database === ':memory:') {
            return new SQLiteDriverConfig(new MemoryConnectionConfig());
        }

        return new SQLiteDriverConfig(new FileConnectionConfig((string) $database));
    }

    private function createMysqlDriver(array $config): MySQLDriverConfig
    {
        return new MySQLDriverConfig(
            new MySQLTcpConnectionConfig(
                (string) ($config['database'] ?? ''),
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 3306),
                $config['charset'] ?? null,
                $config['username'] ?? $config['user'] ?? null,
                $config['password'] ?? null,
                [],
            ),
        );
    }

    private function createPostgresDriver(array $config): PostgresDriverConfig
    {
        return new PostgresDriverConfig(
            new PostgresTcpConnectionConfig(
                (string) ($config['database'] ?? ''),
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 5432),
                $config['username'] ?? $config['user'] ?? null,
                $config['password'] ?? null,
                [],
            ),
        );
    }

    private function normalizeDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'postgres' => 'pgsql',
            default => strtolower($driver),
        };
    }
}
