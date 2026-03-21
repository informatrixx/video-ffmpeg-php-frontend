<?php

declare(strict_types=1);

namespace App;

final class MediaAnalyzer
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function analyze(string $filePath): array
    {
        $filePath = $this->paths->assertInputFile($filePath);
        if ($this->paths->guessType($filePath) !== 'video') {
            throw new \RuntimeException("Primary scan currently supports video files only: {$filePath}");
        }

        return $this->probeAndNormalize($filePath, 'video');
    }

    public function analyzeJoinSource(string $filePath): array
    {
        $filePath = $this->paths->assertInputFile($filePath);
        if (!$this->isJoinable($filePath)) {
            throw new \RuntimeException("File is not joinable: {$filePath}");
        }

        return $this->probeAndNormalize($filePath, $this->paths->guessType($filePath) ?? 'joined');
    }

    private function probeAndNormalize(string $filePath, string $analysisType): array
    {
        $command = $this->config->ffprobePath()
            . ' -show_chapters -show_format -show_streams -print_format json -loglevel quiet '
            . escapeshellarg($filePath);

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("ffprobe failed for {$filePath}");
        }

        $probeData = json_decode(implode(PHP_EOL, $output), true);
        if (!is_array($probeData)) {
            throw new \RuntimeException("Invalid ffprobe output for {$filePath}");
        }

        return $this->normalizeAnalysis($filePath, $analysisType, $probeData);
    }

    private function normalizeAnalysis(string $filePath, string $analysisType, array $probeData): array
    {
        $staticConfig = $this->config->static();
        $format = $probeData['format'] ?? [];

        $audioStreams = [];
        $subtitleStreams = [];
        $videoStreams = [];
        $audioLanguages = [];
        $subtitleLanguages = [];

        foreach (($probeData['streams'] ?? []) as $streamData) {
            $codecType = (string) ($streamData['codec_type'] ?? '');
            $tags = $streamData['tags'] ?? [];
            $disposition = $streamData['disposition'] ?? [];
            $streamIndex = (int) ($streamData['index'] ?? 0);

            if ($codecType === 'video' && (int) ($disposition['attached_pic'] ?? 0) !== 1) {
                $width = (int) ($streamData['width'] ?? 0);
                $height = (int) ($streamData['height'] ?? 0);
                $videoStreams[] = [
                    'stream_index' => $streamIndex,
                    'codec' => [
                        'name' => (string) ($streamData['codec_name'] ?? ''),
                        'name_uc' => strtoupper((string) ($streamData['codec_name'] ?? '')),
                        'long_name' => (string) ($streamData['codec_long_name'] ?? ''),
                    ],
                    'width' => $width,
                    'height' => $height,
                    'resolution_name' => $this->resolutionName($width),
                    'sample_aspect_ratio' => (string) (($streamData['sample_aspect_ratio'] ?? '') ?: '1:1'),
                    'display_aspect_ratio' => (string) (($streamData['display_aspect_ratio'] ?? '') ?: $this->displayAspectRatio($width, $height)),
                    'title' => isset($tags['title']) ? (string) $tags['title'] : null,
                    'disposition' => [
                        'default' => (bool) ($disposition['default'] ?? false),
                        'forced' => (bool) ($disposition['forced'] ?? false),
                    ],
                ];
                continue;
            }

            if ($codecType === 'audio') {
                $language = $this->normalizeLanguage((string) ($tags['language'] ?? 'unknown'));
                $audioLanguages[$language] = (string) (($staticConfig['languages'][$language] ?? null) ?: strtoupper($language));
                $channels = (int) ($streamData['channels'] ?? 2);
                $bitrate = $this->resolveBitrate($streamData);
                $audioStreams[] = [
                    'stream_index' => $streamIndex,
                    'codec' => [
                        'name' => (string) ($streamData['codec_name'] ?? ''),
                        'name_uc' => strtoupper((string) ($streamData['codec_name'] ?? '')),
                        'long_name' => (string) ($streamData['codec_long_name'] ?? ''),
                        'profile' => (string) ($streamData['profile'] ?? ''),
                    ],
                    'language' => [
                        'short' => $language,
                        'human' => (string) (($staticConfig['languages'][$language] ?? null) ?: strtoupper($language)),
                    ],
                    'channels' => [
                        'count' => $channels,
                        'layout' => (string) (($streamData['channel_layout'] ?? '') ?: (string) ($staticConfig['audio']['channels'][(string) $channels] ?? $channels)),
                    ],
                    'sample_rate' => (int) ($streamData['sample_rate'] ?? 0),
                    'bitrate' => [
                        'bps' => $bitrate,
                        'human' => $bitrate !== null ? round($bitrate / 1024, 0) . ' KB/s' : null,
                        'human_short' => $bitrate !== null ? round($bitrate / 1024, 0) . 'K/s' : null,
                    ],
                    'title' => isset($tags['title']) ? (string) $tags['title'] : null,
                    'disposition' => [
                        'default' => (bool) ($disposition['default'] ?? false),
                        'forced' => (bool) ($disposition['forced'] ?? false),
                    ],
                ];
                continue;
            }

            if ($codecType === 'subtitle') {
                $language = $this->normalizeLanguage((string) ($tags['language'] ?? 'unknown'));
                $subtitleLanguages[$language] = (string) (($staticConfig['languages'][$language] ?? null) ?: strtoupper($language));
                $subtitleStreams[] = [
                    'stream_index' => $streamIndex,
                    'codec' => [
                        'name' => (string) ($streamData['codec_name'] ?? ''),
                        'name_uc' => strtoupper((string) ($streamData['codec_name'] ?? '')),
                        'long_name' => (string) ($streamData['codec_long_name'] ?? ''),
                    ],
                    'language' => [
                        'short' => $language,
                        'human' => (string) (($staticConfig['languages'][$language] ?? null) ?: strtoupper($language)),
                    ],
                    'title' => isset($tags['title']) ? (string) $tags['title'] : null,
                    'disposition' => [
                        'default' => (bool) ($disposition['default'] ?? false),
                        'forced' => (bool) ($disposition['forced'] ?? false),
                    ],
                ];
            }
        }

        $sizeBytes = isset($format['size']) ? (int) $format['size'] : ((int) (filesize($filePath) ?: 0));
        $durationSeconds = isset($format['duration']) ? (float) $format['duration'] : 0.0;

        return [
            'type' => $analysisType,
            'file' => $filePath,
            'file_name' => basename($filePath),
            'base_name' => pathinfo($filePath, PATHINFO_FILENAME),
            'info' => [
                'title' => isset($format['tags']['title']) ? (string) $format['tags']['title'] : null,
                'format_name' => (string) ($format['format_long_name'] ?? $format['format_name'] ?? ''),
                'duration_seconds' => $durationSeconds,
                'duration_human' => $this->formatDuration($durationSeconds),
                'size_bytes' => $sizeBytes,
                'size_human' => $this->humanFilesize($sizeBytes),
                'chapter_count' => count($probeData['chapters'] ?? []),
                'has_chapters' => count($probeData['chapters'] ?? []) > 1,
                'video_stream_count' => count($videoStreams),
                'audio_stream_count' => count($audioStreams),
                'subtitle_stream_count' => count($subtitleStreams),
                'languages' => [
                    'audio' => array_values($audioLanguages),
                    'subtitle' => array_values($subtitleLanguages),
                ],
            ],
            'streams' => [
                'video' => $videoStreams,
                'audio' => $audioStreams,
                'subtitle' => $subtitleStreams,
            ],
            'raw_probe' => $probeData,
        ];
    }

    private function resolveBitrate(array $streamData): ?int
    {
        foreach (['bit_rate', 'tags.BPS', 'tags.BPS-eng'] as $candidate) {
            $value = $this->nestedValue($streamData, $candidate);
            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function nestedValue(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        $value = $data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function isJoinable(string $filePath): bool
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $joinExtensions = $this->config->static()['scanModules']['video']['fileExtensions']['join'] ?? [];
        if (is_string($joinExtensions)) {
            $joinExtensions = [$joinExtensions];
        }

        return in_array($extension, $joinExtensions, true) || $this->paths->guessType($filePath) === 'video';
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        return $language !== '' ? $language : 'unknown';
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '00:00:00';
        }

        $rounded = (int) round($seconds);
        $hours = intdiv($rounded, 3600);
        $minutes = intdiv($rounded % 3600, 60);
        $secs = $rounded % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
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

    private function resolutionName(int $width): string
    {
        return match (true) {
            $width > 7500 => '8K',
            $width > 3700 => '4K',
            $width > 2400 => 'Quad HD',
            $width > 1750 => '1080p',
            $width > 1100 => '720p',
            default => 'SD',
        };
    }

    private function displayAspectRatio(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return 'unknown';
        }

        $ratio = round($width / $height, 2);
        return match (true) {
            abs($ratio - 2.33) < 0.08 => '21:9',
            abs($ratio - 1.78) < 0.08 => '16:9',
            abs($ratio - 1.6) < 0.08 => '16:10',
            abs($ratio - 1.89) < 0.08 => '17:9',
            default => $ratio . ':1',
        };
    }
}
