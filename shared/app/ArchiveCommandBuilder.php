<?php

declare(strict_types=1);

namespace App;

final class ArchiveCommandBuilder
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function build(array $job): array
    {
        $plan = $job['compiled_plan'] ?? null;
        if (!is_array($plan)) {
            throw new \RuntimeException('Archive job has no compiled plan');
        }

        $sourcePath = $this->paths->assertInputFile((string) ($job['source_path'] ?? ($plan['source']['path'] ?? '')));
        $outputFolder = $this->paths->assertWritableOutputFolder((string) ($job['output_folder'] ?? ($plan['job']['output_folder'] ?? '')));

        $entries = $plan['archive_entries'] ?? null;
        if (!is_array($entries) || $entries === []) {
            throw new \RuntimeException('Archive job has no selected entries');
        }

        $command = [
            $this->config->unrarPath(),
            $this->toBool($plan['options']['ignore_paths'] ?? false) ? 'e' : 'x',
            '-idq',
            '-y',
            $this->toBool($plan['options']['overwrite'] ?? true) ? '-o+' : '-o-',
            $sourcePath,
        ];

        $seenEntries = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['entry_name'])) {
                continue;
            }

            $entryName = (string) $entry['entry_name'];
            if (isset($seenEntries[$entryName])) {
                continue;
            }

            $seenEntries[$entryName] = true;
            $command[] = $entryName;
        }

        $command[] = rtrim($outputFolder, '/') . '/';

        return [
            'kind' => 'archive',
            'command' => $command,
            'source_path' => $sourcePath,
            'output' => [
                'folder' => $outputFolder,
                'file_name' => (string) ($job['output_file'] ?? ($plan['job']['output_file'] ?? basename($sourcePath))),
                'path' => $outputFolder,
            ],
            'output_path' => $outputFolder,
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        $value = strtolower(trim((string) $value));
        return !in_array($value, ['', '0', 'false', 'no', 'off'], true);
    }
}
