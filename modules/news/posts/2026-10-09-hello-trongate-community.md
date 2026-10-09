---
title: Hello, Trongate community
kind: writing
summary: Why trongate.cloud exists, what it is today, and what we will write about here.
---
Some of you found trongate.cloud before we said much about it. Welcome. This page is where we will say more.

## What it is

trongate.cloud hosts apps built with the Trongate PHP framework. You connect GitHub, pick a repository and a name, and every push after that goes live by itself, on HTTPS, with a database.

It is an independent service. It is not made by, affiliated with or endorsed by the Trongate project. We build it because we use Trongate ourselves and wanted a place to run it that takes the framework's priorities seriously.

## What we care about

1. **Simple, stable, and it scales with you.** No servers to run, nothing to configure. A failed deploy puts the last good build back by itself.
2. **Trongate first.** The platform knows what a Trongate app looks like: its `config/` files, its `ENV` setting, the `.sql` file that comes with a module. Things that are generic elsewhere are specific here.
3. **We run on it.** trongate.cloud's own control panel is a Trongate app, deployed by trongate.cloud on every push. If something breaks, we feel it first.

Trongate's author has written about why [stability matters more than features](https://trongate.io/essays/trongates-new-versioning-system). We agree, and the platform is built to match: we build exactly what you pushed, on the Trongate version you chose.

## What you will find here

- **Changelog** entries when something changes for you, written so you can tell in a few lines whether it matters.
- **Writing** about what we learn running Trongate apps on Kubernetes: what broke, what we measured and what we changed.

trongate.cloud is free while we are in early access. If you build with Trongate, [deploy your first app](/register) and tell us what gets in your way.
