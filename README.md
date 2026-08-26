# Image Creator for Craft CMS

Image Creator adds a standalone **Image Creator** section and a **Create with AI** action to selected image-capable Assets fields in the Craft control panel. Editors can combine configured context with one-off instructions, generate an image with OpenAI, Grok (xAI), or Google Gemini, and save the result as a normal Craft Asset.

The plugin supports Craft CMS 4.4 and Craft CMS 5, including Assets and context fields nested in Matrix blocks.

## Requirements

- Craft CMS 4.4 or later, or Craft CMS 5
- PHP 8.0.2 or later
- A writable Craft volume for each enabled Assets field and for the configured standalone storage location
- Outbound HTTPS access to at least one configured image provider
- An API key with image-generation access for the selected provider
- A working Craft queue runner

## Installation

Install the package and then install the Craft plugin:

```bash
composer require arifje/craft-image-creator:^1.0
php craft plugin/install craft-image-creator
```

## Configuration

Open **Settings -> Plugins -> Image Creator**.

### Standalone storage

Choose the standalone **Storage location** once in the plugin settings. The dropdown shows configured Craft filesystems that are backed by Asset volumes. Image Creator stores the selected volume UID and saves standalone images to that volume's root, so editors do not choose a destination on the Image Creator page.

### Field integration

- **Assets fields** controls which image-capable Assets fields display the **Create with AI** action. The action is available whether the field is empty or already contains an Asset.
- **Context fields** controls which element values are shown in the modal and appended to the provider prompt. Title, slug, global custom fields, and nested Matrix custom fields can be selected.
- **Image prompt** is the multiline base instruction sent for every generation.
- **Default provider** is selected when the modal opens, when that provider is configured.

Fields are stored by UID so the configuration remains stable when handles change and can travel with project config. Nested fields are labelled in the settings screen. In a Matrix block, Image Creator reads context from the closest relevant block before falling back to the surrounding element form.

Configured context fields are shown in the modal so an editor can review or adjust the text for that generation. Caption and Category values use compact single-line inputs; longer context remains multiline. An empty configured field is intentionally included as empty context; it does not prevent generation. Modal edits and **Extra context** are one-off prompt inputs and do not change the element's field values.

### Provider credentials

OpenAI, xAI, and Google each have an API key and model setting. API keys use Craft autosuggest fields and should normally reference environment variables. Models are selected from provider-specific dropdowns populated with image models supported by this plugin. The prompt is a multiline textarea and can contain text or an environment-variable reference.

For example:

```dotenv
IMAGE_CREATOR_PROMPT="Create a natural editorial photograph that accurately reflects the supplied context."
OPENAI_API_KEY="..."
XAI_API_KEY="..."
GEMINI_API_KEY="..."
```

Enter the corresponding prompt and API-key references in the plugin settings, then select each provider's model from its dropdown:

```text
$IMAGE_CREATOR_PROMPT
$OPENAI_API_KEY
$XAI_API_KEY
$GEMINI_API_KEY
```

Only providers with both a resolved API key and model are offered in the creation modal. Provider access, model availability, billing, safety rules, and request limits are managed by the provider account.

The current default models are:

| Provider | Default model | API documentation |
| --- | --- | --- |
| OpenAI | `gpt-image-2` | [Image generation](https://developers.openai.com/api/docs/guides/image-generation) |
| Grok (xAI) | `grok-imagine-image-2.0` | [Image generation](https://docs.x.ai/developers/model-capabilities/images/generation) |
| Google Gemini | `gemini-3.1-flash-image` | [Image generation](https://ai.google.dev/gemini-api/docs/image-generation) |

The dropdowns contain models whose request formats are supported by this plugin. Existing custom or environment-based model values are retained during upgrades; advanced model overrides can also be supplied through the optional config file.

### Optional config file

Settings can also be supplied from `config/craft-image-creator.php`. Use the backing Asset volume UID for `standaloneVolumeUid`, and field UIDs for both target fields and custom context fields; custom context locators use the `field:` prefix.

```php
<?php

return [
    'standaloneVolumeUid' => '11111111-2222-3333-4444-555555555555',
    'assetFieldUids' => [
        'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    ],
    'contextFields' => [
        'title',
        'field:ffffffff-1111-2222-3333-444444444444',
    ],
    'prompt' => '$IMAGE_CREATOR_PROMPT',
    'defaultProvider' => 'openai',
    'openAiApiKey' => '$OPENAI_API_KEY',
    'openAiModel' => 'gpt-image-2',
    'xAiApiKey' => '$XAI_API_KEY',
    'xAiModel' => 'grok-imagine-image-2.0',
    'googleApiKey' => '$GEMINI_API_KEY',
    'googleModel' => 'gemini-3.1-flash-image',
];
```

Do not commit resolved API keys to source control or project config.

## Permissions

Grant editors the **Image Creator -> Create images with AI** permission. For field-based creation, they must also be allowed to edit the current element and save Assets to the field's resolved destination volume. For standalone creation, they need permission to view and save Assets in the volume configured as the standalone storage location.

Every request checks the plugin permission and the relevant `viewAssets:<volumeUid>` and/or `saveAssets:<volumeUid>` permissions. Field-based requests additionally check the element edit permission, selected Assets-field configuration, allowed file kinds, field selection conditions, and the field's resolved upload location. Standalone requests resolve the configured volume and its root on the server. The queue worker repeats these checks immediately before contacting a provider. A visible action is not treated as authorization.

## Standalone workflow

1. Open **Image Creator** in the control-panel sidebar.
2. Select **Create image**.
3. Fill in any configured context fields and optional extra context.
4. Choose a configured provider and image ratio, then generate and review the image.
5. Select **Save Asset**.

The generated file is saved immediately as a normal Craft Asset at the root of the volume backing the storage location configured in the plugin settings. It is not attached to an entry or other element.

## Assets-field workflow

1. Open an entry or another editable element containing an enabled Assets field.
2. Select **Create with AI** below the field.
3. Review the configured context fields and add optional extra context.
4. Choose a configured provider and one of `16:9`, `9:16`, `4:5`, or `1:1`.
5. Generate and review the image.
6. Select **Add to field**.
7. Save the entry or element normally.

The generated file is saved immediately as a new Craft Asset in the Assets field's real upload location. Image Creator then adds its relation to the existing field input and marks the form as changed. The relation is not permanent until the editor saves the entry or element. Existing source Assets are never overwritten.

For an Assets field with a relation limit of one, adding a generated image replaces the relation shown in the input; it does not overwrite or delete the previously related Asset.

## Queue processing

Provider generation runs as a Craft queue job. The modal remains open and polls the user-bound request until the preview is ready; saving the approved preview as an Asset remains synchronous. This keeps slow provider requests out of control-panel web requests.

Craft's default web queue runner can process jobs automatically. If `runQueueAutomatically` is disabled, run a worker such as:

```bash
php craft queue/listen
```

Resetting or closing the modal cancels a queued request. A provider call that has already started cannot necessarily be interrupted, but any result returned after cancellation is discarded and cannot be saved.

Generation state is held in Craft's cache, and previews are stored temporarily under Craft's runtime temp directory. If the web process and queue worker run on different hosts or containers, configure shared cache, mutex, and runtime temp storage that both can access. The prepared prompt, including supplied context, is present in the serialized queue job until that job is removed.

## Ratios

The modal offers these output ratios:

- `16:9` landscape
- `9:16` portrait
- `4:5` portrait
- `1:1` square

Image Creator requests the closest supported provider output and validates the resulting image server-side. Grok currently uses `3:4` as its nearest native request for `4:5`; the result is center-cropped to the exact selected ratio before it is saved.

## Security and temporary files

- Provider credentials stay on the server and are never included in the browser configuration.
- Generation and Asset-save endpoints require an authenticated control-panel request, JSON acceptance, CSRF validation, and the Image Creator permission.
- Generated image bytes are validated server-side for MIME type, dimensions, and file size.
- Queue state and preview tokens are short-lived and bound to the current user and the canonical destination field or configured standalone volume root.
- The queue worker rechecks the user's current permissions and destination immediately before contacting the provider.
- The browser cannot submit an arbitrary remote URL for Asset import.
- Temporary generated files and tokens expire; saving or discarding a result removes the temporary result when possible.

## Development

Install PHP and JavaScript dependencies:

```bash
composer install
npm install
```

The control-panel build requires Node.js 20.19 or later.

Build the control-panel bundle:

```bash
npm run build
```

Run the local checks before a release:

```bash
composer validate --strict
php tests/static-contract.php
vendor/bin/phpstan analyse
vendor/bin/ecs check
npm run check
npm test
```

The compiled control-panel assets are part of the Composer package. Run the production build and commit the generated bundle whenever its source changes.

## License

Image Creator is released under the [MIT License](LICENSE).
