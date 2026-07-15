# Playwright Agents — Explorer Pipeline Best Practices

> Sources: [Playwright official docs](https://playwright.dev/docs/test-agents), [Currents.dev 9 strategies](https://currents.dev/posts/9-strategies-to-get-the-most-out-of-playwright-test-agents), [DEV Community write-up](https://dev.to/playwright/playwright-agents-planner-generator-and-healer-in-action-5ajh), [BrowserStack guide](https://www.browserstack.com/guide/playwright-agent), [Medium guide](https://medium.com/@ismailsobhy/ai-powered-test-automation-part-4-complete-guide-to-playwright-agents-planner-generator-healer-d418166afe34), TTC Global / Microsoft case study (Workday HRIS). Validated empirically on Language Buddy 2026-06-17.

The Playwright Agents pipeline (introduced v1.56, available in v1.60+) is a **Planner → Generator → Healer** loop that explores a live app and produces Playwright specs. This doc covers what the research and community practice say about using it effectively.

---

## The three agents

| Agent | Role | Output |
|---|---|---|
| **Planner** | Navigates the live app, explores interactions, identifies test scenarios | `specs/<name>.plan.md` |
| **Generator** | Executes each planned scenario live, records locators and assertions | `e2e/<name>.spec.ts` |
| **Healer** | Runs the test suite, debugs failures, repairs selectors or marks `test.fixme` | Edited spec files |

Scaffold with: `npx playwright init-agents --loop=claude --prompts`

---

## Rule 1: Scope narrowly — one page/section per run

Every agent action streams the **full accessibility tree** to the LLM context. A whole-app planner run is expensive and produces lower-quality output than a focused single-page run. Always constrain the planner prompt to one module or one user flow at a time.

**Wrong:** "Explore the entire app and find edge cases."  
**Right:** "Explore `/stats` and its sub-pages only. Focus on the calendar strip, heatmap, and reset flow."

---

## Rule 2: The seed file bootstraps every generated test

The seed spec (e.g. `e2e/seed.spec.ts`) is executed by the Planner before exploration begins and its content is copied into all generated tests. It must:

- Authenticate to the app (sign in as a test user)
- Land on the right starting page
- Use the same env vars / fallback credentials as the rest of the e2e suite

The seed is the single point of fixture truth for the generator. If it's wrong, every generated test inherits the error.

---

## Rule 3: Treat generated tests as drafts — not production code

No source (official docs or community) treats agent-generated tests as auto-committable. Every generated test needs the same code review as hand-written code:

- Are selectors stable (not positional or text-only)?
- Does the test scope match the project's tier model?
- Does it follow project conventions (no `waitForTimeout`, prefer role/testid locators)?
- Does it duplicate an existing oracle test?

**Commit only after validation.** Keep generated specs in separate files (e.g. `e2e/<module>-edge.spec.ts`) so they are clearly distinguished from the hand-written oracle suite until reviewed and promoted.

---

## Rule 4: Supply a checklist to improve signal quality

Without a structured checklist in the planner prompt, published benchmarks show Claude Sonnet 4.5 reaches ~49% F1 on real-world web testing. With a focused checklist of specific edge cases to probe, output quality improves significantly.

Include in the planner prompt:
- Specific flows to probe (not just "explore the page")
- Known failure modes to look for (double-submit, stale DOM after HTMX swap, Alpine re-init)
- What to document per finding (reproduction steps, severity, whether an existing test catches it)

---

## Rule 5: Score every planner run against the oracle suite

Before generating tests, review the planner's output and classify each scenario:

| Label | Meaning |
|---|---|
| **KNOWN** | Already covered by an existing hand-written test |
| **GAP** | Not covered; generate a test for this |
| **FALSE POSITIVE** | Not a real issue; skip |

Generate tests only for GAP items. Track the counts across runs to measure the explorer's value over time.

---

## Rule 6: The Healer's decision tree

When a generated test fails, the Healer should:

1. **Selector mismatch** → update the locator (most common cause)
2. **Timing issue** → replace `waitForTimeout` with `waitForSelector` / `waitForResponse`
3. **Flaky (<50% pass rate)** → add `test.slow()` and a more defensive wait
4. **App behaviour changed** → update the test to match; add a comment explaining what changed
5. **Confirmed real bug** → mark `test.fixme('confirmed bug: <issue-link>')` — never delete

Never delete a failing test. A failing test that correctly describes expected behaviour is a bug report.

---

## Rule 7: Know what the data does and does not show

Published case studies (TTC Global on Workday HRIS with GitHub Copilot + Playwright MCP) report:

- **24.9% average time savings** on test authoring (range 12.8–36.2%)
- Greatest savings during script creation vs. maintenance
- **40% increase in data gathering efficiency** in some deployments
- **85–95% reduction** in selector maintenance overhead with self-healing

What is **not** well-documented in published research:
- Whether the explorer finds bugs the human suite missed (defect discovery rate)
- Coverage quality vs. coverage quantity
- Cost per discovered gap

If you measure this on your project, the data is genuinely novel.

---

## Recommended pilot structure

When trying the explorer on a new project or module for the first time:

1. **Pick a page with partial coverage** (not zero, not 100%) — the comparison is richest
2. **Run the Planner on that page only** — read the output before generating anything
3. **Score KNOWN / GAP / FALSE POSITIVE** manually
4. **Generate tests for GAPs only**
5. **Run + heal** — note how many self-healed vs. required manual fix
6. **Fill in the scorecard** and save it alongside the generated plan

Only expand to additional pages once you have a baseline scorecard for the first one.

---

## Common failure modes

| Failure | Cause | Fix |
|---|---|---|
| Strict mode violation | Multiple elements match the locator | Use `data-testid` or a more specific role+name selector |
| Flaky timing | HTMX swap not awaited | `waitForSelector` on the post-swap element |
| Alpine not re-initted | `outerHTML` swap replaced Alpine's scope | Assert the Alpine-bound element is interactive after the swap |
| Double-submit | No guard on the submit button | Test should click once and assert the button disables |
| Stale DOM after Back | HTMX history cache | Navigate forward and back; assert DOM reflects correct state |

---

## Relationship to the oracle suite

```
Oracle suite (e2e/*.spec.ts)          Explorer output (e2e/*-edge.spec.ts)
─────────────────────────────         ────────────────────────────────────
Hand-written, reviewed by A/T/S       AI-generated, draft status
Happy paths + key guards              Edge cases + failure modes
Committed, CI-gated                   Committed only after manual review
Source of truth for "does it work"    Source of bug reports
```

Confirmed bugs from the explorer feed into GitHub issues. The fix (and the regression guard) lands in the oracle suite via the coding pipeline.
