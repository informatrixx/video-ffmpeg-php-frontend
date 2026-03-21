<?php

declare(strict_types=1);

namespace App;

final class TemplateEngine
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function compile(array $analysis, array $schema, ?string $variant = null, array $overrides = []): array
    {
        $this->validateSchema($schema);

        $legacy = is_array($schema['legacy_decisions'] ?? null) ? $schema['legacy_decisions'] : [];
        $rules = $this->sortedRules($schema['rules'] ?? []);
        $variant = $variant ?: $this->defaultVariant($legacy);
        $naming = is_array($schema['naming'] ?? null) ? $schema['naming'] : [];

        $plan = [
            'schema_version' => (int) ($schema['schema_version'] ?? 1),
            'template_name' => (string) ($schema['name'] ?? 'Unnamed template'),
            'variant' => $variant,
            'source' => [
                'path' => $analysis['file'],
                'type' => $analysis['type'],
            ],
            'job' => [
                'title' => (string) (($analysis['info']['title'] ?? null) ?: $analysis['base_name']),
                'output_file' => $analysis['base_name'] . '.mkv',
                'output_folder' => rtrim(dirname($analysis['file']), '/'),
            ],
            'video_outputs' => [],
            'audio_outputs' => [],
            'subtitle_outputs' => [],
        ];

        $plan['job'] = $this->applyJobRules($analysis, $rules, $plan['job']);

        foreach (($analysis['streams']['video'] ?? []) as $videoStream) {
            $defaults = [];
            if ($legacy !== []) {
                $defaults[] = $this->compileLegacyVideoOutput($videoStream, $legacy, $variant);
            }

            $plan['video_outputs'] = array_merge(
                $plan['video_outputs'],
                $this->applyScopedRules('video', $analysis, $videoStream, $rules, $defaults)
            );
        }

        foreach (($analysis['streams']['audio'] ?? []) as $audioStream) {
            $defaults = [];
            if ($legacy !== []) {
                $defaults[] = $this->compileLegacyAudioOutput($audioStream, $legacy, $variant, $naming);
            }

            $plan['audio_outputs'] = array_merge(
                $plan['audio_outputs'],
                $this->applyScopedRules('audio', $analysis, $audioStream, $rules, $defaults)
            );
        }

        $subtitleIndex = 0;
        $subtitlePick = $legacy['subtitles']['pick'] ?? [];
        foreach (($analysis['streams']['subtitle'] ?? []) as $subtitleStream) {
            if (in_array($subtitleStream['language']['short'] ?? '', $subtitlePick, true)) {
                $subtitleIndex++;
            }

            $defaults = [];
            if ($legacy !== []) {
                $defaults[] = $this->compileLegacySubtitleOutput($subtitleStream, $legacy, $naming, $subtitleIndex);
            }

            $plan['subtitle_outputs'] = array_merge(
                $plan['subtitle_outputs'],
                $this->applyScopedRules('subtitle', $analysis, $subtitleStream, $rules, $defaults)
            );
        }

        if (array_key_exists('title', $overrides) && trim((string) $overrides['title']) !== '') {
            $plan['job']['title'] = (string) $overrides['title'];
        }
        if (array_key_exists('output_file', $overrides) && trim((string) $overrides['output_file']) !== '') {
            $plan['job']['output_file'] = (string) $overrides['output_file'];
        }
        if (array_key_exists('output_folder', $overrides) && trim((string) $overrides['output_folder']) !== '') {
            $plan['job']['output_folder'] = rtrim((string) $overrides['output_folder'], '/');
        }

        return $plan;
    }

    public function validateSchema(array $schema): void
    {
        if (($schema['schema_version'] ?? null) !== 1) {
            throw new \RuntimeException('Unsupported template schema_version');
        }

        if (!isset($schema['name']) || !is_string($schema['name']) || trim($schema['name']) === '') {
            throw new \RuntimeException('Template schema requires a non-empty name');
        }

        if (isset($schema['rules']) && !is_array($schema['rules'])) {
            throw new \RuntimeException('Template rules must be an array');
        }

        foreach (($schema['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                throw new \RuntimeException('Each template rule must be an object');
            }

            $scope = strtolower(trim((string) ($rule['scope'] ?? '')));
            if (!in_array($scope, ['job', 'video', 'audio', 'subtitle'], true)) {
                throw new \RuntimeException('Each template rule requires a supported scope');
            }

            if (isset($rule['match']) && !is_array($rule['match'])) {
                throw new \RuntimeException('Template rule matches must be objects');
            }

            $hasOutputs = is_array($rule['outputs'] ?? null) && ($rule['outputs'] ?? []) !== [];
            $hasJobMutation = is_array($rule['job'] ?? null) && ($rule['job'] ?? []) !== [];

            if ($scope === 'job' && !$hasOutputs && !$hasJobMutation) {
                throw new \RuntimeException('Job rules require a job payload or outputs');
            }

            if ($scope !== 'job' && !$hasOutputs) {
                throw new \RuntimeException('Video, audio and subtitle rules require at least one output');
            }
        }
    }

    public function buildDefaultSchema(): array
    {
        $legacy = json_decode((string) file_get_contents(APP_ROOT . 'config/decision_template.json'), true);
        if (!is_array($legacy)) {
            throw new \RuntimeException('Invalid legacy decision template');
        }

        return [
            'schema_version' => 1,
            'name' => 'Legacy Default',
            'description' => 'Bootstrapped from the legacy decision template with generic rules and a sample dual-output audio rule.',
            'naming' => [
                'audio' => $legacy['audio']['autoNaming'] ?? '',
                'subtitle' => $legacy['subtitles']['autoNaming'] ?? '',
            ],
            'legacy_decisions' => $legacy,
            'rules' => [
                [
                    'id' => 'german-ac3-dual-output',
                    'scope' => 'audio',
                    'priority' => 100,
                    'replace_default' => true,
                    'match' => [
                        'language_in' => ['ger', 'deu'],
                        'codec_in' => ['ac3'],
                    ],
                    'outputs' => [
                        [
                            'action' => 'copy',
                            'title' => '{{lang_human}} ({{codec_uc}})',
                        ],
                        [
                            'action' => 'encode',
                            'codec' => 'libfdk_aac',
                            'profile' => 'main',
                            'bitrate' => '256k',
                            'samplerate' => 48000,
                            'channels' => 2,
                            'loudnorm' => 'ebur128',
                            'title' => '{{lang_human}} (AAC, Loudnorm)',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function sortedRules(array $rules): array
    {
        $rules = array_values(array_filter($rules, 'is_array'));
        usort(
            $rules,
            static fn (array $left, array $right): int => (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0)
        );

        return $rules;
    }

    private function applyJobRules(array $analysis, array $rules, array $job): array
    {
        foreach ($rules as $rule) {
            if (($rule['scope'] ?? null) !== 'job') {
                continue;
            }

            if (!$this->matchesRule($rule['match'] ?? [], $analysis, null)) {
                continue;
            }

            foreach ((array) ($rule['job'] ?? []) as $field => $value) {
                if (!in_array($field, ['title', 'output_file', 'output_folder'], true)) {
                    continue;
                }

                $rendered = is_string($value)
                    ? $this->renderJobTemplate($value, $analysis, $job)
                    : $value;

                if (is_string($rendered) && trim($rendered) === '') {
                    continue;
                }

                $job[$field] = is_string($rendered) ? trim($rendered) : $rendered;
            }
        }

        return $job;
    }

    private function applyScopedRules(string $scope, array $analysis, array $stream, array $rules, array $defaults): array
    {
        $outputs = $defaults;

        foreach ($rules as $rule) {
            if (($rule['scope'] ?? null) !== $scope) {
                continue;
            }

            if (!$this->matchesRule($rule['match'] ?? [], $analysis, $stream)) {
                continue;
            }

            if (!empty($rule['replace_default'])) {
                $outputs = [];
            }

            foreach ((array) ($rule['outputs'] ?? []) as $ruleOutput) {
                if (!is_array($ruleOutput)) {
                    continue;
                }

                $outputs[] = match ($scope) {
                    'video' => $this->compileRuleVideoOutput($stream, $ruleOutput),
                    'audio' => $this->compileRuleAudioOutput($stream, $ruleOutput),
                    'subtitle' => $this->compileRuleSubtitleOutput($stream, $ruleOutput),
                    default => [],
                };
            }
        }

        return array_values(array_filter($outputs, 'is_array'));
    }

    private function matchesRule(array $match, array $analysis, ?array $stream): bool
    {
        $analysisType = strtolower((string) ($analysis['type'] ?? ''));
        $typeNeedles = $this->normalizeNeedles($match['type_in'] ?? []);
        if ($typeNeedles !== [] && !in_array($analysisType, $typeNeedles, true)) {
            return false;
        }

        if (!empty($match['file_regex'])) {
            $fileCandidates = [
                (string) ($analysis['file_name'] ?? ''),
                (string) ($analysis['base_name'] ?? ''),
                (string) ($analysis['file'] ?? ''),
            ];
            $matched = false;
            foreach ($fileCandidates as $candidate) {
                if ($candidate !== '' && @preg_match((string) $match['file_regex'], $candidate) === 1) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        if ($stream === null) {
            foreach (['language_in', 'codec_in', 'channels_in', 'title_regex', 'width_min', 'width_max', 'height_min', 'height_max', 'disposition_default', 'disposition_forced'] as $key) {
                if (array_key_exists($key, $match)) {
                    return false;
                }
            }

            return true;
        }

        $codecNeedles = $this->normalizeNeedles($match['codec_in'] ?? []);
        $codecName = strtolower((string) ($stream['codec']['name'] ?? ''));
        if ($codecNeedles !== [] && !in_array($codecName, $codecNeedles, true)) {
            return false;
        }

        $languageNeedles = $this->normalizeNeedles($match['language_in'] ?? []);
        if ($languageNeedles !== []) {
            $languageValues = $this->streamLanguageValues($stream);
            if (array_intersect($languageNeedles, $languageValues) === []) {
                return false;
            }
        }

        if (!empty($match['channels_in'])) {
            $channelNeedles = array_map('intval', (array) $match['channels_in']);
            $channelCount = (int) ($stream['channels']['count'] ?? 0);
            if (!in_array($channelCount, $channelNeedles, true)) {
                return false;
            }
        }

        if (!empty($match['title_regex'])) {
            $title = (string) ($stream['title'] ?? '');
            if (@preg_match((string) $match['title_regex'], $title) !== 1) {
                return false;
            }
        }

        if (array_key_exists('width_min', $match) && (int) ($stream['width'] ?? -1) < (int) $match['width_min']) {
            return false;
        }
        if (array_key_exists('width_max', $match) && (int) ($stream['width'] ?? PHP_INT_MAX) > (int) $match['width_max']) {
            return false;
        }
        if (array_key_exists('height_min', $match) && (int) ($stream['height'] ?? -1) < (int) $match['height_min']) {
            return false;
        }
        if (array_key_exists('height_max', $match) && (int) ($stream['height'] ?? PHP_INT_MAX) > (int) $match['height_max']) {
            return false;
        }

        $matchDefault = $this->normalizeBoolMatch($match['disposition_default'] ?? null);
        if ($matchDefault !== null && $matchDefault !== (bool) ($stream['disposition']['default'] ?? false)) {
            return false;
        }

        $matchForced = $this->normalizeBoolMatch($match['disposition_forced'] ?? null);
        if ($matchForced !== null && $matchForced !== (bool) ($stream['disposition']['forced'] ?? false)) {
            return false;
        }

        return true;
    }

    private function compileRuleVideoOutput(array $stream, array $ruleOutput): array
    {
        $action = (string) ($ruleOutput['action'] ?? 'encode');
        $codec = $action === 'copy' ? 'copy' : (string) ($ruleOutput['codec'] ?? 'libx265');

        return [
            'source_stream_index' => $stream['stream_index'],
            'action' => $action,
            'codec' => $codec,
            'mode' => (string) ($ruleOutput['mode'] ?? 'crf'),
            'mode_value' => $ruleOutput['mode_value'] ?? 23,
            'preset' => $ruleOutput['preset'] ?? 'slow',
            'crop' => $ruleOutput['crop'] ?? 'auto',
            'resize' => $ruleOutput['resize'] ?? '0',
            'nlmeans' => $ruleOutput['nlmeans'] ?? '0',
        ];
    }

    private function compileRuleAudioOutput(array $stream, array $ruleOutput): array
    {
        $action = (string) ($ruleOutput['action'] ?? 'encode');
        $codec = $action === 'copy' ? 'copy' : (string) ($ruleOutput['codec'] ?? 'libfdk_aac');

        return [
            'source_stream_index' => $stream['stream_index'],
            'action' => $action,
            'codec' => $codec,
            'profile' => $ruleOutput['profile'] ?? null,
            'bitrate' => $ruleOutput['bitrate'] ?? null,
            'samplerate' => $ruleOutput['samplerate'] ?? null,
            'channels' => $ruleOutput['channels'] ?? ($stream['channels']['count'] ?? 2),
            'filter' => $ruleOutput['filter'] ?? null,
            'loudnorm' => $ruleOutput['loudnorm'] ?? '0',
            'title' => $this->renderTemplate((string) ($ruleOutput['title'] ?? ''), $stream, $ruleOutput),
            'disposition_default' => array_key_exists('disposition_default', $ruleOutput)
                ? (bool) $ruleOutput['disposition_default']
                : (bool) ($stream['disposition']['default'] ?? false),
            'disposition_forced' => array_key_exists('disposition_forced', $ruleOutput)
                ? (bool) $ruleOutput['disposition_forced']
                : (bool) ($stream['disposition']['forced'] ?? false),
        ];
    }

    private function compileRuleSubtitleOutput(array $stream, array $ruleOutput): array
    {
        $action = (string) ($ruleOutput['action'] ?? 'copy');

        return [
            'source_stream_index' => $stream['stream_index'],
            'action' => $action,
            'codec' => $action === 'skip' ? null : (string) (($ruleOutput['codec'] ?? null) ?: 'copy'),
            'title' => $this->renderTemplate((string) ($ruleOutput['title'] ?? ''), $stream, $ruleOutput),
            'disposition_default' => array_key_exists('disposition_default', $ruleOutput)
                ? (bool) $ruleOutput['disposition_default']
                : (bool) ($stream['disposition']['default'] ?? false),
            'disposition_forced' => array_key_exists('disposition_forced', $ruleOutput)
                ? (bool) $ruleOutput['disposition_forced']
                : (bool) ($stream['disposition']['forced'] ?? false),
        ];
    }

    private function normalizeNeedles(mixed $values): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $values
        ))));
    }

    private function normalizeBoolMatch(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function streamLanguageValues(array $stream): array
    {
        return $this->normalizeNeedles([
            $stream['language']['short'] ?? null,
            $stream['language']['human'] ?? null,
        ]);
    }

    private function renderJobTemplate(string $template, array $analysis, array $job): string
    {
        if ($template === '') {
            return (string) ($job['title'] ?? '');
        }

        $fileName = (string) ($analysis['file_name'] ?? basename((string) ($analysis['file'] ?? '')));
        $baseName = (string) ($analysis['base_name'] ?? pathinfo($fileName, PATHINFO_FILENAME));
        $extension = (string) pathinfo($fileName, PATHINFO_EXTENSION);
        $title = (string) (($job['title'] ?? null) ?: ($analysis['info']['title'] ?? $baseName));
        $sourceDir = rtrim(dirname((string) ($analysis['file'] ?? '')), '/');

        return trim(strtr($template, [
            '{{title}}' => $title,
            '{{job_title}}' => $title,
            '{{source_file_name}}' => $fileName,
            '{{source_base_name}}' => $baseName,
            '{{source_extension}}' => $extension,
            '{{source_dir}}' => $sourceDir,
        ]));
    }

    private function defaultVariant(array $legacy): string
    {
        $variants = array_keys($legacy['presets'] ?? []);
        return $variants[0] ?? 'default';
    }

    private function compileLegacyVideoOutput(array $stream, array $legacy, string $variant): array
    {
        $videoDecision = $this->makeLegacyVideoDecision((int) ($stream['width'] ?? 0), $legacy, $variant);

        return [
            'source_stream_index' => $stream['stream_index'],
            'action' => 'encode',
            'codec' => $videoDecision['codec'],
            'mode' => $videoDecision['mode'],
            'mode_value' => $videoDecision['mode_value'],
            'preset' => $videoDecision['preset'],
            'crop' => $videoDecision['crop'],
            'resize' => $videoDecision['resize'],
            'nlmeans' => $videoDecision['nlmeans'],
        ];
    }

    private function compileLegacyAudioOutput(array $stream, array $legacy, string $variant, array $naming): array
    {
        $audioDecision = $this->makeLegacyAudioDecision($stream, $legacy, $variant);

        return [
            'source_stream_index' => $stream['stream_index'],
            'action' => $audioDecision['codec'] === 'copy' ? 'copy' : 'encode',
            'codec' => $audioDecision['codec'],
            'profile' => $audioDecision['profile'],
            'bitrate' => $audioDecision['bitrate'],
            'samplerate' => $audioDecision['samplerate'],
            'channels' => $audioDecision['channels'],
            'filter' => $audioDecision['filter'],
            'loudnorm' => $audioDecision['loudnorm'],
            'title' => $this->renderTemplate(
                (string) ($naming['audio'] ?? ''),
                $stream,
                [
                    'codec' => $audioDecision['codec'],
                    'channels' => $audioDecision['channels'],
                    'filter' => $audioDecision['filter'],
                    'loudnorm' => $audioDecision['loudnorm'],
                ]
            ),
            'disposition_default' => (bool) ($stream['disposition']['default'] ?? false),
            'disposition_forced' => (bool) ($stream['disposition']['forced'] ?? false),
        ];
    }

    private function compileLegacySubtitleOutput(array $stream, array $legacy, array $naming, int $index): array
    {
        $pick = $legacy['subtitles']['pick'] ?? [];
        $convert = in_array($stream['language']['short'] ?? '', $pick, true);

        return [
            'source_stream_index' => $stream['stream_index'],
            'action' => $convert ? 'copy' : 'skip',
            'codec' => $convert ? 'copy' : null,
            'title' => $this->renderTemplate((string) ($naming['subtitle'] ?? ''), $stream, [], ['index' => $index]),
            'disposition_default' => (bool) ($stream['disposition']['default'] ?? false),
            'disposition_forced' => (bool) ($stream['disposition']['forced'] ?? false),
        ];
    }

    private function makeLegacyVideoDecision(int $width, array $legacy, string $variant): array
    {
        $videoSizeIndex = match (true) {
            $width > 7500 => '8k',
            $width > 3700 => '4k',
            $width > 2400 => 'quad',
            $width > 1750 => '1080',
            $width > 1100 => '720',
            default => 'sd',
        };

        $videoSettings = $legacy['video'][$videoSizeIndex] ?? [];
        $variantSettings = $videoSettings[$variant] ?? [];
        $nlmeans = $variantSettings['nlmeans'] ?? $videoSettings['nlmeans'] ?? '0';

        return [
            'codec' => $variantSettings['codec'] ?? ($videoSettings['codec'] ?? 'libx265'),
            'mode' => $variantSettings['mode'] ?? ($videoSettings['mode'] ?? 'crf'),
            'mode_value' => $variantSettings['setting'] ?? ($videoSettings['setting'] ?? 23),
            'preset' => $variantSettings['preset'] ?? ($videoSettings['preset'] ?? 'slow'),
            'crop' => $variantSettings['crop'] ?? ($videoSettings['crop'] ?? 'auto'),
            'resize' => $variantSettings['resize'] ?? ($videoSettings['resize'] ?? false),
            'nlmeans' => $nlmeans,
        ];
    }

    private function makeLegacyAudioDecision(array $stream, array $legacy, string $variant): array
    {
        $language = $stream['language']['short'] ?? 'default';
        $channels = (int) ($stream['channels']['count'] ?? 2);
        $languageSettings = $legacy['audio']['choices'][$language] ?? $legacy['audio']['choices']['default'] ?? [];
        $selectedProfile = (string) ($languageSettings[(string) $channels] ?? $languageSettings[$channels] ?? 'stereo');
        $profileSettings = $legacy['audio']['profiles'][$selectedProfile] ?? [];
        $variantSettings = $profileSettings[$variant] ?? [];

        $effectiveVariant = $languageSettings['preset'] ?? $variant;
        $variantSettings = $profileSettings[$effectiveVariant] ?? $variantSettings;

        return [
            'codec' => $variantSettings['codec'] ?? ($profileSettings['codec'] ?? 'libfdk_aac'),
            'profile' => $variantSettings['profile'] ?? ($profileSettings['profile'] ?? 'main'),
            'bitrate' => $variantSettings['bitrate'] ?? ($profileSettings['bitrate'] ?? '192k'),
            'samplerate' => $variantSettings['samplerate'] ?? ($profileSettings['samplerate'] ?? 48000),
            'channels' => $variantSettings['channels'] ?? ($profileSettings['channels'] ?? $channels),
            'filter' => $variantSettings['filter'] ?? ($profileSettings['filter'] ?? null),
            'loudnorm' => $variantSettings['loudnorm']
                ?? ($profileSettings['loudnorm'] ?? ($languageSettings['loudnorm'] ?? '0')),
        ];
    }

    private function renderTemplate(string $template, array $stream, array $output, array $context = []): string
    {
        if ($template === '') {
            return (string) ($stream['title'] ?? '');
        }

        $channels = (string) ($output['channels'] ?? ($stream['channels']['count'] ?? ''));
        $channelLabel = $this->config->static()['audio']['channels'][(string) $channels] ?? (string) $channels;
        $loudnormSuffix = (($output['loudnorm'] ?? '0') !== '0') ? ', Loudnorm' : '';
        $title = trim((string) ($stream['title'] ?? ''));
        $forcedSuffix = !empty($output['disposition_forced']) || !empty($stream['disposition']['forced']) ? '(Forced)' : '';
        $index = (string) ($context['index'] ?? '');
        $languageShort = (string) ($stream['language']['short'] ?? '');
        $languageHuman = (string) ($stream['language']['human'] ?? '');
        $codec = (string) ($stream['codec']['name'] ?? '');
        $codecUpper = (string) ($stream['codec']['name_uc'] ?? '');

        $legacyRendered = preg_replace_callback(
            '/##([A-Z]+)([:=][^#]*)?##/',
            function (array $matches) use ($index, $title, $languageShort, $languageHuman, $channelLabel, $loudnormSuffix, $forcedSuffix, $codec, $codecUpper): string {
                $token = $matches[1] ?? '';
                $argument = (string) ($matches[2] ?? '');

                return match ($token) {
                    'INDEX' => $index,
                    'TITLE' => $title !== '' ? '"' . $title . '"' : '',
                    'LANG' => $this->renderLegacyLanguageToken($argument, $title, $languageShort, $languageHuman),
                    'FORCED' => $this->renderLegacyForcedToken($argument, $forcedSuffix),
                    'CHANNELS' => $channelLabel,
                    'LOUDNORM' => $this->renderLegacyLoudnormToken($argument, $loudnormSuffix),
                    'CODEC' => $codec,
                    'CODECUC' => $codecUpper,
                    default => '',
                };
            },
            $template
        );

        $replacements = [
            '{{title}}' => (string) ($stream['title'] ?? ''),
            '{{title_quoted}}' => $title !== '' ? '"' . $title . '"' : '',
            '{{index}}' => $index,
            '{{lang_short}}' => (string) ($stream['language']['short'] ?? ''),
            '{{lang_human}}' => (string) ($stream['language']['human'] ?? ''),
            '{{codec}}' => (string) ($stream['codec']['name'] ?? ''),
            '{{codec_uc}}' => (string) ($stream['codec']['name_uc'] ?? ''),
            '{{channels}}' => $channels,
            '{{channels_label}}' => $channelLabel,
            '{{loudnorm_suffix}}' => $loudnormSuffix,
            '{{forced_suffix}}' => $forcedSuffix,
        ];

        $rendered = strtr((string) $legacyRendered, $replacements);
        $rendered = preg_replace('/\s{2,}/', ' ', $rendered);

        return trim((string) $rendered);
    }

    private function renderLegacyLanguageToken(string $argument, string $title, string $languageShort, string $languageHuman): string
    {
        if ($argument === '' || $argument[0] !== ':') {
            return '';
        }

        $selector = substr($argument, 1);
        $condition = null;
        if (preg_match('/^([^\[]+)\[(.+)\]$/', $selector, $matches) === 1) {
            $selector = trim((string) $matches[1]);
            $condition = trim((string) $matches[2]);
        }

        if ($condition === 'if-no-title' && $title !== '') {
            return '';
        }

        return match (strtolower($selector)) {
            'human' => $languageHuman,
            'short' => $languageShort,
            default => '',
        };
    }

    private function renderLegacyForcedToken(string $argument, string $forcedSuffix): string
    {
        if ($forcedSuffix === '' || $argument === '' || $argument[0] !== '=') {
            return '';
        }

        return substr($argument, 1);
    }

    private function renderLegacyLoudnormToken(string $argument, string $loudnormSuffix): string
    {
        if ($loudnormSuffix === '' || $argument === '') {
            return '';
        }

        return match (substr($argument, 1)) {
            'comma-true' => $loudnormSuffix,
            default => '',
        };
    }
}
