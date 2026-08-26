<?php

declare(strict_types=1);

/**
 * Dependency-free release contract checks.
 *
 * Run with: php tests/static-contract.php
 */

$root = dirname(__DIR__);
$failures = [];

$assert = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function(string $path) use (&$failures): string {
    $contents = file_get_contents($path);
    if ($contents === false) {
        $failures[] = sprintf('Could not read %s.', $path);
        return '';
    }

    return $contents;
};

$composerJson = json_decode($read($root . '/composer.json'), true);
$assert(is_array($composerJson), 'composer.json must contain valid JSON.');
if (is_array($composerJson)) {
    $assert(($composerJson['version'] ?? null) === '1.0.0', 'Composer package version must be 1.0.0.');
    $assert(
        ($composerJson['extra']['handle'] ?? null) === 'craft-image-creator',
        'The Craft plugin handle must remain craft-image-creator.'
    );

    $craftConstraint = (string)($composerJson['require']['craftcms/cms'] ?? '');
    $assert(str_contains($craftConstraint, '^4.4.0'), 'Craft 4.4 must remain supported.');
    $assert(str_contains($craftConstraint, '^5.0.0'), 'Craft 5 must remain supported.');
}

$generatorSource = $read($root . '/src/services/ImageGenerator.php');
foreach (['16:9', '9:16', '4:5', '1:1'] as $ratio) {
    $assert(str_contains($generatorSource, "'{$ratio}'"), "The {$ratio} ratio must remain available.");
}

$settingsTemplate = $read($root . '/src/templates/_settings.twig');
preg_match_all(
    "/forms\\.autosuggestField\\(\\{.*?name:\\s*'([^']+)'/s",
    $settingsTemplate,
    $autosuggestMatches
);
$autosuggestFields = $autosuggestMatches[1] ?? [];
foreach (
    [
        'prompt',
        'openAiApiKey',
        'openAiModel',
        'xAiApiKey',
        'xAiModel',
        'googleApiKey',
        'googleModel',
    ] as $fieldName
) {
    $assert(
        in_array($fieldName, $autosuggestFields, true),
        "The {$fieldName} setting must remain environment-aware."
    );
}

if ($failures !== []) {
    fwrite(STDERR, "Static contract checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Static contract checks passed.\n");
