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
    $assert(($composerJson['version'] ?? null) === '1.0.4', 'Composer package version must be 1.0.4.');
    $assert(
        ($composerJson['extra']['handle'] ?? null) === 'craft-image-creator',
        'The Craft plugin handle must remain craft-image-creator.'
    );

    $craftConstraint = (string)($composerJson['require']['craftcms/cms'] ?? '');
    $assert(str_contains($craftConstraint, '^4.4.0'), 'Craft 4.4 must remain supported.');
    $assert(str_contains($craftConstraint, '^5.0.0'), 'Craft 5 must remain supported.');
}

$packageJson = json_decode($read($root . '/package.json'), true);
$packageLock = json_decode($read($root . '/package-lock.json'), true);
$assert(
    is_array($packageJson) && ($packageJson['version'] ?? null) === '1.0.4',
    'JavaScript package version must be 1.0.4.'
);
$assert(
    is_array($packageLock) &&
    ($packageLock['version'] ?? null) === '1.0.4' &&
    ($packageLock['packages']['']['version'] ?? null) === '1.0.4',
    'JavaScript lockfile versions must be 1.0.4.'
);

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
    preg_match("/forms\\.textareaField\\(\\{[^}]*name:\\s*['\"]prompt['\"]/s", $settingsTemplate) === 0,
    'The runtime image prompt must not remain in project-config-backed plugin settings.'
);
$assert(
    preg_match("/forms\\.selectField\\(\\{[^}]*name:\\s*'standaloneVolumeUid'/s", $settingsTemplate) === 1,
    'Standalone storage must be selected in plugin settings.'
);

foreach (['openAiModel', 'xAiModel', 'googleModel'] as $fieldName) {
    $assert(
        preg_match("/forms\\.selectField\\(\\{[^}]*name:\\s*'{$fieldName}'/s", $settingsTemplate) === 1,
        "The {$fieldName} setting must use a model dropdown."
    );
}

$settingsSource = $read($root . '/src/models/Settings.php');
$assert(
    str_contains($settingsSource, 'public string $standaloneVolumeUid') &&
    str_contains($settingsSource, 'validateStandaloneVolume') &&
    str_contains($settingsSource, 'MissingComponentInterface'),
    'Standalone storage must be persisted and validated as a volume UID.'
);
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
$abstractProviderSource = $read($root . '/src/services/providers/AbstractProvider.php');
$providerExceptionSource = $read($root . '/src/services/providers/ProviderException.php');
$xAiProviderSource = $read($root . '/src/services/providers/XAiProvider.php');
$pluginSource = $read($root . '/src/Plugin.php');
$promptControllerSource = $read($root . '/src/controllers/PromptController.php');
$promptsSource = $read($root . '/src/services/Prompts.php');
$promptUtilitySource = $read($root . '/src/utilities/ImagePrompt.php');
$promptUtilityTemplate = $read($root . '/src/templates/_utilities/image-prompt.twig');
$tableSource = $read($root . '/src/db/Table.php');
$installMigrationSource = $read($root . '/src/migrations/Install.php');
$promptMigrationFiles = glob($root . '/src/migrations/m*_create_prompt*.php') ?: [];
$promptMigrationSource = '';
foreach ($promptMigrationFiles as $promptMigrationFile) {
    $promptMigrationSource .= "\n" . $read($promptMigrationFile);
}
$assetCreatorSource = $read($root . '/src/services/AssetCreator.php');
$modalSource = $read($root . '/resources/js/modal.js');
$standaloneSource = $read($root . '/resources/js/standalone.js');
$creatorTemplate = $read($root . '/src/templates/_creator.twig');
$contextFieldsSource = $read($root . '/src/services/ContextFields.php');
$mainSource = $read($root . '/resources/js/main.js');
$cssSource = $read($root . '/resources/css/image-creator.css');
$distJs = $read($root . '/src/web/assets/dist/image-creator.js');
$distCss = $read($root . '/src/web/assets/dist/image-creator.css');

$assert(
    str_contains($pluginSource, "public string \$schemaVersion = '1.0.1'"),
    'The plugin schema version must be 1.0.1 for the prompt-storage migration.'
);
$assert(
    str_contains($pluginSource, 'EVENT_REGISTER_UTILITIES') &&
    str_contains($pluginSource, 'EVENT_REGISTER_UTILITY_TYPES') &&
    str_contains($pluginSource, 'ImagePrompt::class'),
    'The prompt utility must be registered with compatible Craft 4 and Craft 5 utility events.'
);
$assert(
    str_contains($promptUtilitySource, "return 'image-creator-prompt'") &&
    preg_match("/forms\\.textareaField\\(\\{[^}]*name:\\s*['\"]prompt['\"]/s", $promptUtilityTemplate) === 1 &&
    str_contains($promptUtilityTemplate, "actionInput('craft-image-creator/prompt/save')") &&
    str_contains($promptUtilityTemplate, 'csrfInput()'),
    'The Image Creator Prompt utility must submit a CSRF-protected textarea to its save action.'
);
$assert(
    str_contains($promptControllerSource, 'requireCpRequest()') &&
    str_contains($promptControllerSource, 'requirePostRequest()') &&
    str_contains($promptControllerSource, 'checkAuthorization(ImagePrompt::class)'),
    'The prompt save action must require an authorized control-panel POST request.'
);
$assert(
    str_contains($pluginSource, "'prompts' => Prompts::class") &&
    str_contains($promptsSource, 'function getPrompt(') &&
    str_contains($promptsSource, 'function save(') &&
    str_contains($tableSource, 'craftimagecreator_prompt') &&
    str_contains($installMigrationSource, 'createTable(') &&
    str_contains($installMigrationSource, 'prompt') &&
    count($promptMigrationFiles) === 1 &&
    str_contains($promptMigrationSource, 'createTable(') &&
    str_contains($promptMigrationSource, 'getConfigFromFile(') &&
    str_contains($promptMigrationSource, 'App::parseEnv(') &&
    !str_contains($promptMigrationSource, 'extends Install') &&
    !str_contains($promptMigrationSource, 'Plugin::getInstance()'),
    'The mutable prompt must be stored by the Prompts service and created for fresh installs and upgrades.'
);
$assert(
    str_contains($generatorSource, '->prompts->getPrompt()') &&
    !str_contains($generatorSource, 'getSettings()->getResolvedPrompt()'),
    'New image generation requests must read the database-backed runtime prompt service.'
);

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
$assert(
    str_contains($providerExceptionSource, 'fromHttpResponse(') &&
    str_contains($providerExceptionSource, 'fromResult(') &&
    str_contains($providerExceptionSource, 'fromTransport(') &&
    str_contains($providerExceptionSource, '[redacted]') &&
    str_contains($providerExceptionSource, 'MAX_PUBLIC_MESSAGE_LENGTH'),
    'Provider errors must be categorized, length-limited, and redacted before browser exposure.'
);
$assert(
    str_contains($abstractProviderSource, 'ProviderException::fromHttpResponse(') &&
    str_contains($abstractProviderSource, 'MAX_ERROR_RESPONSE_LENGTH') &&
    str_contains($abstractProviderSource, '->read(self::MAX_ERROR_RESPONSE_LENGTH + 1)') &&
    str_contains($abstractProviderSource, '$this->secrets($headers)') &&
    !str_contains($abstractProviderSource, '$exception->getMessage()'),
    'Provider HTTP failures must use the sanitized boundary instead of raw transport messages.'
);
$assert(
    str_contains($jobSource, '$exception instanceof ProviderException') &&
    str_contains($jobSource, '$exception->getPublicMessage()') &&
    str_contains($jobSource, "'The image generation failed. Try again.'"),
    'Queued jobs must expose only typed provider failures and keep unexpected errors generic.'
);
$assert(
    !str_contains($xAiProviderSource, "'quality' =>") &&
    str_contains($xAiProviderSource, "'response_format' => 'b64_json'"),
    'Grok requests must use the strict REST payload while retaining base64 output.'
);
$assert(
    is_file($root . '/tests/provider-errors.php'),
    'Provider error parsing and credential redaction must have focused regression checks.'
);
$assert(
    str_contains($controllerSource, "\$payload['generation']['error'] = (string)\$generation['error']") &&
    str_contains($modalSource, 'throw new Error(state.error ||') &&
    str_contains($modalSource, 'this.setError(requestErrorMessage(') &&
    str_contains($modalSource, 'this.error.textContent = message'),
    'Safe queued provider errors must reach the modal through a text-only rendering sink.'
);

$assert(str_contains($modalSource, 'resizable: true'), 'The image creator modal must remain resizable.');
$assert(
    str_contains($modalSource, 'craft-image-creator-modal__layout') &&
    str_contains($modalSource, 'craft-image-creator-modal__actions') &&
    str_contains($cssSource, 'craft-image-creator-modal__layout') &&
    str_contains($cssSource, 'block-size: 100%') &&
    str_contains($cssSource, 'overflow-y: auto'),
    'The modal footer must stay fixed while its middle content pane scrolls.'
);
$assert(
    str_contains($modalSource, "item.inputType === 'text' ? 'input' : 'textarea'") &&
    str_contains($contextFieldsSource, 'instanceof Categories') &&
    str_contains($contextFieldsSource, "['category', 'categories']") &&
    str_contains($modalSource, "type: 'button'") &&
    str_contains($modalSource, "this.generateButton.addEventListener('click'"),
    'Caption and Category context values must use single-line inputs.'
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
    !str_contains($creatorTemplate, 'craft-image-creator-folder') &&
    !str_contains($creatorTemplate, 'folderOptions') &&
    str_contains($standaloneSource, 'standaloneTarget()'),
    'Standalone destination selection must be resolved from plugin settings, not the creation page.'
);
$assert(
    str_contains($distJs, 'craft-image-creator-standalone') &&
    str_contains($distJs, 'Waiting for image generation') &&
    str_contains($distCss, 'craft-image-creator-standalone'),
    'The compiled control-panel assets must include the queue and standalone workflows.'
);
$assert(
    str_contains($assetCreatorSource, "'type' => 'standalone'") &&
    str_contains($assetCreatorSource, "'folderUid'") &&
    str_contains($assetCreatorSource, "'volumeUid'") &&
    str_contains($assetCreatorSource, "viewAssets:") &&
    str_contains($assetCreatorSource, "saveAssets:") &&
    str_contains($assetCreatorSource, 'MissingComponentInterface') &&
    !str_contains($assetCreatorSource, 'resolveFolderTarget'),
    'Configured standalone targets must be canonicalized and permission-checked.'
);

if ($failures !== []) {
    fwrite(STDERR, "Static contract checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Static contract checks passed.\n");
