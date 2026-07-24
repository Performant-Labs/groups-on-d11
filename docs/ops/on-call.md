# On-call runbook — groups.performantlabs.com

Playbook for the human who gets pinged when the demo site or its build
pipeline breaks. Each section names the incident pattern, how we've seen
it, how to confirm it, and how to recover. The four patterns below are
the ones that have actually happened during this POC — not a theoretical
inventory.

Related: [`deploy.md`](deploy.md), [`rollback.md`](rollback.md),
[`health-checks.md`](health-checks.md), [`secrets.md`](secrets.md),
[`sla.md`](sla.md). Escalation triggers: [`sla.md`](sla.md) §3.

## 0. First 60 seconds

Before touching anything, capture the current state so the post-mortem
has evidence:

```bash
ssh aangel@100.66.126.125 '
  date -u
  docker ps --format "{{.Names}}\t{{.Status}}" | grep -E "rt7xfshm|ypgqgn"
  df -h /var/lib/docker /data
'
curl -sI https://groups.performantlabs.com/ | head -3
```

Then work the pattern-matched section below. If nothing matches, drop to
[`health-checks.md`](health-checks.md) §10 for the full one-liner and
walk layers top-down.

## 1. Uranus disk full

**Last seen:** 2026-07-24. Coolify deploys failed with `no space left on
device` during image pull; Docker daemon logs showed the same. Root
cause tracked in `Performant-Labs/uranus-infra` issue **#20**.

**Symptoms:**

- Coolify redeploy hangs or fails with `write /var/lib/docker/...: no
  space left on device`.
- `docker pull` on Uranus errors with the same.
- New containers refuse to start; existing containers keep serving until
  their next restart.

**Confirm:**

```bash
ssh aangel@100.66.126.125
df -h /var/lib/docker /data
docker system df
```

If `Use%` on `/var/lib/docker` (or the partition holding it) is > 90%,
this is the incident.

**Recover:**

```bash
ssh aangel@100.66.126.125

# 1. Reclaim unused volumes (safe — Coolify recreates on redeploy)
sudo docker volume prune -f

# 2. Reclaim unused images (dangling + untagged)
sudo docker image prune -a -f

# 3. Recheck
df -h /var/lib/docker
docker system df
```

Combined typically reclaims 5–20 GB on Uranus. If still tight, escalate
to uranus-infra #20 — the long-term fix is a bigger disk / retention
policy, not repeated pruning.

**After recovery:** retry the failed Coolify redeploy per
[`deploy.md`](deploy.md) §5a. Verify with
[`health-checks.md`](health-checks.md) §10.

## 2. Runner offline / all fleet down

**Symptoms:**

- GitHub Actions workflows queue but never start (jobs stuck in "Waiting
  for a runner to pick up this job").
- CI Actions tab shows all runners offline.
- `main` merges succeed in Git but produce no new `ghcr.io` image.

**Confirm:**

```bash
# Requires gh auth with repo/org access
gh api /orgs/Performant-Labs/actions/runners \
  --jq '.runners[] | {name, status, busy}'
```

Expect four runners (`runners-runner-1` through `runners-runner-4`), all
`status: online`. Any `status: offline` on the whole fleet = incident.

**Recover:**

The runners run as Docker containers on Uranus under Coolify. Restart
the fleet:

1. Coolify UI → project **uranus** → service **runners** (compose
   stack) → **Restart** for each of `runners-runner-1..4`.
2. Or SSH fallback:

   ```bash
   ssh aangel@100.66.126.125
   for i in 1 2 3 4; do
     sudo docker restart runners-runner-$i
   done
   ```

3. Reconfirm with the `gh api` command above — all four should return
   to `online` within ~30 seconds.

**If restart doesn't recover:** the runner registration tokens may have
been invalidated (rare — they're per-runner credentials, not the
one-shot registration token). Re-register per
[`secrets.md`](secrets.md) §R6.

**If only one runner is offline:** not an incident — the fleet
tolerates it. File a follow-up but don't page.

## 3. Main goes red post-merge

**Symptoms:**

- A merge to `main` fires `.github/workflows/build.yml` and the build
  fails (red X on the merge commit in the Actions tab).
- Or the build succeeds but the resulting `:latest` image, once
  redeployed, fails [`health-checks.md`](health-checks.md) §1 or §3.

**Confirm:**

```bash
gh run list --repo Performant-Labs/groups-on-d11 --branch main --limit 5
gh run view <run-id> --repo Performant-Labs/groups-on-d11 --log-failed
```

**Decide: revert or roll forward.**

- **Revert** when the failure is on `main`'s build itself (image never
  produced) or when the deployed container is broken and the fix isn't
  obvious in ≤ 15 minutes. Reverts keep `main` shippable.
- **Roll forward** when the fix is a one-liner and you can land it
  faster than a revert cycle.

**Revert path:**

```bash
git fetch origin main
git checkout main && git pull --ff-only
git revert --no-edit <bad-sha>
git push origin main
# The revert commit re-runs build.yml. Once green, redeploy per deploy.md §5.
```

**Roll-forward path:** normal PR with the fix, expedited review, merge,
redeploy.

**If the deployed container is broken** (not just the build): image
rollback is faster than either — see [`rollback.md`](rollback.md) §3
(Coolify UI) or §5 (SSH emergency). Pin the image by digest per
`rollback.md` §3 so the next `main` merge doesn't silently overwrite
the pin.

## 4. Secret expired

**Symptoms vary by which secret:**

- `MYSQL_PASSWORD` drift → app container logs `Access denied for user
  'drupal'@...`, `/` returns 502.
- `DRUPAL_HASH_SALT` change without redeploy → users report being
  logged out unexpectedly (not really an incident — informational).
- Coolify API token revoked → out-of-band redeploy automation 401s;
  UI still works.
- `GITHUB_TOKEN` / repo secrets (e.g. `HARBOR_*`, `DEEPSEEK_API_KEY`)
  → workflow steps that consume them fail with 401 / auth error.
- Let's Encrypt cert renewal failure → browser TLS warning, `curl`
  fails with cert expired.

**Confirm:**

```bash
# App-DB auth
ssh aangel@100.66.126.125
docker logs --tail 100 $(docker ps -q --filter name=rt7xfshm) | grep -Ei 'access denied|SQLSTATE|1045'

# TLS
echo | openssl s_client -connect groups.performantlabs.com:443 -servername groups.performantlabs.com 2>/dev/null \
  | openssl x509 -noout -dates
```

**Recover:** every rotation procedure is in [`secrets.md`](secrets.md)
§ Rotation procedures:

| Symptom | Rotation section |
|---|---|
| App ↔ DB auth broken | R1 — `MYSQL_PASSWORD` |
| Root DB access lost | R2 — `MARIADB_ROOT_PASSWORD` |
| Session-token issues | R3 — `DRUPAL_HASH_SALT` |
| Admin locked out on reseed | R4 — `DRUPAL_ADMIN_PASS` |
| Coolify automation 401 | R5 — Coolify API token |
| Runner registration | R6 — GH runner registration token |
| Private-image pull 401 | R7 — GHCR pull auth |
| GH Actions repo secret expired | R8 — repo secrets |
| TLS cert expired | Traefik auto-renews; if it failed, see [`health-checks.md`](health-checks.md) §2 and the TODO in [`secrets.md`](secrets.md) Inventory (`acme.json`). |

Follow the rotation, then verify with
[`health-checks.md`](health-checks.md) §10.

## 5. When in doubt

- [`health-checks.md`](health-checks.md) §10 — one-liner that surfaces
  which layer is failing.
- [`sla.md`](sla.md) §3 — is this actually alert-worthy or advisory?
- [`../TROUBLESHOOTING.md`](../../TROUBLESHOOTING.md) — general dev-env
  gotchas (mostly local, occasionally relevant).
- `docs/playbook/agent/troubleshooting.md` — Coolify-side routing
  failures (Traefik labels, network membership).

Never loop on the same recovery attempt more than twice. If the second
try doesn't fix it, the diagnosis is wrong — go up a layer.

## 6. History

- 2026-07-24 — #214 (REL-5) — initial runbook covering the four
  incident patterns observed during the POC.
