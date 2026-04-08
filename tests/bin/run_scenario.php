<?php

declare(strict_types=1);

use Impulse\Core\Support\Config;
use Impulse\Db\Db;

$loader = require dirname(__DIR__, 3) . '/project_test/vendor/autoload.php';

$packageRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($packageRoot, $argv): void {
    if (str_starts_with($class, 'Impulse\\Db\\')) {
        $relative = substr($class, strlen('Impulse\\Db\\'));
        $path = $packageRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }

    if (!isset($argv[1]) || !str_starts_with($class, 'App\\')) {
        return;
    }

    $relative = substr($class, strlen('App\\'));
    $path = rtrim($argv[1], '/') . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

if (!isset($argv[1])) {
    fwrite(STDERR, "Missing application root.\n");
    exit(1);
}

$appRoot = $argv[1];
$workingDirectory = $argv[2] ?? $appRoot;
$configPath = $appRoot . '/impulse.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Missing impulse.php in {$appRoot}.\n");
    exit(1);
}

$loader->addPsr4('App\\', $appRoot . '/src', true);

chdir($workingDirectory);
Config::load($configPath);

$db = new Db();
$migrationFiles = glob($appRoot . '/migrations/*.php') ?: [];
sort($migrationFiles, SORT_STRING);
$snapshotPath = $appRoot . '/var/db/entities.ast.json';
$snapshot = is_file($snapshotPath)
    ? json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR)
    : null;
$appliedRows = $db->getDatabase()->query('SELECT migration FROM impulse_db_migrations ORDER BY migration ASC')->fetchAll(PDO::FETCH_ASSOC);
$sqliteTables = $db->getDatabase()->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$notifications = $_SESSION['_impulse_db_notifications'] ?? [];

echo json_encode([
    'snapshot_exists' => is_file($snapshotPath),
    'snapshot_fingerprint' => is_array($snapshot) ? ($snapshot['fingerprint'] ?? null) : null,
    'migration_count' => count($migrationFiles),
    'migrations' => array_map('basename', $migrationFiles),
    'applied_migrations' => array_map(static fn (array $row): string => (string) $row['migration'], $appliedRows),
    'schema_entities' => array_keys($db->getSchema()),
    'sqlite_tables' => array_map(static fn (array $row): string => (string) $row['name'], $sqliteTables),
    'notifications' => $notifications,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
