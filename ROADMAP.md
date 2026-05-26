# Roadmap

Ideas and future directions parked here for reference. Not committed to any timeline.

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

## ACF Dependency Removal

The plugin is already in a transitional state — pages created via the builder store all
data in `_reflsub_sections` (JSON post meta) and no longer need ACF at all. ACF is only
hit via the "legacy path" in `reflection-form.php` for pages originally built in the
ePortfolio theme before the plugin was extracted.

Removing the ACF dependency is feasible and would make the plugin fully standalone:
- Replace `get_field( 'foo', $id )` → `get_post_meta( $id, 'foo', true )` throughout
  the legacy path (underlying meta keys are identical — ACF is just a wrapper)
- Remove `acf-fields.php` field group registration
- Add a one-time migration notice for any pre-existing ACF-configured pages

Do this before a public/ETUG release so the plugin has zero required dependencies.

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

## ePortfolio Theme Cleanup ✓

Completed. The theme (`eportfolio-theme-2`) has been cleaned up:
- `inc/acf-fields.php`, `inc/reflection-form.php`, `inc/post-form.php` stubbed out
- All three removed from the module loader in `functions.php`
- Plugin is now the sole owner of reflection forms, post form, and field config
- Theme retains: content-type taxonomy (with plugin-detection guard), privacy logic,
  portfolio curation, rewrite rules, display/navigation hooks
