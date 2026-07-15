# Infra-Change Workflow — Runbook Pipeline (no IaC tool yet)

> **Purpose:** A seven-phase, human-gated pipeline for **infrastructure changes** to the
> Performant Labs estate (uranus / Hetzner, Coolify, Cloudflare, Pluto) when there is **no
> IaC tool** (no Terraform/Pulumi/Ansible) and the source of truth is the live dashboards
> and the running host. It mirrors the spine of the coding pipeline
> ([`workflow-ofats-dual-review.md`](workflow-ofats-dual-review.md)) — survey → spec →
> review gate → implement → verify → document — but adapted for mutations against live
> infrastructure where a mistake can be **irreversible** (a near-destructive Hetzner
> Rebuild, a detached IP, a resize without a disk grow, a snapshot used without validation).
>
> The **binding control is a single human approval gate (Phase 3) BEFORE any mutation**, and
> a **validated backup plus a written rollback are required before any destructive step**
> (Phase 4). Least privilege is enforced by **credential scoping**, not by the tool list:
> the read-only roles are given **read-only credentials only** (GET-scoped API tokens + a
> read-only/forced-command SSH key) and instructed to run no mutating command — only the
> Executor holds mutate-capable credentials. (Every role's agent allowlist includes `Bash`
> because the read-only roles need a shell for GET-style commands like `df -h`, `docker ps`,
> `dig`, and `hcloud … describe`; `Bash` is itself a universal mutate capability, so it is
> **not** what enforces the boundary — see [Roles](#roles).)

This doc follows the shared primitives in
[`pipeline-conventions.md`](pipeline-conventions.md): the per-run **decision journal**
format (§1), the **role-doc template** (§2), and the **gate mechanics** — Approval vs
Verification gates and the review-rigor dial (§3). Read that file first; this workflow
references it rather than restating it.

---

## When to use this pipeline

Use it for **any change that mutates live infrastructure**: provisioning/resizing/rebuilding
a Hetzner server, attaching/detaching a floating IP or volume, taking or restoring a
snapshot, editing a Coolify service (env, domain, compose, resources), deploying a manual
compose stack on uranus, changing Cloudflare DNS/SSL/WAF, or touching backups.

It is **not** for application code changes (use the coding pipeline) and **not** for pure
read-only investigation (just do Phase 1 and stop).

**Risk dial (per the conventions §3 review-rigor dial).** Every change declares a risk tier
in its change-doc; the tier sets the Phase 3 rigor:

| Risk tier | Examples | Phase 3 rigor |
|-----------|----------|---------------|
| **low** | add a DNS record, publish a static doc, add a non-privileged env var, bump a tag with a known-good rollback | **single human approval** (none dial) |
| **high / destructive** | server resize/rebuild, IP/volume detach, snapshot restore, deleting data, DB migration, opening a port to the public internet, granting broad access | human approval **+ second-opinion** review (a second reviewer on the spec + rollback) |

A change is **high/destructive** if it trips any of these red flags (taken straight from the
motivating failure): **over-permissioned access**, **public exposure** of something
previously private, **a missing or unvalidated backup**, or **irreversibility** (no clean
rollback). When in doubt, treat it as high.

---

## Roles

Defined with the role-doc template (conventions §2). Each role doc lives in `workflow/` and
is installed to `~/.claude/agents/` when running this pipeline.

**How least privilege is actually enforced — three controls, NOT the tool list.** Every
role's agent allowlist includes `Bash` (the read-only roles need a shell for their GET-style
commands — `df -h`, `docker ps`, `dig`, `hcloud … describe`, `curl` to read-only API
endpoints). Because `Bash` can also `ssh`, `curl -X POST`, `docker restart`, etc., the tool
list does **not** draw the read/write boundary. The boundary is enforced by:

1. **Explicit role instructions** — each read-only role's doc forbids running any mutating
   command and scopes it to GETs/reads.
2. **Credential scoping — the load-bearing technical control.** Non-Executor roles are given
   **only read-only credentials**: a Coolify GET-only API token, read-scoped Hetzner and
   Cloudflare tokens, and a read-only / forced-command SSH key. **Only the Executor holds
   mutate-capable credentials** (write API tokens + a write SSH key). A read-only role
   *cannot* mutate even if a command tried to, because its credentials can't authorize it.
3. **Optional defense-in-depth** — `Bash` permission deny-rules in the read-only roles'
   agent config to block obviously-mutating verbs (`-X POST|PUT|DELETE|PATCH`, `docker
   run|restart|rm`, `hcloud … create|delete|rebuild|attach|detach`).

> **Dependency (see risk note at end).** This guarantee **requires** each provider to issue a
> read-only credential. Coolify, Hetzner, and Cloudflare all support scoped/GET-only tokens
> and SSH supports forced-command/read-only keys. If a provider cannot issue a read-only
> credential, the boundary **degrades to procedural** (instructions + deny-rules only) for
> that provider and that must be called out in the change-doc.

| Role | Slug / doc | Credentials held | Responsibility |
|------|------------|------------------|----------------|
| **O — Orchestrator** | [`infra-orchestrator.md`](infra-orchestrator.md) | **None / read-only** — never mutates | Drives phases, owns the decision journal + Chain Summary, holds the gate, never mutates infra. |
| **Researcher** (state capture) | [`infra-researcher.md`](infra-researcher.md) | **Read-only only** — GET tokens + read-only SSH key | Phase 1: snapshot live state into the change-doc. |
| **Spec author** | [`infra-spec-author.md`](infra-spec-author.md) | **Read-only only** | Phase 2: write exact commands/API calls, blast radius, rollback plan. |
| **Reviewer** (pre-apply gate) | [`infra-reviewer.md`](infra-reviewer.md) | **Read-only only** | Phase 3: independent review of spec + rollback; recommends to the human; second opinion for high/destructive. |
| **Executor** | [`infra-executor.md`](infra-executor.md) | **MUTATE — the ONLY role with write credentials** | Phases 4–5: take/validate backup, run the change, capture output. Acts **only** after the human approval recorded in Phase 3. |
| **Verifier** (post-apply) | [`infra-verifier.md`](infra-verifier.md) | **Read-only only** — smoke tests | Phase 6: prove the change is live and nothing else broke. |

> `Bash` is present in every role's allowlist (read-only roles need a shell for GET commands);
> it is **not** the control that keeps a read-only role read-only — the read-only credentials
> are. See the three controls above.

The **human operator** is not a role doc — they are the approver at the Phase 3 gate and the
escalation target. Approval is **never inferred**; silence is not approval (conventions §3).

---

## The decision journal

Per conventions §1, every run keeps an append-only journal at
`changes/<change-slug>/decisions.md` in the **uranus-infra** repo (the change lives with the
infra it mutates, not in the playbook). Each phase appends a block with **Decided /
Assumed / Hedged / Evidence**, and O writes a closing **Chain Summary** (Outcome / Key
decisions / Open assumptions still unverified / Follow-ups). The change-doc itself
(`changes/<change-slug>.md`, from `changes/_TEMPLATE.md`) carries the state snapshot, spec,
blast radius, rollback, and verification checklist; `decisions.md` carries the running
rationale.

---

## Phases

Seven phases. Phase 3 is the **only binding human gate** and it is **before** any mutation.
Phases 4–6 are blocked until Phase 3 records an explicit human approval. A non-pass at a
verification gate (Phase 6) loops back per [Iteration & escalation](#iteration--escalation).

| Phase | Owner | Output | Gate |
|-------|-------|--------|------|
| **1 — State capture (read-only)** | Researcher | state snapshot in the change-doc | — |
| **2 — Change spec** | Spec author | what/why + exact commands + blast radius + rollback in the change-doc | — |
| **3 — Pre-apply review gate (HUMAN)** | Reviewer → **human** | recorded approval (+ second opinion if high/destructive) | **Approval gate — binding, before any mutation** |
| **4 — Pre-flight** | Executor | validated backup/snapshot + preconditions confirmed + rollback armed | **Verification gate — backup must validate** |
| **5 — Execute** | Executor | change applied (idempotent where possible) + captured output | — |
| **6 — Post-apply verification** | Verifier | live smoke-test evidence (PASS / BLOCK) | **Verification gate** |
| **7 — Document + memory** | O | runbook updated + **reviewed PR** to uranus-infra + memory written | — |

---

### Phase 1 — State capture (read-only)

**The source of truth is the live dashboards and the running host, not the repo** (the repo
is auto-synced *after* changes go live, post-hoc and unreviewed — so it lags reality). This
phase is therefore **mandatory** before any change: you cannot write a correct spec or
rollback against a state you have not captured.

The **Researcher** (read-only credentials only) captures, into the change-doc's *State
capture* section, only what the change touches plus its immediate blast radius:

- **Host / provider** (Hetzner): `hcloud server describe`, server type, **disk vs.
  volume layout and free space** (the resize-without-disk-grow lesson — always capture
  actual partition/disk usage), floating IPs and what they're attached to, existing
  snapshots and their age. Hetzner API GET only.
- **Coolify**: service UUID, current env/domains/compose/resources via the Coolify API
  GET (`coolio.performantlabs.com`), container health. **GET only — no deploy/restart.**
- **Cloudflare**: zone, existing DNS records for the affected name, proxy/SSL mode, WAF —
  Cloudflare API GET only.
- **On-host (read-only SSH)**: `docker ps`, `docker inspect`, `df -h`, `free -m`,
  `systemctl status`, config file contents (`cat`), cert expiry (`openssl s_client`/`curl
  -vI`). **No command that writes, restarts, or deletes.**

Record each captured value with the **command/endpoint that produced it** (so the spec and
rollback can be checked against ground truth). Append a Phase-1 block to `decisions.md`
(Decided/Assumed/Hedged/Evidence) — in particular flag anything you **could not** read.

---

### Phase 2 — Change spec

The **Spec author** writes, into the change-doc:

1. **What & why** — one sentence each. Link the motivating ticket/issue.
2. **Risk tier** — low | high/destructive — with the red-flag checklist (over-permissioned
   access / public exposure / missing-or-unvalidated backup / irreversibility) ticked. This
   sets the Phase 3 rigor.
3. **Exact commands / API calls** — the literal `hcloud …`, `curl` to the Coolify/Cloudflare
   API, or compose/SSH commands to run, **copy-pasteable**, with placeholders resolved from
   the Phase-1 snapshot. Mark each as idempotent or not.
4. **Blast radius** — every service, domain, container, IP, volume, cert, or downstream
   consumer that *could* be affected if the change goes right **and** if it goes wrong.
   "Just this one record/service" must be justified against the Phase-1 snapshot, not
   assumed.
5. **Rollback plan** — the exact steps to return to the captured Phase-1 state, **written
   before approval**, including the **specific backup/snapshot** the rollback depends on
   (named, with how to validate it in Phase 4) and the **point of no return** (the step
   after which rollback is no longer clean — e.g. a Rebuild, a volume detach+wipe). If there
   is no clean rollback, say so explicitly — that forces the high/destructive tier.

Append a Phase-2 block to `decisions.md`. The spec author does **not** run anything that
mutates; if a value is missing, send it back to Phase 1.

---

### Phase 3 — Pre-apply review gate (HUMAN) — **binding**

This is an **Approval gate** (conventions §3): a human approves, approval is **never
inferred**, **silence is not approval**, and **no mutation may begin until this gate records
an explicit "approved"**.

1. The **Reviewer** (read-only) independently checks the spec against the Phase-1 snapshot:
   - Do the exact commands match the captured state (right UUID/IP/zone/disk)?
   - Is the blast radius complete and honest?
   - Does the **rollback** actually restore Phase-1 state, and does it name a backup that
     Phase 4 can validate?
   - Are any red flags present (over-permissioned access, public exposure, missing/unvalidated
     backup, irreversibility)?
2. **Rigor dial.** For **low** risk: single reviewer → recommend to the human. For
   **high/destructive**: run a **second-opinion** review — a second independent reviewer (a
   fresh agent or a second human) re-reviews the **same** spec + rollback; both opinions are
   attached to the gate. (Panel rigor is available for exceptional cases per conventions §3.)
3. The Reviewer posts a **recommendation** (approve / approve-with-conditions / reject) with
   reasons — it is a recommendation, **not** the approval.
4. **The human operator records the decision** in `decisions.md` and the change-doc:
   `APPROVED by <name> @ <UTC>` (or rejected / changes-requested). Only an explicit APPROVED
   unblocks Phase 4.

If the gate is rejected or conditioned, the spec returns to Phase 2; re-review on resubmit.

---

### Phase 4 — Pre-flight

Now (and only now) the **Executor** — the sole role holding mutate-capable credentials — prepares to apply,
but **still does not apply the change**:

- **Take the backup/snapshot** the rollback depends on (Hetzner snapshot, Coolify/DB dump,
  config copy, `acme.json` copy, etc.).
- **Validate the backup** — this is a **Verification gate**, and it is the direct lesson
  from the failure where a snapshot was nearly trusted unvalidated. Validation means a
  positive check, not "the command exited 0": **checksum** the dump, or **test-boot /
  test-restore** the snapshot to a throwaway target, or restore a DB dump into a scratch DB
  and count rows. Record the evidence. **If the backup does not validate, BLOCK — do not
  proceed.**
- **Confirm preconditions** from the spec (e.g. *disk has been grown to match the new server
  type* before any resize; DNS already points to `62.238.6.48` before a cert-dependent
  deploy; maintenance window; nobody mid-deploy in Coolify).
- **Arm the rollback** — have the exact rollback commands staged and the point-of-no-return
  marked, so a bad outcome is reversed in seconds, not improvised.

Append a Phase-4 block to `decisions.md` with the backup id + validation evidence.

---

### Phase 5 — Execute

The **Executor** runs the approved commands from the spec, **idempotent where possible**,
one logical step at a time, **capturing stdout/stderr** for each into the change-doc. Stop
**before** the point of no return and re-confirm preconditions one last time. If any step
deviates from the spec's expected output, **halt and roll back** rather than improvising — a
deviation is a re-spec/re-approve event, not a judgment call to make live.

The Executor mutates **only** what the approved spec lists. Anything discovered mid-flight
that needs a new mutation is a new change (new change-doc, back through the gate).

---

### Phase 6 — Post-apply verification

The **Verifier** (read-only) proves the change is live **and** that the blast radius is
clean — a **Verification gate** (PASS / BLOCK):

- **Endpoints**: `curl` the affected URL(s) for expected status/body.
- **TLS**: cert is valid and not stale (`curl -vI` / `openssl s_client`).
- **Containers**: `docker ps` healthy; no restart loop; logs clean.
- **DNS**: the name resolves to the expected target (`dig`/`nslookup` against a public
  resolver, not just locally).
- **Blast-radius regression**: re-check the *other* services in the Phase-2 blast radius are
  still up (a change is not "done" if it fixed X and broke Y).

On **BLOCK**, execute the armed rollback (if still before the point of no return) or escalate
to the human immediately. On **PASS**, proceed to Phase 7.

---

### Phase 7 — Document + memory

O closes the loop:

1. **Update the runbook** in `~/LocalDevelopment/docs/infrastructure/` (machines.md and/or
   the per-service doc) to reflect the new reality.
2. **Commit to `uranus-infra` via a REVIEWED PR** — author on a branch → PR → review →
   merge. This **replaces edit-in-place + the post-hoc daily sync** for reviewed changes.
   (See `changes/README.md` in uranus-infra and the **daily-sync caveat** below.)
3. **Write memory** if this established a durable fact, gotcha, or recovery procedure.
4. O writes the **Chain Summary** in `decisions.md`: Outcome / Key decisions / Open
   assumptions still unverified / Follow-ups.

---

## Iteration & escalation

- **Gate rejection (Phase 3)** → back to Phase 2; re-review on resubmit. No retry cap on the
  human gate (it is a human decision), but a third reject should prompt rethinking the change
  itself.
- **Backup fails to validate (Phase 4)** → **hard BLOCK**; the change cannot proceed without
  a valid backup. Fix the backup or downgrade the change to one that needs none.
- **Verification BLOCK (Phase 6)** → roll back if before the point of no return, else
  escalate to the human at once. Verification gates may loop with a **retry cap of 2**; a
  third failure escalates (conventions §3).
- **Any deviation during Execute (Phase 5)** → halt + roll back + re-spec; deviations are
  never resolved live.

These caps exist so a stuck or surprising change surfaces to a human instead of being
improvised against live infrastructure.

---

## What this pipeline guarantees against the motivating failure

| Failure mode (the incident) | Where this pipeline stops it |
|-----------------------------|------------------------------|
| Server resized without growing the disk | Phase 1 captures disk/volume layout; Phase 4 precondition *disk grown before resize* must be confirmed. |
| Near-destructive Rebuild considered | Phase 2 marks it irreversible → high/destructive tier → Phase 3 second-opinion gate + a written rollback + a point-of-no-return before anyone can run it. |
| IP detached/reattached | Phase 1 captures IP attachments; blast radius (Phase 2) lists every consumer of that IP; verification (Phase 6) re-checks them. |
| Snapshot nearly used without validation | Phase 4 **validates** the backup (checksum/test-boot) before it can be relied on — an unvalidated backup is a hard BLOCK. |
| Unreviewed change going live | Phase 3 binding human gate before any mutation; Phase 7 reviewed PR instead of the post-hoc daily sync. |

---

## Dependencies & assumptions (confirm before relying on the pipeline)

- **Credential scoping requires read-only credentials per provider.** The least-privilege
  guarantee for the read-only roles (Researcher, Spec author, Reviewer, Verifier) is enforced
  by giving them **read-only credentials only** — it is *not* enforced by the tool list
  (`Bash` is in every role). This **requires** each provider to issue a read-only credential:
  - **Coolify** — a GET-only API token. *(If Coolify's token model cannot express
    read-only vs. mutate, the boundary degrades to procedural — instructions + Bash
    deny-rules — for Coolify, and the change-doc must say so. See the open risk.)*
  - **Hetzner** — a read-scoped API token (and `hcloud` context using it).
  - **Cloudflare** — a token scoped to `Zone:DNS:Read` (no `:Edit`).
  - **SSH to uranus** — a read-only / forced-command key for the read-only roles; the
    write key is held only by the Executor.
  - Where a provider **cannot** issue a read-only credential, note it in the change-doc; the
    boundary for that provider degrades to procedural (role instructions + deny-rules) and
    the Reviewer must weigh that at the gate.

---

## References

- [`pipeline-conventions.md`](pipeline-conventions.md) — decision-journal format (§1),
  role-doc template (§2), gate mechanics + review-rigor dial (§3). **Read first.**
- Role docs: [`infra-orchestrator.md`](infra-orchestrator.md),
  [`infra-researcher.md`](infra-researcher.md), [`infra-spec-author.md`](infra-spec-author.md),
  [`infra-reviewer.md`](infra-reviewer.md), [`infra-executor.md`](infra-executor.md),
  [`infra-verifier.md`](infra-verifier.md).
- uranus-infra `changes/_TEMPLATE.md` — the change-doc this pipeline fills in.
- uranus-infra `changes/README.md` — author-on-branch → PR → apply workflow + daily-sync
  reconciliation.
- `~/LocalDevelopment/docs/infrastructure/` — the runbooks Phase 7 keeps current.
- [`workflow-ofats-dual-review.md`](workflow-ofats-dual-review.md) — the coding pipeline this
  one mirrors.
