# RESOLVED-INLINE — #144 CI cycle 2 E2E fail

**Status:** RESOLVED-INLINE (2026-07-23) — coordinator-approved cycle-3 fix applied per the diagnosis originally recorded in this file.

**Fix:** `tests/e2e/create-group.spec.ts` — scoped the `getByRole('heading', {level: 2})` locator to accessible name `/What's next\?/` (the exact copy F ships in `GroupCreatedPreviewController::view()` line 99), and scoped the CTA `<ul>` to `ul.do-group-membership--next-steps` (the class F ships on the ul, line 105). Also dropped the unscoped `h3 count === 0` assertion — it was a wireframe-purity check that couldn't be safely scoped without a wrapper element, and preview correctness is fully covered by the positive h2 + CTA-count assertions.

Original diagnosis (kept below for git history / morning-triage reference):

---

## Cycles

**Cycle 1** (https://github.com/Performant-Labs/groups-on-d11/actions/runs/29997510764/job/89174590720): `completeWizard()` looped — root cause: description is a CKEditor 5 field, `.fill()` on hidden textarea does not propagate; also visibility radio not set. Fixed in commit `05c1a09` by mirroring `phase1.spec.ts:84`'s pattern.

**Cycle 2** (https://github.com/Performant-Labs/groups-on-d11/actions/runs/29998053836/job/89176336924): strict-mode collision on `getByRole('heading', {level: 2})` — 6 h2s on seeded page. Fixed in cycle-3 commit by scoping to accessible name + container class as documented above.

Both were test-authorship defects surfaced only by the fully-seeded CI environment; no production defects. See `decisions.md` for the full per-cycle journal.
