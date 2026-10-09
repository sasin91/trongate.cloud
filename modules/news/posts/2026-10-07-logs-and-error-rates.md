---
title: Logs, and an error count you can glance at
kind: changelog
summary: A Logs tab shows what your running app writes, and the app page now counts errors and warnings per hour.
---
## Logs tab

Each app has a **Logs** tab with the latest output of the running app. It refreshes every few seconds while you watch, marks each line's level and can hide everything except warnings and errors.

**Copy last error** puts the most recent error, with its stack trace, on your clipboard. Paste it into an issue or into your assistant of choice.

## Errors at a glance

The app page has a new **Errors** card: errors and warnings per hour over the last 24 hours. A spike after a deploy is easy to spot, and the Logs tab is one click away.

Counting never wakes a sleeping app. An app that is asleep is not writing anything, so there is nothing to miss.
