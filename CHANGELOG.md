# Release Notes

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
