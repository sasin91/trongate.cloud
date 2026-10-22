---
title: Two critical PHP bugs, and what we did about them
kind: writing
summary: CVE-2026-109618 and CVE-2026-109619 affected every app on trongate.cloud and the panel itself. Here is what we found, what we changed, and what it means for your app.
---
On October 21 the PHP project published two critical security bugs, CVE-2026-109618 and CVE-2026-109619. Every app on trongate.cloud ran an affected version of PHP, and so did the trongate.cloud panel. The same day we turned off what the bugs rely on, and every app now runs PHP 8.4.27, which fixes both.

## The two bugs

Both can let an attacker run their own code inside a PHP app, and both score 9.2 out of 10.

- **CVE-2026-109618, running out of memory.** When a request runs out of memory, PHP can leave half-prepared memory behind, and cleaning up after that request can be steered into running an attacker's code. It can be reached with a crafted upload request that needs no sign-in, whatever the app's own code does. PHP showed full code execution this way on Apache's mod_php. For FrankenPHP, which our apps run on, the advisory says it is likely exposed, based on reading the source, and that how well an attack works depends on the build and setup.
- **CVE-2026-109619, stale state between requests.** A request that ends in a fatal error, such as hitting the time limit or a failed `require`, can leave PHP pointing at memory it has already freed. A later request handled by the same PHP worker can then use it. The confirmed way in goes through PHP's session upload progress feature.

Affected are PHP 8.2 before 8.2.35, 8.3 before 8.3.36, 8.4 before 8.4.27 and 8.5 before 8.5.12. The fixed versions came out the same day.

## Were we affected

Yes. Every app on trongate.cloud is built on the same base image, whatever its repository contains, and that image ran PHP 8.4.26. That covers every hosted app, whichever Trongate version or modules it uses: the bugs are in PHP itself, not in Trongate.

How exposed each part was:

- **Your apps.** Apps already ran without PHP's own memory limit, with the container's memory as the ceiling instead. That is the main setting PHP's advisory recommends against the first bug, so it was much harder to reach on apps, though the advisory says the setting doesn't close it completely. The second bug was open: session upload progress was on, as it is in PHP's standard production settings.
- **The trongate.cloud panel.** It ran with PHP's default memory limit and session upload progress on, so both bugs applied.
- **The build worker** runs PHP from the command line and serves no web requests, so neither of the request paths the advisories describe reaches it. It is updated all the same.

Everything else we run has no PHP in it and isn't affected: Kubernetes, the router in front of your app, certificates, the databases and the session store.

## What we changed

1. **October 21, the day the bugs were published:** session upload progress is off for every app and the panel, and the panel runs without PHP's memory limit, the same as apps. Both are the mitigations PHP's advisories give, and they cut off the confirmed paths of both bugs.
2. **Once the official PHP images had 8.4.27:** the base image of every app, the panel and the build worker moved to it, without waiting for a new FrankenPHP release. Each is pinned to an exact PHP version, so a build can't quietly fall back to an older one. Then we redeployed every app.

## What it means for your app

Your app runs on PHP 8.4.27 from its first deploy after the update, and we redeployed every app for you. An app that was asleep wakes up on the new image.

Session upload progress stays off. Trongate doesn't use it. If your app reads `$_SESSION` upload progress keys to show an upload bar, tell us and we'll talk it through.

## Under the hood

Apps run on FrankenPHP in classic mode, which PHP's advisory names as likely exposed to the first bug, in the same way as Apache's mod_php. FrankenPHP's own images are the official `php:8.4-zts` image with the `frankenphp` binary on top, and that binary loads PHP when it starts. Its 8.4.27 build wasn't out yet, so we put the FrankenPHP 1.12.7 binary onto `php:8.4.27-zts` ourselves. The build worker went from `php:8.4-cli` to `php:8.4.27-cli`. The cluster components we checked and found free of PHP are Kubernetes itself, Traefik, cert-manager, Flux, Sablier, MariaDB, Valkey and JuiceFS.

PHP's advisories: [CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and [CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).
