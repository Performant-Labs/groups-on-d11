# 1Password — Agent Usage Guide

How agents read secrets from the Performant Labs 1Password account and the rules that prevent permission-prompt spam.

---

## Vault layout

| Vault | Purpose |
|-------|---------|
| **Security** | Production credentials — API tokens, app secrets, service keys |
| **Employee** | Personal/employee credentials |
| **Operations** | Infrastructure and ops credentials |
| **Shared** | Shared team credentials |

The `Security` vault is the standard location for production secrets used in deployments and automation scripts.

---

## Uranus headless auth (service account)

On **Uranus**, agents authenticate without user prompts:

```bash
set -a; source <(sudo cat /etc/op-service-account); set +a
```

Token file: `/etc/op-service-account` (`OP_SERVICE_ACCOUNT_TOKEN`, root-readable).  
**Do not ask the human for secrets on Uranus** — read/create items with `op` in one chained Bash call.

Helper scripts per service may wrap this (e.g. `/opt/compose/litellm/vault-to-1password.sh`).

---

## New service item template

When deploying a new Coolify service, create a **`<Service> Production`** item in the Security vault:

| Field label | Type | Purpose |
|-------------|------|---------|
| `litellm-master-key` / app-specific keys | password | Service admin or API keys |
| `postgres-password` | password | If the stack includes Postgres |
| `coolify-service-uuid` | text | Coolify resource UUID |
| `notesPlain` | notes | Immutability warnings, rotation notes |

Example — LiteLLM (already created):

```bash
set -a; source <(sudo cat /etc/op-service-account); set +a && \
/opt/compose/litellm/vault-to-1password.sh
```

---

## Key items in the Security vault

| Item | Field | Used for |
|------|-------|---------|
| `Coolify API Token` | `credential` | Coolify REST API (`http://100.66.126.125:8000/api/v1/…`) |
| `LiteLLM Production` | `litellm-master-key`, `litellm-salt-key`, `postgres-password`, `coolify-service-uuid` | LiteLLM proxy on Uranus (`cpjedj4g7asqcysohpcznzng`) |
| `Language Buddy Production` | various | App env vars: `better-auth-secret`, `database-url`, `public-url`, `almond-tts-*`, `openai-api-key`, `lb-initial-admin-*` |

> **Note:** The Coolify API Token item has a `notesPlain` field warning it was exposed on 2026-06-04 and needs rotation. Rotate before using in any new automation.

---

## Reading secrets

Use `op item get` with `--vault` and `--fields`:

```bash
# Read a single field
op item get "Coolify API Token" --vault Security --fields credential --reveal

# Read a named field from an app-secrets item
op item get "Language Buddy Production" --vault Security --fields label=database-url --reveal
```

The older `op read "op://VaultName/Item/field"` form **does not work** with vault names that contain spaces — use `op item get` instead.

---

## Gotcha: `op run` masks secrets on subprocess stdout

**`op run` redacts any resolved secret value that appears on the wrapped process's stdout/stderr**, replacing it with the literal string `<concealed by 1Password>`. This is a deliberate leak-prevention feature — but it silently corrupts any pattern that *captures* a secret back out of a subprocess.

The trap is container entrypoints that resolve `op://` references one at a time by reading them back through `printenv`:

```sh
# BROKEN — value becomes the literal string "<concealed by 1Password>"
value=$(op run --env-file=op.env -- printenv "$key")
export "${key}=${value}"
```

Because `printenv "$key"` prints the secret to stdout, `op run` masks it, and the masked placeholder is what gets exported. Every secret resolved this way ends up identical: `<concealed by 1Password>` (24 chars). The app starts fine, so the failure is silent until something *uses* the value — a login compares the typed password against `<concealed by 1Password>` and rejects it; an API client sends the placeholder as a bearer token; etc.

**Fix — pass `--no-masking` whenever you deliberately read a secret back out:**

```sh
value=$(op run --no-masking --env-file=op.env -- printenv "$key")
```

Better still, where the architecture allows, **inject directly into the process that consumes the secret** instead of capturing-then-exporting:

```sh
exec op run --env-file=op.env -- node dist/index.js   # no stdout capture, nothing to mask
```

(The capture-via-`printenv` pattern exists to avoid a long-running `op` parent leaving zombie children; `--no-masking` keeps that one-shot design while letting the real value through.)

### Detecting it on a live container

The masked value is invisible to `op`-aware tooling but plain in the kernel's view of the process env:

```sh
pid=$(sudo docker inspect -f '{{.State.Pid}}' <container>)
sudo sh -c "tr '\0' '\n' < /proc/$pid/environ | grep -c 'concealed by 1Password'"
# >0  => secret injection is broken (masking leaked into the values)
#  0  => secrets resolved correctly
```

To verify a specific secret without exposing it, compare hashes — e.g. `printf 'pd:%s' "$value" | sha256sum` against the hash of the known-correct secret.

> **Incident (2026-06-19):** `personal-dashboard` shipped this exact bug — login rejected the correct password for ~13 days because `DASHBOARD_PASSWORD` (and `GITHUB_TOKEN`, `MM_API_TOKEN`) all resolved to `<concealed by 1Password>`. While broken, the empty/garbage password also tripped the app's `if (!PASSWORD) return true` auth bypass, leaving it effectively unauthenticated. Fixed by adding `--no-masking` to the entrypoint.

---

## The one-Bash-call rule

**Every `op` call triggers a permission prompt.** Calling it in N separate Bash tool invocations = N prompts the user must dismiss.

**Always** read the secret once and reuse it within a single chained Bash call:

```bash
# CORRECT — one Bash call, one prompt
TOKEN=$(op item get "Coolify API Token" --vault Security --fields credential --reveal 2>/dev/null) && \
curl -sSL -H "Authorization: Bearer ${TOKEN}" "http://100.66.126.125:8000/api/v1/applications" | jq '.[].name' && \
curl -sSL -w "\nHTTP %{http_code}" -X PATCH \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"git_branch":"main"}' \
  "http://100.66.126.125:8000/api/v1/applications/UUID" && \
curl -sSL -X POST \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{"uuid":"UUID"}' \
  "http://100.66.126.125:8000/api/v1/deploy"
```

**Never** split into separate Bash calls:
```bash
# WRONG — each call is a new shell; variable is gone AND a new prompt fires each time
TOKEN=$(op item get ...)          # prompt 1
curl ... $TOKEN                   # prompt 2 (TOKEN is empty — new shell)
curl ... $TOKEN                   # prompt 3
```

If an operation requires a delay (e.g. polling), use `sleep` inside the same Bash call:

```bash
TOKEN=$(op item get "Coolify API Token" --vault Security --fields credential --reveal 2>/dev/null) && \
curl -X POST ... "$TOKEN" .../deploy && \
for i in 1 2 3 4 5; do
  sleep 30
  STATUS=$(curl -sSL -H "Authorization: Bearer $TOKEN" ".../deployments/UUID" | jq -r '.status')
  echo "$STATUS"
  [ "$STATUS" = "finished" ] || [ "$STATUS" = "failed" ] && break
done
```

---

## Coolify API quick reference

Full reference: [`../infrastructure/coolify-api.md`](../infrastructure/coolify-api.md)

Base URL (Tailscale): `http://100.66.126.125:8000/api/v1`  
On Uranus host: `http://localhost:8000/api/v1` (token in `/etc/coolify/api.env`)

### Git-built applications

| Action | Method | Path |
|--------|--------|------|
| List apps | GET | `/applications` |
| Get app | GET | `/applications/{uuid}` |
| Update app (branch etc.) | PATCH | `/applications/{uuid}` |
| Deploy (rebuild from source) | POST | `/deploy` with body `{"uuid":"…"}` |
| Restart container (no rebuild) | POST | `/applications/{uuid}/restart` |
| Bulk set env vars | POST | `/applications/{uuid}/envs/bulk` |
| Check deployment status | GET | `/deployments/{deployment_uuid}` |

### Docker Compose services (e.g. LiteLLM, Mattermost)

| Action | Method | Path |
|--------|--------|------|
| Create service | POST | `/services` (body includes base64 `docker_compose_raw`) |
| Get service | GET | `/services/{uuid}` |
| Update compose | PATCH | `/services/{uuid}` |
| Start / stop / restart | GET | `/services/{uuid}/start` etc. |
| Create env var | POST | `/services/{uuid}/envs` |
| Update env var | PATCH | `/services/{uuid}/envs` |
| List env vars | GET | `/services/{uuid}/envs` |

> **`/deploy` vs `/restart`:** `/deploy` triggers a full rebuild from source (what you want after a `git push`). `/restart` just restarts the running container — it does **not** pull new code.

> **Env vars:** Values are Laravel-encrypted by Coolify. Never write directly to the `environment_variables` table in the Coolify DB — always use the API. Direct SQL writes corrupt the encryption and break all subsequent API calls for that app.

---

## Language Buddy app

| Property | Value |
|----------|-------|
| UUID | `l10re4pnubdpbzxm06yi7kla` |
| Name | `language-buddy` |
| FQDN | `https://language-buddy.performantlabs.com` |
| Branch | `main` |
| Health | `GET /health` → `{"status":"ok","bootState":"ready","dbReady":true}` |
