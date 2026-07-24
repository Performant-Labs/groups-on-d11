# Round-1 Response — #145 diff-gate

**Verdict from reviewer:** BLOCK / 6 findings.
**O triage:** 1 real (accepted + fixed), 5 misreads (rejected with evidence).

## Accepted

**B-3** (route drift). Brief prescribed `/groups` and `/personas`; codebase reality is `/all-groups` and a persona-switcher block embedded on `/`. Brief has been updated (Surfaces section now names the actual routes and explains the persona-switcher block). Also normalised `/streams/{one}` → `/do-streams/demo/global`. **Spec was already correct** — T-RED made the same correction in Phase 4 (see `decisions.md` T-RED entry).

## Rejected with evidence

**B-1** `@axe-core/playwright` missing → **present** in `package.json` line "`@axe-core/playwright`": "^4.10.0". Reviewer error.

**B-2** `test:e2e` script missing → **present** in `package.json` "`test:e2e`": "playwright test". Reviewer error.

**B-4** `includeSelector` unguarded → **already guarded** at `tests/e2e/a11y-audit.spec.ts:137`:
```ts
if (includeSelector) {
  builder = builder.include(includeSelector);
}
```

**B-5** Audit reports not committed → `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` **is staged** (`git status`, added). `test-results/` is Playwright's runtime output directory and is not tracked (project convention — regenerated on every run). The docs-side copy is the committed source of truth.

**B-6** / **W-1** `test.skip(true, reason)` in-body untested → **empirically confirmed** by T in Phase 6: the actual Playwright run reported "8 passed, 2 skipped (9.2s)". Matches the existing in-repo convention at `tests/e2e/manage-members.spec.ts:155`. If the boolean-form skipped file-wide, we'd see 0 or 2 tests total, not 8+2.

**NIT-1** Missing contrast-ratio comments on tokens → **already present** at `web/themes/custom/groups_chrome/css/tokens.css:37-51`; F documented every changed token with before/after values, failing ratios, and the specific WCAG SC (1.4.3). Reviewer missed lines 37-44.

**NIT-2** Rename `includeSelector` → `scopeSelector` — cosmetic, defer.

## Diff of changes since Round 1
- `docs/planning/handoffs/145-wcag-sweep/brief.md` — Surfaces section aligned to actual routes.
- `docs/planning/handoffs/145-wcag-sweep/decisions.md` — appended O dual-review triage entry.
- No code changes (no other finding warranted them).
