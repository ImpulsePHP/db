<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

final readonly class MigrationFileRepository
{
    public function __construct(
        private AppPaths $paths,
    ) {}

    /**
     * @return list<string>
     */
    public function listFiles(): array
    {
        clearstatcache();

        if (!is_dir($this->paths->migrationsPath())) {
            return [];
        }

        $files = glob($this->paths->migrationsPath() . '/*.php') ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    public function latestFile(): ?string
    {
        $files = $this->listFiles();
        if ($files === []) {
            return null;
        }

        return $files[array_key_last($files)];
    }

    /**
     * @param array<string, mixed> $migration
     */
    public function write(array $migration): string
    {
        $this->paths->ensureMigrationsDirectory();
        $filename = $this->nextFilename();
        $path = $this->paths->migrationsPath() . '/' . $filename;
        $migration['name'] ??= $filename;

        $export = var_export($migration, true);
        $content = <<<PHP
        <?php
        
        declare(strict_types=1);
        
        return {$export};
        PHP;

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param array<string, mixed> $migration
     */
    public function replace(string $path, array $migration): string
    {
        $this->paths->ensureMigrationsDirectory();
        $filename = basename($path);
        $migration['name'] ??= $filename;

        $export = \var_export($migration, true);
        $content = <<<PHP
        <?php
        
        declare(strict_types=1);
        
        return {$export};
        PHP;

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $path): array
    {
        $payload = require $path;
        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Le fichier de migration "%s" doit retourner un tableau.', $path));
        }

        return $payload;
    }

    private function nextFilename(): string
    {
        $prefix = date('YmdHis');

        for ($i = 0; $i < 100; $i++) {
            $candidate = $prefix . '_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '_schema.php';
            if (!file_exists($this->paths->migrationsPath() . '/' . $candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Impossible de générer un nom de fichier de migration unique.');
    }
}
