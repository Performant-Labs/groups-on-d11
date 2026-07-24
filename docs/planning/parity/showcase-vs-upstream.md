# /showcase catalog vs upstream docs-repo feature tour — parity audit

**Issue:** #212 (REL-3, Docs-repo parity sweep)
**Local source:** `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php`
**Upstream source:** https://git.drupalcode.org/project/groupsdrupalorg/-/issues/3578797 ("July 2026 POC Feature Tour & Personas")
**Audit date:** 2026-07-24
**Prior sweeps:** #195 (tooltip inventory), #196 (map view — CLOSED), #197 (theme toggle — CLOSED), #198 (map copy — MERGED).

## Method

Every entry in `ShowcaseCatalog::entries()` and `ShowcaseCatalog::personas()` was cross-referenced against every numbered item and persona in the upstream feature-tour issue. Status codes:

- **MATCH** — local entry mirrors an upstream item with equivalent copy/intent.
- **DRIFT** — local entry maps to an upstream item but copy/framing diverges.
- **MISSING** — upstream item has no local catalog entry.
- **EXTRA / LOCAL-ONLY** — local entry has no upstream counterpart (deliberate local extension).

## Upstream feature-tour items → local catalog

| # | Upstream item | Local catalog entry | Status | Notes |
|---|---|---|---|---|
| 1 | Public Browse (anonymous read access) | (implicit: Anonymous persona) | LOCAL-ONLY (implicit) | Upstream lists as a top-level tour item. Locally covered by the Anonymous persona in the persona-switcher, no dedicated catalog entry. Filed as reconciliation issue — decide whether to add a dedicated entry or accept implicit coverage. |
| 2 | Dual Stream Models | `stream-model` | MATCH | Local decision sentence names node-content vs. activity-log model (post-ST-8/#130 correction). |
| 3 | Persona Switcher (Anonymous, Member, Group Admin, Moderator) | `persona-switcher` | MATCH (with intentional persona-name drift) | Local personas: Anonymous, Elena Garcia (Member), Maria Chen (Organizer), Groups-Moderate. Upstream "Group Admin" → local "Organizer"; upstream "Moderator" → local "Groups-Moderate". Deliberate per #133 SD-6 honesty sweep (brief.md scope item 3). Filed as reconciliation issue — decide whether to push the local names upstream or keep the divergence documented. |
| 4 | Membership Models (open / request-to-join / invite-only) | `membership-models` | MATCH | Local sentence adds a second axis (privacy) — richer than upstream but not contradictory. |
| 5 | Group-Type Homepages (events-first / discussion-first / docs-first) | `group-type-homepages` | MATCH | Both describe per-type UI adaptation. Local sentence is more abstract; upstream names the three concrete variants. Minor DRIFT worth reconciling. |
| 6 | Geographic Directory (Leaflet + geofield) | `directory-presentation` (Map variant) | MATCH | Reconciled by #198 (merged). Local sentence now names Map + geographic axis. |
| 7 | Archive Semantics (archived = published + read-only) | (none) | MISSING | No catalog entry. Feature exists in the demo (archive semantics ship as part of moderation), but is not surfaced on `/showcase`. Filed as reconciliation issue. |
| 8 | Theme Toggle (rejected) | (none — documented in decision record) | Resolved | #197 closed with a decision record; no catalog entry needed. |

## Local catalog entries → upstream

| Local entry | Upstream item | Status |
|---|---|---|
| `discovery-ranking` (Recent / Hot / Promoted) | (none) | LOCAL-ONLY. Upstream tour has no discovery/ranking comparison. Deliberate local extension — flagged with `local_only => TRUE` in the catalog. |
| `directory-presentation` | #6 Geographic Directory | MATCH |
| `membership-models` | #4 Membership Models | MATCH |
| `group-type-homepages` | #5 Group-Type Homepages | MATCH |
| `stream-model` | #2 Dual Stream Models | MATCH |
| `private-group-reveal` | (folded into #4) | LOCAL-ONLY. Upstream folds privacy into membership models; local splits the visibility axis into its own catalog entry (per #134 SC-7). Flagged with `local_only => TRUE`. |
| `persona-switcher` | #3 Persona Switcher | MATCH (with persona-name drift, above) |

## Personas parity

| Upstream | Local `name` | Local `label` | Status |
|---|---|---|---|
| Anonymous | Anonymous | Anonymous | MATCH |
| Member | Elena Garcia | Elena Garcia — Member | MATCH (label preserves the role word) |
| Group Admin | Maria Chen | Maria Chen — Organizer | DRIFT (intentional — #133) |
| Moderator | Groups-Moderate | Groups-Moderate | DRIFT (intentional — #133) |

## Reconciliation issues filed

Filed under label `documentation` at Performant-Labs/groups-on-d11:

- Public Browse (upstream #1) — implicit vs. explicit catalog entry
- Group-Type Homepages copy drift (upstream #5 names three concrete variants; local is abstract)
- Archive Semantics (upstream #7) — MISSING from catalog
- Persona-name drift (upstream #3) — decide push-upstream vs. document-divergence

Already resolved and NOT re-filed:

- #196 Geographic map view — CLOSED
- #197 Theme toggle — CLOSED (decision record)
- #198 Map copy — MERGED

## Regression guard

`ShowcaseCatalogUpstreamRefTest` (new, `tests/src/Unit/`) asserts that every catalog entry carries exactly one of `upstream_ref` (URL to the docs-repo feature tour) or `local_only => TRUE`. Any future entry that omits both fails the test — the same silent-drift failure mode #196/#197/#198 demonstrated is now caught at CI time.

The `ShowcaseCatalog::entries()` return-shape docblock was extended to declare the new optional fields, and every existing entry was populated per the mapping table above.
