<?php

declare(strict_types=1);

namespace App;

final class PathService
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function assertInputFile(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw new \RuntimeException("Input file not found: {$path}");
        }

        if (!is_readable($realPath)) {
            throw new \RuntimeException("Input file is not readable: {$path}");
        }

        if (!$this->isWithinRoots($realPath, $this->config->inputRoots())) {
            throw new \RuntimeException("Input file outside allowed roots: {$path}");
        }

        return $realPath;
    }

    public function assertInputFolder(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new \RuntimeException("Input folder not found: {$path}");
        }

        if (!is_readable($realPath)) {
            throw new \RuntimeException("Input folder is not readable: {$path}");
        }

        if (!$this->isWithinRoots($realPath, $this->config->inputRoots())) {
            throw new \RuntimeException("Input folder outside allowed roots: {$path}");
        }

        return rtrim($realPath, '/');
    }

    public function assertOutputFolder(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            throw new \RuntimeException("Output folder not found: {$path}");
        }

        return $this->assertOutputFolderPath($realPath);
    }

    public function assertWritableOutputFolder(string $path): string
    {
        $realPath = $this->assertOutputFolder($path);
        if (!is_writable($realPath)) {
            throw new \RuntimeException("Output folder is not writable: {$path}");
        }

        return $realPath;
    }

    public function assertOutputFolderPath(string $path): string
    {
        $normalizedPath = $this->normalizeAbsolutePath($path);
        if (!$this->isWithinRoots($normalizedPath, $this->config->outputRoots())) {
            throw new \RuntimeException("Output folder outside allowed roots: {$path}");
        }

        return $normalizedPath;
    }

    public function ensureWritableOutputFolder(string $path): string
    {
        $normalizedPath = $this->assertOutputFolderPath($path);
        if (is_dir($normalizedPath)) {
            if (!is_writable($normalizedPath)) {
                throw new \RuntimeException("Output folder is not writable: {$path}");
            }

            return $normalizedPath;
        }

        if (!mkdir($normalizedPath, 0775, true) && !is_dir($normalizedPath)) {
            throw new \RuntimeException("Could not create output folder: {$path}");
        }

        return $this->assertWritableOutputFolder($normalizedPath);
    }

    public function outputFolderWriteIssue(string $path): ?string
    {
        try {
            $normalizedPath = $this->assertOutputFolderPath($path);
        } catch (\Throwable $throwable) {
            return $throwable->getMessage();
        }

        if (is_dir($normalizedPath)) {
            return is_writable($normalizedPath)
                ? null
                : "Output folder is not writable: {$normalizedPath}";
        }

        if (file_exists($normalizedPath) && !is_dir($normalizedPath)) {
            return "A file already exists at the output folder path: {$normalizedPath}";
        }

        $parent = dirname($normalizedPath);
        while ($parent !== '/' && !file_exists($parent)) {
            $parent = dirname($parent);
        }

        if (!is_dir($parent)) {
            return "No writable parent folder found for output path: {$normalizedPath}";
        }

        if (!$this->isWithinRoots($parent, $this->config->outputRoots())) {
            return "Output folder outside allowed roots: {$normalizedPath}";
        }

        return is_writable($parent)
            ? null
            : "Parent output folder is not writable: {$parent}";
    }

    public function createOutputFolder(string $parentPath, string $folderName): string
    {
        $parentPath = $this->assertWritableOutputFolder($parentPath);
        $folderName = trim($folderName);

        if ($folderName === '' || $folderName === '.' || $folderName === '..') {
            throw new \RuntimeException('Folder name must not be empty');
        }

        if (preg_match('/[\/\\\\]/', $folderName) === 1) {
            throw new \RuntimeException('Folder name must not contain path separators');
        }

        if (preg_match('/[\x00-\x1f]/', $folderName) === 1) {
            throw new \RuntimeException('Folder name contains invalid control characters');
        }

        $targetPath = $parentPath . '/' . $folderName;
        if (file_exists($targetPath)) {
            if (!is_dir($targetPath)) {
                throw new \RuntimeException("A file with the same name already exists: {$folderName}");
            }

            return $this->assertWritableOutputFolder($targetPath);
        }

        if (!mkdir($targetPath, 0775)) {
            throw new \RuntimeException("Could not create folder: {$folderName}");
        }

        return $this->assertWritableOutputFolder($targetPath);
    }

    public function normalizeOutputFileName(?string $fileName, ?string $fallbackBaseName = null, string $defaultExtension = 'mkv'): string
    {
        $fallbackBaseName = trim((string) ($fallbackBaseName ?? 'output'));
        $fileName = trim((string) ($fileName ?? ''));
        $fileName = str_replace(['\\', '/'], '-', basename($fileName));
        $fileName = preg_replace('/[\x00-\x1f<>:"|?*]+/', '-', $fileName) ?? '';
        $fileName = trim((string) preg_replace('/\s+/', ' ', $fileName), " .\t\n\r\0\x0B");

        if ($fileName === '') {
            $base = $fallbackBaseName !== '' ? $fallbackBaseName : 'output';
            $fileName = $base . '.' . ltrim($defaultExtension, '.');
        }

        if (pathinfo($fileName, PATHINFO_EXTENSION) === '') {
            $fileName .= '.' . ltrim($defaultExtension, '.');
        }

        return $fileName;
    }

    public function resolveOutputPath(string $folder, string $fileName): array
    {
        $folder = $this->assertOutputFolder($folder);
        $fileName = $this->normalizeOutputFileName($fileName);
        $candidate = $folder . '/' . $fileName;
        $strategy = $this->config->outputFileExistsStrategy();

        if (!file_exists($candidate) || $strategy === 'overwrite') {
            return [
                'folder' => $folder,
                'file_name' => $fileName,
                'path' => $candidate,
            ];
        }

        if ($strategy === 'error') {
            throw new \RuntimeException("Output file already exists: {$candidate}");
        }

        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        for ($counter = 1; $counter <= 1000; $counter++) {
            $suffix = sprintf('-%03d', $counter);
            $nextFileName = $baseName . $suffix . ($extension !== '' ? '.' . $extension : '');
            $nextPath = $folder . '/' . $nextFileName;
            if (!file_exists($nextPath)) {
                return [
                    'folder' => $folder,
                    'file_name' => $nextFileName,
                    'path' => $nextPath,
                ];
            }
        }

        throw new \RuntimeException("Unable to resolve unique output file name for {$candidate}");
    }

    public function isWithinRoots(string $path, array $roots): bool
    {
        $normalizedPath = rtrim($path, '/');
        foreach ($roots as $rootPath) {
            $normalizedRoot = rtrim($rootPath, '/');
            if ($normalizedRoot === '') {
                continue;
            }
            if ($normalizedPath === $normalizedRoot || str_starts_with($normalizedPath . '/', $normalizedRoot . '/')) {
                return true;
            }
        }

        return false;
    }

    public function guessType(string $path): ?string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $scanModules = $this->config->static()['scanModules'] ?? [];

        foreach ($scanModules as $scanModule => $scanModuleData) {
            $match = $scanModuleData['fileExtensions']['match'] ?? [];
            if (is_string($match)) {
                $match = [$match];
            }

            foreach ($match as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $candidate[0] !== '/' && $candidate === $extension) {
                    return $scanModule;
                }
            }
        }

        return null;
    }

    public function listBatchCandidates(string $folder, bool $recursive = false): array
    {
        $folder = $this->assertInputFolder($folder);
        $files = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)
            );
        } else {
            $iterator = new \FilesystemIterator($folder, \FilesystemIterator::SKIP_DOTS);
        }

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = $item->getPathname();
            $type = $this->guessType($path);
            if ($type === null) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'type' => $type,
            ];
        }

        usort(
            $files,
            static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
        );

        return $files;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || $path[0] !== '/') {
            throw new \RuntimeException("Path must be absolute: {$path}");
        }

        $segments = explode('/', $path);
        $normalizedSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new \RuntimeException("Path must not contain parent traversal: {$path}");
            }

            if (preg_match('/[\x00-\x1f]/', $segment) === 1) {
                throw new \RuntimeException("Path contains invalid control characters: {$path}");
            }

            $normalizedSegments[] = $segment;
        }

        return '/' . implode('/', $normalizedSegments);
    }
}
