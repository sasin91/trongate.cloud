---
title: A Database tab for every app
kind: changelog
summary: Browse and edit your app's tables, run SQL, and restore a backup taken before every change, without a database client.
---
Every app comes with a MariaDB database. Now you can work with it from the panel.

## Browse and edit

The **Database** tab lists your tables. Open one to page through its rows, edit a row, add one or delete one. Foreign keys are links: click one to jump to the row it points at.

## Run SQL

The SQL box runs queries as your app's own database user, so it can do exactly what your app can do and nothing more. It is read-only until you tick **Allow changes**. Several statements run in one transaction: if one fails, the rest are rolled back.

This is also how you set up tables for a new module. Deploys never change your tables, so run the module's `.sql` file here.

## Backups before changes

**Back up first** is on by default. Before a change runs, we save what it touches: the row you edit or delete, or the tables a statement writes to. The Backups list keeps them for seven days, and **Restore** puts things back the way they were.

Every query and edit is logged under **Recent activity**.
