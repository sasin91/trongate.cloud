---
title: Getting started
summary: Put a Trongate app from GitHub online, and what happens on every push after that.
order: 10
---

## What you need

- A Trongate v1 or v2 app in a GitHub repository. Private repositories work.
- A GitHub account to sign in with.

You don't need Docker or a Dockerfile. We build the container image from your repository for you.

## Put your app online

1. [Start free](/register) and connect GitHub.
2. Pick the repository and the branch that should go live.
3. Name the app. The name becomes its address, like `name.trongate.dev`.
4. Push. Each push builds, passes a health check and goes live.

Your app gets a MariaDB database of its own, with the credentials already written into its config.

## Every push after that

A new build only takes traffic once your app answers. Until then, the last good build keeps serving. If a new build fails its check, the last good build comes back by itself.

Every good build is kept, so you can roll back to an earlier one in one click from the app's page, without rebuilding.

## Your own domain

Add the domain on the app's Domain tab and create a CNAME record pointing at `trongate.dev`. We check the DNS and switch the domain on with a certificate as soon as it resolves.

## Quiet apps sleep

Apps sleep after 15 minutes without visitors and wake on the next request. Keep production always on from the app's settings.
