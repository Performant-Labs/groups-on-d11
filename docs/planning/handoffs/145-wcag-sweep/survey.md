# Survey — #145 MC-A11Y WCAG 2.2 AA audit sweep

## Character
Cross-cutting late-wave backstop. Adds an automated axe-core audit spec that reruns in CI, plus any targeted fixes flagged as serious/critical. Sibling to #133 (help/honesty sweep). Scope discipline: only the surfaces the issue names.

## Surfaces (from issue "Cover:" list)
The audit spec walks these routes anonymously (and, where seeded, as `demonstrator`):

1. `/` (front page / directory landing)
2. `/groups` (directory + card grid + filters)
3. `/group/{seeded}` (group homepage — group-about + tabs)
4. `/showcase` (variant switcher devices + POC ribbon)
5. `/personas` (persona banner switcher)
6. `/group/{seeded}/members` (manage-members table)
7. `/group/add/{type}` (create-group form)
8. `/streams/*` (shared stream shell — one representative route)

RTL and maps are listed in the issue but out-of-POC-scope: no maps surface exists in the demo, and RTL is a display-only toggle — capture as documented waivers rather than expanding scope.

## Reuse & Analogous-feature map
- **Closest analogous spec:** `tests/e2e/phase4.spec.ts` and `tests/e2e/showcase.spec.ts` — full-route Playwright walks with `page.goto` per surface.
- **Extend vs new:** NEW file `tests/e2e/a11y-audit.spec.ts` (issue explicitly names this path under "Owns"). No existing a11y spec to extend.
- **Dep to add:** `@axe-core/playwright` (dev). Wire via `AxeBuilder(page).analyze()` inside a `test.describe` loop over the surface list.
- **Fixes:** land in each surface's own template/CSS. No module rewrites. If a serious/critical violation would require a module refactor, log as a documented waiver instead (per POC bar).

## Audit method
Per-surface pattern (one test per route):
```ts
const results = await new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']).analyze();
const serious = results.violations.filter(v => ['serious','critical'].includes(v.impact ?? ''));
expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
```
Also emit a route→result table (violations count by impact) into `test-results/a11y-audit.md` for PR attachment.

## Acceptance mapping
- **Automated axe pass (no serious/critical)** → the spec above; documented waivers via `.skip()` with justification comment.
- **Manual keyboard traversal** → U (playwright-ui-walkthrough) drives Tab/Shift-Tab/Enter across the surfaces and asserts visible focus + AA contrast on focus ring.
- **Audit table attached to PR** → generated `a11y-audit.md` committed under `docs/planning/handoffs/145-wcag-sweep/`.
- **Playwright+axe spec reruns in CI** → `tests/e2e/a11y-audit.spec.ts` runs under the existing `test:e2e` script.

## Forward-compat check
No downstream stories depend on this; it's a final backstop. No shared component created — just a new spec file + local fixes. No conflict.

## Fixes envelope (upper bound)
Expected fix classes (based on typical Drupal 10 + custom theme):
- Missing `alt` on decorative icons → `alt=""` or `aria-hidden="true"`.
- Buttons rendered as `<input type=submit>` where `<button>` is clearer for label/aria — leave alone (project convention per project-override).
- Heading-order jumps in showcase/persona banners.
- Focus-ring visibility on custom-styled links/tabs.
- Contrast on POC ribbon / muted card metadata.

Anything outside these classes (module rewrites, view refactors) → documented waiver.

## Constraints
- POC lean pipeline: no A-dup, no brief-gate, no pre-PR hold.
- Second-opinion review dial per issue footer.
- Scope: only the surfaces named above. Do not expand.
