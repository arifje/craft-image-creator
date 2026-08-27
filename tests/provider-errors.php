<?php

declare(strict_types=1);

/**
 * Dependency-free provider error and redaction checks.
 *
 * Run with: php tests/provider-errors.php
 */

use arifje\craftimagecreator\services\providers\ProviderException;

require_once dirname(__DIR__) . '/src/services/providers/ProviderException.php';

$failures = [];

$assert = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$xAiKey = 'xai-this-is-a-secret-api-key';
$xAiError = ProviderException::fromHttpResponse(
    'Grok',
    400,
    [
        'code' => 'invalid-argument',
        'error' => "Incorrect API key provided: {$xAiKey}\nCheck the account.",
    ],
    'req_xai-123',
    ['Bearer ' . $xAiKey, $xAiKey]
)->getPublicMessage();
$assert(
    str_contains($xAiError, 'Grok rejected the image request (HTTP 400, invalid-argument)'),
    'Scalar xAI error envelopes must include their safe HTTP status and error code.'
);
$assert(
    str_contains($xAiError, 'Incorrect API key provided: [redacted] Check the account.'),
    'Scalar xAI error details must be normalized and retained.'
);
$assert(!str_contains($xAiError, $xAiKey), 'The configured xAI API key must be redacted.');
$assert(str_contains($xAiError, 'Request ID: req_xai-123.'), 'Safe request IDs must be retained.');

$nestedError = ProviderException::fromHttpResponse(
    'OpenAI',
    429,
    [
        'error' => [
            'message' => 'Quota exceeded.',
            'code' => 'insufficient_quota',
        ],
    ]
)->getPublicMessage();
$assert(
    str_contains($nestedError, 'OpenAI rate limit or quota was reached (HTTP 429, insufficient_quota)'),
    'Nested provider errors must retain an actionable category.'
);
$assert(
    !str_contains($nestedError, 'Quota exceeded.'),
    'Quota responses must use a curated summary instead of provider account details.'
);

$validationError = ProviderException::fromHttpResponse(
    'Google Gemini',
    422,
    ['detail' => [['loc' => ['body', 'prompt'], 'msg' => "Prompt\tis too long."]]]
)->getPublicMessage();
$assert(
    str_contains($validationError, 'Prompt is too long.'),
    'Structured validation messages must be extracted and normalized.'
);

$htmlError = ProviderException::fromHttpResponse(
    'Grok',
    502,
    '<html>upstream internals</html>'
)->getPublicMessage();
$assert(
    $htmlError === 'Grok is temporarily unavailable (HTTP 502).',
    'Non-JSON and server-error bodies must never be exposed.'
);

$patternError = ProviderException::fromHttpResponse(
    'OpenAI',
    400,
    [
        'error' => implode(' ', [
            'Bearer abcdefghijklmnop',
            'sk-abcdefghijklmnopqrstuvwxyz',
            'AIzaabcdefghijklmnopqrstuvwxyz123456789',
            'https://example.test/?token=secret-token-value',
        ]),
    ]
)->getPublicMessage();
foreach (
    [
        'abcdefghijklmnop',
        'sk-abcdefghijklmnopqrstuvwxyz',
        'AIzaabcdefghijklmnopqrstuvwxyz123456789',
        'secret-token-value',
    ] as $secret
) {
    $assert(!str_contains($patternError, $secret), "The credential pattern {$secret} must be redacted.");
}

$splitKey = 'sk-abcdefghijklmnop';
$splitKeyError = ProviderException::fromHttpResponse(
    'OpenAI',
    400,
    [
        'error' => 'Keys: sk-abcd<b></b>efghijklmnop and '
            . 'sk-abcd&lt;span&gt;&lt;/span&gt;efghijklmnop.',
    ],
    '',
    [$splitKey]
)->getPublicMessage();
$assert(
    !str_contains($splitKeyError, $splitKey),
    'Markup and entities must be normalized before credential redaction.'
);

$associativeDetailError = ProviderException::fromHttpResponse(
    'Grok',
    422,
    ['detail' => ['prompt' => 'CONFIDENTIAL BASE PROMPT']]
)->getPublicMessage();
$assert(
    !str_contains($associativeDetailError, 'CONFIDENTIAL BASE PROMPT'),
    'Arbitrary associative response values must not be treated as provider messages.'
);

$invalidRequestIdError = ProviderException::fromHttpResponse(
    'Grok',
    400,
    ['error' => 'Invalid request.'],
    "request-id\nwith-control"
)->getPublicMessage();
$assert(
    !str_contains($invalidRequestIdError, 'Request ID:'),
    'Invalid request IDs must not be exposed.'
);

$secretRequestIdError = ProviderException::fromHttpResponse(
    'Grok',
    400,
    ['error' => 'Invalid request.'],
    $xAiKey,
    [$xAiKey]
)->getPublicMessage();
$assert(
    !str_contains($secretRequestIdError, $xAiKey) &&
    !str_contains($secretRequestIdError, 'Request ID:'),
    'Request IDs that contain credentials must be redacted and discarded.'
);
$patternRequestIdError = ProviderException::fromHttpResponse(
    'Grok',
    400,
    ['error' => 'Invalid request.'],
    'xai-another-secret-token'
)->getPublicMessage();
$jwtRequestIdError = ProviderException::fromHttpResponse(
    'Grok',
    400,
    ['error' => 'Invalid request.'],
    'eyJabcdefgh.ijklmnop.qrstuvwx'
)->getPublicMessage();
$assert(
    !str_contains($patternRequestIdError, 'Request ID:') &&
    !str_contains($jwtRequestIdError, 'Request ID:'),
    'Token-pattern request IDs must be redacted and discarded without an exact secret match.'
);

$refusalError = ProviderException::fromResult(
    'Grok',
    ['refusal' => "Safety policy blocked Bearer {$xAiKey}."],
    [$xAiKey]
)->getPublicMessage();
$assert(
    str_contains($refusalError, 'Safety policy blocked Bearer [redacted].') &&
    !str_contains($refusalError, $xAiKey),
    'Explicit successful-response refusals must remain useful without exposing credentials.'
);
$genericResultError = ProviderException::fromResult(
    'Grok',
    ['detail' => ['prompt' => 'CONFIDENTIAL BASE PROMPT']]
)->getPublicMessage();
$assert(
    !str_contains($genericResultError, 'CONFIDENTIAL BASE PROMPT') &&
    str_contains($genericResultError, 'may have refused the prompt'),
    'Unexpected successful responses must use safe refusal guidance.'
);

$longError = ProviderException::fromHttpResponse(
    'Grok',
    400,
    ['error' => str_repeat('x', 1_000)],
    'req_long-error'
)->getPublicMessage();
$assert(
    mb_strlen($longError) <= 480,
    'Public provider errors must fit within the queued generation error limit.'
);
$assert(
    str_contains($longError, 'Request ID: req_long-error.'),
    'Request IDs must be preserved even when provider details require truncation.'
);

$assert(
    ProviderException::fromTransport('Grok', true)->getPublicMessage() ===
        'The request to Grok timed out. Try again.',
    'Timeouts must have deterministic retry guidance.'
);
$assert(
    ProviderException::fromTransport('Grok')->getPublicMessage() ===
        'Could not connect to Grok. Check outbound HTTPS access and try again.',
    'Connection failures must have deterministic network guidance.'
);

if ($failures !== []) {
    fwrite(STDERR, "Provider error checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Provider error checks passed.\n");
