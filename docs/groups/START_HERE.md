# do_runbook — Self-Contained Clean-Room Build

This directory contains **everything** needed for a clean-room installation of Drupal Groups on pl-drupalorg.

## Directory Structure

```
do_runbook/
├── RUNBOOK.md              ← Master orchestration document (~3,150 lines)
├── README.md               ← This file
├── os_HANGING_PROCESSES.md  ← Test troubleshooting (zombie processes)
├── config/                 ← 79 YAML files — all config entities
│   ├── group.type.community_group.yml
│   ├── group.role.community_group-admin.yml
│   ├── flag.flag.*.yml (7 flags + 14 flag actions)
│   ├── views.view.*.yml (11 views)
│   ├── field.storage.*.yml / field.field.*.yml
│   ├── core.entity_view_mode.node.stream_card.yml
│   ├── core.entity_*_display.*.yml (membership form/view displays)
│   ├── block.block.*.yml (3 block placements)
│   ├── taxonomy.vocabulary.*.yml (3 vocabularies)
│   ├── user.role.community.yml
│   ├── pathauto.pattern.group_relationship.yml
│   └── ...
├── modules/                ← 9 custom modules (complete source)
│   ├── do_discovery/       — iCal feeds, content recommendations
│   ├── do_group_extras/    — group form/display helpers
│   ├── do_group_language/  — group-level language negotiation
│   ├── do_group_mission/   — group mission sidebar block
│   ├── do_group_pin/       — pin content in groups (flag-based)
│   ├── do_multigroup/      — cross-post to multiple groups
│   ├── do_notifications/   — follow/mute flags + notification freq
│   ├── do_profile_stats/   — profile completeness + contribution stats
│   └── do_tests/           — integration test module
└── scripts/                ← 18 PHP scripts (idempotent setup)
    ├── step_120a.php       — Create community_group type
    ├── step_120b.php       — Create group fields
    ├── step_130.php        — Create relationship types
    ├── step_160.php        — Verify content type fields
    ├── step_160b.php       — Update attachment limits
    ├── step_170.php        — Create event_types vocabulary
    ├── step_200.php        — Create group_type vocabulary
    ├── step_220.php        — Add field_group_type
    ├── step_300.php        — Set multi-group cardinality
    ├── step_315.php        — Create stream_card view mode
    ├── step_330.php        — Create group_tags + fields
    ├── step_520.php        — Create notification frequency field
    ├── step_620c.php       — Place group mission block
    ├── step_630d.php       — Create field_group_language
    ├── step_640.php        — Multilingual infrastructure
    ├── step_700_demo_data.php — ALL demo data (users/groups/content/flags)
    ├── step_760.php        — Multilingual demo content (FR/DE)
    └── step_770.php        — Solr search server setup
```

## How To Use

1. Follow `RUNBOOK.md` from Phase 1 step by step
2. **Modules**: Copy `modules/` to `web/modules/custom/`
3. **Config**: Import `config/` YAML after creating entities via scripts
4. **Scripts**: Run with `ddev drush php:script docs/groups/do_runbook/scripts/<script>.php`
5. All scripts are idempotent (safe to re-run)

## What's NOT Included

- **Drupal core + contrib** — install via `composer install` per existing `composer.json`
- **DDEV config** — existing `.ddev/` directory provides the dev environment
- **Theme** — `bluecheese` theme is assumed to be installed
- **Database** — clean-room starts from a fresh `ddev drush site:install`
