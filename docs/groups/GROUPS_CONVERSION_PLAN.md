# Converting Groups from Open Social to Standard Drupal

Plan for porting the groups feature built in pl-opensocial (Open Social 13 distribution) into the standard modern Drupal codebase (pl-drupalorg). This is **not** a from-scratch implementation — it's a conversion that identifies what can be reused, what must be adapted, and what must be rebuilt for standard Drupal 10.

---

## Gap Analysis: What pl-opensocial Has That pl-drupalorg Does Not

> [!CAUTION]
> Everything below is **missing** from pl-drupalorg and must be installed, ported, or replaced before the groups feature can work. This is the foundational inventory for sizing and sequencing the work.

### Contrib Modules (installed via Composer)

> **Source key**: 🟦 Install = standard Drupal contrib package • 🟧 Copy & adapt = take from Open Social and modify • 🟥 Build = create from scratch • ⬜ Skip/Evaluate = may not be needed

| Module | What it provides | Source | Action |
|---|---|---|---|
| `drupal/group` | Group entity type, memberships, relationships, permissions | 🟦 Install | `composer require drupal/group` |
| `drupal/flag` | Flagging API (pin-in-group, promote-to-homepage, follow) | 🟦 Install | `composer require drupal/flag` |
| `drupal/flag_count` | Flag counting support for Views | 🟦 Install | `composer require drupal/flag_count` |
| `drupal/linkit` | Inline entity linking in CKEditor | 🟦 Install | `composer require drupal/linkit` |
| `drupal/markdown` | Markdown text filter | ⬜ Evaluate | Only if wiki/markdown content is desired |
| `drupal/message_notify` | Notification email dispatch | 🟦 Install | `composer require drupal/message_notify` |
| `social_tagging` | Free-tagging taxonomy on content | 🟥 Build | Use Drupal core taxonomy; Open Social version is tightly coupled |
| `social_event_type` | Event Type taxonomy + reference field | 🟥 Build | Create vocabulary + entity reference field manually |
| `social_event_managers` | Event Managers (multi-value user ref) | 🟥 Build | Create user reference field manually |
| `social_event_an_enroll` | Anonymous event enrollment | 🟥 Build | Custom enrollment form; Open Social version depends on `event_enrollment` entity |
| `social_event_max_enroll` | Max enrollment cap | 🟥 Build | Custom validation; Open Social version depends on `event_enrollment` entity |
| `social_follow_tag` | Subscribe to taxonomy tags | 🟦 Install Flag + 🟥 Build | Flag module provides the mechanism; config is custom |
| `social_follow_user` | Follow another user | 🟦 Install Flag + 🟥 Build | Flag module provides the mechanism; config is custom |
| `social_follow_content` | Follow specific content | 🟦 Install Flag + 🟥 Build | Flag module provides the mechanism; config is custom |
| `social_language` | Multilingual bundle (enables 4 core modules, grants perms) | 🟥 Build | Enable `language`, `content_translation`, etc. directly + grant perms manually |
| `statistics` (core) | Page view counts for hot scoring | 🟦 Install | `drush en statistics -y` (Drupal core module) |

### Custom Modules (9 modules in `web/modules/custom/`)

| Module | Purpose | Source | Adaptation needed |
|---|---|---|---|
| `pl_discovery` | Hot content scoring, Views integration | 🟧 Copy & adapt | 🟢 Low — no Open Social deps; copy as-is, change `.info.yml` |
| `pl_opensocial_wiki` | `[[Title]]` wiki-style link filter | 🟧 Copy & adapt | 🟢 Low — generic text filter plugin; copy as-is |
| `pl_group_mission` | "About this group" sidebar block | 🟧 Copy & adapt | 🟢 Low — change theme region from `complementary_bottom` → bluecheese equivalent |
| `pl_group_extras` | Archive enforcement, moderation queue, submission guidelines | 🟧 Copy & adapt | 🟡 Medium — rename `flexible_group` → new group type in all hooks |
| `pl_profile_stats` | Contribution stats + completeness blocks | 🟧 Copy & adapt | 🟡 Medium — adapt profile field names + theme regions |
| `pl_group_pin` | Flag-based content pinning + Views sort | 🟧 Copy & adapt | 🟡 Medium — Views query alter table aliases differ |
| `pl_group_language` | Group-level language negotiation | 🟧 Copy & adapt | 🟡 Medium — plugin is generic; field setup on group entity differs |
| `pl_multigroup` | Multi-group posting | 🟧 Copy & adapt | 🔴 High — heavily uses `group_relationship` API; plugin IDs change with group type |
| `pl_notifications` | Subscription management, per-post opt-out | 🟥 Build (reference only) | 🔴 High — depends on Open Social's `activity_send_email`; must rebuild on `message_notify` or custom |

> [!NOTE]
> pl-drupalorg has **no** `web/modules/custom/` directory at all. It will need to be created.
>
> **7 of the 9 modules can be copied** from `pl-opensocial/web/modules/custom/` and adapted. Only `pl_notifications` needs a full rewrite because it depends on Open Social's activity pipeline which doesn't exist in standard Drupal.

### Theme & UI Elements

| Element | Source | Notes |
|---|---|---|
| **Group header** (banner, stats, tabs) | 🟥 Build | bluecheese has no group template; must create from scratch |
| **Group sidebar** (mission, members, events) | 🟧 Copy Twig + adapt | Copy templates from `pl_group_mission`; remap to bluecheese regions |
| **Group directory page** | 🟥 Build | Views page; SocialBlue styles don't transfer |
| **"Pinned" badge** on content cards | 🟧 Copy CSS + adapt | Copy from `pl_group_pin/css/`; adjust selectors for bluecheese markup |
| **"Cross-posted from" badge** | 🟧 Copy CSS + adapt | Copy from `pl_multigroup/css/`; adjust selectors |
| **"Archived" badge** on groups | 🟧 Copy CSS + adapt | Copy from `pl_group_extras/css/`; adjust selectors |
| **Group Audience fieldset** (multi-group selector) | 🟧 Copy & adapt | Form alter comes with `pl_multigroup`; styling needs bluecheese update |
| **Contribution stats grid** on profiles | 🟧 Copy Twig + CSS | From `pl_profile_stats/templates/`; adapt field names |
| **Profile completeness bar** | 🟧 Copy Twig + CSS | From `pl_profile_stats/templates/`; adapt field list |
| **Activity stream** (homepage) | 🟥 Build | Open Social stream is deeply theme-integrated; build a Views page |
| **Quick-post composer** on homepage | ⬜ Skip | Open Social-specific; not core to groups functionality |
| **Notification bell icon** in header | ⬜ Evaluate | Only needed if notifications are implemented |
| **~30 frontend JS libraries** | ⬜ Evaluate | bluecheese has its own JS stack; most are not needed |

### Infrastructure & Configuration

| Component | Source | Notes |
|---|---|---|
| **Group entity type** | 🟥 Build | Create new group type via `drupal/group` UI/config; Open Social's `flexible_group` config is not portable |
| **Group relationship types** (membership, node→group) | 🟥 Build | Plugin IDs are generated per group type; must configure from scratch |
| **Group permissions** (join, post, manage) | 🟥 Build | Map to pl-drupalorg's role structure (differs from Open Social) |
| **Views** (7+ views: directory, stream, pending, hot, promoted, tags, RSS) | 🟧 Reference only | Can study Open Social's View YAML for field/filter ideas but must recreate for different entity types |
| **Flag entities** (pin_in_group, promote_homepage, follow_content) | 🟧 Copy config | Flag entity YAML from `config/sync/flag.flag.*.yml` is mostly portable |
| **Taxonomy vocabularies** (group_type, event_types) | 🟧 Copy config + terms | Vocabulary YAML is portable; terms must be created via Drush |
| **Field storages** on Group entity | 🟥 Build | Field names can match but must be created for the new group type |
| **Language negotiation chain** | 🟧 Copy plugin | `LanguageNegotiationGroup` plugin is Drupal-generic; copy from `pl_group_language` |
| **Test suite** (64 Playwright tests) | ⬜ Evaluate | Port to Nightwatch, add Playwright, or manual verify |
| **`ddev post-install` command** | ⬜ Evaluate | pl-drupalorg uses `composer recreate`; may not need equivalent |

---

## Key Differences Between the Projects

| | pl-opensocial | pl-drupalorg |
|---|---|---|
| **Base** | Open Social 13 distribution | Standard Drupal 10 (drupal.org codebase) |
| **PHP** | 8.3 | 8.4 |
| **DB** | MariaDB 11.8 | MariaDB 10.3 |
| **Theme** | SocialBlue (Open Social) | bluecheese (custom) |
| **Group module** | Bundled with Open Social | **Not installed** (`drupal/group` needed) |
| **Search** | Solr | OpenSearch |
| **Deploy** | Docker Compose on Linode | Helm charts on Kubernetes |
| **Tests** | Playwright | Nightwatch (accessibility) |
| **Services** | None extra | RabbitMQ, OpenSearch, Storybook |

> [!IMPORTANT]
> The pl-opensocial work was built on top of Open Social's bundled Group module, flexible_group entity type, and SocialBlue theme. **None of these exist in pl-drupalorg.** Every reference to Open Social-specific APIs, entities, Views, hooks, and theme regions must be adapted.

---

## Document-by-Document Assessment

### Documents that are directly applicable (with adaptation)

| Document | Relevance | Adaptation needed |
|---|---|---|
| [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) | ⭐️ **Core reference** — 8-phase plan with test specs | Phases must be re-sequenced for standard Drupal; Open Social-specific steps removed |
| [FEATURES.md](FEATURES.md) | ⭐️ **Feature inventory** — definitive list of what to build | Remove Open Social distribution references; map features to standard Drupal modules |
| [DEVELOPER_NOTES.md](DEVELOPER_NOTES.md) | ⭐️ **Gotchas** — config key ordering, PHP patches, missing field storages | Config key ordering applies universally; MentionsFilter patch is Open Social-specific |
| [os_HANGING_PROCESSES.md](os_HANGING_PROCESSES.md) | ⭐️ **Troubleshooting** — 21 catalogued hang types | DDEV/process issues apply directly; Playwright-specific items need Nightwatch equivalents |
| [INSTALL.md](INSTALL.md) | 🔶 **Reference only** — install steps are Open Social-specific | pl-drupalorg already has onboarding in its `README.md`; merge useful DDEV tips |

### Documents that need significant rewrite

| Document | Relevance | What changes |
|---|---|---|
| [RUNBOOK.md](RUNBOOK.md) | 🔶 **Template** — step-by-step build log | Every `ddev drush` command references Open Social entities/config; needs full rewrite for standard Drupal |
| [DEMO_DATA_PLAN.md](DEMO_DATA_PLAN.md) | 🔶 **Template** — demo data seeding scripts | Entity types, fields, and group bundles differ; content themes should match drupal.org context |
| [DOCKER_DEPLOY.md](DOCKER_DEPLOY.md) | 🔶 **Reference** — Docker + host nginx architecture | pl-drupalorg uses Helm charts, not Docker Compose on a single Linode |
| [INSTALL_ON_PROD.md](INSTALL_ON_PROD.md) | 🔶 **Reference** — Linode production deployment | Different deployment target; may inform a similar doc for pl-drupalorg's infra |

### Documents that are informational only

| Document | Status |
|---|---|
| [OPEN_SOCIAL_LANGUAGE_FEATURES.md](OPEN_SOCIAL_LANGUAGE_FEATURES.md) | Describes `social_language` module (Open Social-specific). Standard Drupal multilingual is configured differently. Useful as a reference for what multilingual features to support. |
| [feature_tour/FEATURE_TOUR.md](feature_tour/FEATURE_TOUR.md) | Screenshots from Open Social. Will need entirely new screenshots once groups are built in pl-drupalorg. The structure and annotations are a good template. |

---

## Proposed Phases

> [!CAUTION]
> **DOCUMENTATION ONLY.** Each iteration produces runbook documentation, not code execution. Do NOT run any `composer`, `drush`, or `ddev` commands. Write the step-by-step instructions that a developer will follow later.

The work is iterative — each iteration delivers documentation that can be reviewed before proceeding.

### Iteration 0 — Coding & Testing Guidelines

**Goal**: Establish project coding conventions and testing standards before writing any code, since pl-drupalorg currently has no `CONTRIBUTING.md`, no linting config, and no functional tests.

**What to do**:
1. **Examine recent commits** to pl-drupalorg (via `git log`) to extract the team's implicit conventions:
   - Commit message format and branch naming
   - Code style (indentation, naming, use of type hints, docblock patterns)
   - How config changes are committed (`config/sync/` diffs)
   - How composer dependency additions are handled
2. **Pull standards from official modern Drupal documentation**:
   - [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards) (PHP, CSS, JS, Twig)
   - [Module Development Guide](https://www.drupal.org/docs/develop/creating-modules) (Drupal 10+)
   - [Drupal Testing Guide](https://www.drupal.org/docs/develop/automated-testing) (PHPUnit, Nightwatch, Kernel tests)
   - [Configuration Management](https://www.drupal.org/docs/configuration-management)
   - [Hook system and hook_event_dispatcher](https://www.drupal.org/docs/develop/creating-modules/understanding-hooks) patterns for Drupal 10+
3. **Produce deliverables**:
   - `CONTRIBUTING.md` — branch workflow, commit message format, merge request checklist
   - `phpcs.xml.dist` — PHP CodeSniffer config using `Drupal` and `DrupalPractice` standards
   - `phpunit.xml` — PHPUnit config for custom module unit/kernel testing
   - Testing strategy doc — clarify when to use Nightwatch (a11y), PHPUnit (unit/kernel), and whether to introduce Playwright (functional/E2E)
4. **Validate**: Run `phpcs` against a sample of existing project code to confirm the config is sane

**Source**: Official Drupal.org documentation + `git log --oneline -50` on pl-drupalorg

> [!IMPORTANT]
> This iteration produces **no Drupal code** — only standards documents and tool configs. Everything built in later iterations should follow these guidelines.

---

### Iteration 1 — Foundation & Module Installation

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document the steps to install the Drupal Group module and establish the base group type.

**What to do**:
1. `composer require drupal/group` (the standard Drupal Group module, not Open Social's bundled version)
2. Enable the module: `drush en group -y`
3. Create a group type (e.g., `community_group`) with fields for name, description, and visibility
4. Configure group-node relationships for existing content types (if drupal.org has discussion-like content types)
5. Set up basic permissions for authenticated users to create/join groups
6. Create a group listing page

**Source docs**: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phase 3, [FEATURES.md](FEATURES.md) Groups section

**Key decision needed**: What content types does pl-drupalorg have that should be postable to groups? The Open Social project used Topic, Event, and Page. drupal.org likely has different content types (project, issue, page, etc.).

---

### Iteration 2 — Group Types & Membership Models

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document how to configure group types and membership models matching g.d.o's original groups functionality.

**What to do**:
1. Create group_type taxonomy with terms: Geographical, Working Group, Distribution, Event Planning, Archive
2. Configure membership models:
   - **Open** (direct join)
   - **Moderated** (request → approval)
   - **Invite Only** (admin-managed)
3. Build group directory View at `/all-groups` with filters
4. Port `pl_group_extras` module (archive enforcement, moderation queue, submission guidelines)
   - Adapt hooks from `flexible_group` to the new group type
   - Change theme from `socialblue` to `bluecheese`

**Source docs**: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phase 3, [RUNBOOK.md](RUNBOOK.md) Steps 300-370

---

### Iteration 3 — Content in Groups

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document how to enable posting content to groups.

**What to do**:
1. Configure group-content relationships for the relevant content types
2. Build group stream Views
3. Port the multi-group posting module (`pl_multigroup`)
   - Adapt from `flexible_group` to the new group type
   - Update group_relationship plugin IDs
4. Enable tags on group content

**Source docs**: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phase 5, [RUNBOOK.md](RUNBOOK.md) Steps 700-760

---

### Iteration 4 — Discovery & Feeds

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document hot content scoring, promoted content, RSS, and iCal feeds.

**What to do**:
1. Port `pl_discovery` module (hot content scoring)
   - The `pl_discovery_hot_score` DB table and cron hook are Drupal-generic (no Open Social dependencies)
2. Install and configure Flag module for "Promote to homepage"
3. Create RSS feeds for group streams
4. Create iCal feeds for events (if events exist in pl-drupalorg)

**Source docs**: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phase 4, [RUNBOOK.md](RUNBOOK.md) Steps 500-630

---

### Iteration 5 — Notifications & Subscriptions

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document email notification infrastructure for group activity.

**What to do**:
1. Evaluate pl-drupalorg's existing notification infrastructure (RabbitMQ is available)
2. Port `pl_notifications` module
   - Replace Open Social's `activity_send_email` pipeline with standard Drupal message/rules
   - Subscription management page
   - Per-post opt-out
3. Configure email templates for group events (new content, membership changes)

**Source docs**: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phase 6, [RUNBOOK.md](RUNBOOK.md) Steps 800-850

> [!WARNING]
> Open Social has a built-in `activity_send_email` pipeline and `ActivityDigestWorker`. pl-drupalorg does not. This is the area requiring the most new infrastructure.

---

### Iteration 6 — User Profiles & Group Admin

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document profile stats, group admin features (pinning, language, mission).

**What to do**:
1. Port `pl_profile_stats` (contribution stats, profile completeness)
   - Adapt field names to pl-drupalorg's profile fields
   - Change theme regions from `socialblue` to `bluecheese`
2. Port `pl_group_pin` (content pinning within groups)
   - Adapt Views query alter for pl-drupalorg's Views structure
3. Port `pl_group_mission` (group description sidebar block)
   - Change theme region from `complementary_bottom` to the equivalent in `bluecheese`
4. Evaluate whether `pl_group_language` (group-level language switching) is needed for drupal.org

**Source docs**: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phases 7-8, [RUNBOOK.md](RUNBOOK.md) Steps 915-1100

---

### Iteration 7 — Demo Data & Feature Tour

> ⚠️ **DOCS ONLY** — Write the runbook; do not execute commands.

**Goal**: Document how to populate the site with realistic data and create a feature tour.

**What to do**:
1. Rewrite `DEMO_DATA_PLAN.md` for pl-drupalorg's content types and context
2. Create demo users, groups, content, and memberships
3. Capture screenshots and write a feature tour for the bluecheese theme
4. Update deployment documentation

**Source docs**: [DEMO_DATA_PLAN.md](DEMO_DATA_PLAN.md), [feature_tour/](feature_tour/)

---

## Custom Modules to Port

All 9 custom modules from pl-opensocial will need varying degrees of adaptation:

| Module | Difficulty | Notes |
|---|---|---|
| `pl_group_extras` | 🟡 Medium | Hooks reference `flexible_group`; need new group type name |
| `pl_multigroup` | 🔴 High | Deeply tied to Open Social's `group_relationship` system |
| `pl_discovery` | 🟢 Low | Schema + cron hook are Drupal-generic |
| `pl_notifications` | 🔴 High | Depends on Open Social's activity pipeline |
| `pl_profile_stats` | 🟡 Medium | Field names and block regions differ |
| `pl_group_pin` | 🟡 Medium | Views query alter needs different table aliases |
| `pl_group_language` | 🟡 Medium | Language negotiation plugin is Drupal-generic |
| `pl_group_mission` | 🟢 Low | Simple block plugin; just change theme region |
| `pl_opensocial_wiki` | 🟢 Low | Text filter plugin is Drupal-generic |

---

## Decisions Needed Before Starting

> [!IMPORTANT]
> These questions need answers before Iteration 0 can begin:

1. **What content types should be postable to groups?** The drupal.org codebase has its own content types (projects, issues, pages, etc.). Which ones should support group posting?

2. **Should we use the same Group module version?** The `drupal/group` module has had major API changes between 1.x and 2.x/3.x. Open Social bundles its own version.

3. **Theme regions**: The `bluecheese` theme has different regions than `socialblue`. We need to map the sidebar, content, and complementary regions.

4. **Testing framework**: pl-drupalorg uses Nightwatch. Should we port the Playwright tests to Nightwatch, or add Playwright as a second test framework?

5. **Deployment pipeline**: Will the groups feature deploy through the existing Helm chart pipeline, or does it need its own staging approach?

---

## Verification Plan

### Automated Tests

pl-drupalorg currently uses **Nightwatch** for accessibility testing:
```bash
ddev yarn nightwatch --your --options
```

The pl-opensocial project has 64 Playwright tests across 8 phases. Options for verification:

1. **Port to Nightwatch** — rewrite the test specs to use Nightwatch's API. Most natural fit since pl-drupalorg already uses it.
2. **Add Playwright** — install Playwright alongside Nightwatch. More test coverage but a second framework to maintain.
3. **Manual verification** — use Drush commands and browser checks for each iteration.

> [!IMPORTANT]
> I'd recommend asking the user which testing approach they prefer before starting.

### Manual Verification (per iteration)

After each iteration:
1. Run `ddev drush cr` and verify no PHP errors in `ddev logs -s web`
2. Visit the site in a browser and confirm new features render in the bluecheese theme
3. Run `ddev drush config:status` to confirm config is clean
4. Run existing Nightwatch tests to confirm no regressions:
   ```bash
   composer nightwatch
   ```
