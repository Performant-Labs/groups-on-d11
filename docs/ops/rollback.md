# Rollback — groups.performantlabs.com

Revert the live site to a previous image when a deploy is broken. Recover
the database from backup when the schema itself is bad.

Related: [`deploy.md`](deploy.md), [`health-checks.md`](health-checks.md).

## 1. When to roll back vs. roll forward

- **HTTP 5xx or install-page loop on `/`** after a deploy → roll back the
  image (fast; §3–§5). File a follow-up to root-cause on `main` afterwards.
- **Data corruption / bad config import** → rolling back the image alone
  does **not** revert the database. Restore the DB volume too (§6).
- **Cosmetic / partial-feature bug** → prefer roll-forward with a hot-fix
  PR. Rollback churns the container and burns the demo-seed reset window on
  a fresh install; not worth it for a soft failure.

## 2. Identify the previous good image

The build pipeline pushes only the moving `:latest` tag to
`ghcr.io/performant-labs/groups-on-d11`. To find a specific prior good
version you need to look at the image **digest** on Uranus and cross-reference
GHCR.

### 2a — From Uranus

```bash
ssh aangel@100.66.126.125

# The currently running image + digest
docker inspect $(docker ps -q --filter name=rt7xfshm) \
  --format '{{.Image}} {{index .RepoDigests 0}}'

# Local image history (recent pulls)
docker images --digests ghcr.io/performant-labs/groups-on-d11 | head
```

### 2b — From GHCR

Browse published versions:
<https://github.com/orgs/Performant-Labs/packages/container/groups-on-d11/versions>.

Each version row lists the digest (`sha256:...`) and the commit SHA it was
built from. Pick a version that predates the bad deploy and copy the digest.

### 2c — Correlate to a commit

The build workflow tags only `:latest`, so the durable link back to source is
via the image config label `org.opencontainers.image.revision` (populated by
`docker/build-push-action@v5`):

```bash
docker inspect ghcr.io/performant-labs/groups-on-d11@sha256:<digest> \
  --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
```

TODO: verify with operator — confirm this label is actually populated on
current images; if not, fall back to matching by push timestamp against
`git log --pretty="%H %ci" origin/main` for the window when the bad deploy
went out.

## 3. Roll back via Coolify UI (recommended)

1. Coolify → project **uranus** → **production** → application **groups-on-d11** → **Configuration → General**.
2. Change the image from `ghcr.io/performant-labs/groups-on-d11:latest` to
   `ghcr.io/performant-labs/groups-on-d11@sha256:<good-digest>`.
3. **Save**, then **Redeploy** (not "Force Redeploy" — we want the pinned
   digest, not a re-pull of `:latest`).
4. Verify per §7.
5. Once the fix is on `main` and a new `:latest` has built + passed
   verification, revert the image string back to
   `ghcr.io/performant-labs/groups-on-d11:latest` and redeploy.

Pinning by digest avoids the classic "`:latest` moved under us" trap — the
container that comes up is guaranteed to be the exact bits you named.

## 4. Roll back via Coolify API

```bash
COOLIFY_TOKEN=$(op read "op://Security/<coolify-api-token-item>/credential")
UUID=rt7xfshm01tvw4locfxb8f6t
DIGEST=sha256:<good-digest>

# Update image
curl -sX PATCH "https://coolify.performantlabs.com/api/v1/applications/$UUID" \
  -H "Authorization: Bearer $COOLIFY_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"docker_registry_image_name\":\"ghcr.io/performant-labs/groups-on-d11\",\"docker_registry_image_tag\":\"$DIGEST\"}"

# Redeploy
curl -sX GET "https://coolify.performantlabs.com/api/v1/applications/$UUID/restart" \
  -H "Authorization: Bearer $COOLIFY_TOKEN"
```

TODO: verify with operator — the exact field names on Coolify v4's application
update endpoint (`docker_registry_image_name` / `docker_registry_image_tag`
vs. a single combined field). The UI path in §3 is the safer bet if unsure.

## 5. Emergency SSH rollback (Coolify UI + API both unavailable)

```bash
ssh aangel@100.66.126.125
cd /data/coolify/applications/rt7xfshm01tvw4locfxb8f6t

# Pull the pinned digest
sudo docker pull ghcr.io/performant-labs/groups-on-d11@sha256:<good-digest>

# Edit docker-compose.yaml — swap the `image:` line for the pinned digest
sudoedit docker-compose.yaml

# Recreate
sudo docker compose up -d --force-recreate
```

**Warning:** hand-edits to this compose file are transient. Coolify
regenerates the file from its database on the next UI-driven redeploy, which
will silently restore `:latest`. Repair in the Coolify UI (§3) as soon as
the UI is available again.

## 6. Database rollback

The DB is a separate Coolify database resource (`groups-on-d11-db`, UUID
`ypgqgn9pnaxj1q93rigs878t`), image `mariadb:11`, backed by named volume
`mariadb-data-ypgqgn9pnaxj1q93rigs878t`.

### 6a — If Coolify's built-in backups are enabled

TODO: verify with operator — check Coolify → project **uranus** → **production**
→ database **groups-on-d11-db** → **Backups**. If a schedule + retention is
set, restore from there per the Coolify docs. Confirm cadence + retention
window in [`secrets.md`](secrets.md) §"Backups" (currently unknown).

### 6b — Manual snapshot / restore

Before the risky operation:

```bash
ssh aangel@100.66.126.125
docker exec ypgqgn9pnaxj1q93rigs878t sh -c \
  'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" groups_on_d11' \
  > /tmp/groups_on_d11_$(date +%Y%m%d_%H%M).sql
```

Restore:

```bash
ssh aangel@100.66.126.125
cat /tmp/groups_on_d11_YYYYMMDD_HHMM.sql | \
  docker exec -i ypgqgn9pnaxj1q93rigs878t sh -c \
    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" groups_on_d11'
docker exec $(docker ps -q --filter name=rt7xfshm) drush cr
```

### 6c — Nuclear option: reseed from scratch

`deploy/entrypoint.sh` re-seeds the drupal.org demo data on any container
boot that finds an **empty** database. To use it:

```bash
ssh aangel@100.66.126.125
# Stop app, drop DB volume, restart both
docker stop $(docker ps -q --filter name=rt7xfshm)
docker exec ypgqgn9pnaxj1q93rigs878t sh -c \
  'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE groups_on_d11; CREATE DATABASE groups_on_d11;"'
# Redeploy the app in Coolify — entrypoint will re-install + reseed.
```

Loses all runtime state (test users, comments, etc.). Fine for a POC demo;
never for production data.

## 7. Verify the rollback

```bash
curl -sI https://groups.performantlabs.com/ | head -5
# Expect: HTTP/2 200

ssh aangel@100.66.126.125
docker exec $(docker ps -q --filter name=rt7xfshm) drush status | \
  grep -E 'Drupal|Database|Site'
# Expect: bootstrap: Successful, DB connection: Connected
```

Full signals: [`health-checks.md`](health-checks.md).

If the site is still broken after rollback, do **not** loop — escalate. A
rollback that doesn't fix things means the failure isn't in the image
(likely DB, TLS, Traefik, or upstream DNS).

## 8. Post-rollback

1. Open a follow-up issue on `main` with the failing digest + symptoms.
2. Do **not** delete the bad `:latest` image from GHCR — it's the only
   forensic evidence of what shipped.
3. Confirm the rollback digest is pinned in Coolify (don't leave it on
   `:latest` while the fix is being written — the next `main` merge would
   silently overwrite the pin).
