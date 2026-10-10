---
title: Changelog, October 10
kind: changelog
summary: Run queue workers and other background processes next to your app, and sessions that last a day instead of 24 minutes.
---
Two things today: your app can run background processes, and sessions last a lot longer.

## Background processes

Some work shouldn't wait on a web request: sending mail, resizing images, calling a slow API. Your app can now run commands for that next to it, such as a queue worker.

Add them on your app's Settings page under **Processes**. Each row is a name, a command and how many copies to run. A queue worker might be:

- Name: `worker`
- Command: `php bin/queue.php work`
- Replicas: `1`

A process runs from the same code, settings, environment variables and database as your app. Unlike the app itself, it never sleeps, and if the command exits it starts again. Copies run side by side, so the work they share has to be safe to run at the same time.

Processes start on your next deploy, and each deploy restarts them on the new code. Remove a row and the process stops on the next deploy. An app can have up to five processes with up to five copies each.

Every process shows up on the app's overview, next to the database and services, with what it is doing right now: running, crashing and why, or waiting for the next deploy. Its output is in the **Logs** tab with the app's own.

## Sessions that last a day

PHP sessions in your app now last a day without activity, instead of 24 minutes. Before, a Trongate admin login could end while you waited for a build. Your app picks this up on its next deploy.

The same goes for the trongate.cloud panel: you stay signed in for a day, and our own updates no longer sign you out.

## Smaller things

- trongate.cloud has clearer error pages of its own, and requests made with `fetch()` get the error as JSON.
- Deploys stalled for a few hours today while one of our updates kept the build worker from starting. That is fixed. If a push from today never deployed, press **Deploy** on the app.
