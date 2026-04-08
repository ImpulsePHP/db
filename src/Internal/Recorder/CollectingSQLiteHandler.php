<?php

declare(strict_types=1);

namespace Impulse\Db\Internal\Recorder;

use Cycle\Database\Driver\SQLite\SQLiteHandler;

final class CollectingSQLiteHandler extends SQLiteHandler
{
    use CollectsStatements;
}
