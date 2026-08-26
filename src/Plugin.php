<?php

declare(strict_types=1);

namespace arifje\craftimagecreator;

use arifje\craftimagecreator\models\Settings;
use arifje\craftimagecreator\services\AssetCreator;
use arifje\craftimagecreator\services\ContextFields;
use arifje\craftimagecreator\services\GeneratedResults;
use arifje\craftimagecreator\services\ImageGenerator;
use arifje\craftimagecreator\services\providers\ProviderRegistry;
use arifje\craftimagecreator\web\assets\ImageCreatorAsset;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineFieldHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fields\Assets;
use craft\helpers\Json;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read AssetCreator $assetCreator
 * @property-read ContextFields $contextFields
 * @property-read GeneratedResults $generatedResults
 * @property-read ImageGenerator $imageGenerator
 * @property-read ProviderRegistry $providerRegistry
 */
final class Plugin extends BasePlugin
{
    public const PERMISSION_USE = 'craft-image-creator-use';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public ?string $t9nCategory = 'craft-image-creator';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'components' => [
                'assetCreator' => AssetCreator::class,
                'contextFields' => ContextFields::class,
                'generatedResults' => GeneratedResults::class,
                'imageGenerator' => ImageGenerator::class,
                'providerRegistry' => ProviderRegistry::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerCpRoutes();
        $this->registerPermissions();

        Craft::$app->onInit(function(): void {
            $this->registerFieldActions();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('craft-image-creator/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'assetFieldOptions' => $this->contextFields->getAssetFieldOptions(),
            'contextFieldOptions' => $this->contextFields->getContextFieldOptions(),
            'providerOptions' => Settings::providerOptions(),
        ]);
    }

    public function registerCpAssets(): void
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ImageCreatorAsset::class);
        $view->registerTranslations('craft-image-creator', [
            'Add to field',
            'Add details for this image only. Configured context fields may be left empty.',
            'Close',
            'Craft could not render the generated Asset.',
            'Create with AI',
            'Create an image from the configured prompt and this element’s context.',
            'Extra context',
            'Filename',
            'Generate image',
            'Generated automatically',
            'Generated image',
            'Generating image…',
            'Image created and added to the field.',
            'Image ratio',
            'No AI image provider is configured.',
            'Optional details, visual direction, or constraints for this image.',
            'Provider',
            'Reset',
            'Saving Asset…',
            'Save the element before creating an image.',
            'The Asset was saved, but it could not be added to this field. Try Add to field again.',
            'The Assets field is not ready yet.',
            'The current Asset relation could not be identified.',
            'The generated preview could not be loaded. Generate the image again.',
            'The image could not be generated.',
            'The image could not be saved.',
            'The server did not return a generated image.',
            'The server did not return the saved Asset.',
            'This Assets field has reached its relation limit.',
            'You are not allowed to add Assets to this field.',
            'You are not allowed to replace this Asset relation.',
            'Your generated image will appear here.',
        ]);

        $config = [
            'craftMajorVersion' => (int)explode('.', Craft::$app->getVersion())[0],
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'defaultProvider' => $this->getSettings()->defaultProvider,
            'providers' => $this->getSettings()->getConfiguredProviderOptions(),
            'contextFields' => $this->contextFields->getConfiguredDefinitions(),
            'ratios' => [
                ['value' => '16:9', 'label' => '16:9'],
                ['value' => '9:16', 'label' => '9:16'],
                ['value' => '4:5', 'label' => '4:5'],
                ['value' => '1:1', 'label' => '1:1'],
            ],
            'routes' => [
                'generate' => 'craft-image-creator/creator/generate',
                'save' => 'craft-image-creator/creator/save',
                'discard' => 'craft-image-creator/creator/discard',
            ],
        ];

        $view->registerJs(
            'window.CraftImageCreatorConfig = ' . Json::htmlEncode($config) . ';',
            View::POS_HEAD,
            'craft-image-creator-config'
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['image-creator-ai/api/generate'] = 'craft-image-creator/creator/generate';
                $event->rules['image-creator-ai/api/save'] = 'craft-image-creator/creator/save';
                $event->rules['image-creator-ai/api/discard'] = 'craft-image-creator/creator/discard';
                $event->rules['image-creator-ai/api/preview'] = 'craft-image-creator/creator/preview';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('craft-image-creator', 'Image Creator'),
                    'permissions' => [
                        self::PERMISSION_USE => [
                            'label' => Craft::t('craft-image-creator', 'Create images with AI'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerFieldActions(): void
    {
        Event::on(
            Assets::class,
            Field::EVENT_DEFINE_INPUT_HTML,
            function(DefineFieldHtmlEvent $event): void {
                $request = Craft::$app->getRequest();
                $field = $event->sender;
                $element = $event->element;
                $fieldUid = (string)($field->uid ?? '');

                if (
                    !$request->getIsCpRequest() ||
                    !$field instanceof Assets ||
                    !$element instanceof ElementInterface ||
                    $event->static ||
                    (property_exists($event, 'inline') && $event->inline) ||
                    !$this->getSettings()->isAssetFieldEnabled($fieldUid) ||
                    !$this->fieldAllowsImages($field) ||
                    !Craft::$app->getUser()->checkPermission('accessCp') ||
                    !Craft::$app->getUser()->checkPermission(self::PERMISSION_USE)
                ) {
                    return;
                }

                $this->registerCpAssets();
                $event->html .= Craft::$app->getView()->renderTemplate(
                    'craft-image-creator/_components/field-context.twig',
                    [
                        'element' => $element,
                        'field' => $field,
                    ]
                );
            }
        );
    }

    private function fieldAllowsImages(Assets $field): bool
    {
        return $field->allowUploads && (
            !$field->restrictFiles ||
            !is_array($field->allowedKinds) ||
            $field->allowedKinds === [] ||
            in_array('image', $field->allowedKinds, true)
        );
    }
}
