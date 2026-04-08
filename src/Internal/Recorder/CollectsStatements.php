<?php

declare(strict_types=1);

namespace Impulse\Db\Internal\Recorder;

trait CollectsStatements
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    /**
     * @return list<string>
     */
    public function releaseStatements(): array
    {
        $statements = $this->statements;
        $this->statements = [];

        return $statements;
    }

    protected function run(string $statement, array $parameters = []): int
    {
        $statement = trim($statement);
        if ($statement === '') {
            return 0;
        }

        $this->statements[] = str_ends_with($statement, ';') ? $statement : $statement . ';';

        return 0;
    }
}
