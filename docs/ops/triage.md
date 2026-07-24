# Triage — groups.performantlabs.com

How a new bug report or feature request flows from "reported" to
"working on it" (or "not doing it, here's why"). Deliberately
lightweight — this is a POC, not a support desk.

Related: [`on-call.md`](on-call.md), [`sla.md`](sla.md).

## 1. Where reports come in

- **GitHub Issues** on `Performant-Labs/groups-on-d11` — the only
  channel. Open: <https://github.com/Performant-Labs/groups-on-d11/issues/new>.
- Slack / email / hallway reports → the person who heard it opens a
  GitHub issue on the reporter's behalf, tagging them. **If it isn't
  in GitHub it doesn't exist.**
- Automated signals (SLA breaches per [`sla.md`](sla.md) §3, CI red on
  `main` per [`on-call.md`](on-call.md) §3) — the on-call responder
  files a GitHub issue after mitigation, referencing the incident.

## 2. Labels

Kept minimal on purpose. Every issue gets **one type** and **one
priority**; anything else is optional.

Type (pick one):

- `bug` — something that used to work, or is documented to work, and
  doesn't.
- `enhancement` — new capability or improvement.
- `documentation` — docs-only change.
- `question` — request for information; closed when answered.
- `epic` — umbrella tracking multiple child issues (rare; used for
  release-engineering batches like #216).

Priority (pick one, see §3):

- `P0`, `P1`, `P2`, `P3`.

Optional signals:

- Module scope: `do_activity`, `do_groups`, `do_streams`, etc. — only
  when useful for filtering, not required.
- `good-first-issue` — well-scoped, low-context work for a new
  contributor.
- `blocked` — waiting on an upstream dependency; add a comment naming
  what.

## 3. Priority scheme

| Priority | Meaning | Ack SLA | First-response SLA | Examples |
|---|---|---|---|---|
| **P0** | Demo site down or data loss. On-call pattern in [`on-call.md`](on-call.md). | 1 hour, business hours | Immediate mitigation, then RCA | `/` returns 5xx; DB corrupt; auth broken for everyone. |
| **P1** | Core flow broken but demo mostly works. | 1 business day | 3 business days | Can't post to a group; comments 500 for one role. |
| **P2** | Non-blocking bug or noticeable rough edge. | 1 business day | 3 business days | Alignment off on `/showcase`; one Views filter empty. |
| **P3** | Cosmetic / nice-to-have / future work. | 1 business day (ack only) | No SLA — batched into planning. | Copy tweak; icon swap; new feature request. |

**Default when unlabelled:** `P2`. Escalate deliberately, not by
attrition.

The formal availability + response-time targets for the running site
itself (as opposed to issue triage) live in [`sla.md`](sla.md).

## 4. Flow

1. **Issue opened.** Anyone with a GitHub account can file.
2. **Ack** (owner comments within the SLA window above): "Seen,
   triaging." Add `type` + `priority` labels. If it's a duplicate,
   close with a link to the canonical issue.
3. **First response** (within the SLA window): either a diagnosis /
   reproduction plan, a "won't fix, because …" close, or an assignment
   to a contributor who's picking it up.
4. **Work.** For P0/P1, work in a branch off `main` named `<issue>-<slug>`;
   PR references the issue with `Closes #NNN`.
5. **Close.** PR merge auto-closes via `Closes #NNN`. For non-code
   closures (won't-fix, duplicate, answered), close with an explanatory
   comment — never silently.

## 5. Ownership

Small team, explicit assignments beat implicit ones.

- **Commit access to `Performant-Labs/groups-on-d11`:** the
  `Performant-Labs` org admins. Merge access is gated on CI green +
  mergeable per the standing autonomy rule.
- **Docs review** (`docs/**` changes, including `docs/ops/`): whoever
  authored the closest existing doc. If unclear, the person who opened
  epic #216 for release-engineering docs.
- **Code review:** module owner where identifiable (e.g. `do_ops`,
  `do_showcase`, `do_streams` have distinct authors in `git log`); else
  any committer.
- **On-call rotation:** informal for the POC — whoever is closest to
  the keyboard when [`sla.md`](sla.md) §3 fires. Formalise into a
  rotation before the project graduates from demo status.

## 6. What we don't do (for the POC)

- **No formal support commitments** to external parties. This is a
  demonstration site; the SLAs in §3 apply between contributors, not
  between the project and a paying customer.
- **No paging / on-call phone tree.** Alerts go to `#ops` in Slack
  (see [`sla.md`](sla.md) §3); response is best-effort during business
  hours.
- **No separate security-report channel.** Report security issues via a
  private GitHub Security Advisory
  (`https://github.com/Performant-Labs/groups-on-d11/security/advisories/new`).
  Do **not** file public issues for exploitable bugs.
- **No SLAs on P3.** They're batched into planning; if that's too slow
  for a specific request, re-label it up with a justification.

## 7. History

- 2026-07-24 — #214 (REL-5) — initial triage doc.
