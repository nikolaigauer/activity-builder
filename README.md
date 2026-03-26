# Reflection Submissions

A WordPress plugin for structured reflection in higher-education ePortfolios.

Instructors build reflection prompt pages using a section-based visual builder. Students complete and submit responses directly on the page via the `[reflection_form]` shortcode. No external dependencies — zero ACF, zero page-builder plugins required.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- No additional plugins required

---

## Installation

1. Download the latest release zip from the [Releases](../../releases) page.
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and activate.

---

## Features

### For Instructors
- **Section-based page builder** — compose reflection pages from typed sections: text prompts, multiple-choice questions, PDF embeds, video/image upload fields, and re-reflect cards.
- **Re-reflect section** — automatically surfaces a student's past submission (filtered by date range and tags) to prompt deeper reflection.
- **Submission management** — browse, filter, and read all student submissions per reflection page.
- **Feedback** — leave written feedback on individual submissions; students see it on their My Submissions page.
- **Progress grid** — at-a-glance view of which students have completed which tasks.

### For Students
- Rich text responses, image uploads, PDF attachments, video URLs, and embedded content — all configurable per section.
- Autosave draft (localStorage) with restore notice on reload.
- Friendly client-side file size guard before submission.

---

## Usage

### Shortcode

Add `[reflection_form]` to any page that has been configured as a reflection page via the builder. No attributes required — the plugin reads the page's saved configuration automatically.

### Page Builder

Go to **Reflections → Reflection Pages** in the WordPress admin and create or edit a page. Use the section builder to add and reorder sections, then save. Publish the corresponding WordPress page and add the shortcode.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
