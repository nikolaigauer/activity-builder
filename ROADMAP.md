# Roadmap

Ideas and future directions parked here for reference. Not committed to any timeline.

---

## Edit-mode media retention (known bug + design, 2026-06-05)

**Symptom:** A student returning to edit a submitted post loses the original audio recording —
the existing take isn't retrieved, so even with no edits they can't resubmit (required audio
blocks; the audio is dropped from content).

**Confirmed root cause:** the edit-mode "existing recording" lookup queries
`post_mime_type => 'audio'` (`reflection-form.php`), but browser recordings are stored as
**`video/webm` / `video/mp4`** — webm and mp4 are containers WP types as *video* even when
audio-only. The query matches nothing → no existing block rendered → no keep input → audio lost.
Images (`image/*`) and PDFs (`application/pdf`) are unaffected because their mimes are reliable.

**Robust fix (preferred):** stop querying by mime. The saved post content already carries the
attachment id in its block markup (e.g. `wp:audio {"id":118}`, `wp:image {"id":…}`,
`wp:file {"id":…}`). On edit, parse `post_content` for those block ids — single source of truth,
immune to mime quirks, uniform across media types.
- Cheaper stopgap: broaden the audio query to `array('audio','video/webm','video/mp4','video/ogg')`.

**"Only drop when the user decides":** once retrieval is reliable, each existing media block in
edit mode should render the media + a hidden *keep* input (preserve if untouched) + an explicit
**× Remove** control (drop only on deliberate action). Images already do this; audio + PDF only
support "replace or keep" today and need the explicit remove affordance.

**Deeper refactor opportunity:** replace the per-type ad-hoc keep logic
(`reflsub_keep_image_ids` / `reflsub_keep_pdf_id` / `reflsub_keep_audio_id` + 3 separate queries)
with ONE "existing media" layer: parse blocks → {type, attachment_id, position} → uniform
keep+remove UI → on save, preserve untouched slots in place, re-upload only changed ones. Collapses
duplicated code and makes the next media type trivial.

---

## Feature Requests (2026-06-03)

Incoming from instructors using the Writing Centre deployment.

### Audio recording section type (in progress 2026-06-03)
Let students record an audio response in the browser — a new `audio` section type alongside
`text`/`image`/`pdf`/etc. Chosen approach: **native `MediaRecorder` + `getUserMedia`**, no
external libraries, no transcoding, no telephony vendor. The recorded blob rides the *exact*
storage path the `pdf` section already uses (`$_FILES` → `wp_handle_upload()` → attach to post),
so the back half is already built.

- **Recording limit:** 5 minutes (hard cap — auto-stops the recorder and surfaces remaining
  time in the UI).
- **Playback before submit:** `URL.createObjectURL(blob)` into an `<audio controls>` so the
  student can listen and re-record before committing.
- **Format:** store whatever the browser emits — `audio/webm` (Opus) on Chrome/Firefox,
  `audio/mp4` on Safari/iOS. Both play in all modern browsers; no server-side conversion.
  Allow both MIME types in the upload filter.
- **"Via phone":** the *phone browser* path is free and already covered by `getUserMedia` on
  mobile Safari/Chrome (uses the device mic). True call-in telephony (Twilio etc.) is parked —
  recurring cost + external vendor in the privacy story, not worth it unless an accessibility
  driver appears.
- **Gotchas:** `getUserMedia` requires HTTPS (or `localhost` for dev); ~1 MB/min for Opus, so
  a 5-min clip ≈ 5 MB — reuse the existing client-side `post_max_size` guard; reset the file
  input without wiping the assigned blob (same DataTransfer dance as the drag-drop image zone).
- **Three-place wiring** (mirrors every section type): builder toggle in `page-builder.php`,
  recorder widget + save branch in `reflection-form.php`, same widget in `post-form.php`.

### Activity Page duplicator (parked)
A **Duplicate** row-action under the Actions column on Dashboard › Activity Builder › Activity
Pages (Edit / View / Duplicate / Trash). Handler: `get_post()` the source → `wp_insert_post()`
a **Draft** copy with `" (Copy)"` appended → copy the activity-definition meta only
(`_reflsub_sections`, `reflection_prompt_*`, `submission_privacy`, `allow_*`, `content_type_label`,
`is_reflection_page`).
- **Copy `_reflsub_sections` as the raw stored string** — do *not* decode/re-encode, or you risk
  re-triggering the `wp_slash` / `\uXXXX` JSON-meta corruption fixed 2026-06-03.
- Never copy student submission posts — only the activity definition.
- Nonce + `current_user_can('edit_pages')` on the handler (state-changing link).
- Lands as Draft so the instructor edits before publishing.
- Effort: ~half a day. Lower priority than audio.

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

## Rich text in student submissions — Trix (v1.2.0, DECIDED 2026-06-05)

Give students optional inline formatting (bold/italic/links/lists/headings/quote) in
submission text — useful for reflective writing and for playing with text/poetry. Not a
need; a deliberate, contained nice-to-have.

**Editor choice: [Trix](https://trix-editor.org/), NOT TipTap.** TipTap is ProseMirror/ESM and
assumes a bundler — adopting it means adding a build toolchain, which breaks the plugin's
deliberate **no-build, zero-dependency** character (its biggest virtue; see ACF removal). Trix
is a single `trix.js` + `trix.css`, no build, drop into `assets/` and enqueue exactly like
`audio-recorder.js`. It emits clean semantic HTML and is purpose-built for comment/submission
boxes. (Quill was runner-up but stores its own "Delta" JSON → extra conversion glue.)

**Scope for v1.2.0 — start simple:**
- **Student submissions only** (skip the instructor prompt-authoring idea above for now —
  one surface at a time).
- **Per-page toggle** to begin (`_reflsub_*` page meta, e.g. `_reflsub_allow_rich_text`), set in
  the Activity Page builder. Per-prompt-section granularity is a later refinement.
- **Off by default.** When enabled, the student textarea gets a "Format ✨" button that swaps in
  the Trix editor on demand (student opt-in).

**Design principles (keep complexity contained):**
- **Textarea stays the source of truth.** Trix syncs its HTML back into the hidden/real textarea
  on change. If the script fails to load or the student never opts in, plain text submits
  normally. Purely additive, progressive enhancement.
- **Sanitize hard on save.** Run the HTML through `wp_kses` with a tight allowlist
  (`strong/em/u`, `a[href]`, `ul/ol/li`, `h3/h4`, `blockquote`, `br`) — extend the existing
  `reflsub_sanitize_embed_code` allowlist pattern. Never trust client HTML.
- **Serialization:** wrap the sanitized HTML into block markup (`wp:paragraph` / `wp:list` /
  `wp:heading` / `wp:quote`) — paragraphs allow inline formatting natively.
- **Touchpoints to handle:** localStorage autosave would store HTML; the word-counter should
  count `textContent` not markup; edit-mode round-trip loads saved HTML back into Trix.
- Enqueue the same way as the audio recorder (own `assets/` files, `filemtime()` versioning).

---

## ePortfolio Theme Cleanup ✓

Completed. The theme (`eportfolio-theme-2`) has been cleaned up:
- `inc/acf-fields.php`, `inc/reflection-form.php`, `inc/post-form.php` stubbed out
- All three removed from the module loader in `functions.php`
- Plugin is now the sole owner of reflection forms, post form, and field config
- Theme retains: content-type taxonomy (with plugin-detection guard), privacy logic,
  portfolio curation, rewrite rules, display/navigation hooks
