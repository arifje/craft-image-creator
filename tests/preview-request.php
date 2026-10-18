<?php

declare(strict_types=1);

// Run with an existing Craft bootstrap. Cache, token lookup and user identity
// are process-local fixtures; no providers, queues or database writes are used.
use arifje\craftimagecreator\controllers\CreatorController;
use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\services\GenerationRequests;
use craft\elements\User;
use craft\services\Tokens;
use craft\web\Request;
use yii\caching\ArrayCache;
use yii\web\BadRequestHttpException;
use yii\web\Response;

require $argv[1];
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$loader = require CRAFT_VENDOR_PATH . '/autoload.php';
$loader->addPsr4('arifje\\craftimagecreator\\', dirname(__DIR__) . '/src');

Craft::$app->set('cache', new ArrayCache());
Craft::$app->set('tokens', new class() extends Tokens {
    public function getTokenRoute(string $token): array|false
    {
        return false;
    }
});
Craft::$app->getUser()->setIdentity(new User(['id' => 123]));
$plugin = new Plugin('craft-image-creator', Craft::$app, Plugin::config());
$token = str_repeat('a', 64);
$cache = Craft::$app->getCache();
$path = tempnam(sys_get_temp_dir(), 'image-creator-preview-');
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=');
file_put_contents($path, $bytes);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$makeRequest = static function(array $query): Request {
    $request = new Request([
        'scriptFile' => CRAFT_BASE_PATH . '/web/index.php',
        'scriptUrl' => '/index.php',
        'baseUrl' => '',
        'hostInfo' => 'https://example.test',
        'url' => '/admin/image-creator-ai/api/preview',
        'cookieValidationKey' => 'image-creator-test',
    ]);
    $request->setQueryParams($query);
    return $request;
};
try {
    $cache->set('craft-image-creator:generation:' . hash('sha256', $token), [
        'userId' => 123,
        'status' => GenerationRequests::STATUS_COMPLETE,
        'error' => '',
        'createdAt' => time(),
        'updatedAt' => time(),
    ]);
    $cache->set('craft-image-creator:result:' . hash('sha256', $token), [
        'userId' => 123,
        'path' => $path,
        'mimeType' => 'image/png',
        'extension' => 'png',
        'width' => 1,
        'height' => 1,
        'provider' => 'fixture',
        'ratio' => '1:1',
        'target' => [],
        'createdAt' => time(),
    ]);
    $request = $makeRequest([]);
    $request->setBodyParams(['token' => $token]);
    $controller = new CreatorController('creator', $plugin, [
        'request' => $request,
        'response' => new Response(),
    ]);
    $status = $controller->actionStatus()->data;
    $url = $status['result']['previewUrl'] ?? '';
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    $previewRequest = $makeRequest($query);
    $assert(!$previewRequest->getHasInvalidToken(), 'Generated preview URL collides with Craft token validation.');
    $assert(!array_key_exists('token', $query), 'Preview URLs must not use Craft’s reserved token parameter.');
    $assert($makeRequest(['token' => $token])->getHasInvalidToken(), 'Legacy URL should reproduce the reserved-token failure.');

    $controller->request = $previewRequest;
    $controller->response = new Response();
    // Yii parameter binding must pass the URL value into the real action.
    $action = $controller->createAction('preview');
    $response = $action->runWithParams($query);
    $assert($response->format === Response::FORMAT_RAW, 'Preview must be a raw image response.');
    $assert($response->content === $bytes, 'Preview response differs from the stored image.');
    $assert($response->headers->get('Content-Type') === 'image/png', 'Missing image MIME type.');
    $assert(str_contains($response->headers->get('Cache-Control'), 'no-store'), 'Preview must not be cached.');

    Craft::$app->getUser()->setIdentity(new User(['id' => 456]));
    try {
        $action->runWithParams($query);
        throw new RuntimeException('Another user could read the generated preview.');
    } catch (BadRequestHttpException) {
        // The existing result ownership check must still reject another user.
    }
    echo 'Craft ' . Craft::$app->getVersion() . " preview request regression passed.\n";
} finally {
    unlink($path);
}
