<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\Plugin;
use Craft;
use craft\base\Field;
use craft\fields\Assets;
use craft\fields\Matrix;
use yii\base\Component;

final class ContextFields extends Component
{
    /** @return array<int, array{label: string, value: string}> */
    public function getAssetFieldOptions(): array
    {
        $options = [];
        foreach ($this->allFields() as $field) {
            if (!$field instanceof Assets || !$this->allowsImages($field)) {
                continue;
            }
            $options[] = [
                'label' => $this->fieldLabel($field),
                'value' => (string)$field->uid,
            ];
        }

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    public function getContextFieldOptions(): array
    {
        $options = [
            ['label' => 'Title', 'value' => 'title'],
            ['label' => 'Slug', 'value' => 'slug'],
        ];

        foreach ($this->allFields() as $field) {
            if ($field instanceof Assets || $field instanceof Matrix) {
                continue;
            }
            $options[] = [
                'label' => $this->fieldLabel($field),
                'value' => 'field:' . $field->uid,
            ];
        }

        return $options;
    }

    /** @return array<int, array{key: string, label: string, handle: string, native: bool}> */
    public function getConfiguredDefinitions(): array
    {
        $definitions = [];
        foreach (Plugin::getInstance()->getSettings()->getContextFields() as $locator) {
            if ($locator === 'title' || $locator === 'slug') {
                $definitions[] = [
                    'key' => $locator,
                    'label' => ucfirst($locator),
                    'handle' => $locator,
                    'native' => true,
                ];
                continue;
            }

            $uid = str_starts_with($locator, 'field:') ? substr($locator, 6) : '';
            $field = $uid !== '' ? Craft::$app->getFields()->getFieldByUid($uid) : null;
            if (!$field instanceof Field) {
                continue;
            }
            $definitions[] = [
                'key' => $locator,
                'label' => (string)$field->name,
                'handle' => (string)$field->handle,
                'native' => false,
            ];
        }

        return $definitions;
    }

    /** @return Field[] */
    private function allFields(): array
    {
        $fields = [];
        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (!$field instanceof Field) {
                continue;
            }
            $uid = (string)($field->uid ?? '');
            if ($uid !== '' && !isset($fields[$uid])) {
                $fields[$uid] = $field;
            }
        }

        return array_values($fields);
    }

    private function fieldLabel(Field $field): string
    {
        $nested = ($field->context ?? 'global') !== 'global';

        return sprintf(
            '%s (%s)%s',
            $field->name,
            $field->handle,
            $nested ? ' — nested field' : ''
        );
    }

    private function allowsImages(Assets $field): bool
    {
        return $field->allowUploads && (
            !$field->restrictFiles ||
            !is_array($field->allowedKinds) ||
            $field->allowedKinds === [] ||
            in_array('image', $field->allowedKinds, true)
        );
    }
}
