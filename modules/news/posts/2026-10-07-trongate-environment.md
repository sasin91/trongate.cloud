---
title: Pick each app's Trongate environment
kind: changelog
summary: Set your app's ENV to dev, staging or prod from its Settings page. We write it into config/config.php at deploy.
---
Trongate's `ENV` constant decides how much your app tells visitors when something breaks. In `dev`, error pages show details. In `prod`, they don't.

Each app now has an **Environment** setting on its Settings page with `dev`, `staging` and `prod`. We set the `ENV` define in your `config/config.php` when we build, so the same repository can run as a staging app and as a production app without a separate branch or a config file per environment.

New apps start in `dev`, which suits a first deploy. Switch to `prod` before you share the address.
