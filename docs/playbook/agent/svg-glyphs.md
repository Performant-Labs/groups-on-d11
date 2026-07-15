# SVG icons & glyphs — don't hand-author geometry

> **Why this exists.** Agents repeatedly produce malformed icons in wireframes and templates. The
> root cause is always the same: **hand-authoring SVG coordinate/path data**. An LLM cannot see what
> it draws — it computes geometry symbolically — so any icon built from invented `<path d>` /
> `<polygon points>` / elliptical-arc data is unverified vector math and tends to come out broken.
> The fix is to **stop drawing icons by hand** and to **render-and-look before handoff**.

## The rule

**Never invent SVG icon geometry.** In priority order:

1. **Low-fi wireframes — use Unicode glyphs or labeled placeholders, not vector art.** A wireframe
   communicates structure, not pixels; a hand-drawn vector icon is both over-engineering and the
   single biggest source of "weird glyph" rework. Use a Unicode symbol or a boxed text label:

   | Need | Use | Not |
   |------|-----|-----|
   | play | `▶` / `►` | a hand-built triangle `<polygon>` |
   | pause | `⏸` / `❚❚` | two hand-placed rects |
   | replay / refresh | `↺` / `⟳` | a hand-built elliptical-arc + arrowhead |
   | record / live | `●` (color it) | — |
   | check / pass | `✓` | — |
   | wrong / close | `✕` | — |
   | stepper up/down | `▴` / `▾` (or `−` / `+`) | hand-built chevrons |
   | mic | `🎙` or a boxed `[mic]` label | a hand-built capsule+arc+stem |
   | spinner | a boxed `[⟳ loading]` label, or ONE `<circle>`+arc defined in `<defs>` | re-drawn per site |

2. **When a true vector icon is genuinely required** (production templates, or a wireframe that must
   show the real icon): **copy the exact `<path d="…">` from the project's icon set.** Most repos
   here use **Heroicons** (inline `<svg viewBox="0 0 20 20">` / `24 24` paths — e.g. Language Buddy's
   `item-card.eta`). Copy a known-good path; **do not write or "tweak" `d`/arc data by hand.** If the
   icon isn't in the set, pick the closest one that is, or fall back to a Unicode glyph — do not
   invent it.

3. **Define each icon once; `<use>` it everywhere.** Put the glyph in `<defs>` at a **local origin
   (0,0)** and stamp every instance with `<use href="#id" transform="translate(cx,cy)"/>`. Never
   re-type per-instance absolute coordinates — that is how instances drift (a dropped stem, a Y
   offset). One definition = every instance identical by construction.

## Hard bans (the specific failure modes seen)

- **No hand-written elliptical-arc (`A rx ry rot large-arc sweep x y`) commands for icons.** Arc
  flags are pure non-visual math; this is the #1 "broken curve" cause (the "replay circular arrow").
  Use `↺`/`⟳`, or copy a Heroicon `arrow-path`/`refresh` path.
- **No per-instance absolute coordinates** for a repeated glyph — `<defs>` + `<use>` instead.
- **No icon ships unlooked-at.** See the verification step.

## Verification (mandatory before handoff)

A glyph you did not look at is unverified. Before handing off any SVG containing icons:

1. **Render it and look** — open the file in the preview panel (the harness auto-previews written
   SVG/HTML), or `show_widget` it, or open in a browser. Confirm **every** icon instance is complete,
   centred in its button, and reads as the intended symbol.
2. Check the cheap-to-miss things: each `<use>` resolves (the `href` id exists in `<defs>`); no
   instance is clipped or off-canvas (a wrong Y puts it in another section); proportions look right at
   the size actually used.
3. If you cannot render, say so in the handoff and flag the icons as **unverified** rather than
   implying they're correct.

## One line

*Don't draw icons — reference them (Unicode for low-fi, copied Heroicon paths for production),
define once and `<use>`, and **look at the render** before you hand it off.*
