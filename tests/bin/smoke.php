<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__, 2);
$tmpRoot = sys_get_temp_dir() . '/impulse-db-smoke-' . bin2hex(random_bytes(6));
$appRoot = $tmpRoot . '/app';
$publicRoot = $appRoot . '/public';

mkdir($appRoot . '/src/Entity', 0777, true);
mkdir($publicRoot, 0777, true);

file_put_contents($appRoot . '/impulse.php', <<<'PHP'
<?php

return [
    'env' => 'dev',
    'database' => [
        'name' => 'smoke',
        'driver' => 'sqlite',
        'database' => __DIR__ . '/var/storage/database.sqlite',
    ],
];
PHP);

file_put_contents($appRoot . '/src/Entity/User.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use Cycle\Annotated\Annotation as Cycle;

#[Cycle\Entity(table: 'users')]
class User
{
    #[Cycle\Column(type: 'primary')]
    private ?int $id = null;

    #[Cycle\Column(type: 'string')]
    private ?string $email = null;
}
PHP);

file_put_contents($publicRoot . '/impulse.php', <<<'PHP'
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';
PHP);

$runner = escapeshellarg($packageRoot . '/tests/bin/run_scenario.php');
$appArg = escapeshellarg($appRoot);
$publicArg = escapeshellarg($publicRoot);

$first = json_decode((string) shell_exec("php {$runner} {$appArg} {$publicArg}"), true, 512, JSON_THROW_ON_ERROR);
if (($first['snapshot_exists'] ?? false) !== true) {
    throw new RuntimeException('Snapshot was not generated on the first run.');
}
if (!is_file($appRoot . '/var/db/entities.ast.json')) {
    throw new RuntimeException('Snapshot was not stored in var/db.');
}
if (($first['migration_count'] ?? 0) !== 1) {
    throw new RuntimeException('Expected one migration on the first run.');
}
if (($first['applied_migrations'] ?? []) === []) {
    throw new RuntimeException('Expected the first migration to be applied automatically.');
}
if (!in_array('users', $first['sqlite_tables'] ?? [], true)) {
    throw new RuntimeException('Expected the users table to exist after the first run.');
}
if (strpos(implode("\n", array_column($first['notifications'] ?? [], 'message')), 'Entités créées : User') === false) {
    throw new RuntimeException('Expected a success notification for created entities.');
}

$second = json_decode((string) shell_exec("php {$runner} {$appArg} {$publicArg}"), true, 512, JSON_THROW_ON_ERROR);
if (($second['migration_count'] ?? 0) !== 1) {
    throw new RuntimeException('A second identical run should not create a new migration.');
}

file_put_contents($appRoot . '/src/Entity/User.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity;

use Cycle\Annotated\Annotation as Cycle;

#[Cycle\Entity(table: 'users')]
class User
{
    #[Cycle\Column(type: 'primary')]
    private ?int $id = null;

    #[Cycle\Column(type: 'string')]
    private ?string $email = null;

    #[Cycle\Column(type: 'string', nullable: true)]
    private ?string $displayName = null;
}
PHP);

$third = json_decode((string) shell_exec("php {$runner} {$appArg} {$publicArg}"), true, 512, JSON_THROW_ON_ERROR);
if (($third['migration_count'] ?? 0) !== 1) {
    throw new RuntimeException('A schema modification should update the existing migration instead of creating a new one.');
}
if (count($third['applied_migrations'] ?? []) !== 1) {
    throw new RuntimeException('The updated migration should be the only tracked applied migration.');
}
if (!in_array('users', $third['sqlite_tables'] ?? [], true)) {
    throw new RuntimeException('The users table should still exist after the second schema update.');
}
if (strpos(implode("\n", array_column($third['notifications'] ?? [], 'message')), 'Entités modifiées : User') === false) {
    throw new RuntimeException('Expected a success notification for modified entities.');
}
if (strpos(implode("\n", array_column($third['notifications'] ?? [], 'message')), 'Migration mise à jour :') === false) {
    throw new RuntimeException('Expected a success notification for updated migration.');
}

unlink($appRoot . '/src/Entity/User.php');

$fourth = json_decode((string) shell_exec("php {$runner} {$appArg} {$publicArg}"), true, 512, JSON_THROW_ON_ERROR);
if (($fourth['migration_count'] ?? 0) !== 1) {
    throw new RuntimeException('Deleting an entity should update the existing migration instead of creating a new one.');
}
if (in_array('users', $fourth['sqlite_tables'] ?? [], true)) {
    throw new RuntimeException('The users table should be removed after deleting the entity.');
}
if (strpos(implode("\n", array_column($fourth['notifications'] ?? [], 'message')), 'Entités supprimées : User') === false) {
    throw new RuntimeException('Expected a success notification for deleted entities.');
}
if (count($fourth['applied_migrations'] ?? []) !== 1) {
    throw new RuntimeException('The rewritten migration should remain the only tracked applied migration.');
}

echo "Smoke test passed.\n";
