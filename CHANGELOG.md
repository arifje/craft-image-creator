# Release Notes

## 1.0.0 - 2026-08-26

- Adds a **Create with AI** action to configured image-capable Assets fields, including empty fields, dynamically rendered fields, tabs, and Matrix blocks.
- Adds configurable title, slug, global-field, and nested-field context with an additional one-off context input.
- Adds OpenAI, Grok (xAI), and Google Gemini image-provider integrations with environment-aware API-key and model settings.
- Adds `16:9`, `9:16`, `4:5`, and `1:1` output options.
- Saves validated generated images as normal Craft Assets in each field's resolved upload location and inserts them into the live element form.
- Adds authenticated, CSRF-protected generation and Asset-save endpoints with short-lived, user-bound preview tokens and server-side permission checks.
- Supports Craft CMS 4.4 and Craft CMS 5.
