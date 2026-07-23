# Decisions — #140 MC-1 Links & Resources

Append-only. Every phase adds an entry.

---

## Phase 1 — O (survey + brief)

**Decided**
- Extend `do_group_extras` for any PHP tweaks; do NOT create a new module (Reuse rule; earned complexity).
- Field type = core `link` (native title+URL per delta) with cardinality `-1`.
- Formatter uses core `link` formatter's built-in `rel: noopener` + `target: _blank` settings — no PHP-side rewrite.
- Create the group Full display YAML in this story (none exists) with named section markers + spaced weights (Description=0, About=10 reserved for #141, Links=20).
- Add `link` to `do_group_extras.info.yml` `dependencies` so assemble-config picks it up for CI.

**Assumed**
- The site-installer + assemble-config path enables `link` module transitively via `do_group_extras`'s dependency; confirm in T's GREEN pass.
- Core link formatter's `rel` setting is a comma-separated list; `noopener` is a valid entry that Drupal emits verbatim on external URLs.

**Hedged**
- If the core `link` formatter does NOT emit `rel="noopener"` on external URLs out-of-box (behavior varies by version), fall back to a minimal `preprocess_field__field_group_links` in `do_group_extras.module` (or hook attribute) that appends `rel="noopener noreferrer"` to `<a>` items with an external absolute URL. Decide at T's RED authoring: test the observable outcome (attribute present in rendered HTML) regardless of mechanism.

**Skipped**
- **D (Designer):** No novel UI. The design is "render a `<section><h2>Links & Resources</h2><ul>...</ul></section>` under Description on the group Full page." Convention-bound; visual polish comes from subtheme CSS (basic list). Wireframe adds no signal; skipping per O's judgment latitude. Recorded here per pipeline rules.

**Evidence**
- `docs/planning/handoffs/140-links/survey.md`
- `docs/planning/handoffs/140-links/brief.md`
- `gh issue view 140 --repo Performant-Labs/groups-on-d11` (title "MC-1: Links & Resources field + rendering", owns disjoint files list matches survey)
- Analogue verified: `docs/groups/config/field.{storage,field}.group{,.community_group}.field_group_description.yml`
