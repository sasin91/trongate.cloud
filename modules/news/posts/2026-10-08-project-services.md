---
title: Run a service next to your apps
kind: changelog
summary: Add any container image to a project, such as a search engine or a queue, and its apps get its address on their next deploy.
---
Some apps need more than PHP and a database: a search engine, a PDF renderer, a small API written in another language.

A project's page now has a **Services** card. Give the service a name, an image and the port it listens on, plus any environment variables it needs. Each app in the project gets a `{NAME}_URL` variable on its next deploy:

```php
$search = getenv('SEARCH_URL'); // http://... reachable only from this project
```

Services run always on, without root, and only the project's own apps can reach them. They are not exposed to the internet.
