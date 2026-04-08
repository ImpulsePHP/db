<?php

declare(strict_types=1);

namespace Impulse\Db\Internal\Recorder;

use Cycle\Database\Driver\MySQL\MySQLHandler;

final class CollectingMySQLHandler extends MySQLHandler
{
    use CollectsStatements;
}
