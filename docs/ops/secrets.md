# Secrets — groups.performantlabs.com

Inventory of every secret in the deploy path, where it lives, and how to
rotate it without downtime.

Related: [`deploy.md`](deploy.md), [`rollback.md`](rollback.md).

## Principles

- **1Password `Security` vault is the source of truth.** Coolify and GitHub
  are consumers — updating them without updating 1Password creates drift no
  one can debug six months later.
- **Read via the `General Services Service Account (PL.com)` service
  account.** Its token is in the workstation user env (`OP_SERVICE_ACCOUNT_TOKEN`).
  No desktop prompts, scope-limited to `Security`. See
  `~/.claude/CLAUDE.md` for details.
- **Never print a secret to a terminal, chat log, or PR body.** Pipe values
  directly to the consumer (`op read ... | curl --data-binary @-`, etc.).
- **Rotate on a real trigger, not on a timer.** Compromise, staff change,
  service migration — yes. "It's been a year" — no; churn breaks more than
  it protects for a POC of this size.

## Inventory

| Secret | Where used | Storage of record | Consumers to update on rotation |
|---|---|---|---|
| `MYSQL_PASSWORD` / `MARIADB_PASSWORD` | Drupal ↔ MariaDB auth | 1Password `Security` (TODO: verify item name) | Coolify app env `MYSQL_PASSWORD` + Coolify DB env `MARIADB_PASSWORD` (must match) |
| `MARIADB_ROOT_PASSWORD` | DB admin, backups, manual restore | 1Password `Security` (TODO: verify item name) | Coolify DB env `MARIADB_ROOT_PASSWORD` |
| `DRUPAL_HASH_SALT` | Drupal session / one-time-login token signing | 1Password `Security` (TODO: verify item name) | Coolify app env `DRUPAL_HASH_SALT` |
| `DRUPAL_ADMIN_PASS` | admin user on **fresh install only** | 1Password `Security` (TODO: verify item name) | Coolify app env `DRUPAL_ADMIN_PASS` — plus `drush upwd admin ...` on the live site if the running install predates the rotation |
| Coolify API token | `deploy.md` §5b, `rollback.md` §4 | 1Password `Security` (TODO: verify item name) | Any automation that calls Coolify (currently: none in this repo — GH Actions does not deploy) |
| GHCR pull auth | Coolify pulling `ghcr.io/performant-labs/groups-on-d11` | GHCR is **public** for this image — no pull auth needed today | n/a while public. If the image is made private, add a PAT with `read:packages` to Coolify → Sources → Docker Registries |
| `GITHUB_TOKEN` (build push to GHCR) | `.github/workflows/build.yml` | GitHub-managed, per-run ephemeral | Nothing to rotate — GitHub issues a fresh one per workflow run |
| Repo secret `HARBOR_USERNAME` | GH Actions (usage TBD) | GitHub repo secret + 1Password `Security` (TODO: verify item name) | GH repo secret |
| Repo secret `HARBOR_PASSWORD` | GH Actions (usage TBD) | GitHub repo secret + 1Password `Security` (TODO: verify item name) | GH repo secret |
| Repo secret `DEEPSEEK_API_KEY` | GH Actions (usage TBD) | GitHub repo secret + 1Password `Security` (TODO: verify item name) | GH repo secret |
| Self-hosted GH runner registration token | Registering a new self-hosted runner (opt-in via repo var `CI_RUNNER`) | Ephemeral — generated on demand from GitHub Settings → Actions → Runners | Runner install script only; token is single-use, no long-term storage |
| Let's Encrypt account key | Traefik TLS on `groups.performantlabs.com` | Managed by Traefik on Uranus, `/data/coolify/proxy/acme.json` | Managed automatically; do not rotate manually unless the file is compromised |

TODO: verify with operator — the exact 1Password item names for each row
above. On 2026-07-24 the `Security` vault was not enumerated during this
task (would raise a prompt outside the service-account's read path for
listing, per the standing 1Password rule). Fill these in during the next
rotation, or on next handoff.

## Rotation procedures

All rotations follow the same shape: **generate new → write to 1Password →
push to consumer(s) → verify → destroy old**. The DB password is the only
one where consumer order matters (the DB must accept the new password
before the app tries it, or the app 502s).

### R1 — `MYSQL_PASSWORD` (app ↔ DB shared secret)

Zero-downtime version (recommended):

```bash
# 1. Generate
NEW=$(openssl rand -base64 48 | tr -d '/+=' | head -c 64)

# 2. Store in 1Password (via service account)
# TODO: verify item name — command shown assumes item exists; use op item edit if it does.

# 3. Add a second grant on the DB (MariaDB allows multiple GRANTs per user):
ssh aangel@100.66.126.125
docker exec ypgqgn9pnaxj1q93rigs878t sh -c \
  "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e \"
    ALTER USER 'drupal'@'%' IDENTIFIED BY '$NEW';
    FLUSH PRIVILEGES;
  \""
# ^ ALTER USER replaces the password. The running app container is still
# using the *old* password on its existing connection pool, and its
# connections stay valid until they're closed — MariaDB doesn't kill
# authenticated sessions on a password change.

# 4. Update Coolify app env MYSQL_PASSWORD to $NEW (UI: Application →
#    Environment → edit) and update the DB resource's MARIADB_PASSWORD
#    to match.

# 5. Redeploy the app (Coolify UI → Redeploy). New container starts with
#    the new password, cutover ~5–15 s (see deploy.md §5).

# 6. Verify: docker exec <newCT> drush status | grep 'DB status'
#    Expect: Connected.

# 7. Delete the old value from 1Password (or note the rotation date on the
#    item history). Nothing further to do on MariaDB — the old password is
#    already gone from user table.
```

### R2 — `MARIADB_ROOT_PASSWORD`

Only used for admin / restore. Rotate opportunistically:

```bash
NEW=$(openssl rand -base64 48 | tr -d '/+=' | head -c 64)
ssh aangel@100.66.126.125
docker exec ypgqgn9pnaxj1q93rigs878t sh -c \
  "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e \"
    ALTER USER 'root'@'%' IDENTIFIED BY '$NEW';
    ALTER USER 'root'@'localhost' IDENTIFIED BY '$NEW';
    FLUSH PRIVILEGES;
  \""
# Update Coolify DB env MARIADB_ROOT_PASSWORD and update 1Password. No app
# restart needed — root is not used by the app.
```

### R3 — `DRUPAL_HASH_SALT`

Rotating invalidates every active session + every unclaimed
one-time-login link. Expected impact: users are logged out. No downtime.

```bash
NEW=$(openssl rand -hex 32)
# Update Coolify app env DRUPAL_HASH_SALT to $NEW, then Redeploy.
# Update 1Password.
```

### R4 — `DRUPAL_ADMIN_PASS`

Rotating the env var **does not** change the running admin password —
`entrypoint.sh` consumes it only on a fresh install. To actually rotate:

```bash
NEW="four random words joined by spaces"
# 1. Update 1Password + Coolify env (so a future reseed uses the new value).
# 2. Change the live admin user:
ssh aangel@100.66.126.125
docker exec $(docker ps -q --filter name=rt7xfshm) drush upwd admin "$NEW"
```

### R5 — Coolify API token

1. In Coolify → your profile → **API tokens** — revoke the old, create a
   new scoped to the same permissions.
2. Store in 1Password `Security` (TODO: verify item name), replacing the
   old value.
3. Redeploy nothing — the token is only used by out-of-band automation.

### R6 — GH self-hosted runner registration token

Registration tokens are **single-use, ~1 hour TTL**, generated on demand:

1. GitHub → repo Settings → Actions → Runners → **New self-hosted runner**.
2. Copy the `./config.sh --token ...` line, run it on the runner host.
3. Nothing to store — the token is consumed by `config.sh` and becomes
   worthless. What persists on the runner is a *runner credential* in
   `.credentials`, tied to that specific runner instance; back that up with
   the runner host's normal disk backup, not in 1Password.

Deregister a runner: GitHub UI **Remove** button, or on the host
`./config.sh remove --token <fresh-registration-token>`.

### R7 — GHCR pull auth (only if image is made private)

The image is public today (§ Inventory). If made private:

1. Create a fine-grained PAT: user profile → Developer settings → Personal
   access tokens → Fine-grained → scope: `read:packages` on
   `Performant-Labs/groups-on-d11`, expiry: 90 days.
2. Store in 1Password `Security` (TODO: verify item name).
3. Coolify → **Sources** → **Docker Registries** → add `ghcr.io` with the
   PAT as password.
4. Redeploy the app so the pull uses the new credential.

### R8 — Repo secrets (`HARBOR_*`, `DEEPSEEK_API_KEY`)

For each secret listed in the inventory:

```bash
NEW=<generated at the source of that secret — Harbor UI, DeepSeek dashboard, etc.>
# Store in 1Password `Security` first.
gh secret set HARBOR_PASSWORD --repo Performant-Labs/groups-on-d11 --body "$NEW"
# Revoke the old value at the source.
```

Note: `HARBOR_*` and `DEEPSEEK_API_KEY` are configured on this repo but
their consumers in this codebase are not obvious — no reference in the
current workflows or Dockerfile. TODO: verify with operator — confirm
whether they're still needed here, or leftovers that should be deleted.

## Backups

- **DB dumps:** TODO: verify with operator — is Coolify's built-in
  scheduled backup enabled for the `groups-on-d11-db` resource? Cadence?
  Retention? Off-host copy target? Currently undocumented; assume none
  until confirmed. See [`rollback.md`](rollback.md) §6 for manual dump
  syntax.
- **1Password:** covered by 1Password's own SaaS backups; no local backup
  needed.
- **Coolify config:** `/data/coolify/` on Uranus contains all generated
  compose files + env files. TODO: verify with operator — confirm this
  directory is included in Uranus's disk backup.

## After a rotation

- Update this file's inventory table with the 1Password item name if it
  was still `TODO: verify`.
- Note the rotation date + reason on the 1Password item (Notes field).
- Run the one-liner in [`health-checks.md`](health-checks.md) §10 and
  paste the output into the incident / rotation ticket.
