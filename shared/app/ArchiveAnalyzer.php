<?php

declare(strict_types=1);

namespace App;

final class ArchiveAnalyzer
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function analyze(string $archivePath): array
    {
        $archivePath = $this->paths->assertInputFile($archivePath);
        if ($this->paths->guessType($archivePath) !== 'rar') {
            throw new \RuntimeException('Only RAR archives are supported');
        }

        $command = $this->config->unrarPath() . ' lt ' . escapeshellarg($archivePath);
        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            throw new \RuntimeException("Unable to inspect RAR archive: {$archivePath}");
        }

        $archiveFiles = $this->parseTechnicalListing($output);

        return [
            'type' => 'rar',
            'file' => $archivePath,
            'file_name' => basename($archivePath),
            'base_name' => pathinfo($archivePath, PATHINFO_FILENAME),
            'info' => [
                'format_name' => 'RAR Archive',
                'entry_count' => count($archiveFiles),
                'size_bytes' => filesize($archivePath) ?: 0,
                'size_human' => $this->humanFilesize((int) (filesize($archivePath) ?: 0)),
            ],
            'job_defaults' => [
                'output_folder' => rtrim(dirname($archivePath), '/'),
                'ignore_paths' => true,
                'overwrite' => true,
            ],
            'archive_files' => $archiveFiles,
            'raw_listing' => $output,
        ];
    }

    private function parseTechnicalListing(string $output): array
    {
        $archiveFiles = [];
        $current = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if (($current['Type'] ?? null) === 'File' && !empty($current['Name'])) {
                    $archiveFiles[$current['Name']] = $this->buildArchiveEntry($current);
                }
                $current = [];
                continue;
            }

            if (preg_match('/^(Name|Type|Size|mtime):\s+(.+)$/', $trimmed, $matches) !== 1) {
                continue;
            }

            $current[$matches[1]] = trim($matches[2]);
        }

        if (($current['Type'] ?? null) === 'File' && !empty($current['Name'])) {
            $archiveFiles[$current['Name']] = $this->buildArchiveEntry($current);
        }

        return array_values($archiveFiles);
    }

    private function buildArchiveEntry(array $data): array
    {
        $entryName = trim((string) ($data['Name'] ?? ''));
        $sizeBytes = (int) ($data['Size'] ?? 0);
        $rawDate = trim((string) ($data['mtime'] ?? ''));
        $date = $rawDate;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $rawDate, $matches) === 1) {
            $date = $matches[0];
        }

        return [
            'entry_name' => $entryName,
            'file_name' => basename($entryName),
            'size_bytes' => $sizeBytes,
            'size_human' => $this->humanFilesize($sizeBytes),
            'date' => $date,
            'enabled' => true,
        ];
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
