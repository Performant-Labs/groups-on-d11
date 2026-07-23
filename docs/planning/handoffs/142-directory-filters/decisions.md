# Decision Journal — #142 Directory location + language filters

Run slug: `142-directory-filters` · Started 2026-07-22 · Overnight autonomous mode.

## O — Phase 1 (initial survey + brief)
Decided: skip D; location = free-text; language = `field_group_language`; new `field_group_location`.
Assumed: no #139 blocker.
Evidence: issue text, existing view yml, baseline field yml.

## A — Phase 3 (up-front plan review, r1) → BLOCK
Blocking:
1. `field_group_location` collides with #125 (geofield). Rename to `field_group_location_text`.
2. Language-field authority unresolved vs #139 (`field_group_primary_language`).
Warns:
3. Pin views language filter `plugin_id: language`.
4. Kernel test must run anonymous, not UID 1.
5. Form/view-display collision — dissolves with rename.

Handoff: `handoff-A-plan.md`.

## O — Phase 3.5 (amend, overnight-mode adjudication)
Decided:
- Rename to `field_group_location_text` (accept A #1).
- Use baseline `field_group_language` (already on origin/main). #139 issue text itself says "verify vs `do_group_language`; reuse if it already provides this" — this decision is *consistent with* #139's own reuse preference, so overnight-mode picks forward progress.
- Pin `plugin_id: language`. Kernel test runs anonymous.

Assumed (Open — surface in Chain Summary):
- #139 owner accepts reusing `field_group_language` rather than introducing a parallel `field_group_primary_language`.

Evidence: A handoff `handoff-A-plan.md`; #139 issue body; #125 issue body confirms geofield ownership.

Hedged: if S ultimately blocks on field-name choice, we would either rename baseline (invasive) or add an alias — for POC we ship and let #139 adapt.
