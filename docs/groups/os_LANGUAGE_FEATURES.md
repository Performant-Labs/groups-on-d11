# Open Social: Language & Multilingual Features

## Overview

Open Social is built on Drupal, which has mature multilingual support built into its core. Out of the box, however, a fresh Open Social installation runs as a single-language site with no translation tooling enabled. This document explains what standard Drupal core provides, and what becomes available when you enable the optional `social_language` module included in the Open Social distribution.

---

## Standard Drupal Core: What Is Already There

Drupal ships with four multilingual modules that form the foundation of all translation functionality. They are present in every Open Social installation but must be enabled and configured manually:

| Module | Purpose |
|---|---|
| `language` | Adds and manages languages on the site |
| `interface_translation` | Translates UI strings (buttons, labels, menus) |
| `config_translation` | Translates configuration text (email templates, site name, etc.) |
| `content_translation` | Translates content fields (titles, body text, etc.) |

### What an administrator can do with core alone

- Add languages to the site (e.g. Spanish, French, Arabic)
- Set a default language for the site
- Configure how the site detects which language to serve — by URL prefix (`/es/...`), browser setting, session, or account preference
- Download and apply community-contributed UI translations from [localize.drupal.org](https://localize.drupal.org)
- Manually translate configuration items and content nodes through the admin UI

### Limitations without `social_language`

While the core modules are powerful, they have no awareness of Open Social's specific roles, content types, or UI components. This means:

- The **Site Manager role** has no translation permissions by default — only administrators can manage translations
- Open Social's **activity stream**, **notification centre**, and **notification emails** do not respond to language settings
- There is no per-user language preference field visible in the Open Social profile UI
- Links generated inside the activity stream lose their language prefix, sending multilingual users to the wrong language version of a page
- All setup requires manual configuration by a developer or administrator

---

## Enabling `social_language`: What Changes

The `social_language` module is an optional module included in the Open Social distribution. Enabling it activates all four core translation modules in a single step and applies a set of pre-configured defaults and permissions specifically designed for Open Social's role structure and content model.

### For end users

- **Users will be able to choose their preferred interface language** directly from their own account settings page, without needing administrator intervention. The site will remember their preference and serve the correct language on every visit.

### For site managers

Site managers are the non-developer community administrators in Open Social. Without `social_language`, they have no translation capabilities at all. After enabling it, site managers will be able to:

- **Translate content** — create, update, and delete translations of topics, events, and pages so that community members in different languages see content in their own language
- **Translate menu links** — update navigation labels so the site's menu structure reads naturally in each enabled language
- **Translate account settings text** — update the copy in account-related emails and forms, so communications sent to users (password resets, welcome emails, etc.) arrive in the correct language
- **Translate system information** — update the site name, slogan, and other global text that appears across the platform

> **Note:** Site managers cannot add or remove languages from the site — that remains an administrator-only action.

### For administrators

- **The four translation modules are enabled automatically** in a single action, with sensible defaults already applied. No manual module-by-module activation and configuration is needed.
- **Translation file storage** is pre-configured out of the box, so downloaded translation files work without additional setup.

### Platform-wide behaviour

- **The activity stream becomes translation-aware.** Activity items (e.g. "Maria commented on your event"), notifications, and notification emails will be rendered in the correct language for each user rather than always defaulting to the site's base language.
- **Language prefixes persist in stream links.** When URL-based language detection is active, links inside the activity stream correctly carry the language code (e.g. `/es/node/123`). Without this fix, users following activity links would silently land on the English version of a page.

---

## Summary Comparison

| Capability | Core only (manual setup) | With `social_language` |
|---|---|---|
| Multiple languages on the site | ✅ Possible, manual | ✅ Streamlined |
| UI translation (buttons, labels) | ✅ Possible, manual | ✅ Auto-enabled |
| Content translation | ✅ Possible, manual | ✅ Auto-enabled |
| Config translation (emails, site name) | ✅ Possible, manual | ✅ Auto-enabled |
| Site manager can translate content | ❌ No permissions by default | ✅ Granted on install |
| Site manager can translate menus & emails | ❌ No permissions by default | ✅ Granted on install |
| Per-user language preference on profile | ❌ Not wired into Open Social UI | ✅ Visible on user settings page |
| Activity stream respects language | ❌ Not translation-aware | ✅ Fully translation-aware |
| Notification emails respect language | ❌ Not translation-aware | ✅ Fully translation-aware |
| Language prefix preserved in stream links | ❌ Links lose language prefix | ✅ Links carry correct prefix |

---

## What `social_language` Does Not Cover

Enabling `social_language` gives you the infrastructure and permissions for a working multilingual community, but some advanced scenarios remain outside its scope:

- **Adding and removing languages** is still an administrator task — site managers cannot manage the language list
- **Serving entirely different content streams to different language audiences** (i.e. a Spanish-speaking user seeing a completely different homepage feed from an English-speaking user) is part of GoalGorilla's commercial multilingual add-on
- **Machine translation or automatic content translation** is not included — all translations are created manually by site managers or content editors
- **Right-to-left (RTL) language layout** support depends on the theme and is not handled by `social_language` alone

---

*Based on Open Social distribution source code and documentation. `social_language` is included in the free open source distribution available at [drupal.org/project/social](https://www.drupal.org/project/social).*
