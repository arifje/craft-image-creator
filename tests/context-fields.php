<?php

declare(strict_types=1);

// In-memory Craft input-event regression; no database writes or provider calls.
use arifje\craftimagecreator\Plugin;
use craft\base\Field;
use craft\elements\Entry;
use craft\events\DefineFieldHtmlEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\web\Request;

require $argv[1];
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$loader = require CRAFT_VENDOR_PATH . '/autoload.php';
$loader->addPsr4('arifje\\craftimagecreator\\', dirname(__DIR__) . '/src');

Craft::$app->set('request', new class() extends Request {
    public function getIsCpRequest(): bool
    {
        return true;
    }
});
Craft::$app->set('user', new class() extends craft\console\User {
    public function checkPermission(string $permissionName): bool
    {
        return true;
    }
});
$plugin = new Plugin('craft-image-creator', Craft::$app, Plugin::config());
$plugin->getSettings()->contextFields = ['field:summary-uid'];
$field = new PlainText(['name' => 'Summary', 'handle' => 'summary', 'uid' => 'summary-uid']);
$layoutField = new CustomField($field, ['handle' => 'imageCaption']);
$event = new DefineFieldHtmlEvent(['inline' => false, 'element' => new Entry(), 'html' => '<textarea></textarea>']);
$layoutField->getField()->trigger(Field::EVENT_DEFINE_INPUT_HTML, $event);
if (
    !str_contains($event->html, 'data-image-creator-context-key="field:summary-uid"') ||
    !str_contains($event->html, 'data-image-creator-context-handle="imageCaption"') ||
    $field->handle !== 'summary'
) {
    throw new RuntimeException('The rendered context marker must use the effective layout handle and stable field UID.');
}

$event = new DefineFieldHtmlEvent(['inline' => false, 'element' => new Entry(), 'html' => '', 'static' => true]);
$layoutField->getField()->trigger(Field::EVENT_DEFINE_INPUT_HTML, $event);
if ($event->html !== '') {
    throw new RuntimeException('Static fields must not expose editable context markers.');
}

$field->uid = 'unconfigured-uid';
$event = new DefineFieldHtmlEvent(['inline' => false, 'element' => new Entry(), 'html' => '']);
$field->trigger(Field::EVENT_DEFINE_INPUT_HTML, $event);
if ($event->html !== '') {
    throw new RuntimeException('Unconfigured fields must not expose context markers.');
}
echo 'Craft ' . Craft::$app->getVersion() . " context field override checks passed.\n";
