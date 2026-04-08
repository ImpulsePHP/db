<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Cycle\Database\Schema\AbstractTable;
use Cycle\Schema\Registry;

final readonly class SchemaBuildResult
{
    /**
     * @param array<string, array<int, mixed>> $schema
     * @param array<string, AbstractTable> $tables
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public Registry $registry,
        public array    $schema,
        public array    $tables,
        public array    $snapshot,
    ) {}
}
