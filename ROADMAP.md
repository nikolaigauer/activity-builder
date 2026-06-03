# Roadmap

Ideas and future directions parked here for reference. Not committed to any timeline.

---

## Production Smoke Test — Bugs Found & Fixed (2026-06-02 → 2026-06-03, Writing Centre)

First real deployment: Writing Centre learning tutors journaling their praxis on
ePortfolio Theme 2 + Activity Builder. Three issues found in the director walkthrough,
all fixed 2026-06-03.

### Bug 2 — Em dash / curly apostrophe corrupt in prompts ✓ FIXED
`week’s` → `weeku2019s`, `feedback – giving` → `feedback u2013 giving` — at save time.
- **Real cause (confirmed by local repro):** `update_post_meta()` runs `wp_unslash()` on its
  value internally, and `wp_json_encode()` emits `’`/`—` escapes by default. WP
  strips the backslash out of the stored JSON → `u2019` garbage. **Server-side**, nothing to
  do with the JS `jsonStringify()` wrapper (which is a no-op on modern browsers — `JSON.stringify`
  no longer escapes those chars). Worse latent form: a prompt containing a literal `"` made
  `json_decode` return `null`, silently wiping the page's entire sections array.
- **Fix:** wrap every JSON-meta write in `wp_slash( wp_json_encode( … ) )` so WP's internal
  unslash cancels cleanly. `JSON_UNESCAPED_UNICODE` alone is **not** enough (still breaks on
  `"`/newlines). Applied at `page-builder.php` (`_reflsub_sections`) and `reflection-form.php`
  (`_reflsub_mcq_*`, `_reflsub_student_blocks`).
- **Not auto-repaired:** pages already saved with the corrupt build keep the garbled text in
  the DB — the instructor must retype those prompts once. (Few pages in the smoke test; a
  migration wasn't worth the false-positive risk.)
- **Follow-up (optional):** the now-dead `jsonStringify()`/`jsonStringifyUtf8()` JS wrappers
  can be removed; harmless to leave. Add a round-trip test fixture (`— – ’ " newline`).

### Bug 3 — Student Tools toggle flattened structured pages ✓ FIXED
Enabling Student Tools dropped the instructor's structured media slots, deflating a designed
activity chain into a generic "add whatever" form.
- **Cause:** `reflection-form.php` `continue`'d on `image`/`video`/`embed`/`pdf` sections when
  `$allow_student_blocks` was on (assumed the "+ Add" palette *replaced* baked-in slots).
- **Fix:** removed the skip. Student Tools are now **additive** — structured sections always
  render in the designed order and the palette appends optional extras after them. The
  submission handler already processed both unconditionally, so no handler change was needed.

### Bug 1 — Content Type field was a no-op + now supports ad-hoc creation ✓ FIXED
When `content-type` had no terms, the builder showed a free-text input that *looked* editable
but only stored an orphan slug — `reflection-form.php:905` looks up the term by slug, finds
nothing, tags nothing.
- **Fix:** field is now a single combobox (`<input list=…>` + `<datalist>`) that works whether
  or not terms exist — pick an existing type or type a new one. New `reflsub_resolve_content_type_slug()`
  resolves the input by name/slug and `wp_insert_term()`s it on the fly when missing, storing
  the resulting slug. Instructors can now create content types without leaving the builder.

---

## Re-reflection & Student Growth Features

The core pedagogical insight: ePortfolios derive their value from students being able to
look *back* at past work and notice change, patterns, and recurring ideas. Most platforms
(PebblePad, Mahara) expect students to go looking for connections themselves — very few do.
The opportunity here is to *surface* connections automatically using data we already have:
timestamps, instructor-applied auto-tags, and student-applied tags.

### "You wrote about this before" (highest value, medium effort)
When a student lands on a reflection page, query their past submissions that share one or
more of the current page's auto-tags. Surface 1–3 excerpts quietly in a sidebar or below
the prompt — a gentle nudge toward re-reflection rather than a disruptive popup.
- Plugin provides the query logic and a shortcode/block
- Theme places it in the appropriate template position

### Tag Cloud (medium value, low effort)
Query all of a student's submissions, group by tag, render size proportional to frequency.
A live "what I've been thinking about" widget for the student's portfolio homepage.
Scoped to current author on `/author/` pages.
- Shortcode: `[reflsub_tag_cloud]`
- Plugin provides data + shortcode; theme styles and places it

### Chronological Timeline (medium value, medium effort)
Student's submissions laid out on a timeline, filterable by tag. Makes growth over time
visible at a glance. Pure CSS timeline or Chart.js.
- Shortcode: `[reflsub_timeline]`
- Could be the centrepiece of the portfolio homepage

### Tag Co-occurrence Network (ambitious, longer term)
A D3.js node graph where nodes are tags and edges form when two tags co-occur in the same
submission. As posts accumulate, the network grows. Visually striking and pedagogically
powerful for exposing conceptual relationships the student may not have consciously noticed.
- Would need D3.js bundled or loaded from CDN
- Student-facing block on portfolio page

---

## Architecture Notes: Plugin vs. Theme Split

The plugin owns the **data and query logic** — submissions, tags, sections, privacy.
The theme owns the **presentation context** — `/author/` scoping, layout, placement.

Correct split:
- Plugin provides shortcodes/blocks (`[reflsub_tag_cloud]`, `[reflsub_timeline]`, etc.)
- Theme places these in template parts (sidebar, homepage, author archive)
- All queries in the plugin can be author-scoped using `get_queried_object()` on author
  archive pages, so they work naturally within the `/author/username/` structure

---

## Block-Based Rendering & Instructor-Controlled Styling

Today the activity page renders via the `[reflection_form]` shortcode, with the section
schema stored as JSON in `_reflsub_sections` post meta. Submissions, by contrast, are
already written as native Gutenberg block markup. Three related directions, in increasing
ambition:

### Shortcode → dynamic block (low–medium effort)
Register `activity-builder/form` as a dynamic block with a PHP `render_callback` that calls
the same render function the shortcode uses. Benefits: discoverable in the block inserter,
shows as a labelled block with a placeholder preview on the canvas, future-proof for block
themes / FSE. Honest limit: it's still server-rendered, so the editor shows a placeholder —
it does **not** make the live form or the sections block-editable. Mostly a discoverability /
"feels native" upgrade, not a structural change.

### Hybrid composition: static parts as blocks, inputs as field shortcodes (high effort)
Let instructors arrange the *static* scaffolding — intro text, headings, prompt copy,
images — as ordinary Gutenberg blocks in the page, and drop small per-field tokens only
where a live input belongs (e.g. `[reflection_field id="3"]` or a dedicated field block).
The instructor then controls layout and design with the native editor; only the interactive,
per-user fields stay server-rendered.
- Feasible in principle (this is how some form plugins work).
- Cost: gives up the single-source-of-truth schema and the unified builder. The submission
  handler would have to *discover* which fields exist by scanning page content rather than
  reading one JSON blob — more moving parts, more validation, harder migration.
- Decision to make: is instructor layout freedom worth losing the constrained, typed builder?

### Advanced "design" panel for instructors (medium–high effort)
Rather than instructors editing plugin CSS, give them a styling panel — ideally a split
screen: controls on one side, live placeholder preview on the other, updating in real time.
- Store overrides in an **option / post meta**, output as a scoped `<style>` block on render.
  Never write to the plugin's own CSS files — those are overwritten on update.
- Keep plugin defaults intact so "Reset to defaults" is trivial (just clear the overrides).
- Could be global (site-wide theme) and/or per-page.

This is the more likely long-term answer to "let instructors control the look" without the
re-architecture cost of the hybrid approach — and the two could combine later.

---

## ACF Dependency Removal ✓

Completed. The plugin has **zero required dependencies** — no `get_field()`/`the_field()`
or any ACF call remains anywhere in `inc/` or the main plugin file.
- All field config reads/writes via `get_post_meta()` / `update_post_meta()` (same meta
  keys ACF used — ACF was only ever a wrapper)
- `acf-fields.php` field-group registration deleted
- Both the builder (`_reflsub_sections`) and legacy paths now read post meta directly

---

## Naming & Licensing

- Plugin name needs revisiting before public release
- "Reflection Page" terminology may need to be more generic ("Activity Page"? "Prompt Page"?)
- License: GPL-2.0-or-later (already declared in plugin header — just needs a LICENSE file)
- Add readme.txt in WordPress plugin directory format for potential wp.org submission

---

## Dashboard Menu: Theme + Plugin Handshake

When both the ePortfolio theme and the Activity Builder plugin are active,
the combined admin sidebar should feel like a single coherent product — not two
separate tools bolted together. When only one is active, each should still present
a logical, self-contained menu.

Things to think through:
- The plugin currently adds top-level "Reflections" and "New Post" menus. The theme
  adds its own student dashboard items. Together these may produce a cluttered or
  confusing sidebar, especially for students.
- Consider a shared top-level menu (e.g. "ePortfolio") that both the theme and plugin
  contribute submenus to when they co-exist — plugin detection on both sides to decide
  whether to register as top-level or as a submenu of the shared parent.
- For students (non-admins) the sidebar should be minimal: just "New Post" and maybe
  "My Submissions". Everything else hidden.
- Graceful degradation: plugin active without theme → plugin menus stand alone cleanly.
  Theme active without plugin → theme menus stand alone cleanly.

---

## README: "Customizing styles in your theme" section (small, near-term)

The plugin ships sensible visual defaults but emits stable, documented class names so
theme authors / site admins can override anything without touching plugin code. Document
the override surface in README.md so it's discoverable to anyone browsing the repo, then
later mirror the same content into a Setup admin tab (see next item).

Classes worth listing (non-exhaustive starting set):
- `.reflection-intro` — intro/excerpt box at the top of the activity page
- `.reflsub-prompt-label` — italicized weighted label above each prompt response on rendered posts
- `.reflection-form-wrap`, `.reflection-form`, `.reflection-field` — form scaffolding
- `.reflsub-drop-zone`, `.reflsub-existing-images`, `.reflsub-existing-wrap` — image upload UI
- `.reflsub-student-block`, `.reflsub-student-palette` — student-added blocks palette
- Re-reflect card classes (TBD: confirm names from `reflection-form.php`)

Pattern: include a small copy-pasteable example override block in the README so the
reader doesn't have to invent one.

---

## Setup admin page: "Theme styling" tab (medium effort, blocks on the above)

Current Setup page is a single-flow card layout — no tab framework. Introducing tabs
only makes sense once there's more than one tab justified. Add tabs when both:
1. "Site Setup" (current content) stays as tab 1
2. "Theme styling" (mirror of the README class list, eventually with a color picker
   and live preview) lands as tab 2

Storage pattern when the styling panel grows: persist overrides in a site option,
emit them as a scoped `<style>` block on render. Never write to the plugin's own CSS
files — they're overwritten on update. See "Advanced design panel" section above —
this is the same idea staged smaller.

---

## Gutenberg editor styles for `.reflsub-prompt-label` (tiny, near-term)

The prompt-label class is preserved on a paragraph block round-trip (Gutenberg respects
the `className` attribute), but the visual styling lives in a `wp_head` `<style>` tag
that only fires on the frontend — so students editing a post in Gutenberg see a plain
paragraph instead of the italicized weighted label. The class survives, the styling
doesn't.

Fix: add `enqueue_block_editor_assets` hook that injects the same CSS into the editor
iframe so the editor view matches the published view. ~6 lines. Low risk, high consistency
payoff for any student who opens "Edit Post" after submitting.

---

## TipTap-based rich text editor (large, exploratory)

Replace the current plain `<textarea>` inputs in the page builder (instructor side) with
a [TipTap](https://tiptap.dev/) editor for prompt copy, intro text, and re-reflect headings —
giving instructors inline bold/italic/links/lists without leaving the builder UI.

Optionally make the same editor available to students for paragraph blocks in the
submission form, gated by a per-page toggle (so instructors can choose plain text for
short-form prompts and rich text for longer reflective writing).

Considerations:
- TipTap is ProseMirror-based, modular, framework-agnostic — bundles cleanly without React
- Serialization format choice matters: HTML round-trips into Gutenberg paragraph blocks
  naturally; JSON would need a converter. Probably store as constrained HTML.
- Sanitization: the existing `wp_kses` whitelist approach extends naturally — define an
  allowed-tags set that matches what TipTap can produce
- Bundle size: TipTap + ProseMirror is ~100kb minified — acceptable for the builder,
  worth more thought before loading on every student form
- Conflict surface: WordPress already ships TinyMCE and Gutenberg. Adding a third
  editor is a deliberate choice — justify it by the friction it removes for instructors
  composing prompts and the consistency of UI between prompt config and submission

---

## ePortfolio Theme Cleanup ✓

Completed. The theme (`eportfolio-theme-2`) has been cleaned up:
- `inc/acf-fields.php`, `inc/reflection-form.php`, `inc/post-form.php` stubbed out
- All three removed from the module loader in `functions.php`
- Plugin is now the sole owner of reflection forms, post form, and field config
- Theme retains: content-type taxonomy (with plugin-detection guard), privacy logic,
  portfolio curation, rewrite rules, display/navigation hooks
