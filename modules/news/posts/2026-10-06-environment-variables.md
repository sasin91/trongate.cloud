---
title: Environment variables and secrets
kind: changelog
summary: Give your app API keys and other secrets from its Settings page. Values are encrypted and never shown again.
---
Every app now has an **Environment variables** section on its Settings page. Add a name and a value, and your app reads it with `getenv()`:

```php
$stripe_key = getenv('STRIPE_SECRET');
```

- Values are stored encrypted and are write-only. Nobody, you included, can read one back from the panel. To change a value, save it again under the same name.
- Changes take effect on the next deployment, so a half-finished edit never reaches a running app.
- `PORT` and a few names the platform sets itself are reserved.

Keep secrets out of your repository and out of `config/`. This is the place for them.
