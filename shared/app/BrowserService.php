<?php

declare(strict_types=1);

namespace App;

final class BrowserService
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function browse(?string $folderPath, ?string $activeSourcePath = null): array
    {
        $activeSourcePath = $this->normalizeActiveSourcePath($activeSourcePath);

        return $this->browseRoots(
            $folderPath,
            $this->config->inputRoots(),
            fn (string $path): string => $this->paths->assertInputFolder($path),
            true,
            $activeSourcePath
        );
    }

    public function browseOutputFolders(?string $folderPath): array
    {
        return $this->browseRoots(
            $folderPath,
            $this->config->outputRoots(),
            fn (string $path): string => $this->paths->assertOutputFolder($path),
            false,
            null
        );
    }

    private function browseRoots(
        ?string $folderPath,
        array $roots,
        callable $assertFolder,
        bool $includeFiles,
        ?string $activeSourcePath
    ): array {
        if ($folderPath === null || trim($folderPath) === '') {
            return [
                'folder' => '',
                'root_name' => null,
                'root_path' => null,
                'breadcrumbs' => [],
                'folders' => array_map(
                    static fn (string $name, string $path): array => [
                        'name' => $name,
                        'path' => $path,
                        'is_parent' => false,
                    ],
                    array_keys($roots),
                    array_values($roots)
                ),
                'files' => [],
                'active_source_path' => $activeSourcePath,
            ];
        }

        $folderPath = $assertFolder($folderPath);
        [$rootName, $rootPath] = $this->resolveRoot($folderPath, $roots);
        $entries = $this->readDirectory($folderPath, $activeSourcePath, $roots, $includeFiles);

        return [
            'folder' => $folderPath,
            'root_name' => $rootName,
            'root_path' => $rootPath,
            'breadcrumbs' => $this->buildBreadcrumbs($folderPath, $rootPath, $rootName),
            'folders' => $entries['folders'],
            'files' => $entries['files'],
            'active_source_path' => $activeSourcePath,
        ];
    }

    private function readDirectory(string $folderPath, ?string $activeSourcePath, array $roots, bool $includeFiles): array
    {
        $folders = [];
        $files = [];

        $iterator = new \FilesystemIterator($folderPath, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $name = $entry->getFilename();
            if ($name !== '' && $name[0] === '.') {
                continue;
            }

            $path = $entry->getPathname();
            if ($entry->isDir()) {
                $folders[] = [
                    'name' => $name,
                    'path' => rtrim($path, '/'),
                    'is_parent' => false,
                ];
                continue;
            }

            if (!$includeFiles || !$entry->isFile()) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'path' => $path,
                'size_bytes' => $entry->getSize(),
                'size_human' => $this->humanFilesize($entry->getSize()),
                'scan_type' => $this->paths->guessType($path),
                'joinable' => $this->isJoinable($path),
                'is_active' => $activeSourcePath !== null && $activeSourcePath === $path,
                'grouped_files' => [],
            ];
        }

        usort(
            $folders,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
        );
        usort(
            $files,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
        );

        if ($includeFiles) {
            $files = $this->groupFiles($files);
        }

        $parentFolder = dirname($folderPath);
        if ($this->paths->isWithinRoots($parentFolder, $roots) && $parentFolder !== $folderPath) {
            array_unshift($folders, [
                'name' => '..',
                'path' => rtrim($parentFolder, '/'),
                'is_parent' => true,
            ]);
        }

        return [
            'folders' => $folders,
            'files' => $files,
        ];
    }

    private function groupFiles(array $files): array
    {
        $groupsByKey = [];
        $groupKeysByBaseName = [];
        $groupedMemberPaths = [];

        foreach ($files as $file) {
            $groupPattern = $this->groupPatternForFile($file['path'], $file['scan_type']);
            if ($groupPattern === null) {
                continue;
            }

            $pathInfo = pathinfo($file['path']);
            $baseName = (string) ($pathInfo['filename'] ?? $file['name']);
            $groupsByKey[$file['scan_type'] . '|' . $baseName] = [
                'pattern' => $groupPattern,
                'members' => [],
            ];
            $groupKeysByBaseName[$baseName][] = $file['scan_type'] . '|' . $baseName;
        }

        if ($groupsByKey === []) {
            return $files;
        }

        foreach ($files as $file) {
            $pathInfo = pathinfo($file['path']);
            $baseName = (string) ($pathInfo['filename'] ?? $file['name']);
            $groupKeys = $groupKeysByBaseName[$baseName] ?? [];
            if ($groupKeys === []) {
                continue;
            }

            $extension = strtolower((string) ($pathInfo['extension'] ?? ''));
            foreach ($groupKeys as $groupKey) {
                if (preg_match($groupsByKey[$groupKey]['pattern'], $extension) !== 1) {
                    continue;
                }

                $groupsByKey[$groupKey]['members'][] = [
                    'name' => $file['name'],
                    'path' => $file['path'],
                    'size_bytes' => $file['size_bytes'],
                    'size_human' => $file['size_human'],
                ];
                $groupedMemberPaths[$file['path']] = true;
            }
        }

        foreach ($groupsByKey as &$group) {
            if ($group['members'] !== []) {
                usort(
                    $group['members'],
                    static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
                );
            }
        }
        unset($group);

        foreach ($files as &$file) {
            $pathInfo = pathinfo($file['path']);
            $baseName = (string) ($pathInfo['filename'] ?? $file['name']);
            $groupKey = ($file['scan_type'] ?? '') . '|' . $baseName;
            if (!isset($groupsByKey[$groupKey]) || $groupsByKey[$groupKey]['members'] === []) {
                continue;
            }

            $file['grouped_files'] = $groupsByKey[$groupKey]['members'];
        }
        unset($file);

        return array_values(array_filter(
            $files,
            static function (array $file) use ($groupedMemberPaths): bool {
                return !isset($groupedMemberPaths[$file['path']]);
            }
        ));
    }

    private function buildBreadcrumbs(string $folderPath, string $rootPath, string $rootName): array
    {
        $breadcrumbs = [
            [
                'name' => $rootName,
                'path' => $rootPath,
            ],
        ];

        $relative = trim(substr($folderPath, strlen($rootPath)), '/');
        if ($relative === '') {
            return $breadcrumbs;
        }

        $parts = explode('/', $relative);
        $current = rtrim($rootPath, '/');
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            $breadcrumbs[] = [
                'name' => $part,
                'path' => $current,
            ];
        }

        return $breadcrumbs;
    }

    private function resolveRoot(string $folderPath, array $roots): array
    {
        foreach ($roots as $name => $rootPath) {
            if ($this->paths->isWithinRoots($folderPath, [$rootPath])) {
                return [$name, $rootPath];
            }
        }

        throw new \RuntimeException('Folder is outside configured input roots');
    }

    private function isJoinable(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $joinExtensions = $this->config->static()['scanModules']['video']['fileExtensions']['join'] ?? [];
        if (is_string($joinExtensions)) {
            $joinExtensions = [$joinExtensions];
        }

        if (in_array($extension, $joinExtensions, true)) {
            return true;
        }

        return $this->paths->guessType($path) === 'video';
    }

    private function normalizeActiveSourcePath(?string $activeSourcePath): ?string
    {
        if ($activeSourcePath === null || trim($activeSourcePath) === '') {
            return null;
        }

        $realPath = realpath($activeSourcePath);
        if ($realPath === false || !is_file($realPath)) {
            return null;
        }

        if (!$this->paths->isWithinRoots($realPath, $this->config->inputRoots())) {
            return null;
        }

        return $realPath;
    }

    private function groupPatternForFile(string $path, ?string $scanType): ?string
    {
        if ($scanType === null) {
            return null;
        }

        $pattern = $this->config->static()['scanModules'][$scanType]['fileExtensions']['group'] ?? null;
        return is_string($pattern) && $pattern !== '' ? $pattern : null;
    }

    private function humanFilesize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf($power === 0 ? '%.0f%s' : '%.2f%s', $value, $units[$power]);
    }
}
