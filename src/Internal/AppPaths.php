<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Impulse\Core\Support\Config;

final readonly class AppPaths
{
    public function __construct(
        private string $rootPath,
        private string $configPath,
    ) {}

    public static function discover(): self
    {
        foreach (self::configLoadedPaths() as $configPath) {
            return new self(dirname($configPath), $configPath);
        }

        $candidates = [
            getcwd() . '/impulse.php',
            getcwd() . '/../impulse.php',
            getcwd() . '/../../impulse.php',
        ];

        $bestCandidate = self::bestCandidate($candidates);
        if ($bestCandidate !== null) {
            return new self(dirname($bestCandidate), $bestCandidate);
        }

        $fallback = getcwd() . '/impulse.php';

        return new self(getcwd(), $fallback);
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function configPath(): string
    {
        return $this->configPath;
    }

    public function entityPath(): string
    {
        return $this->rootPath . '/src/Entity';
    }

    public function varPath(): string
    {
        return $this->rootPath . '/var';
    }

    public function dbVarPath(): string
    {
        return $this->varPath() . '/db';
    }

    public function migrationsPath(): string
    {
        return $this->rootPath . '/migrations';
    }

    public function snapshotPath(): string
    {
        return $this->dbVarPath() . '/entities.ast.json';
    }

    public function snapshotMetaPath(): string
    {
        return $this->dbVarPath() . '/entities.ast.meta.json';
    }

    public function schemaCachePath(): string
    {
        return $this->dbVarPath() . '/entities.schema.php';
    }

    public function ensureVarDirectory(): void
    {
        $this->ensureDirectory($this->varPath());
        $this->ensureDirectory($this->dbVarPath());
    }

    public function ensureMigrationsDirectory(): void
    {
        $this->ensureDirectory($this->migrationsPath());
    }

    public function resolveApplicationPath(string $path): string
    {
        if ($path === '' || $path === ':memory:' || $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return $this->rootPath . '/' . ltrim($path, '/');
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Impossible de créer le répertoire "%s".', $path));
        }
    }

    /**
     * @return list<string>
     */
    private static function configLoadedPaths(): array
    {
        if (!class_exists(Config::class)) {
            return [];
        }

        $paths = [];
        foreach (Config::getLoadedPaths() as $loadedPath) {
            if (!is_string($loadedPath) || $loadedPath === '') {
                continue;
            }

            $real = realpath($loadedPath) ?: $loadedPath;
            if (is_file($real) && basename($real) === 'impulse.php') {
                $paths[] = $real;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $candidates
     */
    private static function bestCandidate(array $candidates): ?string
    {
        $bestPath = null;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $real = realpath($candidate) ?: $candidate;
            $root = dirname($real);
            $score = 0;

            if (is_dir($root . '/src')) {
                $score += 20;
            }

            if (is_dir($root . '/src/Entity')) {
                $score += 40;
            }

            if (is_dir($root . '/var')) {
                $score += 10;
            }

            if (is_dir($root . '/public')) {
                $score += 5;
            }

            if (basename($root) === 'public') {
                $score -= 100;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = $real;
            }
        }

        return $bestPath;
    }
}
