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
    $assert(($composerJson['version'] ?? null) === '1.0.1', 'Composer package version must be 1.0.1.');
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
        'openAiApiKey',
        'xAiApiKey',
        'googleApiKey',
    ] as $fieldName
) {
    $assert(
        in_array($fieldName, $autosuggestFields, true),
        "The {$fieldName} setting must remain environment-aware."
    );
}

$assert(
    preg_match("/forms\\.textareaField\\(\\{[^}]*name:\\s*'prompt'/s", $settingsTemplate) === 1,
    'The prompt setting must use a textarea field.'
);

foreach (['openAiModel', 'xAiModel', 'googleModel'] as $fieldName) {
    $assert(
        preg_match("/forms\\.selectField\\(\\{[^}]*name:\\s*'{$fieldName}'/s", $settingsTemplate) === 1,
        "The {$fieldName} setting must use a model dropdown."
    );
}

$settingsSource = $read($root . '/src/models/Settings.php');
foreach (['gpt-image-2', 'grok-imagine-image-2.0', 'gemini-3.1-flash-image'] as $model) {
    $assert(str_contains($settingsSource, "'{$model}'"), "The {$model} default must remain a model option.");
}
$assert(
    str_contains($settingsSource, 'Current/custom'),
    'Existing custom or environment-based model settings must remain selectable.'
);

$controllerSource = $read($root . '/src/controllers/CreatorController.php');
$jobSource = $read($root . '/src/jobs/GenerateImage.php');
$requestSource = $read($root . '/src/services/GenerationRequests.php');
$pluginSource = $read($root . '/src/Plugin.php');
$assetCreatorSource = $read($root . '/src/services/AssetCreator.php');
$modalSource = $read($root . '/resources/js/modal.js');
$mainSource = $read($root . '/resources/js/main.js');
$cssSource = $read($root . '/resources/css/image-creator.css');
$distJs = $read($root . '/src/web/assets/dist/image-creator.js');
$distCss = $read($root . '/src/web/assets/dist/image-creator.css');

$assert(str_contains($jobSource, 'extends BaseJob'), 'Image generation must run as a Craft queue job.');
$assert(str_contains($controllerSource, 'Queue::push('), 'Generation requests must be pushed to Craft’s queue.');
$assert(
    str_contains($controllerSource, 'actionStatus()') && str_contains($pluginSource, "api/status"),
    'Queued generation must expose a polling endpoint.'
);
$assert(
    str_contains($requestSource, "'userId' => \$userId") && str_contains($requestSource, 'getMutex()'),
    'Generation state must remain user-bound and race-safe.'
);

$assert(str_contains($modalSource, 'resizable: true'), 'The image creator modal must remain resizable.');
$assert(
    str_contains($cssSource, 'craft-image-creator-modal__footer') && str_contains($cssSource, 'flex-wrap: wrap'),
    'Modal actions must wrap instead of being clipped.'
);

$assert(
    str_contains($pluginSource, 'EVENT_REGISTER_CP_NAV_ITEMS') &&
    str_contains($pluginSource, "\$event->rules['image-creator-ai']"),
    'The standalone Image Creator must be registered in the CP navigation.'
);
$assert(
    is_file($root . '/src/templates/_creator.twig') && str_contains($mainSource, 'installStandaloneCreator'),
    'The standalone Image Creator page and client initializer must exist.'
);
$assert(
    str_contains($distJs, 'craft-image-creator-standalone') &&
    str_contains($distJs, 'Waiting for image generation') &&
    str_contains($distCss, 'craft-image-creator-standalone'),
    'The compiled control-panel assets must include the queue and standalone workflows.'
);
$assert(
    str_contains($assetCreatorSource, "'type' => 'folder'") &&
    str_contains($assetCreatorSource, "'folderUid'") &&
    str_contains($assetCreatorSource, "'volumeUid'") &&
    str_contains($assetCreatorSource, "viewAssets:") &&
    str_contains($assetCreatorSource, "saveAssets:"),
    'Standalone folder targets must be canonicalized and permission-checked.'
);

if ($failures !== []) {
    fwrite(STDERR, "Static contract checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Static contract checks passed.\n");
