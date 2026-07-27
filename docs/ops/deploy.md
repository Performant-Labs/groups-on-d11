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

**Preferred:** from a clean checkout of `main`, run

```bash
npm run app:prod:deploy
```

This runs [`scripts/ops/coolify-redeploy.sh`](../../scripts/ops/coolify-redeploy.sh),
which enforces three preflight guards (on `main`, clean working tree, not
behind `origin/main`), fetches the Coolify API token from 1Password at run
time (item **`coolio/coolify (PL.com)`** in the **`Security`** vault, ID
`k2xnfs4rjmldr77666gwuez3l4`, `notesPlain` field), POSTs to Coolify's
deploy endpoint, and prints the deployment UUID + a Coolify UI URL to
watch.

**Raw curl equivalent** (if you need to trigger a deploy from a machine
without this repo checked out):

```bash
COOLIFY_TOKEN=$(op read "op://Security/k2xnfs4rjmldr77666gwuez3l4/notesPlain" \
  | grep -oE '^[0-9]+\|[A-Za-z0-9]+' | head -n 1)
curl -sS -X POST \
  "https://coolify.performantlabs.com/api/v1/deploy?uuid=rt7xfshm01tvw4locfxb8f6t" \
  -H "Authorization: Bearer $COOLIFY_TOKEN" \
  -H 'Accept: application/json'
```

Endpoint is Coolify v4's official deploy trigger: `POST /api/v1/deploy?uuid={uuid}`.
(An earlier revision of this doc showed `GET /restart` — that path does not
exist in Coolify v4 and was never correct.)

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

## 7a. Newly-added modules on redeploys (#250 lesson)

`deploy/entrypoint.sh` runs `site:install` + `config:import` + `drush en`
**only on a fresh DB** (§6). On a redeploy against an *existing* DB, the
install/seed block is skipped — so a module added to the codebase after the
initial install is **not** automatically enabled by the redeploy alone.

As of #250 (2026-07-27) the entrypoint carries a second, always-on
`drush en` belt (§3b in the script) that runs on every container start and
enables the full set of custom demo modules. `drush en` is idempotent, so
this is a no-op on containers where the modules are already on, and it
enables newly-added modules on their first redeploy afterwards. If a new
`do_*` module is added:

1. Add it to **both** belts in `deploy/entrypoint.sh` (the fresh-install
   list and the always-on list — the Unit test
   `EntrypointModuleBeltTest::testEntrypointBeltCoversAllProdModules` in
   `do_ops` enforces this at CI time).
2. Redeploy the Coolify container (§5). The always-on belt will pick it up
   on the next boot without any manual `docker exec`.

**Manual recovery** (if a currently-running container is missing a module
and you cannot wait for a redeploy):

```bash
CONT=$(docker ps --format '{{.Names}}' | grep rt7xfshm)
docker exec "$CONT" drush en do_showcase -y  # or whichever module
docker exec "$CONT" drush cr
curl -sI https://groups.performantlabs.com/showcase | head -1  # expect 200
```

The #250 root cause was exactly this drift: `do_showcase` had been in the
codebase and enabled in CI for weeks, but the live container's DB
predated its addition, so the redeploy never enabled it and `/showcase`
returned 404 on prod while returning 200 everywhere else.

## 8. Gaps / follow-ups

- TODO: verify with operator — is there a repo variable / label / GitHub
  Deployment that should auto-trigger Coolify on a `main` merge, or is
  manual-redeploy the intended cadence for this POC? (Currently manual.)
- ~~TODO: verify with operator — the Coolify API host and the 1Password item
  holding the Coolify API token (§5b).~~ **Resolved #272 (2026-07-27):** host is
  `https://coolify.performantlabs.com`; token lives in 1Password Security
  vault item **`coolio/coolify (PL.com)`** (ID `k2xnfs4rjmldr77666gwuez3l4`),
  in the `notesPlain` field.
