<?php

declare(strict_types=1);

/**
 * Dependency-free model-catalog parsing and cache-key checks.
 *
 * Run with: php tests/model-catalog.php
 */

use arifje\craftimagecreator\services\ModelCatalog;

require_once dirname(__DIR__) . '/src/services/ModelCatalog.php';

$failures = [];
$assert = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$openAiIds = ModelCatalog::parseOpenAiIds([
    'data' => [
        ['id' => 'gpt-image-2'],
        ['id' => 'gpt-6'],
        ['id' => 'gpt-image-1'],
        ['id' => 'gpt-image-1-mini'],
        ['id' => 'gpt-image-3-preview'],
        ['id' => 'gpt-image-2'],
        ['id' => "gpt-image-3\nunsafe"],
    ],
]);
$assert(
    $openAiIds === ['gpt-image-3-preview', 'gpt-image-2'],
    'OpenAI listing must include only GPT Image 2+ models compatible with this plugin.'
);
$assert(ModelCatalog::parseOpenAiIds(['data' => 'invalid']) === [], 'Malformed OpenAI listings must be ignored.');

$xAiIds = ModelCatalog::parseXAiIds([
    'models' => [
        ['id' => 'grok-imagine-image-2.0', 'aliases' => ['grok-imagine-image', 'grok-imagine-image-2.0']],
        ['id' => 'grok-imagine-image-2.1', 'aliases' => [null, ['id' => 'not-an-alias']]],
        ['id' => ''],
    ],
]);
$assert(
    $xAiIds === ['grok-imagine-image-2.1', 'grok-imagine-image-2.0', 'grok-imagine-image'],
    'xAI listing must include model IDs and string aliases, without duplicates.'
);
$assert(ModelCatalog::parseXAiIds(['models' => null]) === [], 'Malformed xAI listings must be ignored.');

$googleIds = ModelCatalog::parseGoogleIds([
    'models' => [
        [
            'name' => 'models/gemini-3.1-flash-image',
            'baseModelId' => 'gemini-3.1-flash-image',
            'supportedGenerationMethods' => ['generateContent'],
        ],
        ['name' => 'models/gemini-3-pro-image-preview'],
        ['name' => 'models/gemini-2.5-flash-image', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-3.1-flash', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/imagen-4.0-generate-001', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-3.1-flash-image-preview', 'supportedGenerationMethods' => ['embedContent']],
        ['name' => 'models/gemini-3-pro-image-preview'],
    ],
]);
$assert(
    $googleIds === ['gemini-3.1-flash-image', 'gemini-3-pro-image-preview'],
    'Gemini listing must include 3+ image variants but exclude legacy, text, Imagen, and non-generation models.'
);
$assert(ModelCatalog::parseGoogleIds(['models' => false]) === [], 'Malformed Gemini listings must be ignored.');

$tooMany = [];
for ($i = 0; $i <= 200; $i++) {
    $tooMany[] = ['id' => 'grok-imagine-image-' . $i];
}
try {
    ModelCatalog::parseXAiIds(['models' => $tooMany]);
    $assert(false, 'Oversized model listings must fail.');
} catch (RuntimeException) {
    // Expected: a bad provider response must not be cached.
}

$cacheKeyMethod = new ReflectionMethod(ModelCatalog::class, 'cacheKey');
$first = $cacheKeyMethod->invoke(null, 'openai', 'secret-one');
$second = $cacheKeyMethod->invoke(null, 'openai', 'secret-two');
$otherProvider = $cacheKeyMethod->invoke(null, 'xai', 'secret-one');
$assert(
    is_string($first) && $first !== $second && $first !== $otherProvider && !str_contains($first, 'secret-one'),
    'Catalog cache keys must be isolated by provider and credential without exposing the credential.'
);
$assert(ModelCatalog::CACHE_DURATION === 900, 'Model listings must be cached for 15 minutes.');

$source = file_get_contents(dirname(__DIR__) . '/src/services/ModelCatalog.php');
$assert(
    is_string($source) &&
    str_contains($source, 'MAX_GOOGLE_PAGES = 5') &&
    str_contains($source, 'MAX_RESPONSE_BYTES = 1_048_576') &&
    str_contains($source, 'isset($seenTokens[$next])'),
    'Gemini pagination must be bounded and reject repeated page tokens.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Model catalog checks passed.\n");
