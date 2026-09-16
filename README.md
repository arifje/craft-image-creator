# Image Creator for Craft CMS

Image Creator adds a **Create with AI** action to selected image-capable Assets fields in the Craft control panel. Editors can combine configured context with one-off instructions, generate an image with OpenAI, Grok (xAI), or Google Gemini, and save the result as a normal Craft Asset. A standalone creator remains available by its direct control-panel URL without adding another item to the sidebar.

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

After updating an existing installation, run `php craft up` so Craft can apply the plugin's database migrations.

## Configuration

Open **Settings -> Plugins -> Image Creator**.

### Standalone storage

Choose the standalone **Storage location** once in the plugin settings. The dropdown shows configured Craft filesystems that are backed by Asset volumes. Image Creator stores the selected volume UID and saves standalone images to that volume's root, so editors do not choose a destination on the Image Creator page.

### Field integration

- **Assets fields** controls which image-capable Assets fields display the **Create with AI** action. The action is available whether the field is empty or already contains an Asset.
- **Context fields** controls which element values are shown in the modal and appended to the provider prompt. Title, slug, global custom fields, and nested Matrix custom fields can be selected.
- **Default provider** is selected when the modal opens, when that provider is configured.

Fields are stored by UID so the configuration remains stable when handles change and can travel with project config. Nested fields are labelled in the settings screen. In a Matrix block, Image Creator reads context from the closest relevant block before falling back to the surrounding element form.

Configured context fields are shown in the modal so an editor can review or adjust the text for that generation. Caption and Category values use compact single-line inputs; longer context remains multiline. An empty configured field is intentionally included as empty context; it does not prevent generation. Modal edits and **Extra context** are one-off prompt inputs and do not change the element's field values.

### Image prompt

Open **Utilities -> Image Creator Prompt** to edit the multiline base instruction used for image generation. The prompt is stored in the current environment's database rather than plugin settings or project config. Authorized users can therefore update the production prompt without changing `allowAdminChanges`, committing project config, or deploying a new plugin version.

When upgrading an existing installation, Image Creator copies the previously effective plugin-setting prompt into the database once. This includes resolving an environment-variable reference that was configured previously. After that migration, the Utilities value is authoritative; changing the legacy plugin setting, config-file value, or environment variable does not change the active prompt.

Prompt edits apply to generation requests submitted after the prompt is saved. Requests that are already queued keep the prepared prompt that was captured when they were submitted.

### Provider credentials

Settings are organized into **General**, **OpenAI**, **Grok (xAI)**, and **Google Gemini** tabs. Switch tabs without losing edits, then save all settings together.

OpenAI, xAI, and Google each have an API key and model setting. API keys use Craft autosuggest fields and should normally reference environment variables. Models are selected from provider-specific dropdowns. On opening the settings page, the plugin loads image-model IDs available to each saved API key from the provider; use **Refresh models** beside a dropdown to fetch a new list immediately. Successful lists are cached for 15 minutes per provider and API key. Refreshing the list does not change the selected model or save plugin settings. Save API-key changes before refreshing models.

For example:

```dotenv
OPENAI_API_KEY="..."
XAI_API_KEY="..."
GEMINI_API_KEY="..."
```

Enter the corresponding API-key references in the plugin settings, then select each provider's model from its dropdown:

```text
$OPENAI_API_KEY
$XAI_API_KEY
$GEMINI_API_KEY
```

Only providers with both a resolved API key and model are offered in the creation modal. Provider access, model availability, billing, safety rules, and request limits are managed by the provider account.

When a provider rejects a generation request, the modal shows a sanitized diagnostic with the provider, HTTP status, safe provider detail, and request ID when available. API keys, bearer tokens, non-JSON response bodies, raw request/response payloads, and unexpected internal exception details are never returned to the browser. Provider validation text may repeat part of the context the editor just submitted, so HTTP response detail is shown only for statuses `400`, `409`, `413`, `415`, and `422`; other statuses use curated summaries. Explicit refusal messages in otherwise successful responses are sanitized in the same way. Connection failures and timeouts include retry or outbound-network guidance; full unexpected failures remain available only through Craft's server logs.

The current default models are:

| Provider | Default model | API documentation |
| --- | --- | --- |
| OpenAI | `gpt-image-2` | [Image generation](https://developers.openai.com/api/docs/guides/image-generation) |
| Grok (xAI) | `grok-imagine-image-2.0` | [Image generation](https://docs.x.ai/developers/model-capabilities/images/generation) |
| Google Gemini | `gemini-3.1-flash-image` | [Image generation](https://ai.google.dev/gemini-api/docs/image-generation) |

The dropdowns include provider-discovered image models compatible with the plugin's image-generation endpoints. OpenAI and Google do not expose image-output capabilities in their model-list responses, so the plugin conservatively filters their model IDs; a listed model can still have provider-specific access or parameter restrictions. If discovery is unavailable, the dropdown keeps its bundled fallback models. Existing custom or environment-based model values remain selectable even when a provider omits them; advanced model overrides can also be supplied through the optional config file.

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
    'defaultProvider' => 'openai',
    'openAiApiKey' => '$OPENAI_API_KEY',
    'openAiModel' => 'gpt-image-2',
    'xAiApiKey' => '$XAI_API_KEY',
    'xAiModel' => 'grok-imagine-image-2.0',
    'googleApiKey' => '$GEMINI_API_KEY',
    'googleModel' => 'gemini-3.1-flash-image',
];
```

The active image prompt is intentionally not a config-file setting; edit it under **Utilities -> Image Creator Prompt**. Do not commit resolved API keys to source control or project config.

## Permissions

Grant editors the **Image Creator -> Create images with AI** permission. For field-based creation, they must also be allowed to edit the current element and save Assets to the field's resolved destination volume. For standalone creation, they need permission to view and save Assets in the volume configured as the standalone storage location.

Users who may change the base prompt also need the **Utilities -> Image Creator Prompt** permission. This is independent from the image-creation permission; administrators implicitly have access to all utilities. The prompt-saving endpoint checks the utility authorization separately from access to the Utility page.

Every request checks the plugin permission and the relevant `viewAssets:<volumeUid>` and/or `saveAssets:<volumeUid>` permissions. Field-based requests additionally check the element edit permission, selected Assets-field configuration, allowed file kinds, field selection conditions, and the field's resolved upload location. Standalone requests resolve the configured volume and its root on the server. The queue worker repeats these checks immediately before contacting a provider. A visible action is not treated as authorization.

## Standalone workflow

1. Open the standalone `image-creator-ai` control-panel URL directly; it is intentionally not listed in the sidebar.
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

Provider generation runs as a Craft queue job. The modal remains open and polls the user-bound request until the preview is ready; saving the approved preview as an Asset remains synchronous. This keeps slow provider requests out of control-panel web requests. The combined prompt is prepared and stored with the queued request, so a later Utilities edit affects only requests submitted after the change.

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
composer --no-plugins validate --strict --no-check-version --no-check-publish
php tests/static-contract.php
php tests/provider-errors.php
vendor/bin/phpstan analyse
vendor/bin/ecs check
npm run check
npm test
```

The compiled control-panel assets are part of the Composer package. Run the production build and commit the generated bundle whenever its source changes.

## License

Image Creator is released under the [MIT License](LICENSE).
