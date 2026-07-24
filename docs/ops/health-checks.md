# Health checks — groups.performantlabs.com

Signals to confirm the site is healthy after a deploy or during an
incident. Ordered from cheapest / most external to deepest / most invasive.

Related: [`deploy.md`](deploy.md), [`rollback.md`](rollback.md). A
first-class `/healthz` endpoint + Grafana dashboard are planned under epic
#216 story #213 (REL-4) — until that lands, use the manual checks below.

## 1. External HTTP

The single most important signal: can an anonymous browser reach the front
page?

```bash
curl -sI https://groups.performantlabs.com/ | head -5
```

Expected:

```
HTTP/2 200
server: nginx
x-drupal-cache: HIT | MISS
```

- **Anything other than 200** on `/` — degraded. Bad statuses observed
  historically: `302 → /core/install.php` (settings.php not pointing at a
  populated DB), `504` (Traefik couldn't reach the container — check
  `traefik.docker.network=coolify` label per
  `docs/playbook/agent/troubleshooting.md` U1), `502` (php-fpm crashed),
  `525` (Let's Encrypt cert didn't renew).
- `x-drupal-cache: MISS` on every hit — bootcache / dynamic-page-cache
  disabled or purged; check `drush cget system.performance`.

Also verify the HTTP → HTTPS redirect:

```bash
curl -sI http://groups.performantlabs.com/ | head -3
# Expect: 307 Temporary Redirect ; Location: https://groups.performantlabs.com/
```

## 2. TLS certificate

```bash
echo | openssl s_client -connect groups.performantlabs.com:443 -servername groups.performantlabs.com 2>/dev/null | \
  openssl x509 -noout -issuer -dates
```

Expected: `issuer=... Let's Encrypt`, `notAfter` at least **7 days** out.
Traefik renews automatically via `certresolver=letsencrypt`; alert threshold
is 7 days remaining.

## 3. Drupal bootstrap (inside the container)

```bash
ssh aangel@100.66.126.125
CT=$(docker ps -q --filter name=rt7xfshm)
docker exec $CT drush status
```

Expected key lines:

```
Drupal version   : 11.x.x
Site URI         : https://groups.performantlabs.com
DB driver        : mysql
DB hostname      : ypgqgn9pnaxj1q93rigs878t
DB status        : Connected
Bootstrap        : Successful
Default theme    : olivero  (or the configured demo theme)
```

Red flags:

- **`Bootstrap : Drupal not installed`** — the container is pointed at an
  empty / wrong DB. Check `MYSQL_*` env vs. the DB container's config
  ([`deploy.md`](deploy.md) §3).
- **`DB status : not connected`** — the DB container is down, or the two
  containers aren't on the same `coolify` network (see U1 in
  `docs/playbook/agent/troubleshooting.md`).

## 4. Database

```bash
ssh aangel@100.66.126.125
docker exec ypgqgn9pnaxj1q93rigs878t \
  sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "SHOW GLOBAL STATUS LIKE \"Uptime\"; SHOW GLOBAL STATUS LIKE \"Threads_connected\";"'
```

Expected: `Uptime` monotonically increasing between checks; `Threads_connected`
in the single or low double digits under normal demo load. A sudden `Uptime`
reset means the DB restarted — investigate `docker logs ypgqgn9pnaxj1q93rigs878t`.

Container-level healthcheck (defined in the DB compose) already runs every
5 s (`healthcheck.sh --connect --innodb_initialized`). Docker's own status
reflects it:

```bash
docker inspect ypgqgn9pnaxj1q93rigs878t --format '{{.State.Health.Status}}'
# Expect: healthy
```

## 5. Cache backend

Groups-on-d11 uses Drupal's default database-backed cache (no Redis /
Memcached is provisioned). "Cache backend responding" therefore reduces to
"the DB is reachable" (§3–§4) plus:

```bash
docker exec $CT drush cache:rebuild
# Expect: [success] Cache rebuild complete.
```

TODO: verify with operator — confirm no external cache (Redis/Memcached) is
in use for this deployment. If one is added later, extend this section.

## 6. Render times

Baseline from Uranus + Cloudflare, warm cache, unauthenticated:

- `/` (front page) — expect **< 400 ms** TTFB. Investigate if > 1 s.
- `/showcase` (tour page) — expect **< 500 ms** TTFB.
- Group detail pages — expect **< 600 ms** TTFB (Views + Group access
  checks).

Quick measurement:

```bash
for url in / /showcase; do
  echo -n "$url  "
  curl -so /dev/null -w 'HTTP %{http_code}  TTFB %{time_starttransfer}s  Total %{time_total}s\n' \
    "https://groups.performantlabs.com$url"
done
```

Sustained TTFB > 2 s → check php-fpm workers, DB slow-query log, and the
Drupal cache-hit rate (`x-drupal-cache` header from §1).

## 7. Container liveness

```bash
ssh aangel@100.66.126.125
docker ps --format '{{.Names}}\t{{.Status}}' | grep -E 'rt7xfshm|ypgqgn'
```

Both should be `Up <time> (healthy)` (DB) / `Up <time>` (app). The app
container has no Docker-level healthcheck configured today — its liveness
is inferred from §1 + §3.

TODO: verify with operator — add a lightweight healthcheck to the app
container (e.g. `curl -f localhost:8080/` or, once #213 lands, `/healthz`)
so Docker itself surfaces app failure without waiting for a human check.

## 8. Logs

```bash
ssh aangel@100.66.126.125
docker logs --tail 200 $(docker ps -q --filter name=rt7xfshm)
docker logs --tail 200 ypgqgn9pnaxj1q93rigs878t
```

Also relevant on Uranus:

- Traefik / Caddy edge logs — see `docs/playbook/agent/troubleshooting.md`
  §B for Coolify-side routing failures.
- Drupal watchdog: `docker exec $CT drush watchdog:show --count=50`.

## 9. Existing observability

- **Uptime monitoring:** TODO: verify with operator — is there an external
  monitor (UptimeRobot, Better Uptime, Cloudflare Health, etc.) hitting
  `/` on a schedule? Not documented as of 2026-07-24.
- **Metrics / dashboards:** none for this site yet. The `token-tracker`
  stack on Uranus (Grafana + Loki + Mimir + Tempo) is provisioned for LLM
  spend, not for groups-on-d11. Adding a groups dashboard is REL-4 (#213).
- **Alerting:** none. Failures are noticed by humans loading the page.

## 10. Quick "is everything OK?" one-liner

```bash
ssh aangel@100.66.126.125 '
  set -e
  CT=$(docker ps -q --filter name=rt7xfshm)
  echo "== HTTP =="; curl -sI https://groups.performantlabs.com/ | head -1
  echo "== App container =="; docker ps --format "{{.Names}}\t{{.Status}}" | grep -E "rt7xfshm|ypgqgn"
  echo "== DB health =="; docker inspect ypgqgn9pnaxj1q93rigs878t --format "{{.State.Health.Status}}"
  echo "== Drush =="; docker exec $CT drush status | grep -E "Drupal|Database|Bootstrap"
'
```

Any red line here → drop to the section covering that layer above.
