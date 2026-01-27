# CEL Multilingual AI

AI-powered multilingual functionality for WordPress and WooCommerce using OpenRouter.

## Features
- Separate translated pages/posts per language.
- Manual and API-triggered translations.
- Frontend language switcher.
- Background processing via WP-Cron.
- WooCommerce product support (including template-based variation titles).
- SEO ready (hreflang tags).

## Setup
1. Activate the plugin.
2. Go to **Settings -> AI Translate**.
3. Enter your **OpenRouter API Key**.
4. Select the desired AI Model.
5. Use the **AI Translations** meta box on any Page or Product to start translating.

## Configuration via wp-config.php
You can define your API key in `wp-config.php` for better security:
```php
define('CEL_AI_OPENROUTER_KEY', 'sk-or-v1-...');
```

## Shortcodes
- `[cel_ai_switcher]`: Displays the language switcher dropdown.
