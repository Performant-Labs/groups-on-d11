# Project Profile — EXAMPLE (copy per site)

> ⚠️ **WEBSITE FRONT-END PIPELINE — PROJECT PROFILE (instance config).** The per-site
> values the pipeline needs — like a connection string. Copy to the project repo (e.g.
> `docs/<project>/frontend-pipeline-profile.md`) and fill in. Pair with an adapter
> (the platform "how") and the core (the platform-agnostic engine).

Example values below are from a real DripYard/Drupal site (shown as a worked example —
replace all of them).

## Identity
- **Project / slug:** `pl2` (performantlabs.com homepage overhaul)
- **Adapter:** `drupal-canvas-sdc`
- **Local site URL:** `https://pl-performantlabs.com.3.ddev.site:8493`
- **Theme machine name:** `performant_labs_20260418`
- **Theme inheritance chain:** `dripyard_base → neonbyte → performant_labs_20260418`

## Paths
- **Active theme root:** `themes/neonbyte`
- **Parent theme root(s) (reuse + scan):** `themes/dripyard_base`
- **Token files:** `themes/neonbyte/css/themes/*.css`, `themes/neonbyte/css/_variables/`
- **Component roots:** `themes/dripyard_base/components/`, `themes/neonbyte/components/`
- **Handoff / docs dir:** `docs/pl2/handoffs/`
- **Runbook:** `docs/pl2/pl-plan--<page>-overhaul.md`

## Verification config
- **axe base URL:** the local site URL above (`AXE_BASE_URL`)
- **Stateful-surface inventory:** `docs/pl2/stateful-surfaces.md` + `scripts/state-invariants.config.json`
- **Breakpoint overrides:** none (uses adapter default 992px)
- *(Audit scan/render config — css-scan roots, render-inspect themeVars — lives in the
  separate Website Audit Pipeline's profile.)*

## Posture
- **Repo posture:** local-only — merge with `--no-ff`, never push, no PRs. *(Set per project;
  most projects will push and open PRs normally.)*
