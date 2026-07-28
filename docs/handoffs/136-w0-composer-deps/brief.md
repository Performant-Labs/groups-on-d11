# Brief — #136 W0: Dependency pre-story

## Objective

One PR that adds ALL program composer dependencies to `composer.json`/`composer.lock` up
front, so later Wave 1-4 stories only enable/configure — never touch the lock file again
except via the documented serial-merge rule. Modules are added but **NOT enabled**. No config,
no seed data, no UI, no behavior change.

## Survey

`docs/handoffs/136-w0-composer-deps/survey.md` — Reuse-map summary: extend the existing
`require` block in place (same pattern as the prior `drupal/flag`/`drupal/group` additions);
no new composer.json structure needed. Key findings:

- **masquerade, message, message_notify, geofield, geofield_map**: all have D11-compatible
  releases, no Group-core coupling, safe to add.
- **grequest (membership-request mechanism, for #121): DO NOT ADD.** No Group-4.x-compatible
  release/branch exists; latest release (3.2.4) hard-requires `drupal/group: ^3.0`, which
  conflicts with this project's `drupal/group: 4.0.x-dev`. Finding recorded on #121 via PR
  description (this pipeline run posts the comment at merge time, per acceptance criteria).
- **Map formatter (for #125): `drupal/geofield_map`** — the only viable contrib map-formatter
  project (no standalone Leaflet-views package exists on drupal.org). Finding recorded on
  #125.

## Input documents

- GitHub issue #136 (this repo, Performant-Labs/groups-on-d11)
- Epic #108 (Streams) for cross-epic wave/rule context
- `docs/groups/RUNBOOK.md` (pre-sanctions message/message_notify as Phase 5 optional deps)
- `composer.json` (current require block)

## Acceptance criteria

- [ ] `composer.json` `require` gains exactly 5 new entries: `drupal/masquerade`,
      `drupal/message`, `drupal/message_notify`, `drupal/geofield`, `drupal/geofield_map` —
      alphabetically sorted (composer.json has `sort-packages: true`).
- [ ] `drupal/grequest` is **NOT** added — finding recorded in PR body + as a follow-up
      comment on issue #121.
- [ ] `composer.lock` regenerated cleanly via a real `composer require` run (or
      `composer update` for the lock) inside the DDEV/container environment — verified
      against Drupal 11 core + `drupal/group: 4.0.x-dev`, not just release-history metadata.
- [ ] `composer install` / `composer validate` clean on a fresh checkout of the branch.
- [ ] None of the 5 newly-required modules are enabled — assert enable-state unchanged
      (`drush pm:list` before/after, or equivalent check) — this PR changes no runtime
      behavior.
- [ ] Existing suite green (no behavior changed, so this should be a no-op regression check).
- [ ] PR description records: masquerade D11-compat verification (for #120), the
      grequest/Group-4.x incompatibility finding (for #121), and the geofield_map formatter
      choice (for #125) — per the issue's "Verification notes recorded" acceptance line.

## Handoff locations

- `docs/handoffs/136-w0-composer-deps/survey.md`
- `docs/handoffs/136-w0-composer-deps/brief.md` (this file)
- `docs/handoffs/136-w0-composer-deps/decisions.md` (decision journal)
- `docs/handoffs/136-w0-composer-deps/handoff-A.md`
- `docs/handoffs/136-w0-composer-deps/handoff-F.md`
- `docs/handoffs/136-w0-composer-deps/handoff-S.md`

## Branch name

`136-w0-composer-deps` off `origin/main`.

## Review-rigor dial

**`none`** — thin/mechanical dependency-manifest change; the story itself declares "Review
rigor: none" and CI build is the check. In-session review only (A + light S). No Designer, no
outside dual-review, no UI Walkthrough (D and U are N/A — no UI surface).

## Pipeline shape for this run

`O -> A (quick plan: confirm package names + versions) -> F (implement: composer require x5) ->
verify (composer install/validate + enable-state assert + existing suite) -> S (light spec
check, no visual audit) -> O (PR)`. T(red)/T(green) authored-test cycle is not applicable in
the traditional sense — there is no new PHP/behavior to unit-test; F's own verification (clean
resolve + unchanged enable-state + green suite) is the "test." A confirms this shape as
appropriate for a manifest-only change before F proceeds.
