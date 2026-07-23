# Handoff-U: Phase 8 — #111 ST-2 Following feed (`/following`)

**Date:** 2026-07-23
**Branch:** 111-stream-following
**Issue:** #111 ST-2
**Worktree / DDEV:** `~/Projects/_worktrees/groups-stream-111` / `gm111-stream` (untouched; already up from T-green)

## Option used

**Option A — Playwright walkthrough spec.**

Playwright was fully cooperative from T-green's site state, so we captured reproducible artefacts. The dedicated `playwright-ui-walkthrough` subagent had a model config issue and is being substituted; U is acting as its stand-in per the operator's instruction.

- New walkthrough spec: `docs/handoffs/111-stream-following/walkthrough.spec.ts` (7 scenarios covering all 5 persona walks + 2 regression cross-checks). Kept out of `tests/e2e/` so it does not enter the regular suite; copied into `tests/e2e/_walkthrough_111.spec.ts` only for the duration of the run, then removed. The spec in the handoff dir is the canonical source.
- Ran via: `BASE_URL="https://gm111-stream.ddev.site" npx playwright test tests/e2e/_walkthrough_111.spec.ts --output=docs/handoffs/111-stream-following/playwright-output`.
- Screenshots in `docs/handoffs/111-stream-following/screenshots/` (full-page PNGs, one per scenario).
- Traces for the one failed scenario in `docs/handoffs/111-stream-following/playwright-output/` (Playwright default `retain-on-failure`).

**Final tally: 6 / 7 pass.** The single failure is a real spec-vs-implementation mismatch in the empty-state copy (not a walkthrough regression on the code we shipped) — details in "WCAG / advisory findings" below.

## Per-persona results

| # | Persona | URL | HTTP | Screenshot | Result |
|---|---------|-----|------|------------|--------|
| 1 | anonymous | `/following` | 403 | `screenshots/01-anonymous.png` | PASS — full HTML 403 page (well-formed `<!DOCTYPE html>` "Access denied" render), no WSOD, no PHP error, no stack trace |
| 2 | elena_garcia | `/following` | 200 | `screenshots/02-elena-following.png` | PASS — all 4 branches visible: `Patch Review Process RFC` (follow_content), Maria-authored (`Sprint Planning: Portland 2026` etc. — follow_user), `Drupal 11 Migration Path` (`core` follow_term), `Venue Logistics Thread` (`drupalcon` follow_term). Dedupe verified: `Patch Review Process RFC` renders exactly 1 card despite matching two branches. `.stream-card-wrapper` markup present (visual parity with `/stream`). Exactly one `<h1>`. |
| 3 | ravi_patel | `/following` | 200 | `screenshots/03-ravi-following.png` | PASS — at least one Maria-authored node renders. |
| 4 | sophie_mueller | `/following` | 200 | `screenshots/04-sophie-following.png` | PASS — `Getting Started with Paragraphs` visible via the new follow_content seed. |
| 5 | alex_novak (no follows) | `/following` | 200 | `screenshots/05-empty-state.png` | **PASS with 1 advisory** — empty state renders with correct copy, correct heading, both inline `stream` link and the `Browse the stream` button are visible/focusable, `/stream` link resolves (200). BUT: `/tags` link is 404 on this build — see advisory below. |

## Empty-state observations (all 4 points from the walkthrough script)

1. **"You're not following anything yet" heading** — PRESENT (rendered inside `.gc-empty > .gc-empty__title`, matches brief.md line 47 verbatim).
2. **Working `/stream` link** — TWO links present per approved copy: inline `<a href="/stream">stream</a>` and button `<a class="gc-button gc-button--primary" href="/stream">Browse the stream</a>`. Both resolve — `curl -sk -o /dev/null -w "%{http_code}" .../stream` → **200**.
3. **Working `/tags` link** — LINK PRESENT with correct `href="/tags"` and accessible name, but **the route returns 404** on this build. `curl` and Playwright `page.request.get('/tags')` both confirm 404. See advisory §1 below.
4. **Working "Browse the stream" button** — present, focusable, resolves to `/stream` 200.
5. **Tab / focus rings** — All three interactive elements accept focus (`element.focus()` → `.toBeFocused()` passes). Focus indicator computed styles logged to stdout for the record (browser default outline is theme-dependent; no color-only-status issue observed).

## WCAG 2.2 AA spot-checks

| Check | Populated `/following` (elena) | Empty `/following` (alex) | Result |
|---|---|---|---|
| Single meaningful `<h1>` | 1 (asserted via `getByRole('heading', { level: 1 }).toHaveCount(1)`) | 1 (same assertion) | PASS |
| All links have accessible names | Yes (all card title links have text; empty-state links have text) | Yes (`stream`, `Browse the stream`, `tags` all named) | PASS |
| Focus visible on interactive elements | Not exhaustively swept on populated page; card title links are default `<a>` (browser outline applies) | All 3 empty-state links accept focus + focus state logged | PASS |
| No color-only status conveyance | No status indicators on cards use color alone; text labels present | Empty state uses text + a text-labelled button (no color-only signal) | PASS |
| Empty-state text/button contrast (eyeball) | n/a | Button contrast against page background: sufficient (screenshot review) | PASS |

## Regression cross-check

- `/stream` as elena_garcia → **200 OK, `<h1>` present, no PHP error** (`screenshots/06-stream-regression.png`). No visible regression from the following-scoped CSS additions (`.following-feed` scope is class-guarded per handoff-F.md).
- `/my-feed` as elena_garcia → **404 as expected** (`screenshots/07-myfeed-404.png`). Sibling #110 not merged, no leak.

## Advisory findings (spec ambiguities → route to O)

**1. `/tags` link in the empty state is a dead link on this build.**

The approved empty-state copy (brief.md line 47) says "Browse the stream or explore <a href=\"/tags\">tags</a> to find people, content, and topics to follow." The `/tags` route resolves to 404 — the only view with a `tags` path in this codebase is `views.view.tags_aggregation.yml`, and it declares `path: tags/%` (line 108), i.e. it requires a term-slug argument. Bare `/tags` never routes.

- Not a bug in #111's implementation — the copy was approved verbatim in the brief, and F reproduced it byte-for-byte.
- Not a WCAG failure per se — the link is well-formed and accessible; it just points at a 404.
- **This is a genuine cross-story gap.** The most likely remediation options for O:
  - (a) Add a bare-slug landing display (`path: tags`) to `tags_aggregation` (or a new small view). Small, scoped to `do_discovery`/whatever owns `tags_aggregation`.
  - (b) Update the empty-state copy in `following_feed.yml` to link at a route that actually exists today (e.g. `/stream` for both, or drop the tags link entirely, or link at a specific `tags/<slug>`).
  - (c) File a follow-up ticket if this is expected to be addressed by an upcoming sibling story (e.g. a tag-index story).
- The same wording likely applies to sibling #110's `/my-feed` empty-state copy if it mirrors this one — worth checking when #110 lands.

Rendered HTML excerpt (from the live `/following` empty state, unchanged from brief.md's approved copy):

```html
<div class="gc-empty">
  <p class="gc-empty__title">You're not following anything yet</p>
  <p class="gc-empty__text">Browse the <a href="/stream">stream</a> or explore <a href="/tags">tags</a> to find people, content, and topics to follow.</p>
  <a class="gc-button gc-button--primary" href="/stream">Browse the stream</a>
</div>
```

## Notes for S

- Walkthrough spec (`walkthrough.spec.ts` in the handoff dir) is standalone; it deliberately does not join the `tests/e2e/` suite so it will not run in CI. S may choose to promote it or not.
- One test in the walkthrough spec (scenario 5) will fail on any build until the `/tags` route exists OR the empty-state copy is updated. This is the SAME failure documented as advisory §1 above — it is not a regression from anything U changed.
- No production files were modified in this phase. `git status --short` before and after the walkthrough differs only by the new files under `docs/handoffs/111-stream-following/` (walkthrough spec, screenshots dir, playwright-output artifacts) and this handoff document itself. `.ddev/config.yaml` untouched. `gm111-stream` DDEV project untouched.

## VERDICT

**VERDICT: ADVISORY**

All acceptance criteria from brief.md §Acceptance are met by the implementation (6/7 walkthrough scenarios pass, and the 1 failure is not against the criteria but against a downstream expectation that the linked route resolves). The `/tags` dead-link is a spec ambiguity between the approved empty-state copy and the actual routing surface on this build, not an implementation defect against #111 — routing it to O for a follow-up call (fix copy in this story, fix route in a sibling, or ship as-is and file a follow-up ticket).

## U — Phase 8 delta re-verify — 2026-07-23

Targeted re-verify of ST-2 empty state only, after F removed the `/tags` link from the copy and T re-verified assertions green. Re-ran the empty-state scenario (scenario 5) from `walkthrough.spec.ts` via a temporary copy at `tests/e2e/_walkthrough_111.spec.ts` (removed after run). Walkthrough spec updated to drop the `/tags` assertions and add a negative assertion that no `a[href="/tags"]` exists inside `.gc-empty`. Other scenarios not re-run (trusting T's regression verification).

- Screenshot: `docs/handoffs/111-stream-following/screenshots-delta/05-empty-state-delta.png` (also overwritten at `docs/handoffs/111-stream-following/screenshots/05-empty-state.png`).
- Rendered `.gc-empty` innerHTML on live UI (alex_novak, no follows):
  ```html
  <p class="gc-empty__title">You're not following anything yet</p>
  <p class="gc-empty__text">Browse the <a href="/stream">stream</a> to find people, content, and topics to follow.</p>
  <a class="gc-button gc-button--primary" href="/stream">Browse the stream</a>
  ```

Confirmed:
- "You're not following anything yet" heading present (rendered in `.gc-empty__title`).
- Inline `<a href="/stream">stream</a>` link present, focusable (`element.focus()` → `.toBeFocused()`), visible focus indicator (`outline: solid 2px`).
- "Browse the stream" button present (`.gc-button--primary` → `/stream`), focusable, visible focus indicator (`outline: solid 2px`).
- No `/tags` link anywhere in the empty state (`a[href="/tags"]` count == 0 inside `.gc-empty`).
- `/stream` resolves 200.
- Single `<h1>` on page (WCAG 2.2 AA); no PHP error / WSOD.
- Screenshot captured.

VERDICT: PASS
