# Deploy — groups.performantlabs.com

How a merge to `main` reaches the live demo site, and how an operator can
force a redeploy out of cycle.

Related: [`rollback.md`](rollback.md), [`health-checks.md`](health-checks.md),
[`secrets.md`](secrets.md). Higher-level architecture: [`../INSTALLATION_INSTRUCTIONS.md`](../INSTALLATION_INSTRUCTIONS.md).

## 1. Topology

```
GitHub push to main
      │
      ▼
.github/workflows/build.yml           (self-hosted runner or ubuntu-latest)
      │  docker build → docker push
      ▼
ghcr.io/performant-labs/groups-on-d11:latest
      │
      ▼  (manual redeploy in Coolify, or Coolify API call)
Coolify on Uranus (100.66.126.125 via Tailscale)
      │  application uuid rt7xfshm01tvw4locfxb8f6t
      ▼
Container: rt7xfshm01tvw4locfxb8f6t-<build-number>
      │  (attached to docker network `coolify`)
      ├─ Traefik → https://groups.performantlabs.com  (Let's Encrypt)
      └─ MariaDB container ypgqgn9pnaxj1q93rigs878t   (database uuid, name groups-on-d11-db)
```

The container inside Coolify runs the image's own nginx + php-fpm on port 8080.
Traefik on the `coolify` docker network fronts it with TLS. There is **no**
host-nginx or DDEV involved in the Coolify path.

## 2. Coolify app configuration

Values captured from the live container on 2026-07-24. Source of truth is
Coolify's UI, not this document — if these drift, Coolify wins.

| Setting | Value |
|---|---|
| Coolify project | `uranus` |
| Coolify environment | `production` |
| Application name | `groups-on-d11` |
| Application UUID | `rt7xfshm01tvw4locfxb8f6t` |
| Application ID | `9` |
| Container name (currently) | `rt7xfshm01tvw4locfxb8f6t-170440508175` |
| Image | `ghcr.io/performant-labs/groups-on-d11:latest` |
| Internal port | `8080` |
| Public URL | `https://groups.performantlabs.com` |
| TLS resolver | Traefik + Let's Encrypt (`certresolver=letsencrypt`) |
| Docker network | `coolify` (external, shared with other Uranus apps) |
| Coolify compose file on disk | `/data/coolify/applications/rt7xfshm01tvw4locfxb8f6t/docker-compose.yaml` |
| Env file on disk | `/data/coolify/applications/rt7xfshm01tvw4locfxb8f6t/.env` |
| Restart policy | `unless-stopped` |
| Mem / CPU limits | Unset (`0`) |

Companion database (separate Coolify **database** resource, not part of the
app compose):

| Setting | Value |
|---|---|
| Database name in Coolify | `groups-on-d11-db` |
| Database UUID | `ypgqgn9pnaxj1q93rigs878t` |
| Database ID | `1` |
| Image | `mariadb:11` |
| Container name | `ypgqgn9pnaxj1q93rigs878t` |
| Database schema | `groups_on_d11` |
| Volume | `mariadb-data-ypgqgn9pnaxj1q93rigs878t` |
| Compose file on disk | `/data/coolify/databases/ypgqgn9pnaxj1q93rigs878t/docker-compose.yml` |

## 3. Environment variables

Set in the Coolify UI (Application → Environment). Coolify materialises them
into `/data/coolify/applications/rt7xfshm01tvw4locfxb8f6t/.env`, mounted as
`env_file` in the generated compose. Verified from a live `docker inspect`
2026-07-24:

| Key | Value / shape | Source |
|---|---|---|
| `MYSQL_HOST` | `ypgqgn9pnaxj1q93rigs878t` (the DB container's service alias on the `coolify` network) | Coolify — must match the database resource UUID |
| `MYSQL_DATABASE` | `groups_on_d11` | Coolify — must match the database's `MARIADB_DATABASE` |
| `MYSQL_USER` | `drupal` | Coolify — must match `MARIADB_USER` on the DB |
| `MYSQL_PASSWORD` | 64-char random | 1Password (see [`secrets.md`](secrets.md)); must match `MARIADB_PASSWORD` on the DB |
| `DRUPAL_HASH_SALT` | 64-hex random | 1Password (see [`secrets.md`](secrets.md)) |
| `DRUPAL_ADMIN_PASS` | four-word passphrase | 1Password (see [`secrets.md`](secrets.md)); consumed only on first-boot install |
| `PORT` | `8080` | Coolify autogenerates (`COOLIFY_FQDN`, `COOLIFY_URL`, etc. too — leave them) |

The container's `deploy/entrypoint.sh` reads `MYSQL_*` + `DRUPAL_HASH_SALT` at
boot to generate `settings.php`, and consumes `DRUPAL_ADMIN_PASS` only on a
fresh install (idempotent — safe to restart).

## 4. Build trigger

Every push to `main` runs `.github/workflows/build.yml`:

```yaml
on:
  push:
    branches: [main]
```

Steps: checkout → login to `ghcr.io` with `GITHUB_TOKEN` → buildx → push to
`ghcr.io/performant-labs/groups-on-d11:latest` (using GHA cache).

Runner selection: `runs-on: ${{ vars.CI_RUNNER || 'ubuntu-latest' }}` — the
repo variable `CI_RUNNER`, if set, routes to a self-hosted runner. Currently
unset in this repo (no repo variables defined), so builds run on
`ubuntu-latest`.

Build time: ~4–8 minutes cold, 1–2 minutes with layer cache.

Watch the build: <https://github.com/Performant-Labs/groups-on-d11/actions>.

## 5. Deploy trigger

**There is no GitHub → Coolify webhook wired up.** (`gh api repos/Performant-Labs/groups-on-d11/hooks`
returns `[]`.) A merge to `main` publishes a new `:latest` image but does
**not** automatically redeploy on Uranus. Redeploys are operator-initiated.

Three options, in order of preference:

### 5a — Coolify UI (recommended for humans)

1. Open Coolify → project **uranus** → **production** → application **groups-on-d11**.
2. Click **Redeploy** (or **Force Redeploy** to bypass the image-digest cache).
3. Watch the deployment log stream in the UI until it turns green.

Coolify pulls `ghcr.io/performant-labs/groups-on-d11:latest`, stops the
running container, and starts a new one with the same env + labels. The
`unless-stopped` restart policy + Traefik keep the URL live through the swap
(~5–15 s cutover).

### 5b — Coolify API (recommended for automation)

```bash
COOLIFY_TOKEN=$(op read "op://Security/<coolify-api-token-item>/credential")
curl -sX GET "https://coolify.performantlabs.com/api/v1/applications/rt7xfshm01tvw4locfxb8f6t/restart" \
  -H "Authorization: Bearer $COOLIFY_TOKEN"
```

TODO: verify with operator — the exact Coolify API base URL (host / port) and
the 1Password item name that holds the API token. The pattern matches how
`personal-dashboard` triggers redeploys (see
`docs/playbook/agent/troubleshooting.md`, the `personal-dashboard` note under
Coolify redeploy).

### 5c — SSH fallback (only if Coolify UI + API are down)

```bash
ssh aangel@100.66.126.125
cd /data/coolify/applications/rt7xfshm01tvw4locfxb8f6t
sudo docker compose pull
sudo docker compose up -d --force-recreate
```

**Caveat:** editing the compose file here by hand does **not** survive a
Coolify redeploy — Coolify regenerates it from its DB. Use only for the pull-
and-recreate case above, then repair in the Coolify UI at the earliest
opportunity.

## 6. First-boot vs. subsequent-boot behaviour

`deploy/entrypoint.sh`:

- Always regenerates `settings.php` from `MYSQL_*` + `DRUPAL_HASH_SALT`.
- On a **fresh** database only, runs `drush site:install standard` →
  `drush config:import` → enables `do_*` modules → runs
  `docs/groups/scripts/step_700_demo_data.php` to seed drupal.org-themed demo
  content.
- On an **existing** database, skips install/seed. The container is safe to
  restart, and safe to point at a persistent volume.

## 7. Verifying a deploy

After redeploy, confirm the site is live before closing the incident window:

```bash
# From anywhere with internet:
curl -sI https://groups.performantlabs.com/ | head -5
# Expect: HTTP/2 200

# From Uranus, deeper checks:
ssh aangel@100.66.126.125
docker exec rt7xfshm01tvw4locfxb8f6t-<build> drush status
docker exec rt7xfshm01tvw4locfxb8f6t-<build> drush cr
```

The exact container name changes on every deploy (`-<build-number>` suffix).
Look it up with `docker ps --format '{{.Names}}' | grep rt7xfshm`.

Full health signals: [`health-checks.md`](health-checks.md).

## 8. Gaps / follow-ups

- TODO: verify with operator — is there a repo variable / label / GitHub
  Deployment that should auto-trigger Coolify on a `main` merge, or is
  manual-redeploy the intended cadence for this POC? (Currently manual.)
- TODO: verify with operator — the Coolify API host and the 1Password item
  holding the Coolify API token (§5b).
