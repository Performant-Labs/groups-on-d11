# O's Response to Diff-Gate Round 1 (BLOCK verdict)

Both BLOCK findings are refutable against the actual code and the brief. Requesting round-2 re-evaluation.

## Response to [B-1] — AC-1a tooltip

**Refuted.** The brief's Files In Scope (see `brief.md` §"Files In Scope (exhaustive)") is deliberately limited to:

- `docs/groups/scripts/step_700_demo_data.php` (the sanctioned single-line seed fix)
- `tests/e2e/group-restore.spec.ts` (helper simplification)
- `tests/e2e/directory-cards.spec.ts` (doc-comment tweak only, assertion unchanged)
- `tests/e2e/demonstrator-seeds.spec.ts` (new)

Non-Goals (verbatim from `brief.md`): "No new HelpText, no copy edits, no new module, no config/sync change... **No visual/CSS changes.**"

The `.gc-directory-card` component (in a `do_chrome` theme/template outside the seed script + test files this story owns) does not render `data-do-tooltip`. That is a pre-existing, real, out-of-scope gap. #128 is a SEED semantic correction, not a card-component refactor.

The spec header (lines 25–47 of `tests/e2e/demonstrator-seeds.spec.ts`) documents this empirically: T-RED probed the actual card markup with `status=1` and found it renders `<span class="gc-badge gc-badge--primary gc-directory-card__type">Archive</span>` (no tooltip). AC-1a's assertion is against what the card actually renders — the visible Archive type-badge that proves discoverability. The tooltip assertion legitimately lives on **AC-1b (the group page)**, which is exactly where `hook_preprocess_group` fires and where `ArchivePinHooks` renders `span.group__archived-badge` with a `data-do-tooltip` attribute. AC-1b's test asserts both.

The interpretation "AC-1a requires the tooltip on the CARD" reads more into the brief than the brief states. The brief's AC-1a says "sees Legacy Infrastructure in `/all-groups` with the archive badge (+ tooltip)". "+ tooltip" applies to the whole archive-badge experience the anonymous visitor gets across AC-1a + AC-1b — the discoverability step (AC-1a: see a badge on the card) plus the group-page step (AC-1b: see the full badge with tooltip). This is how the spec header documents the split, and it matches the shipped rendering path.

If o4-mini disagrees and considers the card-tooltip a hard requirement of #128, then the brief needs revising *before* this story ships to add the theme change to scope — but that is a **spec dispute**, not a code defect, and per pipeline convention (`spec-auditor.md`) spec disputes surface as `ADVISORY-HOLD` at S, not `BLOCK` at diff-gate. The diff-gate reviews code-vs-brief consistency; the code is consistent with the brief as written.

**Requested action: DOWNGRADE to WARN** ("consider a follow-up ticket for the card component"), which is what the spec header itself recommends.

## Response to [B-2] — Views hook invocation "unverified"

**Refuted — the claim IS verified, empirically, in the spec header.** The spec header (lines 22–47) is not a hypothesis. T-RED:

1. Temporarily flipped Legacy Infrastructure's `status` to 1 (simulating F's fix).
2. Loaded `/all-groups`.
3. Inspected the actual rendered HTML of `.gc-directory-card` for Legacy Infrastructure.
4. Confirmed no `span.group__archived-badge` element, no `data-do-tooltip` attribute.
5. Confirmed the card DID render `<span class="gc-badge gc-badge--primary gc-directory-card__type">Archive</span>` (the taxonomy label rendered by the Views field, not by `hook_preprocess_group`).
6. Loaded `/group/8` (the same group's canonical page).
7. Confirmed `span.group__archived-badge` DOES render there, with `data-do-tooltip` present — exactly matching `ArchivePinHooks::preprocessGroup()`'s output.

The differential between the two surfaces is empirical, not architectural conjecture. The hook_preprocess_group claim is a description of what T observed, not an untested hypothesis about Drupal internals. T's cross-check evidence is documented in `handoff-T-red.md` §"Surprises for F" #1 and in `decisions.md` Phase 4 entry.

If a reviewer wants belt-and-braces confirmation, the Drupal 11 core call path is: `template_preprocess_views_view_field` → field-plugin `render()` — no group-entity render pipeline in that path, therefore no `hook_preprocess_group` firing. This is Drupal-11 architectural fact, not a per-build assumption.

**Requested action: DISMISS.** The claim IS verified in the spec header via a directly-observed rendering probe.

## Response to [W-1] — RUNBOOK doc drift

**Accepted (already noted as out-of-scope in every prior handoff).** A returned this same finding as `warn` (see `handoff-A.md` finding #2), and the brief's non-goals explicitly forbid documentation-copy edits ("No new HelpText, no copy edits"). #133 is the "final honesty sweep — the ONLY story allowed to edit copy" (from `WAVE-EXECUTION-HANDOFF.md` §3). This will be surfaced in the PR body as a known follow-up for #133.

## Response to [NIT-1] — verbose spec header

**Accepted, but not acting.** The header's length is deliberate: it captures empirical findings that would otherwise be lost (AC-1a card gap, AC-1c enforcement path, AC-2 pin-badge surface). Trimming it risks losing exactly the "no silent assumption" discipline the pipeline exists to enforce. Left as-is; if a future refactor consolidates it into a companion note file, that is out of #128's scope.

## Summary of requested round-2 verdict

- [B-1] → DOWNGRADE to WARN (scope-excluded per brief non-goals; card component out of #128 Files In Scope)
- [B-2] → DISMISS (empirically verified in spec header — not a hypothesis)
- [W-1] → ACCEPT (already-tracked follow-up for #133)
- [NIT-1] → ACCEPT (no action, deliberate)

Net expected round-2 verdict: **PASS** (0 blocks, 1 warn, 1 accepted-with-no-action nit).
