<?php

declare(strict_types=1);

namespace Impulse\Db\Contracts;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\ORMInterface;

interface DbInterface
{
    public function getDatabase(?string $name = null): CycleDatabaseInterface;
    public function getDatabaseManager(): DatabaseManager;
    public function getORM(): ORMInterface;
    public function getConfig(): array;
    public function getSchema(): array;
}
