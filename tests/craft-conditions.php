<?php

declare(strict_types=1);

// Read-only native-rule checks. Pass an existing Craft project's bootstrap.php.
use craft\elements\Asset;
use craft\elements\conditions\assets\FilenameConditionRule;
use craft\elements\conditions\assets\VolumeConditionRule;
use craft\models\Volume;

require $argv[1];
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$asset = new class() extends Asset {
    public string $testVolumeUid = 'temporary';

    public function getVolume(): Volume
    {
        return new Volume(['uid' => $this->testVolumeUid]);
    }
};
$filenameRule = new FilenameConditionRule(['value' => 'editorial.png']);
$volumeRule = new VolumeConditionRule(['values' => ['destination']]);
$asset->setFilename('temporary.png');
if ($filenameRule->matchElement($asset) || $volumeRule->matchElement($asset)) {
    throw new RuntimeException('Temporary properties unexpectedly match destination rules.');
}
$asset->setFilename('editorial.png');
$asset->testVolumeUid = 'destination';
if (!$filenameRule->matchElement($asset) || !$volumeRule->matchElement($asset)) {
    throw new RuntimeException('Final properties do not match destination rules.');
}
$asset->setFilename('editorial-1.png');
if ($filenameRule->matchElement($asset)) {
    throw new RuntimeException('A conflict-renamed filename bypassed the rule.');
}
echo 'Craft ' . Craft::$app->getVersion() . " native condition checks passed.\n";
