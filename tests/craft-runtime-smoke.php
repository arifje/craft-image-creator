<?php

declare(strict_types=1);

// Opt-in native regression. Refuses every site except the private audit copy.
use arifje\craftimagecreator\jobs\GenerateImage;
use arifje\craftimagecreator\models\Prompt;
use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\services\GenerationRequests;
use arifje\craftimagecreator\services\providers\GeneratedImage;
use arifje\craftimagecreator\services\providers\ProviderException;
use craft\elements\Entry;
use yii\web\ForbiddenHttpException;

$root = '/tmp/image-creator-audit-20261020';
if (getenv('IC_AUDIT_SITE') !== $root . '/site') {
    throw new RuntimeException('Set IC_AUDIT_SITE to the isolated image creator audit site.');
}
require $root . '/site/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
if ($app->getDb()->createCommand('SELECT DATABASE()')->queryScalar() !== 'image_creator_audit_20261020') {
    throw new RuntimeException('Wrong audit database.');
}
$read = static function(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Cannot read audit fixture: {$path}");
    }
    return $contents;
};
/** @return list<string> */
$readRequests = static function() use ($root): array {
    $lines = file($root . '/provider-requests.jsonl', FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot read the audit provider request log.');
    }
    return $lines;
};
$fixture = json_decode($read($root . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$plugin = Plugin::getInstance();
$checks = 0;
$assert = static function(bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS: {$message}\n";
};
$reject = static function(callable $callback, string $message, string $class = RuntimeException::class) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        $assert($exception instanceof $class, $message . ' (' . get_class($exception) . ')');
        return;
    }
    $assert(false, $message);
};
$writer = $app->getUsers()->getUserById($fixture['users']['writer']);
$app->getUser()->setIdentity($writer);
$target = ['type' => 'standalone'];
$binding = $plugin->assetCreator->resolveTarget($target)->binding();
$fieldTarget = ['type' => 'field', 'fieldUid' => $fixture['fieldUid'], 'elementId' => $fixture['entryId'], 'siteId' => $fixture['siteId'], 'elementType' => Entry::class];
$fieldBinding = $plugin->assetCreator->resolveTarget($fieldTarget)->binding();
$assert($fieldBinding['volumeUid'] === $fixture['volumeUid'], 'Craft 5 field override resolves its real upload volume');
foreach (['reader', 'novolume'] as $role) {
    $user = $app->getUsers()->getUserById($fixture['users'][$role]);
    $reject(fn() => $plugin->assetCreator->resolveTarget($target, $user), "{$role} cannot resolve writable standalone storage", ForbiddenHttpException::class);
}
$bytes = $read($root . '/image.jpg');
foreach (['openai', 'xai', 'google'] as $provider) {
    $GLOBALS['icAuditMode'] = 'success';
    $image = $plugin->imageGenerator->generateFromPrompt($provider, '4:5', 'Audit provider request');
    $result = $plugin->generatedResults->store($image, $writer->id, $provider, '4:5', $binding);
    $assert($result['width'] === 160 && $result['height'] === 200, "{$provider} image is decoded and cropped to 4:5");
    $reject(fn() => $plugin->generatedResults->get($result['token'], $fixture['users']['reader']), "{$provider} preview is owner-bound");
    $reject(fn() => $plugin->generatedResults->get($result['token'], $writer->id, $fieldBinding), "{$provider} result is destination-bound");
    $asset = $plugin->assetCreator->save($result['token'], $writer->id, $target, 'audit-' . $provider . '.jpg');
    $assert($asset->volumeId === $fixture['volumeId'] && $asset->getWidth() === 160, "{$provider} creates a native private Asset");
    $reject(fn() => $plugin->assetCreator->save($result['token'], $writer->id, $target, 'duplicate.jpg'), "{$provider} duplicate save is rejected");
    foreach (['http', 'bad-json', 'base64', 'refusal', 'timeout', 'shape'] as $mode) {
        $GLOBALS['icAuditMode'] = $mode;
        try {
            $plugin->imageGenerator->generateFromPrompt($provider, '1:1', 'Audit failure request');
            $assert(false, "{$provider} {$mode} response rejected");
        } catch (Throwable $exception) {
            $assert($exception instanceof ProviderException, "{$provider} {$mode} is a controlled provider error (" . get_class($exception) . ')');
            $assert(!str_contains($exception->getMessage(), 'audit-mock-key') && $exception->getPrevious() === null, "{$provider} {$mode} hides credentials and transport chain");
        }
    }
}
$GLOBALS['icAuditMode'] = 'success';
$field = $app->getFields()->getFieldByUid($fixture['fieldUid']);
$field->allowUploads = false;
try {
    $reject(fn() => $plugin->assetCreator->resolveTarget($fieldTarget), 'Native field upload restriction is enforced');
} finally {
    $field->allowUploads = true;
}
$allowedKinds = $field->allowedKinds;
$field->allowedKinds = ['video'];
try {
    $reject(fn() => $plugin->assetCreator->resolveTarget($fieldTarget), 'Native field rejects an image when only video is allowed');
} finally {
    $field->allowedKinds = $allowedKinds;
}
$GLOBALS['icAuditMode'] = 'content-shape';
$reject(fn() => $plugin->imageGenerator->generateFromPrompt('google', '1:1', 'Malformed content'), 'Malformed Google content is a controlled provider error', ProviderException::class);
$GLOBALS['icAuditMode'] = 'success';
$retryResult = $plugin->generatedResults->store(new GeneratedImage($bytes, 'image/jpeg'), $writer->id, 'openai', '1:1', $binding);
$reject(fn() => $plugin->assetCreator->save($retryResult['token'], $writer->id, $target, str_repeat('x', 256)), 'Rejected native filename does not consume preview');
$assert(is_file($plugin->generatedResults->get($retryResult['token'], $writer->id)['path']), 'Preview survives rejected native save');
$retryAsset = $plugin->assetCreator->save($retryResult['token'], $writer->id, $target, 'retry-after-error.jpg');
$assert($retryAsset->id !== null, 'The same preview can be saved after correcting filename');
$expiredToken = $plugin->generationRequests->create($writer->id);
$requestKey = 'craft-image-creator:generation:' . hash('sha256', $expiredToken);
$expiredRequest = $app->getCache()->get($requestKey);
$expiredRequest['createdAt'] = time() - 3_601;
$app->getCache()->set($requestKey, $expiredRequest, 60);
$reject(fn() => $plugin->generationRequests->get($expiredToken, $writer->id), 'Expired request is rejected even if cache still contains it');
$app->getCache()->delete($requestKey);
$reject(fn() => $plugin->generatedResults->store(new GeneratedImage('<html>not an image</html>', 'image/jpeg'), $writer->id, 'openai', '1:1', $binding), 'Non-image bytes rejected despite claimed MIME');
$reject(fn() => $plugin->generatedResults->store(new GeneratedImage(str_repeat('a', 25_000_001), 'image/jpeg'), $writer->id, 'openai', '1:1', $binding), 'Oversized image bytes rejected');
$run = static function(string $mode, bool $cancelBefore = false, bool $runningBefore = false) use ($plugin, $writer, $binding, $app): array {
    $token = $plugin->generationRequests->create($writer->id);
    $GLOBALS['icAuditToken'] = $token;
    $GLOBALS['icAuditUser'] = $writer->id;
    $GLOBALS['icAuditMode'] = $mode;
    if ($cancelBefore) {
        $plugin->generationRequests->cancel($token, $writer->id);
    }
    if ($runningBefore) {
        $plugin->generationRequests->markRunning($token, $writer->id);
    }
    $job = new GenerateImage(['token' => $token, 'userId' => $writer->id, 'provider' => 'openai', 'ratio' => '1:1', 'prompt' => 'Captured prompt snapshot', 'target' => $binding]);
    $id = $app->getQueue()->push($job);
    $app->getQueue()->executeJob((string)$id);
    return [$token, $plugin->generationRequests->get($token, $writer->id)];
};
[$token, $state] = $run('success');
$assert($state['status'] === GenerationRequests::STATUS_COMPLETE, 'Native queued job completes and publishes preview');
$plugin->generatedResults->discardIfExists($token, $writer->id);
$originalPrompt = $plugin->prompts->getPrompt();
try {
    $plugin->prompts->save(new Prompt(['prompt' => 'Changed after queue submission']));
    [$token, $state] = $run('success');
    $requests = $readRequests();
    $lastLine = end($requests);
    if ($lastLine === false) {
        throw new RuntimeException('No audit provider request was recorded.');
    }
    $lastRequest = json_decode($lastLine, true, 512, JSON_THROW_ON_ERROR);
    $assert($lastRequest['payload']['prompt'] === 'Captured prompt snapshot', 'Queued prompt snapshot survives later Utility changes');
    $plugin->generatedResults->discardIfExists($token, $writer->id);
} finally {
    $plugin->prompts->save(new Prompt(['prompt' => $originalPrompt]));
}
$calls = count($readRequests());
[$token, $state] = $run('success', true);
$assert($state['status'] === GenerationRequests::STATUS_CANCELLED, 'Cancelled queued job remains cancelled');
$assert(count($readRequests()) === $calls, 'Cancelled queued job never calls provider');
[$token, $state] = $run('cancel');
$assert($state['status'] === GenerationRequests::STATUS_CANCELLED, 'Cancellation during provider work wins over late response');
$reject(fn() => $plugin->generatedResults->get($token, $writer->id), 'Late cancelled response is discarded');
[$token, $state] = $run('success', false, true);
$assert($state['status'] === GenerationRequests::STATUS_FAILED, 'Redelivered running job fails without repeating provider work');
$calls = count($readRequests());
try {
    $permissions = array_values(array_diff($fixture['permissions']['writer'], [Plugin::PERMISSION_USE]));
    $app->getUserPermissions()->saveUserPermissions($writer->id, $permissions);
    [$token, $state] = $run('success');
    $assert($state['status'] === GenerationRequests::STATUS_FAILED && str_contains($state['error'], 'permission'), 'Worker rechecks revoked plugin permission');
    $assert(count($readRequests()) === $calls, 'Permission-revoked job never calls provider');
} finally {
    $app->getUserPermissions()->saveUserPermissions($writer->id, $fixture['permissions']['writer']);
}
[$token, $state] = $run('http');
$assert($state['status'] === GenerationRequests::STATUS_FAILED && str_contains($state['error'], 'HTTP 422') && !str_contains($state['error'], 'audit-mock-key'), 'Queue stores controlled redacted provider failure');
[$token, $state] = $run('not-image');
$assert($state['status'] === GenerationRequests::STATUS_FAILED, 'Invalid provider image fails native job safely');
$entry = Entry::find()->id($fixture['entryId'])->status(null)->one();
$assert($entry instanceof Entry && !$entry->enabled, 'Audit entry remains unpublished');
$assert((new ReflectionClass(Plugin::class))->getFileName() === '/workspace/Codex/craft-image-creator/src/Plugin.php', 'Native runtime loaded the Codex plugin source');
echo "Native runtime: {$checks} checks passed; Craft " . $app->getVersion() . ".\n";
