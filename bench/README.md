# Benchmarks

Hello-world benchmarks in the style of https://trongate.io/benchmarks, run against the
runtime customer apps get: FrankenPHP with Railpack's php.ini and Caddyfile.

- `Dockerfile` builds stock Trongate v2 plus a `bench` module (`app/Bench.php`): `/bench` prints
  Hello World through Trongate's full bootstrap, `/raw.php` is the plain-PHP baseline.
  `--build-arg SESSION_START=lazy` swaps in `app/ignition.lazy.php`, which starts a session only
  for visitors that already have a session cookie or write to `$_SESSION`.
- `session-tests.sh` checks session behaviour (cookies, flashdata across a redirect, CSRF, late
  writes, no leaked locks) against an app on :8080.
- `users.lua` makes wrk act as 1000 returning visitors, each with their own session cookie.
- `push-images.sh` builds and pushes the app and wrk images; `cluster-run.sh` runs the whole set in
  a throwaway `bench` namespace (own Valkey, wrk on a different node than the app) and deletes it.
  Results go to `results/` (git-ignored).
