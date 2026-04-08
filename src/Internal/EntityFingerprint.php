<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

final class EntityFingerprint
{
    /**
     * @return array{
     *     hash: string,
     *     files: array<int, array{path: string, hash: string, size: int, mtime: int}>
     * }
     * @throws \JsonException
     */
    public function calculate(string $entityPath, string $rootPath): array
    {
        if (!is_dir($entityPath)) {
            return [
                'hash' => sha1('[]'),
                'files' => [],
            ];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = ltrim(str_replace($rootPath, '', $path), '/');
            $files[] = [
                'path' => $relativePath,
                'hash' => sha1_file($path) ?: '',
                'size' => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];
        }

        usort($files, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return [
            'hash' => sha1(json_encode($files, JSON_THROW_ON_ERROR)),
            'files' => $files,
        ];
    }
}
