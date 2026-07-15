# Pattern: First-Boot Setup Wizard

A multi-step onboarding flow shown once when a self-hosted app has no users yet.
Gate disappears permanently once setup completes; all state is derived from the
database — no separate "setup complete" flag.

---

## When to use

Use a wizard when **all five conditions hold**:

1. The task is complicated (multiple distinct sub-tasks)
2. It requires a specific sequence
3. It cannot be meaningfully interrupted mid-flow
4. It is infrequent (first-boot only, not a daily operation)
5. Completion is mandatory before the rest of the app is usable

If any condition is missing, prefer a single settings page or a progressive
disclosure form instead.

---

## Step count

Keep steps between **3 and 7**. Fewer than 3 is not a wizard; more than 7
overwhelms users. A canonical first-boot wizard covers: create admin account →
create organization → create project → copy API token / CI setup.

---

## Route gating

Gate on a real database predicate, not a flag:

```
SELECT COUNT(*) FROM users
```

- **0 rows** → `/setup` is open; all other non-static routes → `302 /setup`
- **≥ 1 row** → `/setup` → `410 Gone` forever; normal routes open

Wire this in the framework's earliest hook (`onRequest` in Fastify; before any
auth check) so the redirect fires even for unauthenticated requests. Calling
`reply.redirect()` from a hook terminates the hook chain — the route handler
never executes.

Exempt `/setup`, `/setup/step/*`, and static assets from the gate via a
`skipSetupGate` config flag on each route, mirroring the `skipAuth` pattern
already in use.

```ts
// Fastify example
fastify.addHook('onRequest', async (request, reply) => {
  if (request.routeOptions.config.skipSetupGate) return;
  const count = await getUserCount(request.em);
  if (count === 0 && !request.url.startsWith('/setup')) {
    return reply.redirect('/setup');
  }
  if (count > 0 && request.url.startsWith('/setup')) {
    return reply.code(410).send('Gone');
  }
});
```

**Do not** use a `Refresh: 1; url=/setup` header as a catch-all fallback (Gitea
does this to avoid 30x showing up in browser history). Prefer explicit 302s —
they are simpler to test.

---

## Progress indicator

Always show a progress indicator. It must communicate:

- Where the user currently is
- What has been completed
- What remains

Use `role="progressbar"` with `aria-valuenow` / `aria-valuemax`, or a
step-heading hierarchy (`<h2>Step 1 of 4: Create admin account</h2>`). Both
satisfy ARIA. A horizontal step list with completed / current / pending states
is the most scannable visual form.

```html
<nav aria-label="Setup progress">
  <ol>
    <li aria-current="step">Create admin account</li>
    <li>Create organization</li>
    <li>Create first project</li>
    <li>CI/CD setup</li>
  </ol>
</nav>
```

---

## Sequential enforcement

- Users may not skip to a later step.
- Disable or hide the Next button until the current step's form passes
  client-side validation.
- Always allow backward navigation — going back must not lose data.
- Each step POST commits independently so a crash mid-wizard leaves
  the furthest-advanced step as the resume point.

---

## Crash-resumability

Create the draft record as soon as a stable identity exists — i.e., after step 1
creates and authenticates the user. The session cookie then acts as the resume
token; no separate random token is needed.

Two fields cover most cases:

| Field | Type | Purpose |
|---|---|---|
| `current_step` | int | Which step to show on resume |
| `completed_steps` | int[] or bitmask | Which steps to mark done |

On return: query `current_step`, render that step pre-populated with any
previously saved data.

Distinguish "missing" (acceptable in a draft, allow forward progress) from
"invalid" (block submit). Never prevent forward progress on optional fields.

---

## Passphrase field (step 1: admin account)

Encourage **passphrases** — sequences of random words — instead of
character-class complexity rules. Passphrases are longer, higher-entropy, and
far more memorable than `Tr0ub4dor&3`.

### Guidance to display (before the user types)

> Choose a passphrase — three or four random words work well.  
> Example: `coffee-table-mountain-rain`  
> Minimum 16 characters. No character-class requirements.

### Strength scoring

Use **zxcvbn** for real-time scoring. It measures actual guessability rather
than character class compliance, making it ideal for passphrases.

```ts
import zxcvbn from 'zxcvbn';

const result = zxcvbn(password, [email, displayName]); // penalise user inputs
// result.score: 0–4
// result.feedback.warning / .suggestions (only when score ≤ 2)
```

- Pass the user's email and display name as `user_inputs` so `zxcvbn` penalises
  passwords that contain them.
- Scores map to: 0 = too weak, 1 = weak, 2 = fair, 3 = strong, 4 = very strong.
- Require score ≥ 3 to enable the Next button.
- Show feedback text only when score ≤ 2 (`result.feedback.warning`).

### Strength bar

Render a horizontal bar with four segments, coloured by score:

| Score | Colour | Label |
|---|---|---|
| 0–1 | Red | Too weak |
| 2 | Amber | Fair |
| 3 | Green | Strong |
| 4 | Green (full) | Very strong |

### UX rules

- Show the guidance text and the (empty) strength bar **before** the user types.
- Provide a show/hide toggle with an accessible label (`aria-label="Show password"`).
  The toggle must not steal focus from the input.
- **Never disable paste** — this breaks password managers and passphrase
  generators.
- Minimum length: 16 characters. No uppercase, number, or symbol requirements.
- Do not show a confirm-password field; the single-field + show/hide toggle is
  the modern standard.

### zxcvbn loading

Bundle size is ~400 kB minified. Load it at the bottom of the page or via
dynamic import to avoid blocking page render:

```ts
const { default: zxcvbn } = await import('zxcvbn');
```

---

## Token display (CI/CD step)

API tokens are shown **once only** at creation time.

- Display the token in a read-only input with a **Copy** button.
- Show an explicit warning: *"Save this token now — it cannot be retrieved after
  you leave this page."*
- Offer a **Download as .env** fallback (plain-text file with `API_TOKEN=<value>`).
- Token is hidden by default (`type="password"`); provide a reveal toggle.
- Never omit the copy button — do not rely on manual text selection.

Strapi, GitHub, and GitLab all implement exactly this pattern.

---

## Env-var seed path

If the following env vars are all set **and** the users table is empty at boot,
run the seed transaction automatically and skip the wizard entirely:

```
CTRFHUB_INITIAL_ADMIN_EMAIL
CTRFHUB_INITIAL_ADMIN_PASSWORD   (or …_FILE for secret injection)
CTRFHUB_INITIAL_ADMIN_ORG_NAME
```

Rules:
- **Idempotent** — if users > 0, skip silently regardless of whether the env
  vars are set.
- Run in a **single transaction** (user + org + optional project). Roll back
  entirely on any failure.
- After seeding, `/setup` returns 410 from the first request.
- Document a `…_FILE` suffix variant (e.g., `CTRFHUB_INITIAL_ADMIN_PASSWORD__FILE`)
  for container secret injection (Gitea pattern).

---

## Alpine.js + server-rendered HTML notes

- Track `currentStep` in a top-level Alpine `x-data` component.
- Each step POST is a standard form submit (no fetch/XHR). HTMX `hx-post` on
  the form with `hx-target="main"` and `hx-swap="outerHTML"` swaps the entire
  step region.
- The server re-renders the next step template (or the same step with errors)
  and returns it as the HTMX partial.
- Password strength runs entirely client-side via Alpine; the strength score is
  not sent to the server (it is a UX signal only — server enforces length + a
  minimum `zxcvbn` score server-side via a hidden input or re-check in the route
  handler).
- For the copy button, Alpine's clipboard factory (`navigator.clipboard.writeText`)
  is the simplest approach; no `exec('copy')` fallback needed for modern browsers.

---

## Checklist

- [ ] Route gate wired in `onRequest` hook; `/setup*` and static assets exempt
- [ ] `410 Gone` returned for `/setup` once users > 0
- [ ] Env-var seed path runs at boot; idempotent
- [ ] Progress indicator with `aria-current="step"` on active step
- [ ] Steps commit independently (crash-resumable)
- [ ] Session cookie used as resume token after step 1
- [ ] Passphrase guidance shown before user types
- [ ] zxcvbn loaded async; user inputs passed as second argument
- [ ] Strength bar visible; Next disabled until score ≥ 3
- [ ] Show/hide toggle accessible; paste not disabled
- [ ] Token shown once with Copy button + download fallback
- [ ] T2 ARIA: progress indicator, all inputs labeled, Next/Back as `<button>`
- [ ] T1 tests: `/setup` → 410 after users > 0; redirect fires for non-setup routes
