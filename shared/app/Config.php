<?php

declare(strict_types=1);

namespace App;

final class Config
{
    private array $config;
    private array $staticConfig;

    public function __construct()
    {
        $this->config = $this->loadJson(APP_ROOT . 'config.json');
        $this->staticConfig = $this->loadJson(APP_ROOT . 'config/static_config.json');
    }

    public function all(): array
    {
        return $this->config;
    }

    public function static(): array
    {
        return $this->staticConfig;
    }

    public function debug(): bool
    {
        return (bool) ($this->config['Debugging'] ?? false);
    }

    public function databaseDsn(): string
    {
        $runtime = $this->config['Runtime'] ?? [];
        if (!empty($runtime['DatabaseDsn'])) {
            return (string) $runtime['DatabaseDsn'];
        }

        return 'sqlite:' . $this->databasePath();
    }

    public function databasePath(): string
    {
        $runtime = $this->config['Runtime'] ?? [];
        if (!empty($runtime['DatabasePath'])) {
            return (string) $runtime['DatabasePath'];
        }

        return APP_ROOT . 'config/runtime.sqlite';
    }

    public function ffprobePath(): string
    {
        return (string) ($this->config['Binaries']['ffprobe'] ?? '/usr/bin/ffprobe');
    }

    public function ffmpegPath(): string
    {
        return (string) ($this->config['Binaries']['ffmpeg'] ?? '/usr/bin/ffmpeg');
    }

    public function unrarPath(): string
    {
        return (string) ($this->config['Binaries']['unrar'] ?? '/usr/bin/unrar');
    }

    public function mkvpropeditPath(): string
    {
        return (string) ($this->config['Binaries']['mkvpropedit'] ?? '/usr/bin/mkvpropedit');
    }

    public function securityConfig(): array
    {
        return $this->config['Security'] ?? [];
    }

    public function authConfigured(): bool
    {
        $security = $this->securityConfig();
        return !empty($security['AdminPasswordHash']);
    }

    public function adminPasswordHash(): ?string
    {
        $security = $this->securityConfig();
        return isset($security['AdminPasswordHash']) ? (string) $security['AdminPasswordHash'] : null;
    }

    public function sessionName(): string
    {
        $security = $this->securityConfig();
        return (string) ($security['SessionName'] ?? 'movieffmpegphpfrontend');
    }

    public function outputFileExistsStrategy(): string
    {
        $strategy = strtolower((string) ($this->config['OutputFileExists'] ?? 'move'));
        return in_array($strategy, ['move', 'overwrite', 'error'], true) ? $strategy : 'move';
    }

    public function workerPollIntervalMs(): int
    {
        $runtime = $this->config['Runtime'] ?? [];
        return max(100, (int) ($runtime['WorkerPollIntervalMs'] ?? 500));
    }

    public function workerIdleSleepMs(): int
    {
        $runtime = $this->config['Runtime'] ?? [];
        return max(250, (int) ($runtime['WorkerIdleSleepMs'] ?? 1000));
    }

    public function workerCancelGraceSeconds(): int
    {
        $runtime = $this->config['Runtime'] ?? [];
        return max(1, (int) ($runtime['WorkerCancelGraceSeconds'] ?? 8));
    }

    public function workerParallelLoudnormAnalysisMax(): int
    {
        $runtime = $this->config['Runtime'] ?? [];
        return max(0, min(8, (int) ($runtime['WorkerParallelLoudnormAnalysisMax'] ?? 3)));
    }

    public function workerFeedbackFile(): string
    {
        $runtime = $this->config['Runtime'] ?? [];
        return (string) ($runtime['WorkerFeedbackFile'] ?? (APP_ROOT . 'config/worker-feedback.jsonl'));
    }

    public function inputRoots(): array
    {
        $roots = $this->config['InputRoots'] ?? $this->config['ConvertRoots'] ?? [];
        return $this->normalizeRoots($roots);
    }

    public function outputRoots(): array
    {
        $roots = $this->config['OutputRoots'] ?? $this->config['ConvertRoots'] ?? [];
        return $this->normalizeRoots($roots);
    }

    public function allowedBatchExtensions(): array
    {
        $extensions = [];
        foreach (($this->staticConfig['scanModules'] ?? []) as $module) {
            $match = $module['fileExtensions']['match'] ?? [];
            if (is_string($match)) {
                $match = [$match];
            }
            foreach ($match as $extension) {
                if (is_string($extension) && $extension !== '' && $extension[0] !== '/') {
                    $extensions[] = strtolower($extension);
                }
            }
        }

        return array_values(array_unique($extensions));
    }

    private function normalizeRoots(array $roots): array
    {
        $normalized = [];
        foreach ($roots as $name => $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $resolvedPath = realpath($path);
            $normalized[$name] = rtrim(is_string($resolvedPath) ? $resolvedPath : $path, '/');
        }

        return $normalized;
    }

    private function loadJson(string $fileName): array
    {
        if (!is_file($fileName)) {
            throw new \RuntimeException("Missing config file: {$fileName}");
        }

        $data = json_decode((string) file_get_contents($fileName), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON config: {$fileName}");
        }

        return $data;
    }
}
