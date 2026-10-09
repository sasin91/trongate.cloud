---
title: Sturdier configs, and Trongate upgrades as pull requests
kind: changelog
summary: We now read your config files as PHP instead of matching text, and a v2 app can get a pull request that moves it to the newest Trongate. One step for you: accept the GitHub app's new permissions.
---
## Your config files, read as PHP

Every deploy sets a few values in your `config/*.php`: your app's address, its `ENV` and its database connection. We used to find those lines by matching text. That broke on configs laid out a little differently from the stock ones.

Now we read the files the way PHP does. We change only the values we own, wherever and however they are written, and leave every other line as you wrote it. A value that isn't there gets added. Your own `config/database.php` stays yours, with the connection filled in.

If a config file can't be read, the deploy stops before anything changes and shows a **Deploy failed** card with the file, the line and the reason. Depending on the cause, it links to that line on GitHub so you can fix it and push, or offers a **Deploy** button to try again.

Rollbacks now reuse the exact config they were built with. Deploys made before this change can't be rolled back to, so deploy each app once to get a fresh starting point.

## Trongate upgrades as pull requests

Moving an app to a newer Trongate used to mean copying framework files by hand. Now a v2 app has an **Upgrade** tab with a **Check for an upgrade** button.

It works out which release your app is on and opens a pull request on your repository, on a `trongate-upgrade/` branch, that moves it to the newest Trongate v2 release:

- Framework files you never touched take the newer copy.
- Files only you changed stay as they are.
- Files you and Trongate both changed are merged. Where the changes overlap, the pull request lists the file under **Resolve before merging**.
- Your own modules are never read or changed.
- New settings in `config/` are added, and every value you set stays.
- SQL that the new release adds is listed under **Import into your database**. Run it in your app's Database tab.

Every change comes from fixed rules, so the same app and release always give the same pull request. Nothing deploys until you merge it. Upgrades from Trongate v1 to v2 are not covered.

## One step for you: accept the new permissions

To open these pull requests, the trongate.cloud GitHub app now asks for two more permissions on the repositories you gave it: **Contents: Read and write** and **Pull requests: Read and write**. GitHub doesn't grant new permissions on its own. Whoever installed the app has to accept them once. Until then, deploys keep working as before, and **Check for an upgrade** says the permissions are missing.

On a personal account:

1. Open GitHub and go to **Settings**, then **Applications**, then the **Installed GitHub Apps** tab.
2. Find **trongate.cloud** and click **Configure**.
3. Click **Review request** in the banner at the top and then **Accept new permissions**.

On an organization, an owner of the organization does the same from the organization's **Settings**, then **GitHub Apps**, then **Configure** next to trongate.cloud.

GitHub also emails a link to the same page. Deploys still only ever read your code. The write access is used for the upgrade pull request and nothing else.
