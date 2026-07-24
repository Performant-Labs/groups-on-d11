## Implementation Review (Round 1

### BLOCK findings

**[B-1]** package.json — missing `@axe-core/playwright` devDependency  
– Why it blocks: the spec’s axe‐powered tests cannot run without the Playwright integration.  
– Remediation: add `@axe-core/playwright` to `devDependencies` in `package.json` and commit the updated lockfile.

**[B-2]** package.json — no `test:e2e` script defined  
– Why it blocks: acceptance requires `npm run test:e2e` to invoke the new audit spec; without that script, the command will fail.  
– Remediation: add a `"test:e2e"` script (e.g. `"playwright test tests/e2e/a11y-audit.spec.ts"` or a broader pattern) to `package.json`.

**[B-3]** tests/e2e/a11y-audit.spec.ts — test navigates to `/all-groups` whereas the brief prescribes `/groups`  
– Why it blocks: one of the eight audited surfaces will go untested or misreported if the route does not match the brief (spec drift).  
– Remediation: verify the actual application route (is it `/groups` or `/all-groups`?), then update either the test or the brief so they align exactly.

**[B-4]** tests/e2e/a11y-audit.spec.ts — unverified handling of `includeSelector` in `auditRoute()`  
– Why it blocks: if `includeSelector` is `undefined` and passed directly into `new AxeBuilder(page).include(includeSelector)`, it may throw or behave unpredictably, causing false passes/fails.  
– Remediation: update `auditRoute()` to call `.include()` only when `includeSelector` is a non‐empty string, and add a unit‐style test to exercise both code paths.

**[B-5]** Missing committed audit reports (`test-results/a11y-audit.md` and `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md`)  
– Why it blocks: acceptance requires that the generated violation‐count table be written to `test-results/a11y-audit.md` and copied to the docs folder. Without those artifacts in source control, the handoff is incomplete.  
– Remediation: commit the freshly generated `test-results/a11y-audit.md` and the updated `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md`.

**[B-6]** tests/e2e/a11y-audit.spec.ts — use of `test.skip(true, reason)` inside test bodies unverified  
– Why it blocks: Playwright’s overload resolution at runtime may not treat this pattern as intended and could skip the wrong scope or all tests.  
– Remediation: confirm in the Playwright docs and/or in a minimal reproduction that `test.skip(condition, reason)` inside the test body only skips that one test and does not trigger a file‐wide skip; adjust to the officially documented pattern if necessary.

### WARN findings

**[W-1]** tests/e2e/a11y-audit.spec.ts — waiver tests use the boolean‐first overload of `test.skip` (`test.skip(true, reason)`) rather than the string‐first pattern (`test.skip('route', 'reason')`) shown in the brief.  
– Recommendation: standardize on the string‐first overload at declaration time so that waivers remain grep‐able and semantically clearer.

### NIT findings

**[NIT-1]** web/themes/custom/groups_chrome/css/tokens.css — the updated color tokens lack comments indicating the WCAG contrast ratios they satisfy.  
– Suggestion: annotate each token with its contrast ratio for future maintainability.

**[NIT-2]** tests/e2e/a11y-audit.spec.ts — consider renaming the `includeSelector` parameter to something like `scopeSelector` to better convey that it restricts the axe scan to a DOM subtree.

### Verdict

BLOCK — 6 blocking finding(s); must resolve before testing starts.
