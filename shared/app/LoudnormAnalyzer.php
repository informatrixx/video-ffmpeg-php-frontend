<?php

declare(strict_types=1);

namespace App;

final class LoudnormAnalyzer
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function analyzeOutput(array $output): array
    {
        $prepared = $this->prepareOutput($output);
        if ($prepared === null) {
            return [];
        }
        $result = $this->runProcess($prepared['command']);

        return $this->measurementFromPrepared($prepared, $result['stderr']);
    }

    public function prepareOutput(array $output): ?array
    {
        $profileKey = trim((string) ($output['loudnorm'] ?? '0'));
        $profile = $this->resolveProfile($profileKey);
        if ($profile === null) {
            return null;
        }

        $sourcePath = $this->paths->assertInputFile((string) ($output['source_path'] ?? ''));
        $streamIndex = (int) ($output['source_stream_index'] ?? -1);
        if ($streamIndex < 0) {
            throw new \RuntimeException('Audio output references an invalid source stream for loudnorm analysis');
        }

        return [
            'profile_key' => $profileKey,
            'target' => $profile,
            'source_path' => $sourcePath,
            'source_stream_index' => $streamIndex,
            'command' => [
                $this->config->ffmpegPath(),
                '-hide_banner',
                '-nostdin',
                '-nostats',
                '-v',
                'info',
                '-i',
                $sourcePath,
                '-map',
                '0:' . $streamIndex,
                '-vn',
                '-sn',
                '-dn',
                '-af',
                $this->buildAnalysisFilterChain($output, $profile),
                '-f',
                'null',
                '-',
            ],
        ];
    }

    public function measurementFromPrepared(array $prepared, string $stderr): array
    {
        $measurement = $this->parseMeasurement($stderr);

        return [
            'profile' => (string) ($prepared['profile_key'] ?? ''),
            'target' => $prepared['target'] ?? [],
            'measured_I' => (string) $measurement['input_i'],
            'measured_TP' => (string) $measurement['input_tp'],
            'measured_LRA' => (string) $measurement['input_lra'],
            'measured_thresh' => (string) $measurement['input_thresh'],
            'offset' => (string) $measurement['target_offset'],
            'normalization_type' => isset($measurement['normalization_type']) ? (string) $measurement['normalization_type'] : null,
            'analyzed_at' => gmdate('c'),
        ];
    }

    private function buildAnalysisFilterChain(array $output, array $profile): string
    {
        $filters = [];

        $customFilter = trim((string) ($output['filter'] ?? ''));
        if ($customFilter !== '' && $customFilter !== '0') {
            $filters[] = $customFilter;
        }

        $sampleRate = (int) ($output['samplerate'] ?? 0);
        if ($sampleRate > 0) {
            $filters[] = 'aresample=' . $sampleRate;
        }

        $channelLayout = $this->normalizeChannelLayout($output['channels'] ?? null);
        if ($channelLayout !== null) {
            $filters[] = 'aformat=channel_layouts=' . $channelLayout;
        }

        $filters[] = sprintf(
            'loudnorm=I=%s:TP=%s:LRA=%s:print_format=json',
            $this->filterValue($profile['I']),
            $this->filterValue($profile['TP']),
            $this->filterValue($profile['LRA'])
        );

        return implode(',', $filters);
    }

    private function resolveProfile(string $profileKey): ?array
    {
        if ($profileKey === '' || $profileKey === '0') {
            return null;
        }

        $profiles = $this->config->static()['audio']['loudnorm'] ?? [];
        $profile = $profiles[$profileKey] ?? null;
        if (!is_array($profile) || !isset($profile['I'], $profile['TP'], $profile['LRA'])) {
            throw new \RuntimeException("Unsupported loudnorm profile: {$profileKey}");
        }

        return $profile;
    }

    private function normalizeChannelLayout(mixed $channels): ?string
    {
        if ($channels === null || $channels === false || $channels === '') {
            return null;
        }

        if (is_int($channels)) {
            return match ($channels) {
                1 => 'mono',
                2 => 'stereo',
                6 => '5.1',
                8 => '7.1',
                default => null,
            };
        }

        return match (strtolower(trim((string) $channels))) {
            '1', 'mono' => 'mono',
            '2', 'stereo', 'dpl' => 'stereo',
            '6', '5.1' => '5.1',
            '8', '7.1' => '7.1',
            default => null,
        };
    }

    private function runProcess(array $command): array
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            APP_ROOT,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start loudnorm analysis process');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                break;
            }

            usleep(100000);
        } while (true);

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $procClose = proc_close($process);
        if ($procClose >= 0) {
            $exitCode = $procClose;
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Loudnorm analysis failed with exit code ' . $exitCode . ': ' . trim($stderr)
            );
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function parseMeasurement(string $stderr): array
    {
        preg_match_all('/\{\s*"input_i"\s*:\s*"[^"]*".*?"target_offset"\s*:\s*"[^"]*"\s*\}/s', $stderr, $matches);
        $jsonBlocks = $matches[0] ?? [];
        if (!is_array($jsonBlocks) || $jsonBlocks === []) {
            throw new \RuntimeException('Loudnorm analysis did not return JSON measurements');
        }

        $raw = (string) end($jsonBlocks);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Unable to decode loudnorm analysis JSON');
        }

        foreach (['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new \RuntimeException("Loudnorm analysis JSON is missing {$key}");
            }
        }

        return $decoded;
    }

    private function filterValue(mixed $value): string
    {
        return str_replace(',', '.', trim((string) $value));
    }
}
