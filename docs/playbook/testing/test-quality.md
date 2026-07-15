# Test Quality — validity, non-redundancy, and proportion

> Sources: generalised from the CTRFHub Test-writer "test-sizing rule" (proven in production), the Three-Tier hierarchy in [`verification-cookbook.md`](verification-cookbook.md), and the "Coverage as a Floor, Not a Goal" rule in [`../frameworks/vitest/conventions.md`](../frameworks/vitest/conventions.md).

The hierarchy docs tell you **which tier** to use and **what** to verify. This doc governs whether the tests you end up with are actually **good**: each one earns its place, none re-prove each other, and the suite as a whole stays diagnostic and cheap. It exists because automated/agentic test authoring fails in a predictable direction — it **over-produces**: data-driven fan-outs, assertions that can't fail, and three tiers all proving the same branch. Coverage has a **ceiling**, not just a floor.

This is a standard, not a suggestion. The review rubric in §7 is meant to be run by the Tester on self-check and by the Spec-Auditor / reviewer as a gate.

---

## 1. The master question (validity)

Every test must pass this before it is written or kept:

> **"Would this test fail, in isolation, if the code it targets were wrong — and fail for the *right reason*?"**

If the honest answer is **no**, the test is invalid — **delete it**, don't tune it. Three ways a test fails this:

- **It only fails if a sibling also fails.** It re-proves a branch another test already covers; it adds maintenance cost and zero independent signal.
- **It asserts an outcome the code physically cannot produce.** (e.g. asserting "no redirect header" on a path whose early `return` runs before any redirect logic — the assertion can only break if an *unrelated* branch is deleted, so it points at the wrong cause when it fails.)
- **It has no real assertion**, or asserts a tautology (`expect(x).toBe(x)`, asserting the mock returns what the mock was told to return, snapshotting output nobody reads).

A valid test is a **regression tripwire for one specific cause**. If it can't catch a regression on its own, it isn't one.

---

## 2. Test behaviour, not implementation

Assert the **observable contract** — the return value, the HTTP status + body, the rendered DOM, the persisted row, the emitted event. Do **not** assert internal structure — private method names, call counts on collaborators, the order of internal steps, the shape of intermediate variables.

The test: *would a correct refactor that changes no observable behaviour break this test?* If yes, it's coupled to implementation and will generate false failures that train people to ignore the suite. Behaviour tests survive refactors; that's the point of having them.

> Mocking is the usual culprit. A test that asserts "service called `repo.save()` once with these args" tests *the wiring you already wrote*, not *the outcome you care about*. Prefer asserting the **effect** (the row exists, the response is 201). Reach for a call-count assertion only when the side effect is genuinely unobservable any other way.

---

## 3. The unit of a test is a code path, not a data value

This is where over-production happens. Rules:

- **One test per distinct branch added; one per distinct branch removed.**
- **Inputs that flow through the same branch are ONE test.** A `for` loop over N values that all hit the same conditional proves the branch **once**; the other N−1 add nothing. Use one representative value + one boundary + one negative.
- **A prefix/range condition is one branch.** `startsWith('/assets/')` takes the identical path for every matching value — one value proves it.
- **The 4xx matrix (401/403/422/429/413) is a per-route *ceiling*, not a per-asset multiplier.** It is the maximum applicable set for a *single new route* — never a template to fan across paths, files, or assets.

### Worked counter-example (what NOT to do)

A diff adds **one** conditional to a request hook: `if (rawPath.startsWith('/assets/')) return;` — **one new branch, no new route.**

❌ **Wrong** — fan the 4xx matrix across every asset path:

```ts
const ASSET_PATHS = ['/assets/app.js', '/assets/htmx.js', '/assets/alpine.js',
                     '/assets/idiomorph.js', '/assets/flowbite.js', '/assets/style.css'];
for (const path of ASSET_PATHS) {
  it(`${path} returns 200 without auth`, ...);    // 6
  it(`${path} is not redirected`, ...);           // 6
  it(`${path} emits no redirect header`, ...);     // 6
  it(`${path} returns 401 on bad token`, ...);     // 6
}
// → 24 tests for one prefix check. 23 re-prove the same branch.
// Worse: the early `return` runs before any redirect logic, so the
// "no redirect header" assertions test an outcome the code cannot produce —
// they fail only if an UNRELATED branch is deleted.
```

✅ **Right** — one test per distinct path, plus boundaries:

```ts
it('a path under /assets/ is served 200 without auth', ...);  // the branch
it('a path NOT under /assets/ still gates', ...);             // the negative
it('/assetsx (prefix not followed by /) still gates', ...);  // the boundary
it('an asset path with a query string still bypasses', ...);  // real extra logic
// → ~4 tests. Every one fails in isolation if the branch is wrong.
```

Smaller **and** strictly more diagnostic: each test isolates one cause, so a failure names it.

---

## 4. Non-redundancy across tiers — each tier proves what the others can't

The Three-Tier hierarchy is about cost, but it's also about **ownership**: a behaviour should be pinned at the **cheapest tier that can prove it**, and **not re-proven** at a more expensive one. Re-proving a unit-level branch through an E2E is the most expensive redundancy there is.

| Tier | Uniquely owns | Do NOT use it to re-prove |
|---|---|---|
| **Unit** (no I/O) | pure logic, branch coverage, edge/boundary values, error mapping | — |
| **Integration** (`inject()` / in-mem DB) | wiring, request→response contract, persistence, auth gating, the *one* happy + key error paths per route | every input permutation a unit test already covers |
| **E2E** (browser) | cross-page flows, real rendering, accessibility, things only a browser exhibits | business-logic branches, validation matrices, anything a unit/integration test already pins |

Rule of thumb: **if a failure could be caught by a cheaper tier, that's where the test belongs** — and once it's there, the expensive tier asserts only the slice unique to it (e.g. E2E asserts "the error is *shown to the user*," not "the validator rejects the input" — that's the unit's job).

---

## 5. Proportionality — scale tests to the change, not to the file

- **Feature stories** earn a full, tiered suite for the new behaviour (happy path + the meaningful error/edge paths + the declared tiers).
- **Audit / fix / refactor stories** write **only the test(s) that guard the specific finding.** A one-line overflow fix gets one overflow assertion — not a re-test of the whole page. Touching a file does not entitle the file to a new test sweep.
- A change with **zero behavioural delta** (rename, move, comment, pure-mechanical refactor covered by existing tests) earns **zero new tests** — the existing suite staying green *is* the verification.

Default to the smallest set that catches a real regression in the changed behaviour. If you're adding tests to a path you didn't change, stop.

---

## 6. Test smells (delete-on-sight)

- **Assertion-free / tautological** — no `expect`, or asserts a value against itself, or asserts the mock returns its own stub.
- **Unreachable-outcome assertion** — checks something the code path can't produce (§1).
- **Duplicate-signal sibling** — fails only when another test also fails (§3).
- **Mock-shaped** — asserts call counts/args instead of effects; breaks on refactor (§2).
- **Snapshot-everything** — a giant `toMatchSnapshot` nobody reviews; it rubber-stamps whatever the code emits and "passes" by being regenerated.
- **Coverage-padding** — a test whose only purpose is to execute a line so the % goes up, with no behaviour asserted.
- **Flaky-by-design** — depends on wall-clock, ordering, network, or shared mutable state across tests.

---

## 7. Review rubric (Tester self-check + Spec-Auditor / reviewer gate)

Run this against **every test in the diff** (new or modified). It is the contract the Spec-Auditor uses to check the Tester's output — not just "do tests exist," but "are these tests *good*."

**Per test — all four must hold:**
1. **Names a behaviour.** You can state, in one line, the single cause it catches.
2. **Fails in isolation, for the right reason.** Mentally break the targeted code → this test (and ideally only the relevant tests) goes red. (§1)
3. **Tier-correct & non-duplicative.** It's at the cheapest tier that can prove it, and it doesn't re-prove a branch already owned by another test/tier. (§3, §4)
4. **Behavioural, not structural.** A no-op refactor wouldn't break it. (§2)

**Per suite (the diff as a whole):**
5. **Proportionate** to the change (§5). Count of new tests is justified by count of new branches/behaviours, not by files touched.
6. **No smell** from §6 present.

**Verdict:** any test failing 1–4, or any §6 smell → finding. A reviewer's finding here is **"delete or merge this test,"** the same weight as "add a missing test." The suite is correct when it's the *smallest* set that still trips on every real regression in the changed behaviour — not the largest set that's green.

---

## 8. When to delete an existing test

Deletion is a valid, encouraged outcome — not vandalism — when a test fails the rubric:
- It duplicates another test's signal, or re-proves a branch at a more expensive tier (§4).
- It asserts an unreachable outcome or has no real assertion (§1, §6).
- It's coupled to an implementation detail that has legitimately changed (§2) **and** the behaviour is still covered elsewhere.

Always confirm the behaviour remains covered by a surviving test before deleting, and say so in the handoff/PR. Removing a redundant or invalid test **improves** the suite's signal-to-noise; keeping it is the cost.

---

## References
- [`verification-cookbook.md`](verification-cookbook.md) — the Three-Tier hierarchy; "fastest tier that gives sufficient confirmation."
- [`../frameworks/vitest/conventions.md`](../frameworks/vitest/conventions.md) — "Coverage as a Floor, Not a Goal"; unit/integration patterns; interface doubles.
- [`visual-regression-strategy.md`](visual-regression-strategy.md) — budget rules for the expensive visual tier (the same "don't over-produce" logic, applied to screenshots).
- [`../workflow/tester.md`](../workflow/tester.md) — the T role that runs the §7 self-check before handoff.
- [`../workflow/spec-auditor.md`](../workflow/spec-auditor.md) — the S role that runs the §7 rubric as a gate over T's output.
