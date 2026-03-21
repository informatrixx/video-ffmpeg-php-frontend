<?php

declare(strict_types=1);

namespace App;

final class CropPreviewService
{
    private Config $config;
    private PathService $paths;

    public function __construct(Config $config, PathService $paths)
    {
        $this->config = $config;
        $this->paths = $paths;
    }

    public function render(string $filePath, float $seekSeconds, ?float $sampleAspectRatio = null): array
    {
        if (!class_exists(\Imagick::class)) {
            throw new \RuntimeException('Crop preview requires php-imagick');
        }

        $filePath = $this->paths->assertInputFile($filePath);
        $seekSeconds = max(0.0, $seekSeconds);
        $sampleAspectRatio = $sampleAspectRatio !== null && $sampleAspectRatio > 0
            ? $sampleAspectRatio
            : $this->detectSampleAspectRatio($filePath);

        $cacheDir = $this->resolveCacheDir();

        $seekKey = preg_replace('/[^0-9]+/', '-', sprintf('%.3f', $seekSeconds)) ?: '0';
        $hash = md5($filePath . '|' . $seekKey);
        $jpgPath = $cacheDir . '/scan-' . $hash . '.jpg';
        $bmpPath = $cacheDir . '/scan-' . $hash . '.bmp';
        $metaPath = $cacheDir . '/scan-' . $hash . '.json';

        $inputMtime = filemtime($filePath) ?: time();
        $cropData = null;

        if (is_file($jpgPath) && is_file($metaPath) && (filemtime($jpgPath) ?: 0) === $inputMtime) {
            $cached = json_decode((string) file_get_contents($metaPath), true);
            return [
                'image_url' => $this->dataUrl($jpgPath),
                'seek_human' => $this->formatDuration($seekSeconds),
                'sar' => $sampleAspectRatio,
                'crop' => is_array($cached) ? $cached : null,
                'cached' => true,
            ];
        }

        $extractCommand = sprintf(
            '%s -y -ss %s -i %s -frames:v 1 -update 1 -vf %s -an -sn %s 2>/dev/null',
            escapeshellcmd($this->config->ffmpegPath()),
            escapeshellarg((string) $seekSeconds),
            escapeshellarg($filePath),
            escapeshellarg("scale='trunc(ih*dar):ih',setsar=1"),
            escapeshellarg($bmpPath)
        );
        exec($extractCommand, $extractOutput, $extractExitCode);
        if ($extractExitCode !== 0 || !is_file($bmpPath)) {
            throw new \RuntimeException('Unable to generate crop preview frame');
        }

        $detectCommand = sprintf(
            '%s -ss %s -skip_frame nokey -i %s -frames:v %d -vf %s -an -sn -f null - 2>&1',
            escapeshellcmd($this->config->ffmpegPath()),
            escapeshellarg((string) $seekSeconds),
            escapeshellarg($filePath),
            (int) ($this->config->static()['cropPreview']['detectNumberFrames'] ?? 10),
            escapeshellarg('cropdetect=round=2')
        );
        $detectOutput = shell_exec($detectCommand);
        if (is_string($detectOutput) && preg_match('/crop=(\d+):(\d+):(\d+):(\d+)/', $detectOutput, $matches) === 1) {
            $cropData = [
                'string' => $matches[0],
                'width' => (int) $matches[1],
                'height' => (int) $matches[2],
                'x' => (int) $matches[3],
                'y' => (int) $matches[4],
                'width_sar' => (int) round(((int) $matches[1]) * $sampleAspectRatio),
            ];
        }

        $image = new \Imagick();
        $image->readImage($bmpPath);

        if ($cropData !== null) {
            $overlay = new \ImagickDraw();
            $overlay->setStrokeColor(new \ImagickPixel('red'));
            $overlay->setStrokeWidth(2);
            $overlay->setFillOpacity(0);
            $overlay->setStrokeDashArray([18, 10]);
            $overlay->rectangle(
                $cropData['x'],
                $cropData['y'],
                ($cropData['x'] + $cropData['width'] - 1) * $sampleAspectRatio,
                $cropData['y'] + $cropData['height']
            );
            $image->drawImage($overlay);
        }

        $image->thumbnailImage(800, 600, true);
        $image->setImageFormat('jpeg');
        $image->writeImage($jpgPath);
        $image->clear();
        $image->destroy();

        @unlink($bmpPath);
        file_put_contents($metaPath, json_encode($cropData, JSON_PRETTY_PRINT));
        @touch($jpgPath, $inputMtime);

        return [
            'image_url' => $this->dataUrl($jpgPath),
            'seek_human' => $this->formatDuration($seekSeconds),
            'sar' => $sampleAspectRatio,
            'crop' => $cropData,
            'cached' => false,
        ];
    }

    private function detectSampleAspectRatio(string $filePath): float
    {
        $command = sprintf(
            '%s -of csv=p=0 -v quiet -select_streams v:0 -show_entries stream=sample_aspect_ratio %s',
            escapeshellcmd($this->config->ffprobePath()),
            escapeshellarg($filePath)
        );

        $sarString = trim((string) shell_exec($command));
        if ($sarString === '' || $sarString === 'N/A') {
            return 1.0;
        }

        $parts = explode(':', $sarString);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1]) || (float) $parts[1] == 0.0) {
            return 1.0;
        }

        return (float) $parts[0] / (float) $parts[1];
    }

    private function formatDuration(float $seconds): string
    {
        $rounded = (int) round(max(0.0, $seconds));
        $hours = intdiv($rounded, 3600);
        $minutes = intdiv($rounded % 3600, 60);
        $secs = $rounded % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    private function dataUrl(string $jpgPath): string
    {
        $bytes = file_get_contents($jpgPath);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Unable to read crop preview cache file');
        }

        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    private function resolveCacheDir(): string
    {
        $candidates = [
            APP_ROOT . 'config/crop-preview-cache',
            rtrim(sys_get_temp_dir(), '/') . '/movie-ffmpeg-php-frontend/crop-preview-cache',
        ];

        foreach ($candidates as $candidate) {
            if ($this->ensureWritableDirectory($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to find writable crop preview cache directory');
    }

    private function ensureWritableDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        return is_writable($directory);
    }
}
