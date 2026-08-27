# Release Notes

## 1.0.4 - 2026-08-27

- Shows actionable, sanitized provider failures in the creation modal, including safe HTTP status, provider error, and request-ID details where available.
- Supports xAI's scalar error response format and removes the redundant Grok `quality` parameter while retaining xAI's documented medium-quality default.
- Redacts API keys and token patterns, suppresses non-JSON response bodies, and keeps unexpected internal errors generic while retaining safe server logging.

## 1.0.3 - 2026-08-26

- Moves the base image prompt to **Utilities -> Image Creator Prompt**, where authorized users can update it directly in each environment without deploying plugin settings or project config.
- Adds database-backed prompt storage and migrates the previously effective prompt once during the upgrade, including resolved legacy environment-variable values.
- Secures prompt updates with Craft's native Utility permission and a separately authorized, CSRF-protected save endpoint on both Craft 4 and Craft 5.

## 1.0.2 - 2026-08-26

- Moves the standalone Image Creator destination from the creation page to a single **Storage location** filesystem setting and saves generated Assets to the root of its backing volume.
- Keeps the modal action footer fixed and fully visible while its context fields and preview area scroll independently.
- Uses single-line controls for **Caption** and **Category** context values.

## 1.0.1 - 2026-08-26

- Adds a standalone **Image Creator** control-panel section for creating normal Craft Assets directly in an authorized destination folder.
- Runs provider generation through Craft queue jobs with user-bound status polling, cancellation, and permission checks at execution time.
- Replaces manual model inputs with provider-specific supported-model dropdowns while retaining existing custom values.
- Changes the configured image prompt to a multiline textarea.
- Makes the creation modal resizable and viewport-aware, with responsive action buttons that remain fully visible.
- Strengthens generated-result ownership by binding queue requests and previews to the current user and canonical destination.

## 1.0.0 - 2026-08-26

- Adds a **Create with AI** action to configured image-capable Assets fields, including empty fields, dynamically rendered fields, tabs, and Matrix blocks.
- Adds configurable title, slug, global-field, and nested-field context with an additional one-off context input.
- Adds OpenAI, Grok (xAI), and Google Gemini image-provider integrations with environment-aware API-key and model settings.
- Adds `16:9`, `9:16`, `4:5`, and `1:1` output options.
- Saves validated generated images as normal Craft Assets in each field's resolved upload location and inserts them into the live element form.
- Adds authenticated, CSRF-protected generation and Asset-save endpoints with short-lived, user-bound preview tokens and server-side permission checks.
- Supports Craft CMS 4.4 and Craft CMS 5.
