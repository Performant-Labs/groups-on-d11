# 0002 — Theme toggle (bluecheese vs default) is rejected for the demo

**Status:** Rejected (2026-07-24)
**Sources:** Issue #197, upstream https://git.drupalcode.org/project/groupsdrupalorg/-/issues/3578797

## Decision

The demo will **not** offer a runtime theme toggle between the default theme
and a "bluecheese" alternative. A single custom theme (`groups_chrome`)
ships and is the only user-visible theme.

## Rationale

The upstream docs-repo issue considered a theme toggle as a variant-switcher
candidate and explicitly rejected it. Reasoning captured here so a future
contributor doesn't re-open the question:

- **Variant switcher's purpose is decision support** for group-module
  behavior (persona, membership model, discovery ranking, layout, model). Theme
  choice doesn't teach anything about the Group module — it teaches about
  Drupal theming, which is out of scope for this demo.
- **Adding a toggle multiplies the QA matrix** — every persona × every
  showcase variant × every screen would need visual verification against two
  themes. Cost/benefit doesn't clear the bar for a POC.
- **A single theme lets copy assume specific chrome** — e.g., the SD-4 tooltip
  wiring on the stream switcher relies on selectors that would need parallel
  CSS in a second theme.

## Consequences

- No user-facing setting or URL parameter switches themes.
- Any future theme-related demo need should be handled by shipping the
  content into a separate site or a preview link, not an in-app toggle.

## Implementation

No code change required — this is a decision to NOT build a feature.
Documented so the question stays answered.
