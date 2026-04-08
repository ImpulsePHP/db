<?php

declare(strict_types=1);

namespace Impulse\Db\Internal\Recorder;

use Cycle\Database\Driver\Postgres\PostgresHandler;

final class CollectingPostgresHandler extends PostgresHandler
{
    use CollectsStatements;
}
