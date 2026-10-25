<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\mcp;

use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\services\GenerationRequests;
use arifje\craftimagecreator\services\ImageGenerator;
use arifje\mcptools\attributes\McpToolMeta;
use arifje\mcptools\enums\Capability;
use arifje\mcptools\enums\ToolCategory;
use arifje\mcptools\support\Authorization;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Throwable;

/**
 * MCP tools for the "Create with AI" button, through the same services the
 * modal uses (Generations, ContextFields, AssetCreator):
 *
 *  - ai_image_options: providers, ratios, context fields and destinations;
 *  - ai_image_generate: queue an image from an entry's context (read on the
 *    server, as the modal reads it from the form) and optional extra context;
 *  - ai_image_result: follow the request and, once complete, save the image
 *    as an asset at the destination it was generated for.
 *
 * Registered only when Craft MCP Tools is installed (Plugin::init). The tools
 * act as the token's Craft user, who needs "Create images with AI"; a
 * connection without a user (stdio) cannot use them.
 */
final class ImageTools
{
    private const TARGET_CACHE_TTL = 3600;

    #[McpTool(
        name: 'ai_image_options',
        title: 'AI image options',
        description: 'What ai_image_generate accepts on this site: the configured AI providers (with models), the aspect ratios, the context fields read from the entry, the Assets fields images may be saved into, and whether a standalone volume is set.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function options(): array
    {
        $this->user();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $fields = [];
        foreach ($settings->getAssetFieldUids() as $uid) {
            $field = Craft::$app->getFields()->getFieldByUid($uid);
            if ($field !== null) {
                $fields[] = ['handle' => $field->handle, 'name' => $field->name];
            }
        }

        return [
            'providers' => array_map(static fn(array $option): array => [
                'handle' => $option['value'],
                'label' => $option['label'],
                'model' => $settings->getResolvedModel($option['value']),
            ], $settings->getConfiguredProviderOptions()),
            'defaultProvider' => $settings->defaultProvider,
            'ratios' => ImageGenerator::RATIOS,
            'contextFields' => array_map(static fn(array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
            ], $plugin->contextFields->getConfiguredDefinitions()),
            'fields' => $fields,
            'standaloneVolume' => $settings->getStandaloneVolumeUid() !== '',
        ];
    }

    #[McpTool(
        name: 'ai_image_generate',
        title: 'Generate an AI image',
        description: 'Generate one image with AI, as the "Create with AI" button does. With entryId and field, the prompt uses that entry\'s context fields (see ai_image_options) and the image will be saved into the field\'s upload folder for the entry; without them it goes to the standalone volume. Add extraContext for direction (style, subject, mood). Runs on Craft\'s queue and returns a token: call ai_image_result with it to save the image once done. Each call is a paid request to the AI provider.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true, capability: Capability::Upload)]
    public function generate(
        #[Schema(description: 'Aspect ratio.', enum: ImageGenerator::RATIOS)]
        string $ratio,
        #[Schema(description: 'The entry (or Matrix block entry) the image is for; its context fields feed the prompt. The token\'s user must be allowed to edit it.')]
        ?int $entryId = null,
        #[Schema(description: 'Handle of the Assets field to save into (one of fields from ai_image_options). Requires entryId.')]
        ?string $field = null,
        #[Schema(description: 'Site of the entry. Omit for the primary site.')]
        ?int $siteId = null,
        #[Schema(description: 'Additional direction for the image, up to 20,000 characters.')]
        string $extraContext = '',
        #[Schema(definition: ['type' => ['object', 'null'], 'description' => 'Context values by context field key, overriding what is read from the entry (or supplying it without an entry).'])]
        ?array $context = null,
        #[Schema(description: 'AI provider handle from ai_image_options. Omit for the default.')]
        ?string $provider = null,
    ): array {
        $plugin = Plugin::getInstance();
        $user = $this->user();
        if (($field === null) !== ($entryId === null)) {
            throw new ToolCallException('Pass entryId and field together, or neither to save into the standalone volume.');
        }

        $siteId ??= (int)Craft::$app->getSites()->getPrimarySite()->id;
        $values = [];
        if ($entryId !== null) {
            $entry = Entry::find()->id($entryId)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one();
            if (!$entry instanceof Entry) {
                throw new ToolCallException("Entry {$entryId} was not found on site {$siteId}.");
            }
            Authorization::assertCanSave($entry);

            $layoutField = $entry->getFieldLayout()?->getFieldByHandle((string)$field);
            if ($layoutField === null) {
                throw new ToolCallException("The entry has no field '{$field}'.");
            }
            $target = [
                'type' => 'field',
                'fieldUid' => (string)$layoutField->uid,
                'elementId' => (int)$entry->id,
                'siteId' => (int)$entry->siteId,
                'elementType' => Entry::class,
            ];
            $values = $plugin->contextFields->valuesFromElement($entry);
        } else {
            $target = ['type' => 'standalone'];
        }

        foreach ($context ?? [] as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new ToolCallException('context must map context field keys to text.');
            }
            $values[$key] = (string)$value;
        }

        $token = $this->run(fn() => $plugin->generations->queue(
            (int)$user->id,
            $provider ?? $plugin->getSettings()->defaultProvider,
            $ratio,
            $values,
            $extraContext,
            $target,
        ));
        Craft::$app->getCache()->set($this->targetKey((int)$user->id, $token), $target, self::TARGET_CACHE_TTL);

        return [
            'token' => $token,
            'status' => GenerationRequests::STATUS_QUEUED,
            'context' => $values,
            'next' => 'Call ai_image_result with this token until it reports the saved asset or a failure.',
        ];
    }

    #[McpTool(
        name: 'ai_image_result',
        title: 'Get an AI image',
        description: 'Follow a request from ai_image_generate. While it is queued or running this reports the status; once complete it saves the image as an asset at the destination chosen when generating, and returns the asset id and natural key {volume, path, filename}. The asset is not added to the entry: put the key in the field with update_entry. Pass discard: true to throw a finished image away instead of saving it.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true, capability: Capability::Upload)]
    public function result(
        #[Schema(description: 'The token from ai_image_generate.')]
        string $token,
        #[Schema(description: 'Filename without extension for the saved asset. Omit for "ai-image-{date}".')]
        string $filename = '',
        #[Schema(description: 'Cancel the request, or throw away a finished image, instead of saving it.')]
        bool $discard = false,
    ): array {
        $plugin = Plugin::getInstance();
        $user = $this->user();
        $userId = (int)$user->id;
        $cache = Craft::$app->getCache();
        $targetKey = $this->targetKey($userId, $token);

        if ($discard) {
            $plugin->generations->cancel($token, $userId);
            $cache->delete($targetKey);

            return ['token' => $token, 'status' => GenerationRequests::STATUS_CANCELLED];
        }

        $status = $this->run(fn() => $plugin->generations->status($token, $userId));
        if ($status['status'] !== GenerationRequests::STATUS_COMPLETE) {
            if (in_array($status['status'], [GenerationRequests::STATUS_QUEUED, GenerationRequests::STATUS_RUNNING], true)) {
                $status['hint'] = 'Not finished yet; call again shortly. If it stays queued, the site needs a queue worker (php craft queue/listen).';
            }

            return $status;
        }

        $target = $cache->get($targetKey);
        if (!is_array($target)) {
            throw new ToolCallException('The destination of this request has expired. Generate the image again.');
        }

        $asset = $this->run(fn() => $plugin->generations->save($token, $userId, $target, $filename));
        $cache->delete($targetKey);

        return ['token' => $token, 'status' => 'saved'] + $this->assetResult($asset);
    }

    private function user(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            throw new ToolCallException('The AI image tools act as a Craft user. Connect over HTTP with a token to use them.');
        }
        if (!$user->can('accessCp') || !$user->can(Plugin::PERMISSION_USE)) {
            throw new ToolCallException('This token\'s user may not create images with AI (needs the "Create images with AI" permission).');
        }

        return $user;
    }

    private function targetKey(int $userId, string $token): string
    {
        return 'craft-image-creator:mcp-target:' . $userId . ':' . hash('sha256', $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function assetResult(Asset $asset): array
    {
        $key = ['volume' => $asset->getVolume()->handle, 'filename' => $asset->getFilename()];
        $path = $asset->getFolder()->path ?? '';
        if ($path !== '') {
            $key['path'] = $path;
        }

        try {
            $url = $asset->getUrl();
        } catch (Throwable) {
            $url = null;
        }

        return array_filter([
            'assetId' => (int)$asset->id,
            'key' => $key,
            'title' => (string)$asset->title,
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'url' => $url,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Run a service call, handing its refusal to the agent as a tool error
     * with the same message the control panel shows.
     */
    private function run(callable $call): mixed
    {
        try {
            return $call();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (Throwable $e) {
            Craft::warning('[craft-image-creator] MCP: ' . $e->getMessage(), __METHOD__);
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
