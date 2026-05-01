<?php
/**
 * New Post Form (plugin version)
 *
 * Adds a simplified "New Post" page for students (Author role and above).
 * Supports text, image(s), and embed sections plus WP standard tags.
 *
 * Submenu parent: top-level "New Post" menu (edit_posts capability)
 * Save action:    admin_post_reflsub_create_post
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Admin menu registration ────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_post_form_register' );
function reflsub_post_form_register() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    add_menu_page(
        'New Post',
        'New Post',
        'edit_posts',
        'reflsub-new-post',
        'reflsub_render_post_form',
        'dashicons-edit',
        27
    );
}


// ── Enqueue media uploader on this page only ──────────────────────────────────

add_action( 'admin_enqueue_scripts', 'reflsub_post_form_enqueue' );
function reflsub_post_form_enqueue( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'reflsub-new-post' ) {
        return;
    }
    wp_enqueue_media();
}


// ── Render new post form ──────────────────────────────────────────────────────

function reflsub_render_post_form() {

    $user_id = get_current_user_id();

    // Status message after draft save
    $edit_link = '';
    if ( isset( $_GET['reflsub_draft'] ) ) {
        $draft_id = intval( $_GET['reflsub_draft'] );
        if ( $draft_id ) {
            $edit_link = get_edit_post_link( $draft_id );
        }
    }

    // Available tags (top 20 most-used)
    $used_tags = get_terms( array(
        'taxonomy'   => 'post_tag',
        'orderby'    => 'count',
        'order'      => 'DESC',
        'number'     => 20,
        'hide_empty' => true,
    ) );

    // Content-type terms
    $content_types = taxonomy_exists( 'content-type' )
        ? get_terms( array( 'taxonomy' => 'content-type', 'hide_empty' => false ) )
        : array();

    ?>
    <div class="wrap">
    <div class="reflsub-app">

        <?php if ( isset( $_GET['reflsub_draft'] ) && $edit_link ) : ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
            <p>Draft saved. <a href="<?php echo esc_url( $edit_link ); ?>">Edit it in the full editor →</a></p>
        </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['reflsub_error'] ) ) : ?>
        <div class="notice notice-error is-dismissible" style="margin-bottom:20px;">
            <p><?php echo esc_html( urldecode( $_GET['reflsub_error'] ) ); ?></p>
        </div>
        <?php endif; ?>

        <div class="reflsub-page-header">
            <div>
                <h1>New Post</h1>
                <p>Write and publish a post to your portfolio.</p>
            </div>
        </div>

        <form id="reflsub-post-form" method="post" enctype="multipart/form-data"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

            <?php wp_nonce_field( 'reflsub_create_post', 'reflsub_post_nonce' ); ?>
            <input type="hidden" name="action" value="reflsub_create_post">
            <input type="hidden" name="reflsub_sections_data" id="reflsub-sections-data" value="">

            <div class="reflsub-card">
                <div class="reflsub-card-header">Post Details</div>
                <div class="reflsub-card-body">

                    <div class="reflsub-field">
                        <label for="reflsub-post-title">
                            Title <span class="reflsub-optional">optional</span>
                        </label>
                        <input type="text" id="reflsub-post-title" name="reflsub_post_title"
                               placeholder="Give your post a title…">
                    </div>

                    <?php if ( ! empty( $content_types ) && ! is_wp_error( $content_types ) ) : ?>
                    <div class="reflsub-field">
                        <label>Content Type</label>
                        <div class="reflsub-check-group">
                            <?php foreach ( $content_types as $ct ) : ?>
                            <label class="reflsub-check-label">
                                <input type="checkbox" name="reflsub_content_types[]"
                                       value="<?php echo esc_attr( $ct->term_id ); ?>">
                                <?php echo esc_html( $ct->name ); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="reflsub-field">
                        <label for="reflsub-tags-input">
                            Tags <span class="reflsub-optional">optional</span>
                        </label>
                        <input type="text" id="reflsub-tags-input" name="reflsub_tags"
                               placeholder="Comma-separated tags…">
                        <?php if ( ! empty( $used_tags ) && ! is_wp_error( $used_tags ) ) : ?>
                        <span class="reflsub-field-desc" style="margin-bottom:8px; display:block;">Previously used — click to add:</span>
                        <div id="reflsub-tag-chips" style="display:flex; flex-wrap:wrap; gap:6px;">
                            <?php foreach ( $used_tags as $tag ) : ?>
                            <button type="button" class="reflsub-tag-chip"
                                    data-tag="<?php echo esc_attr( $tag->name ); ?>">
                                <?php echo esc_html( $tag->name ); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <div class="reflsub-card">
                <div class="reflsub-card-header">Content</div>
                <div class="reflsub-card-body">
                    <p class="reflsub-card-desc">Add sections to build your post.</p>
                    <div id="reflsub-post-sections"></div>
                    <div class="reflsub-add-palette">
                        <span class="reflsub-add-label">+ Add</span>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubPostAddSection('text')">Text</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubPostAddSection('image')">Image(s)</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubPostAddSection('pdf')">PDF / File</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubPostAddSection('embed')">Embed / URL</button>
                    </div>
                </div>
            </div>

            <div class="reflsub-actions">
                <button type="submit" name="reflsub_post_action" value="publish" class="reflsub-btn-primary">
                    Publish
                </button>
                <button type="submit" name="reflsub_post_action" value="draft" class="reflsub-btn-secondary">
                    Save Draft
                </button>
            </div>

        </form>
    </div><!-- .reflsub-app -->
    </div><!-- .wrap -->

    <style>
        /* Tokens shared with page-builder.php — defined there on :root */

        /* ── App shell ─────────────────────────────────────────────── */
        .reflsub-app {
            max-width: 820px;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* ── Page header ───────────────────────────────────────────── */
        .reflsub-page-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; padding: 24px 28px; margin-bottom: 24px;
            background: #fff;
            border: 1px solid #e2e8f0; border-top: 6px solid #ff4128;
            /*border-radius: 12px; */
            box-shadow: 0 1px 3px rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.04);
        }
        .reflsub-page-header h1 {
            font-size: 20px; font-weight: 800; color: #1b28b4;
            margin: 0 0 4px; padding: 0; line-height: 1.2;
            text-transform: uppercase; letter-spacing: .06em;
        }
        .reflsub-page-header p { font-size: 14px; color: #64748b; margin: 0; }

        /* ── Cards ─────────────────────────────────────────────────── */
        .reflsub-card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.04);
            margin-bottom: 20px; overflow: hidden;
        }
        .reflsub-card-header {
            padding: 13px 24px; font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .reflsub-card-body { padding: 24px; }
        .reflsub-card-desc { margin: 0 0 20px; font-size: 14px; color: #64748b; }

        /* ── Fields ────────────────────────────────────────────────── */
        .reflsub-field { margin-bottom: 20px; }
        .reflsub-field:last-child { margin-bottom: 0; }
        .reflsub-field > label {
            display: block; font-size: 15px; font-weight: 600;
            color: #334155; margin-bottom: 6px;
        }
        .reflsub-optional {
            font-size: 12px; font-weight: normal; color: #64748b;
            background: #f1f5f9; padding: 1px 6px; border-radius: 4px; margin-left: 5px;
        }
        .reflsub-field input[type="text"] {
            display: block; width: 100%; max-width: 520px;
            padding: 9px 12px; font-size: 14px; font-family: inherit;
            color: #0f172a; background: #f8fafc;
            border: 1.5px solid #e2e8f0; border-radius: 7px;
            box-sizing: border-box; -webkit-appearance: none; appearance: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .reflsub-field input[type="text"]:focus {
            border-color: #1b28b4; background: #fff;
            box-shadow: 0 0 0 3px rgba(27,40,180,.18); outline: none;
        }
        .reflsub-field-desc { display: block; margin-top: 5px; font-size: 12px; color: #64748b; }

        /* ── Checkbox group ────────────────────────────────────────── */
        .reflsub-check-group { display: flex; flex-wrap: wrap; gap: 10px; }
        .reflsub-check-label {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 13px; color: #334155; cursor: pointer;
            background: #f8fafc; border: 1.5px solid #e2e8f0;
            border-radius: 7px; padding: 6px 12px;
            transition: border-color .15s, background .15s;
        }
        .reflsub-check-label:has(input:checked) {
            border-color: #ace7d4; background: #f3feca; color: #141e88;
        }

        /* ── Content section cards ─────────────────────────────────── */
        .reflsub-post-section {
            background: #fff; border: 1px solid #ace7d4;
            border-radius: 9px; margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.04);
        }
        .reflsub-post-section-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 16px; background: #f3feca;
            border-bottom: 1px solid #ace7d4; border-radius: 9px 9px 0 0;
        }
        .reflsub-post-section-header span {
            font-weight: 700; font-size: 14px; color: #141e88;
            text-transform: uppercase; letter-spacing: .08em;
        }
        .reflsub-post-section-header .button {
            border-radius: 999px !important; border-color: rgba(255,65,40,.28) !important;
            color: #ff4128 !important; background: rgba(255,65,40,.08) !important;
            padding: 5px 14px !important; font-size: 12px !important; font-weight: 600 !important;
            line-height: 1.7 !important; height: auto !important; box-shadow: none !important;
            transition: background .15s !important;
        }
        .reflsub-post-section-header .button:hover { background: rgba(255,65,40,.15) !important; }
        .reflsub-post-section-body { padding: 16px; }
        .reflsub-post-section-body label {
            display: block; font-weight: 600; font-size: 13px; color: #64748b;
            text-transform: uppercase; letter-spacing: .07em; margin-bottom: 5px;
        }
        .reflsub-post-section-body textarea,
        .reflsub-post-section-body input[type="text"],
        .reflsub-post-section-body input[type="url"] {
            width: 100%; max-width: 640px; padding: 10px 12px;
            border: 1.5px solid #e2e8f0; border-radius: 7px;
            font-size: 15px; font-family: inherit; background: #f8fafc;
            box-sizing: border-box; transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .reflsub-post-section-body textarea:focus,
        .reflsub-post-section-body input[type="text"]:focus,
        .reflsub-post-section-body input[type="url"]:focus {
            border-color: #1b28b4; background: #fff;
            box-shadow: 0 0 0 3px rgba(27,40,180,.18); outline: none;
        }
        .reflsub-post-section-body textarea { resize: vertical; }

        /* ── Image thumbnails ─────────────────────────────────────── */
        .reflsub-image-thumbs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .reflsub-image-thumbs img {
            width: 80px; height: 80px; object-fit: cover;
            border-radius: 6px; border: 2px solid #ace7d4;
        }

        /* ── Add-section palette ───────────────────────────────────── */
        .reflsub-add-palette {
            display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
            padding: 18px 20px; margin-top: 12px;
            background: #f8fafc; border: 1.5px dashed #ace7d4; border-radius: 7px;
        }
        .reflsub-add-label { font-size: 13px; font-weight: 600; color: #64748b; margin-right: 2px; }
        .reflsub-add-btn {
            border: 1.5px solid #ace7d4 !important; color: #141e88 !important;
            background: #fff !important; border-radius: 999px !important;
            padding: 9px 20px !important; font-size: 15px !important; font-weight: 600 !important;
            line-height: 1.5 !important; height: auto !important; box-shadow: none !important;
            cursor: pointer; transition: background .15s, color .15s, border-color .15s !important;
        }
        .reflsub-add-btn:hover {
            background: #1b28b4 !important; color: #f3feca !important; border-color: #1b28b4 !important;
        }

        /* ── Actions ───────────────────────────────────────────────── */
        .reflsub-actions { display: flex; align-items: center; gap: 12px; padding: 8px 0 24px; }
        .reflsub-btn-primary {
            background: #ff4128 !important; color: #fff !important;
            border: none !important; border-radius: 7px !important;
            padding: 10px 26px !important; font-size: 14px !important; font-weight: 700 !important;
            line-height: 1.4 !important; height: auto !important; cursor: pointer;
            box-shadow: 0 2px 8px rgba(255,65,40,.4) !important;
            transition: background .15s, box-shadow .15s !important;
        }
        .reflsub-btn-primary:hover {
            background: #d63210 !important; color: #fff !important;
            box-shadow: 0 4px 14px rgba(255,65,40,.5) !important;
        }
        .reflsub-btn-secondary {
            background: #fff !important; color: #334155 !important;
            border: 1.5px solid #e2e8f0 !important; border-radius: 7px !important;
            padding: 10px 20px !important; font-size: 14px !important; font-weight: 500 !important;
            line-height: 1.4 !important; height: auto !important; cursor: pointer;
            box-shadow: none !important; transition: border-color .15s, color .15s !important;
        }
        .reflsub-btn-secondary:hover { border-color: #1b28b4 !important; color: #1b28b4 !important; }

        /* ── Tag chips ────────────────────────────────────────────── */
        .reflsub-tag-chip {
            border: 1.5px solid #ace7d4; color: #141e88; background: #fff;
            border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 600;
            line-height: 1.5; height: auto; box-shadow: none; cursor: pointer;
            transition: background .15s, color .15s, border-color .15s;
        }
        .reflsub-tag-chip:hover { background: #1b28b4; color: #f3feca; border-color: #1b28b4; }

        /* ── PDF drop zone ─────────────────────────────────────────── */
        .reflsub-pdf-drop-zone {
            position: relative; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 6px;
            min-height: 90px; padding: 20px;
            border: 2px dashed #ace7d4; border-radius: 9px;
            background: #f8fafc; cursor: pointer;
            transition: border-color .15s, background .15s;
            text-align: center;
        }
        .reflsub-pdf-drop-zone:hover,
        .reflsub-pdf-drop-zone.reflsub-pdf-drag-over {
            border-color: #1b28b4; background: #f3feca;
        }
        .reflsub-pdf-drop-label {
            font-size: 13px; color: #64748b; font-weight: 500; pointer-events: none;
        }
        .reflsub-pdf-filename {
            font-size: 13px; color: #1b28b4; font-weight: 600; pointer-events: none;
            word-break: break-all;
        }
        .reflsub-pdf-has-file { border-color: #1b28b4; background: #f3feca; }
        .reflsub-pdf-has-file .reflsub-pdf-drop-label { display: none; }

        /* ── Section reorder ───────────────────────────────────────── */
        .reflsub-drag-handle {
            cursor: grab; font-size: 18px; color: #94a3b8;
            user-select: none; line-height: 1; padding: 0 2px; flex-shrink: 0;
        }
        .reflsub-drag-handle:active { cursor: grabbing; }
        .reflsub-move-btn {
            background: none !important; border: 1px solid transparent !important;
            box-shadow: none !important; color: #94a3b8 !important;
            font-size: 15px !important; padding: 2px 5px !important;
            cursor: pointer; line-height: 1; height: auto !important; border-radius: 4px !important;
            transition: color .15s, border-color .15s, background .15s !important;
        }
        .reflsub-move-btn:hover {
            color: #1b28b4 !important; border-color: #ace7d4 !important;
            background: #f3feca !important;
        }
        .reflsub-post-section.reflsub-drag-over {
            border-color: #1b28b4 !important;
            box-shadow: 0 0 0 2px rgba(27,40,180,.25) !important;
        }
    </style>

    <script>
    (function() {
        var sectionCount = 0;

        // ── Drag-and-drop + up/down reordering ──────────────────────────────────
        var reflsubPostDragSrc = null;

        function reflsubPostInitDrag(el) {
            var handle = el.querySelector('.reflsub-drag-handle');
            if (!handle) return;
            handle.addEventListener('mousedown', function() {
                el.setAttribute('draggable', 'true');
            });
            el.addEventListener('dragstart', function(e) {
                reflsubPostDragSrc = el;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
                setTimeout(function() { el.style.opacity = '0.45'; }, 0);
            });
            el.addEventListener('dragend', function() {
                el.setAttribute('draggable', 'false');
                el.style.opacity = '';
                document.querySelectorAll('#reflsub-post-sections .reflsub-post-section').forEach(function(s) {
                    s.classList.remove('reflsub-drag-over');
                });
            });
            el.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                el.classList.add('reflsub-drag-over');
            });
            el.addEventListener('dragleave', function(e) {
                if (!el.contains(e.relatedTarget)) {
                    el.classList.remove('reflsub-drag-over');
                }
            });
            el.addEventListener('drop', function(e) {
                e.preventDefault(); e.stopPropagation();
                el.classList.remove('reflsub-drag-over');
                if (reflsubPostDragSrc && reflsubPostDragSrc !== el) {
                    var container = el.parentNode;
                    var all = Array.from(container.children);
                    var srcIdx = all.indexOf(reflsubPostDragSrc);
                    var tgtIdx = all.indexOf(el);
                    if (srcIdx < tgtIdx) {
                        container.insertBefore(reflsubPostDragSrc, el.nextSibling);
                    } else {
                        container.insertBefore(reflsubPostDragSrc, el);
                    }
                }
            });
        }

        window.reflsubPostMoveSection = function(btn, dir) {
            var section = btn.closest('.reflsub-post-section');
            var container = section.parentNode;
            if (dir === 'up' && section.previousElementSibling) {
                container.insertBefore(section, section.previousElementSibling);
            } else if (dir === 'down' && section.nextElementSibling) {
                container.insertBefore(section.nextElementSibling, section);
            }
        };

        // ── Section builder ────────────────────────────────────────────────────

        function buildPostSection(type) {
            var id = ++sectionCount;
            var div = document.createElement('div');
            div.className = 'reflsub-post-section';
            div.dataset.type = type;

            var labels = { text: 'Text', image: 'Image(s)', pdf: 'PDF / File', embed: 'Embed / URL' };

            var header = '<div class="reflsub-post-section-header">' +
                '<div style="display:flex;align-items:center;gap:8px;">' +
                '<span class="reflsub-drag-handle" title="Drag to reorder">&#x28BF;</span>' +
                '<span>' + (labels[type] || type) + '</span>' +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:4px;">' +
                '<button type="button" class="reflsub-move-btn" onclick="reflsubPostMoveSection(this,\'up\')" title="Move up">&#8593;</button>' +
                '<button type="button" class="reflsub-move-btn" onclick="reflsubPostMoveSection(this,\'down\')" title="Move down">&#8595;</button>' +
                '<button type="button" class="button button-small" onclick="reflsubPostRemoveSection(this)">&#x2715; Remove</button>' +
                '</div>' +
                '</div>';

            var body = '<div class="reflsub-post-section-body">' + buildPostBody(type, id) + '</div>';
            div.innerHTML = header + body;
            reflsubPostInitDrag(div);
            return div;
        }

        function buildPostBody(type, id) {
            if (type === 'text') {
                return '<textarea rows="8" class="reflsub-post-text" placeholder="Write here… (leave a blank line between paragraphs)"></textarea>';
            }
            if (type === 'image') {
                return '<button type="button" class="button" onclick="reflsubSelectImages(this)">Select Image(s)</button>' +
                    '<div class="reflsub-image-thumbs"></div>' +
                    '<input type="hidden" class="reflsub-image-ids" value="">';
            }
            if (type === 'pdf') {
                var inputName = 'reflsub_post_pdf_' + id;
                return '<div class="reflsub-pdf-drop-zone" onclick="this.querySelector(\'input[type=file]\').click()">' +
                    '<span class="reflsub-pdf-drop-label">Drop a file here or click to browse</span>' +
                    '<span class="reflsub-pdf-filename"></span>' +
                    '<input type="file" name="' + inputName + '" class="reflsub-pdf-file-input" accept=".pdf,.doc,.docx,.ppt,.pptx" style="display:none;">' +
                    '</div>' +
                    '<p style="margin:6px 0 0; font-size:12px; color:#64748b;">PDF, Word, or PowerPoint — max 15 MB.</p>';
            }
            if (type === 'embed') {
                return '<label style="font-size:12px; font-weight:600; color:#50575e; display:block; margin-bottom:4px;">URL or Embed Code</label>' +
                    '<textarea rows="3" class="reflsub-post-embed" placeholder="Paste a YouTube/Vimeo URL, or an &lt;iframe&gt; embed code…"></textarea>';
            }
            return '';
        }

        window.reflsubPostAddSection = function(type) {
            document.getElementById('reflsub-post-sections').appendChild(buildPostSection(type));
        };

        window.reflsubPostRemoveSection = function(btn) {
            btn.closest('.reflsub-post-section').remove();
        };

        // ── Image selection via WP media modal ─────────────────────────────────

        window.reflsubSelectImages = function(btn) {
            var sectionBody = btn.closest('.reflsub-post-section-body');
            var thumbsDiv   = sectionBody.querySelector('.reflsub-image-thumbs');
            var idsInput    = sectionBody.querySelector('.reflsub-image-ids');

            var frame = wp.media({
                title:    'Select Images',
                button:   { text: 'Use these images' },
                multiple: true
            });

            frame.on('select', function() {
                var attachments = frame.state().get('selection').toJSON();
                var ids = attachments.map(function(a) { return a.id; });
                idsInput.value = ids.join(',');
                thumbsDiv.innerHTML = attachments.map(function(a) {
                    var src = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
                    return '<img src="' + src + '" alt="' + (a.alt || '') + '">';
                }).join('');
            });

            frame.open();
        };

        // ── Serialise sections before submit ───────────────────────────────────

        // PDF drop zone: show filename on file select, support native drag-and-drop
        document.addEventListener('change', function(e) {
            if (!e.target.classList.contains('reflsub-pdf-file-input')) return;
            var zone = e.target.closest('.reflsub-pdf-drop-zone');
            var label = zone.querySelector('.reflsub-pdf-filename');
            label.textContent = e.target.files[0] ? e.target.files[0].name : '';
            zone.classList.toggle('reflsub-pdf-has-file', !!e.target.files[0]);
        });
        document.addEventListener('dragover', function(e) {
            var zone = e.target.closest('.reflsub-pdf-drop-zone');
            if (zone) { e.preventDefault(); zone.classList.add('reflsub-pdf-drag-over'); }
        });
        document.addEventListener('dragleave', function(e) {
            var zone = e.target.closest('.reflsub-pdf-drop-zone');
            if (zone && !zone.contains(e.relatedTarget)) zone.classList.remove('reflsub-pdf-drag-over');
        });
        document.addEventListener('drop', function(e) {
            var zone = e.target.closest('.reflsub-pdf-drop-zone');
            if (!zone) return;
            e.preventDefault();
            zone.classList.remove('reflsub-pdf-drag-over');
            var input = zone.querySelector('.reflsub-pdf-file-input');
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                var label = zone.querySelector('.reflsub-pdf-filename');
                label.textContent = e.dataTransfer.files[0].name;
                zone.classList.add('reflsub-pdf-has-file');
            }
        });

        document.getElementById('reflsub-post-form').addEventListener('submit', function() {
            var sections = [];
            document.querySelectorAll('#reflsub-post-sections .reflsub-post-section').forEach(function(el) {
                var type = el.dataset.type;
                if (type === 'text') {
                    sections.push({ type: 'text', content: el.querySelector('.reflsub-post-text').value });
                } else if (type === 'image') {
                    sections.push({ type: 'image', ids: el.querySelector('.reflsub-image-ids').value });
                } else if (type === 'embed') {
                    sections.push({ type: 'embed', content: el.querySelector('.reflsub-post-embed').value });
                }
                // pdf sections are handled via $_FILES — excluded from JSON
            });
            document.getElementById('reflsub-sections-data').value = JSON.stringify(sections);
        });

        // ── Tag chips ──────────────────────────────────────────────────────────

        document.querySelectorAll('.reflsub-tag-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                var input = document.getElementById('reflsub-tags-input');
                var tag   = chip.dataset.tag;
                var tags  = input.value.split(',').map(function(t) { return t.trim(); }).filter(Boolean);
                if (tags.indexOf(tag) === -1) {
                    tags.push(tag);
                }
                input.value = tags.join(', ');
            });
        });

        // Add one text section by default
        reflsubPostAddSection('text');
    })();
    </script>
    <?php
}


// ── Save handler ──────────────────────────────────────────────────────────────

add_action( 'admin_post_reflsub_create_post', 'reflsub_handle_create_post' );
function reflsub_handle_create_post() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( 'Sorry, you do not have permission to do this.' );
    }

    check_admin_referer( 'reflsub_create_post', 'reflsub_post_nonce' );

    $user_id   = get_current_user_id();
    $title     = sanitize_text_field( wp_unslash( $_POST['reflsub_post_title'] ?? '' ) );
    $action    = sanitize_key( $_POST['reflsub_post_action'] ?? 'publish' );
    $post_status = $action === 'draft' ? 'draft' : 'publish';

    $tags_raw  = sanitize_text_field( wp_unslash( $_POST['reflsub_tags'] ?? '' ) );
    $tags      = array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) );

    $ct_ids    = array_map( 'intval', (array) ( $_POST['reflsub_content_types'] ?? array() ) );

    // Decode sections JSON
    $sections_raw = wp_unslash( $_POST['reflsub_sections_data'] ?? '' );
    $sections     = array();
    if ( $sections_raw ) {
        $decoded = json_decode( $sections_raw, true );
        if ( is_array( $decoded ) ) {
            $sections = $decoded;
        }
    }

    // Build post content from sections
    $content_parts = array();

    foreach ( $sections as $sec ) {
        $type = $sec['type'] ?? '';

        if ( $type === 'text' ) {
            $text = sanitize_textarea_field( wp_unslash( $sec['content'] ?? '' ) );
            if ( $text === '' ) continue;

            // Split on double newlines → paragraphs
            $paragraphs = preg_split( '/\n{2,}/', $text );
            foreach ( $paragraphs as $para ) {
                $para = trim( $para );
                if ( $para === '' ) continue;
                $content_parts[] = '<!-- wp:paragraph --><p>' . nl2br( esc_html( $para ) ) . '</p><!-- /wp:paragraph -->';
            }
        }

        if ( $type === 'image' ) {
            $ids_raw = sanitize_text_field( $sec['ids'] ?? '' );
            $ids     = array_values( array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) ) );
            if ( empty( $ids ) ) continue;

            if ( count( $ids ) === 1 ) {
                $id  = $ids[0];
                $src = wp_get_attachment_image_url( $id, 'large' );
                $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
                if ( $src ) {
                    $content_parts[] = sprintf(
                        '<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="%s" alt="%s" class="wp-image-%d"/></figure><!-- /wp:image -->',
                        $id, esc_url( $src ), esc_attr( $alt ), $id
                    );
                }
            } else {
                $imgs = '';
                foreach ( $ids as $id ) {
                    $src = wp_get_attachment_image_url( $id, 'large' );
                    $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
                    if ( $src ) {
                        $imgs .= sprintf(
                            '<figure class="wp-block-image"><img src="%s" alt="%s" class="wp-image-%d"/></figure>',
                            esc_url( $src ), esc_attr( $alt ), $id
                        );
                    }
                }
                if ( $imgs ) {
                    $content_parts[] = sprintf(
                        '<!-- wp:gallery {"columns":2} --><figure class="wp-block-gallery">%s</figure><!-- /wp:gallery -->',
                        $imgs
                    );
                }
            }
        }

        if ( $type === 'embed' ) {
            $raw = wp_unslash( $sec['content'] ?? '' );
            $raw = trim( $raw );
            if ( $raw === '' ) continue;

            // Check if it looks like a URL
            if ( preg_match( '/^https?:\/\//i', $raw ) ) {
                $url = esc_url_raw( $raw );
                $content_parts[] = sprintf(
                    '<!-- wp:embed {"url":"%s"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">%s</div></figure><!-- /wp:embed -->',
                    esc_url( $url ), esc_url( $url )
                );
            } else {
                // Treat as raw embed / iframe — sanitize
                $clean = reflsub_sanitize_embed_code( $raw );
                if ( $clean ) {
                    $content_parts[] = $clean;
                }
            }
        }
    }

    $post_content = implode( "\n\n", $content_parts );

    // Create post
    $post_id = wp_insert_post( array(
        'post_title'   => $title ?: '',
        'post_content' => $post_content,
        'post_status'  => $post_status,
        'post_author'  => $user_id,
        'post_type'    => 'post',
    ), true );

    if ( is_wp_error( $post_id ) ) {
        $err = urlencode( $post_id->get_error_message() );
        wp_redirect( admin_url( 'admin.php?page=reflsub-new-post&reflsub_error=' . $err ) );
        exit;
    }

    // Tags
    if ( ! empty( $tags ) ) {
        wp_set_post_tags( $post_id, $tags );
    }

    // File uploads (PDF / File sections)
    if ( ! empty( $_FILES ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        foreach ( $_FILES as $key => $file ) {
            if ( strpos( $key, 'reflsub_post_pdf_' ) !== 0 ) continue;
            if ( $file['error'] !== UPLOAD_ERR_OK || empty( $file['name'] ) ) continue;
            $upload = wp_handle_upload( $file, array( 'test_form' => false ) );
            if ( isset( $upload['file'] ) && ! isset( $upload['error'] ) ) {
                $att_id = wp_insert_attachment( array(
                    'post_mime_type' => $upload['type'],
                    'post_title'     => sanitize_file_name( $file['name'] ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ), $upload['file'], $post_id );
                if ( $att_id && ! is_wp_error( $att_id ) ) {
                    wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
                }
            }
        }
    }

    // Content-type taxonomy
    if ( ! empty( $ct_ids ) && taxonomy_exists( 'content-type' ) ) {
        $valid_ids = array();
        foreach ( $ct_ids as $ct_id ) {
            if ( term_exists( $ct_id, 'content-type' ) ) {
                $valid_ids[] = $ct_id;
            }
        }
        if ( $valid_ids ) {
            wp_set_post_terms( $post_id, $valid_ids, 'content-type' );
        }
    }

    if ( $post_status === 'draft' ) {
        wp_redirect( admin_url( 'admin.php?page=reflsub-new-post&reflsub_draft=' . $post_id ) );
    } else {
        $archive_url = get_author_posts_url( $user_id );
        wp_redirect( $archive_url );
    }
    exit;
}
