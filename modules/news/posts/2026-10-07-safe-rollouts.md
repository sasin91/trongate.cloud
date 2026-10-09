---
title: Failed deploys undo themselves
kind: changelog
summary: A new build only takes traffic once your app answers, and if a deploy fails its check, the last good build comes back by itself.
---
Two changes make a deploy safe to walk away from.

## Nothing goes live untested

When a new build starts, your current version keeps serving. The new one only takes traffic once its home page answers. If it never does, your visitors never see it.

## A smoke test after every deploy

Once the new version is live, we load your app's address the way a visitor would and expect a real page back from your app. If the check fails, we put the last passing deploy back automatically. Nobody has to wake up for it.

Rollbacks get the same check. You can still roll back to any earlier build by hand, in one click and without a rebuild.
