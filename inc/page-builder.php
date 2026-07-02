<?php
/**
 * Reflection Page Builder
 *
 * Admin submenus:
 *   reflsub-pages  — List of all pages with is_reflection_page = 1
 *   reflsub-build  — Create / edit a reflection page via section builder
 *
 * Save action: admin_post_reflsub_save_reflection_page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Admin menu registration ────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_page_builder_register' );
function reflsub_page_builder_register() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    add_submenu_page(
        'activity-builder',
        'New Activity Page',
        'New Activity Page',
        'manage_options',
        'reflsub-build',
        'reflsub_render_page_builder'
    );
}


// ── Enqueue builder JS/CSS on the builder page only ───────────────────────────

add_action( 'admin_enqueue_scripts', 'reflsub_page_builder_enqueue' );
function reflsub_page_builder_enqueue( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'reflsub-build' ) {
        return;
    }
    // Inline script; no external file needed.
}


// ── AJAX: quick-create a parent page ──────────────────────────────────────────

add_action( 'wp_ajax_reflsub_create_parent_page', 'reflsub_ajax_create_parent_page' );
function reflsub_ajax_create_parent_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.', 403 );
    }
    check_ajax_referer( 'reflsub_create_parent_page', 'nonce' );

    $title = sanitize_text_field( wp_unslash( $_POST['parent_name'] ?? '' ) );
    if ( ! $title ) {
        wp_send_json_error( 'Please enter a name.' );
    }

    $page_id = wp_insert_post( array(
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_type'   => 'page',
        'post_parent' => 0,
    ), true );

    if ( is_wp_error( $page_id ) ) {
        wp_send_json_error( $page_id->get_error_message() );
    }

    wp_send_json_success( array(
        'id'    => $page_id,
        'title' => get_the_title( $page_id ),
    ) );
}


// ── Activity Pages list ─────────────────────────────────────────────────────

function reflsub_render_pages_list() {
    // Handle delete action
    if (
        isset( $_GET['reflsub_delete'] ) &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'reflsub_delete_page_' . intval( $_GET['reflsub_delete'] ) )
    ) {
        $del_id = intval( $_GET['reflsub_delete'] );
        if ( $del_id && current_user_can( 'manage_options' ) ) {
            wp_trash_post( $del_id );
            echo '<div class="notice notice-success is-dismissible"><p>Activity page moved to trash.</p></div>';
        }
    }

    // Handle duplicate action — clones the activity *definition* only (never submissions),
    // lands as a Draft titled "… (Copy)" so the instructor edits before publishing.
    if (
        isset( $_GET['reflsub_duplicate'] ) &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'reflsub_duplicate_page_' . intval( $_GET['reflsub_duplicate'] ) )
    ) {
        $src_id = intval( $_GET['reflsub_duplicate'] );
        $source = $src_id ? get_post( $src_id ) : null;

        if ( $source && $source->post_type === 'page' && current_user_can( 'edit_pages' ) ) {
            $new_id = wp_insert_post( array(
                'post_title'   => ( $source->post_title ?: 'Reflection Page' ) . ' (Copy)',
                'post_excerpt' => $source->post_excerpt,
                'post_content' => $source->post_content,
                'post_parent'  => $source->post_parent,
                'post_type'    => 'page',
                'post_status'  => 'draft',
            ), true );

            if ( ! is_wp_error( $new_id ) && $new_id ) {
                // Definition meta only — modern builder keys + legacy ACF-era keys.
                // Never copy submission linkage (_reflection_source_page lives on student posts).
                $copy_keys = array(
                    '_reflsub_sections', 'is_reflection_page', 'submission_privacy',
                    'allow_resubmission', '_reflsub_auto_tags', '_reflsub_content_type_slug',
                    '_reflsub_allow_student_blocks',
                    // Legacy keys (pages configured pre-plugin via ACF):
                    'reflection_prompt_1', 'reflection_prompt_2', 'reflection_prompt_3',
                    'allow_image_upload', 'allow_video_url', 'allow_embed', 'content_type_label',
                );
                foreach ( $copy_keys as $meta_key ) {
                    $val = get_post_meta( $src_id, $meta_key, true );
                    if ( $val === '' ) {
                        continue;
                    }
                    // wp_slash(): update_post_meta() unslashes internally; re-slashing stores the
                    // value byte-identical — critical for the _reflsub_sections JSON blob (see
                    // MEMORY.md "JSON in post meta"). Harmless no-op for plain scalar values.
                    update_post_meta( $new_id, $meta_key, wp_slash( $val ) );
                }
                echo '<div class="notice notice-success is-dismissible"><p>Activity page duplicated as a draft: <a href="' . esc_url( admin_url( 'admin.php?page=reflsub-build&edit=' . $new_id ) ) . '">' . esc_html( get_the_title( $new_id ) ) . '</a></p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Could not duplicate the activity page.</p></div>';
            }
        }
    }

    $pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array(
            array(
                'key'   => 'is_reflection_page',
                'value' => '1',
            ),
        ),
    ) );

    $build_url = admin_url( 'admin.php?page=reflsub-build' );
    $new_url   = wp_nonce_url(
        admin_url( 'admin-post.php?action=reflsub_new_reflection_page' ),
        'reflsub_new_reflection_page'
    );
    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:16px;">
            Activity Pages
            <a href="<?php echo esc_url( $build_url ); ?>" class="page-title-action">+ New Activity Page</a>
        </h1>

        <?php if ( isset( $_GET['reflsub_saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo $_GET['reflsub_saved'] === 'created' ? 'Activity page created.' : 'Activity page updated.'; ?></p>
        </div>
        <?php endif; ?>

        <?php if ( empty( $pages ) ) : ?>
        <p style="color:#646970; font-style:italic;">No activity pages yet. <a href="<?php echo esc_url( $build_url ); ?>">Build one now.</a></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="max-width:1240px;">
            <thead>
                <tr>
                    <th style="width:24%">Title</th>
                    <th style="width:18%">Assignment (Parent)</th>
                    <th style="width:8%">Sections</th>
                    <th style="width:10%">Submissions</th>
                    <th style="width:8%">Status</th>
                    <th style="width:32%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $pages as $page ) :
                $raw_sections  = get_post_meta( $page->ID, '_reflsub_sections', true );
                $sections      = $raw_sections ? json_decode( $raw_sections, true ) : array();
                $section_count = is_array( $sections ) ? count( $sections ) : 0;

                $parent_title = $page->post_parent ? get_the_title( $page->post_parent ) : '—';

                $sub_count = (int) ( new WP_Query( array(
                    'post_type'      => 'post',
                    'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'   => '_reflection_source_page',
                            'value' => $page->ID,
                        ),
                    ),
                ) ) )->found_posts;

                $edit_url   = admin_url( 'admin.php?page=reflsub-build&edit=' . $page->ID );
                $delete_url = wp_nonce_url(
                    admin_url( 'admin.php?page=activity-builder&reflsub_delete=' . $page->ID ),
                    'reflsub_delete_page_' . $page->ID
                );
                $dup_url    = wp_nonce_url(
                    admin_url( 'admin.php?page=activity-builder&reflsub_duplicate=' . $page->ID ),
                    'reflsub_duplicate_page_' . $page->ID
                );
                $view_url   = get_permalink( $page->ID );

                $status_colors = array(
                    'publish' => '#00a32a',
                    'draft'   => '#646970',
                    'pending' => '#dba617',
                    'private' => '#2271b1',
                );
                $status_color = $status_colors[ $page->post_status ] ?? '#646970';
            ?>
            <tr>
                <td>
                    <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $page->post_title ?: '(no title)' ); ?></a></strong>
                </td>
                <td><?php echo esc_html( $parent_title ); ?></td>
                <td><?php echo $raw_sections ? intval( $section_count ) : '<em style="color:#646970;">Legacy</em>'; ?></td>
                <td><?php echo intval( $sub_count ); ?></td>
                <td>
                    <span style="color:<?php echo esc_attr( $status_color ); ?>; font-weight:600; font-size:12px;">
                        <?php echo esc_html( ucfirst( $page->post_status ) ); ?>
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Edit</a>
                    <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small" target="_blank">View</a>
                    <a href="<?php echo esc_url( $dup_url ); ?>" class="button button-small"
                       onclick="return confirm('Duplicate this activity page as a new draft?');">Duplicate</a>
                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small"
                       style="color:#d63638;"
                       onclick="return confirm('Move this activity page to trash?');">Trash</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px; color:#646970; font-size:12px;">
            <?php echo count( $pages ); ?> activity page<?php echo count( $pages ) !== 1 ? 's' : ''; ?>.
        </p>
        <?php endif; ?>
    </div>
    <?php
}


// ── Page Builder ──────────────────────────────────────────────────────────────

function reflsub_render_page_builder() {
    $edit_id   = isset( $_GET['edit'] ) ? intval( $_GET['edit'] ) : 0;
    $is_edit   = $edit_id > 0;

    // Load existing page data in edit mode
    $page_title          = '';
    $intro_text          = '';
    $privacy             = 'publish';
    $allow_resub         = 0;
    $parent_id           = 0;
    $sections_json       = '';
    $page_status         = 'publish'; // default for new pages
    $auto_tags_val       = '';
    $content_type_slug   = '';
    $allow_student_blocks = 1; // default ON for new pages

    if ( $is_edit ) {
        $page = get_post( $edit_id );
        if ( ! $page || $page->post_type !== 'page' ) {
            echo '<div class="wrap"><p>Page not found.</p></div>';
            return;
        }
        $page_title          = $page->post_title;
        $intro_text          = $page->post_excerpt;
        $privacy             = get_post_meta( $edit_id, 'submission_privacy', true ) ?: 'publish';
        $allow_resub         = (int) get_post_meta( $edit_id, 'allow_resubmission', true );
        $parent_id           = (int) $page->post_parent;
        $sections_json       = get_post_meta( $edit_id, '_reflsub_sections', true ) ?: '';
        $page_status         = $page->post_status;
        $auto_tags_val       = get_post_meta( $edit_id, '_reflsub_auto_tags', true ) ?: '';
        $content_type_slug   = get_post_meta( $edit_id, '_reflsub_content_type_slug', true ) ?: '';
        // Missing/empty meta defaults to allowed; only the explicit string '0' disables.
        $asb_raw = get_post_meta( $edit_id, '_reflsub_allow_student_blocks', true );
        $allow_student_blocks = ( $asb_raw === '0' ) ? 0 : 1;
    }

    // Top-level pages only for parent selector (exclude self in edit mode)
    $all_pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => array( 'publish', 'draft', 'private' ),
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'post_parent'    => 0,
        'exclude'        => $edit_id ? array( $edit_id ) : array(),
    ) );

    $form_action = admin_url( 'admin-post.php' );
    $heading     = $is_edit ? 'Edit Activity Page' : 'Build an Activity Page';
    $subtitle    = $is_edit
        ? 'Update settings and form sections for this page.'
        : 'Configure settings and build the form students will complete.';
    ?>
    <div class="wrap">
    <div class="reflsub-app">

        <?php if ( isset( $_GET['reflsub_error'] ) ) : ?>
        <div class="notice notice-error is-dismissible" style="margin-bottom:20px;">
            <p><?php echo esc_html( urldecode( $_GET['reflsub_error'] ) ); ?></p>
        </div>
        <?php endif; ?>

        <div class="reflsub-page-header">
            <div>
                <h1><?php echo esc_html( $heading ); ?></h1>
                <p><?php echo esc_html( $subtitle ); ?></p>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=activity-builder' ) ); ?>" class="reflsub-back-link">
                ← All Pages
            </a>
        </div>

        <form method="post" action="<?php echo esc_url( $form_action ); ?>" id="reflsub-builder-form">
            <?php wp_nonce_field( 'reflsub_save_reflection_page', 'reflsub_builder_nonce' ); ?>
            <input type="hidden" name="action" value="reflsub_save_reflection_page">
            <?php if ( $is_edit ) : ?>
            <input type="hidden" name="reflsub_page_id" value="<?php echo esc_attr( $edit_id ); ?>">
            <?php endif; ?>
            <input type="hidden" name="reflsub_sections_json" id="reflsub-sections-json" value="<?php echo esc_attr( $sections_json ); ?>">

            <div class="reflsub-card">
                <div class="reflsub-card-header">Page Settings</div>
                <div class="reflsub-card-body">
                    <div class="reflsub-builder-columns">

                        <!-- ── Main column ── -->
                        <div class="reflsub-col-main">

                            <div class="reflsub-field">
                                <label for="reflsub-page-title">Page Title</label>
                                <input type="text" id="reflsub-page-title" name="reflsub_page_title"
                                       value="<?php echo esc_attr( $page_title ); ?>"
                                       placeholder="e.g. Week 3 Reflection">
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-intro-text">
                                    Intro Text <span class="reflsub-optional">optional</span>
                                </label>
                                <textarea id="reflsub-intro-text" name="reflsub_intro_text" rows="6"
                                          placeholder="Optional description shown above the student form…"><?php echo esc_textarea( $intro_text ); ?></textarea>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-parent">
                                    Parent Page <span class="reflsub-optional">optional</span>
                                    <?php echo reflsub_tip( 'Child pages appear automatically in any menu built from their parent — like the menus Setup creates. Parents also group pages in Progress.' ); ?>
                                </label>
                                <select id="reflsub-parent" name="reflsub_parent_id">
                                    <option value="0">— Standalone —</option>
                                    <?php foreach ( $all_pages as $p ) : ?>
                                    <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $parent_id, $p->ID ); ?>>
                                        <?php echo esc_html( $p->post_title ?: '(no title) #' . $p->ID ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="reflsub-new-parent-toggle" class="reflsub-new-parent-toggle"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'reflsub_create_parent_page' ) ); ?>">
                                    + New parent page
                                </button>
                                <div id="reflsub-new-parent-wrap" class="reflsub-new-parent-wrap" hidden>
                                    <input type="text" id="reflsub-new-parent-name" placeholder="e.g. Weekly Prompts" autocomplete="off">
                                    <button type="button" id="reflsub-new-parent-create" class="reflsub-new-parent-create">Create</button>
                                    <span id="reflsub-new-parent-msg" class="reflsub-new-parent-msg"></span>
                                </div>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-auto-tags">
                                    Auto-tags <span class="reflsub-optional">optional</span>
                                    <?php echo reflsub_tip( 'Applied to every submission from this page. Students never see or edit these tags — useful for filtering and Re-reflect later.' ); ?>
                                </label>
                                <input type="text" id="reflsub-auto-tags" name="reflsub_auto_tags"
                                       placeholder="e.g. week-3, photography, critical-thinking"
                                       value="<?php echo esc_attr( $auto_tags_val ); ?>">
                                <span class="reflsub-field-desc">Comma-separated.</span>
                            </div>

                        </div><!-- /.reflsub-col-main -->

                        <!-- ── Sidebar column ── -->
                        <div class="reflsub-col-sidebar">

                            <div class="reflsub-field">
                                <label for="reflsub-page-status">Page Status</label>
                                <select id="reflsub-page-status" name="reflsub_page_status">
                                    <option value="publish" <?php selected( $page_status, 'publish' ); ?>>Published</option>
                                    <option value="draft"   <?php selected( $page_status, 'draft' ); ?>>Draft</option>
                                    <option value="private" <?php selected( $page_status, 'private' ); ?>>Private</option>
                                </select>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-privacy">
                                    Submission Privacy
                                    <?php echo reflsub_tip( 'The post status each submission gets. Pending Review keeps posts hidden until you approve them on the Submissions screen.', 'left' ); ?>
                                </label>
                                <select id="reflsub-privacy" name="reflsub_privacy">
                                    <option value="publish" <?php selected( $privacy, 'publish' ); ?>>Publish — visible to all</option>
                                    <option value="private" <?php selected( $privacy, 'private' ); ?>>Private — student only</option>
                                    <option value="pending" <?php selected( $privacy, 'pending' ); ?>>Pending Review</option>
                                </select>
                            </div>

                            <div class="reflsub-field reflsub-field-check">
                                <label class="reflsub-toggle">
                                    <input type="checkbox" name="reflsub_allow_resub" value="1"
                                           <?php checked( $allow_resub, 1 ); ?>>
                                    <span>Re-use Form</span>
                                    <?php echo reflsub_tip( 'On: students can submit this form repeatedly; each submission becomes its own post. Off: returning students see their existing submission and can edit it instead.', 'left' ); ?>
                                </label>
                            </div>

                            <div class="reflsub-field reflsub-field-check">
                                <label class="reflsub-toggle">
                                    <input type="checkbox" name="reflsub_allow_student_blocks" value="1"
                                           <?php checked( $allow_student_blocks, 1 ); ?>>
                                    <span>Enable Student Tools</span>
                                    <?php echo reflsub_tip( 'Adds an "+ Add" palette at the end of the form so students can attach their own paragraphs, images, video, embeds, PDFs or audio. Off: the form is exactly the sections below.', 'left' ); ?>
                                </label>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-content-type">
                                    Content Type <span class="reflsub-optional">optional</span>
                                    <?php echo reflsub_tip( 'Tags every submission with this content type so portfolios and archives can filter by it. Type a new name to create one on the fly.', 'left' ); ?>
                                </label>
                                <?php
                                $ct_terms = taxonomy_exists( 'content-type' )
                                    ? get_terms( array( 'taxonomy' => 'content-type', 'hide_empty' => false ) )
                                    : array();
                                if ( is_wp_error( $ct_terms ) ) {
                                    $ct_terms = array();
                                }
                                // Show the saved term's display name in the combobox; fall back
                                // to the raw slug if the term was since deleted.
                                $content_type_name = '';
                                if ( $content_type_slug ) {
                                    foreach ( $ct_terms as $ct ) {
                                        if ( $ct->slug === $content_type_slug ) {
                                            $content_type_name = $ct->name;
                                            break;
                                        }
                                    }
                                    if ( $content_type_name === '' ) {
                                        $content_type_name = $content_type_slug;
                                    }
                                }
                                ?>
                                <input type="text" id="reflsub-content-type" name="reflsub_content_type_name"
                                       list="reflsub-content-type-options" autocomplete="off"
                                       placeholder="Pick an existing type or type a new one…"
                                       value="<?php echo esc_attr( $content_type_name ); ?>">
                                <datalist id="reflsub-content-type-options">
                                    <?php foreach ( $ct_terms as $ct ) : ?>
                                    <option value="<?php echo esc_attr( $ct->name ); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                        </div><!-- /.reflsub-col-sidebar -->

                    </div><!-- /.reflsub-builder-columns -->
                </div>
            </div>

            <div class="reflsub-card">
                <div class="reflsub-card-header">Form Sections</div>
                <div class="reflsub-card-body">
                    <p class="reflsub-card-desc">Build the form students will see. Sections appear in the order added.</p>
                    <div id="reflsub-sections"></div>
                    <div class="reflsub-add-palette">
                        <span class="reflsub-add-label">+ Add section</span>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('entry_title')">Entry Title</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('prompt')">Prompt</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('mcq')">Multiple Choice</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('image')">Image</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('video')">Video URL</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('embed')">Embed</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('tags')">Student Tags</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('pdf')">PDF / File</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('audio')">Audio Recording</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('re_reflect')">Re-reflect</button>
                    </div>
                </div>
            </div>

            <div class="reflsub-actions">
                <button type="submit" name="reflsub_submit" class="reflsub-btn-primary">
                    <?php echo $is_edit ? 'Update Page' : 'Create Page'; ?>
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=activity-builder' ) ); ?>" class="reflsub-btn-ghost">
                    Cancel
                </a>
            </div>

        </form>
    </div><!-- .reflsub-app -->
    </div><!-- .wrap -->


    <script>
    (function() {
        var sectionCount = 0;

        // Section type labels
        var LABELS = {
            entry_title: 'Entry Title',
            prompt: 'Prompt (Text Response)',
            mcq:    'Multiple Choice Question',
            image:  'Image Upload',
            video:  'Video URL',
            embed:  'Embed Code',
            tags:   'Student Tags',
            pdf:        'PDF / File Upload',
            audio:      'Audio Recording',
            re_reflect: 'Re-reflect on a Past Post'
        };

        // Hover help per section type, shown as a (?) in the section header.
        // Mirrors reflsub_tip() in inc/admin-page.php — keep the markup in sync.
        var HELP = {
            entry_title: 'The student\'s answer becomes the post title. Left blank, the title falls back to the activity page name plus today\'s date.',
            image:      'Adds a drag-and-drop image upload field. Students can attach several images.',
            video:      'Adds a video URL field (YouTube / Vimeo).',
            embed:      'Adds an embed-code box (Kaltura, iframe…). Everything except the iframe is stripped on save.',
            tags:       'Students add their own tags to their submission. For tags you control, use Auto-tags in Page Settings.',
            pdf:        'Adds a PDF upload field (one file, max 15 MB).',
            audio:      'Adds an in-browser recorder: students record with their microphone or upload an audio file.',
            re_reflect: 'Shows the student one of their own past posts matching these filters as a read-only card — a nudge to look back before writing. If nothing matches, the card is silently hidden.'
        };

        function tipHtml(text) {
            return '<span class="reflsub-tip" tabindex="0">' +
                '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>' +
                '<span class="reflsub-tip-text" role="tooltip">' + text + '</span>' +
                '</span>';
        }

        // ── Drag-and-drop + up/down reordering ──────────────────────────────────
        var reflsubDragSrc = null;

        function reflsubInitDrag(el) {
            var handle = el.querySelector('.reflsub-drag-handle');
            if (!handle) return;
            handle.addEventListener('mousedown', function() {
                el.setAttribute('draggable', 'true');
            });
            el.addEventListener('dragstart', function(e) {
                reflsubDragSrc = el;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
                setTimeout(function() { el.style.opacity = '0.45'; }, 0);
            });
            el.addEventListener('dragend', function() {
                el.setAttribute('draggable', 'false');
                el.style.opacity = '';
                document.querySelectorAll('#reflsub-sections .reflsub-section').forEach(function(s) {
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
                if (reflsubDragSrc && reflsubDragSrc !== el) {
                    var container = el.parentNode;
                    var all = Array.from(container.children);
                    var srcIdx = all.indexOf(reflsubDragSrc);
                    var tgtIdx = all.indexOf(el);
                    if (srcIdx < tgtIdx) {
                        container.insertBefore(reflsubDragSrc, el.nextSibling);
                    } else {
                        container.insertBefore(reflsubDragSrc, el);
                    }
                }
            });
        }

        window.reflsubMoveSection = function(btn, dir) {
            var section = btn.closest('.reflsub-section');
            var container = section.parentNode;
            if (dir === 'up' && section.previousElementSibling) {
                container.insertBefore(section, section.previousElementSibling);
            } else if (dir === 'down' && section.nextElementSibling) {
                container.insertBefore(section.nextElementSibling, section);
            }
        };

        // Build section DOM
        function buildSection(type, data) {
            data = data || {};
            var id = ++sectionCount;
            var div = document.createElement('div');
            div.className = 'reflsub-section';
            div.dataset.type = type;

            var header = '<div class="reflsub-section-header">' +
                '<div style="display:flex;align-items:center;gap:8px;">' +
                '<span class="reflsub-drag-handle" title="Drag to reorder">&#x28BF;</span>' +
                '<span>' + (LABELS[type] || type) + '</span>' +
                (HELP[type] ? tipHtml(HELP[type]) : '') +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:4px;">' +
                '<button type="button" class="reflsub-move-btn" onclick="reflsubMoveSection(this,\'up\')" title="Move up">&#8593;</button>' +
                '<button type="button" class="reflsub-move-btn" onclick="reflsubMoveSection(this,\'down\')" title="Move down">&#8595;</button>' +
                '<button type="button" class="button button-small" onclick="reflsubRemoveSection(this)">&#x2715; Remove</button>' +
                '</div>' +
                '</div>';

            var body = '<div class="reflsub-section-body">' + buildBody(type, data, id) + '</div>';
            div.innerHTML = header + body;
            reflsubInitDrag(div);
            return div;
        }

        function buildBody(type, data, id) {
            if (type === 'entry_title') {
                var reqChecked = data.required ? ' checked' : '';
                return '<label>Label / Instruction <span style="font-weight:normal;font-size:.9em;">(shown above the title field)</span></label>' +
                    '<input type="text" class="reflsub-et-label" placeholder="e.g. Give your entry a title" value="' + esc(data.label || '') + '" style="width:100%;">' +
                    '<div class="reflsub-section-meta" style="margin-top:10px;"><label><input type="checkbox" class="reflsub-et-required"' + reqChecked + '> Required</label></div>';
            }

            if (type === 'prompt') {
                var checked = data.required ? ' checked' : '';
                return '<label>Prompt / Question</label>' +
                    '<textarea class="reflsub-prompt-label" rows="3" placeholder="Enter the prompt students will respond to, or leave blank…">' +
                    esc(data.label || '') + '</textarea>' +
                    '<div class="reflsub-section-meta">' +
                    '<label><input type="number" class="reflsub-word-limit" min="0" placeholder="No limit" value="' + esc(data.word_limit || '') + '"> Word limit (0 = none)</label>' +
                    '<label><input type="checkbox" class="reflsub-required"' + checked + '> Required</label>' +
                    '</div>';
            }

            if (type === 'mcq') {
                var options = data.options || ['', ''];
                var optHtml = '';
                for (var i = 0; i < options.length; i++) {
                    optHtml += buildMCQOptionRow(options[i]);
                }
                var multiChecked = data.multi ? ' checked' : '';
                return '<label>Question</label>' +
                    '<textarea class="reflsub-mcq-question" rows="2" placeholder="Enter the question…">' + esc(data.question || '') + '</textarea>' +
                    '<label>Options</label>' +
                    '<div class="reflsub-mcq-options">' + optHtml + '</div>' +
                    '<button type="button" class="button button-small" onclick="reflsubAddMCQOption(this)" style="margin-bottom:10px;">+ Add Option</button>' +
                    '<div class="reflsub-section-meta"><label><input type="checkbox" class="reflsub-mcq-multi"' + multiChecked + '> Allow multiple selections</label></div>';
            }

            if (type === 'image' || type === 'video' || type === 'embed' || type === 'tags') {
                return '<p class="reflsub-flag-note">No settings.</p>';
            }

            if (type === 'pdf') {
                var reqChecked = data.required ? ' checked' : '';
                return '<div class="reflsub-section-meta"><label><input type="checkbox" class="reflsub-pdf-required"' + reqChecked + '> Required</label></div>';
            }

            if (type === 'audio') {
                var reqChecked = data.required ? ' checked' : '';
                var maxMin = parseInt(data.max_minutes, 10);
                if (isNaN(maxMin) || maxMin <= 0) maxMin = 5;
                return '<div class="reflsub-section-meta">' +
                    '<label><input type="number" class="reflsub-audio-max" min="1" max="30" value="' + esc(maxMin) + '" style="width:70px;"> Max length (minutes)</label>' +
                    '<label><input type="checkbox" class="reflsub-audio-required"' + reqChecked + '> Required</label>' +
                    '</div>';
            }

            if (type === 're_reflect') {
                var pickRandom  = (!data.pick || data.pick === 'random')  ? ' selected' : '';
                var pickLatest  = (data.pick === 'latest')                ? ' selected' : '';
                var pickOldest  = (data.pick === 'oldest')                ? ' selected' : '';
                return '<label>Heading <span style="font-weight:normal;font-size:.9em;">(shown above the past post card)</span></label>' +
                    '<input type="text" class="reflsub-rr-heading" placeholder="Before you write, look back at this…" value="' + esc(data.heading || '') + '" style="width:100%;">' +
                    '<div class="reflsub-section-meta" style="margin-top:12px;gap:16px;">' +
                    '<label style="display:flex;flex-direction:column;gap:4px;">Date from<input type="date" class="reflsub-rr-date-from" value="' + esc(data.date_from || '') + '"></label>' +
                    '<label style="display:flex;flex-direction:column;gap:4px;">Date to<input type="date" class="reflsub-rr-date-to" value="' + esc(data.date_to || '') + '"></label>' +
                    '</div>' +
                    '<label style="display:block;margin-top:12px;">Tag filter <span style="font-weight:normal;font-size:.9em;">(comma-separated slugs — leave blank for any tag)</span></label>' +
                    '<input type="text" class="reflsub-rr-tags" placeholder="e.g. media-ethics, critical-thinking" value="' + esc( (data.tags || []).join(', ') ) + '" style="width:100%;">' +
                    '<div class="reflsub-section-meta" style="margin-top:12px;">' +
                    '<label>Selection&nbsp;<select class="reflsub-rr-pick">' +
                    '<option value="random"' + pickRandom + '>Random</option>' +
                    '<option value="latest"' + pickLatest + '>Most recent</option>' +
                    '<option value="oldest"' + pickOldest + '>Oldest</option>' +
                    '</select></label>' +
                    '</div>';
            }

            return '';
        }

        function buildMCQOptionRow(value) {
            return '<div class="reflsub-mcq-option-row">' +
                '<input type="text" class="reflsub-mcq-option" placeholder="Option…" value="' + esc(value || '') + '">' +
                '<button type="button" class="button button-small" onclick="reflsubRemoveMCQOption(this)" style="color:#d63638;">&#x2715;</button>' +
                '</div>';
        }

        function esc(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        // Serialise all sections to JSON
        function serialiseSections() {
            var container = document.getElementById('reflsub-sections');
            var sections  = [];
            container.querySelectorAll('.reflsub-section').forEach(function(el) {
                var type = el.dataset.type;
                var sec  = { type: type };

                if (type === 'prompt') {
                    sec.label      = el.querySelector('.reflsub-prompt-label').value.trim();
                    var wl         = parseInt(el.querySelector('.reflsub-word-limit').value, 10);
                    sec.word_limit = isNaN(wl) || wl <= 0 ? 0 : wl;
                    sec.required   = el.querySelector('.reflsub-required').checked;
                }

                if (type === 'mcq') {
                    sec.question = el.querySelector('.reflsub-mcq-question').value.trim();
                    sec.options  = Array.from(el.querySelectorAll('.reflsub-mcq-option'))
                        .map(function(i) { return i.value.trim(); })
                        .filter(Boolean);
                    sec.multi    = el.querySelector('.reflsub-mcq-multi').checked;
                }

                if (type === 'pdf') {
                    sec.required = el.querySelector('.reflsub-pdf-required').checked;
                }

                if (type === 'audio') {
                    var am = parseInt(el.querySelector('.reflsub-audio-max').value, 10);
                    sec.max_minutes = isNaN(am) || am <= 0 ? 5 : Math.min(am, 30);
                    sec.required    = el.querySelector('.reflsub-audio-required').checked;
                }

                if (type === 'entry_title') {
                    sec.label    = el.querySelector('.reflsub-et-label').value.trim();
                    sec.required = el.querySelector('.reflsub-et-required').checked;
                }

                if (type === 're_reflect') {
                    sec.heading   = el.querySelector('.reflsub-rr-heading').value.trim();
                    sec.date_from = el.querySelector('.reflsub-rr-date-from').value;
                    sec.date_to   = el.querySelector('.reflsub-rr-date-to').value;
                    var rawTags   = el.querySelector('.reflsub-rr-tags').value;
                    sec.tags      = rawTags.split(',').map(function(t){ return t.trim(); }).filter(Boolean);
                    sec.pick      = el.querySelector('.reflsub-rr-pick').value;
                }

                sections.push(sec);
            });
            document.getElementById('reflsub-sections-json').value = jsonStringify(sections);
        }

        // Public API (called by inline onclick attributes)
        window.reflsubAddSection = function(type) {
            var container = document.getElementById('reflsub-sections');
            container.appendChild(buildSection(type, {}));
        };

        window.reflsubRemoveSection = function(btn) {
            btn.closest('.reflsub-section').remove();
        };

        window.reflsubAddMCQOption = function(btn) {
            var optContainer = btn.closest('.reflsub-section-body').querySelector('.reflsub-mcq-options');
            var row = document.createElement('div');
            row.innerHTML = buildMCQOptionRow('');
            optContainer.appendChild(row.firstElementChild);
        };

        window.reflsubRemoveMCQOption = function(btn) {
            var row = btn.closest('.reflsub-mcq-option-row');
            var options = row.closest('.reflsub-mcq-options');
            if (options.querySelectorAll('.reflsub-mcq-option-row').length > 2) {
                row.remove();
            }
        };

        // JSON stringify that preserves non-ASCII characters as real UTF-8
        // instead of \uXXXX escape sequences.  This prevents PHP's wp_unslash
        // (stripslashes) from eating the leading backslash out of e.g. \u2014.
        function jsonStringify(data) {
            return JSON.stringify(data).replace(/\\u([0-9a-fA-F]{4})/g, function(_, hex) {
                return String.fromCharCode(parseInt(hex, 16));
            });
        }

        // Serialise before form submit
        var form = document.getElementById('reflsub-builder-form');
        if (form) {
            form.addEventListener('submit', function() {
                serialiseSections();
            });
        }

        // Load existing sections from hidden input on page load
        document.addEventListener('DOMContentLoaded', function() {
            var existingJson = document.getElementById('reflsub-sections-json').value;
            if (!existingJson) return;
            try {
                var sections = JSON.parse(existingJson);
                var container = document.getElementById('reflsub-sections');
                sections.forEach(function(sec) {
                    container.appendChild(buildSection(sec.type, sec));
                });
            } catch(e) {
                console.warn('reflsub: could not parse existing sections JSON', e);
            }
        });

        // ── Quick-create parent page ─────────────────────────────────────────
        (function() {
            var toggle = document.getElementById('reflsub-new-parent-toggle');
            var wrap   = document.getElementById('reflsub-new-parent-wrap');
            var input  = document.getElementById('reflsub-new-parent-name');
            var btn    = document.getElementById('reflsub-new-parent-create');
            var msg    = document.getElementById('reflsub-new-parent-msg');
            var select = document.getElementById('reflsub-parent');
            if (!toggle) return;

            toggle.addEventListener('click', function() {
                if (wrap.hasAttribute('hidden')) {
                    wrap.removeAttribute('hidden');
                    input.focus();
                    toggle.textContent = '− Cancel';
                } else {
                    wrap.setAttribute('hidden', '');
                    input.value = '';
                    msg.textContent = '';
                    msg.className = 'reflsub-new-parent-msg';
                    toggle.textContent = '+ New parent page';
                }
            });

            function doCreate() {
                var name = input.value.trim();
                if (!name) { input.focus(); return; }
                btn.disabled = true;
                msg.textContent = 'Creating…';
                msg.className = 'reflsub-new-parent-msg';

                var data = new FormData();
                data.append('action',      'reflsub_create_parent_page');
                data.append('nonce',       toggle.dataset.nonce);
                data.append('parent_name', name);

                fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            var opt = document.createElement('option');
                            opt.value       = res.data.id;
                            opt.textContent = res.data.title;
                            select.appendChild(opt);
                            select.value = res.data.id;
                            wrap.setAttribute('hidden', '');
                            input.value = '';
                            toggle.textContent = '+ New parent page';
                        } else {
                            msg.textContent = res.data || 'Error creating page.';
                            msg.className = 'reflsub-new-parent-msg error';
                        }
                    })
                    .catch(function() {
                        msg.textContent = 'Network error. Please try again.';
                        msg.className = 'reflsub-new-parent-msg error';
                    })
                    .finally(function() { btn.disabled = false; });
            }

            btn.addEventListener('click', doCreate);
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); doCreate(); }
            });
        })();
    })();
    </script>
    <?php
}


// ── Save handler ──────────────────────────────────────────────────────────────

/**
 * Resolve a content-type input — a display name or a slug — to a term slug,
 * creating the term on the fly when it doesn't exist yet. Lets instructors add
 * a content type straight from the builder instead of going to Posts → Content Type.
 * Returns '' when the input is empty or the taxonomy isn't registered.
 */
function reflsub_resolve_content_type_slug( $input ) {
    $input = trim( (string) $input );
    if ( $input === '' || ! taxonomy_exists( 'content-type' ) ) {
        return '';
    }
    // Prefer an existing term — match on display name first, then slug.
    $term = get_term_by( 'name', $input, 'content-type' );
    if ( ! $term ) {
        $term = get_term_by( 'slug', sanitize_title( $input ), 'content-type' );
    }
    if ( $term && ! is_wp_error( $term ) ) {
        return $term->slug;
    }
    // No match — create it from the typed name and use the generated slug.
    $created = wp_insert_term( $input, 'content-type' );
    if ( ! is_wp_error( $created ) && ! empty( $created['term_id'] ) ) {
        $new = get_term( $created['term_id'], 'content-type' );
        if ( $new && ! is_wp_error( $new ) ) {
            return $new->slug;
        }
    }
    return '';
}

add_action( 'admin_post_reflsub_save_reflection_page', 'reflsub_save_reflection_page' );
function reflsub_save_reflection_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Sorry, you do not have permission to do this.' );
    }

    check_admin_referer( 'reflsub_save_reflection_page', 'reflsub_builder_nonce' );

    $page_id     = intval( $_POST['reflsub_page_id'] ?? 0 );
    $title       = sanitize_text_field( wp_unslash( $_POST['reflsub_page_title'] ?? '' ) );
    $intro       = sanitize_textarea_field( wp_unslash( $_POST['reflsub_intro_text'] ?? '' ) );
    $privacy     = sanitize_key( $_POST['reflsub_privacy'] ?? 'publish' );
    $allow_resub          = isset( $_POST['reflsub_allow_resub'] ) ? 1 : 0;
    $allow_student_blocks = isset( $_POST['reflsub_allow_student_blocks'] ) ? 1 : 0;
    $parent_id   = intval( $_POST['reflsub_parent_id'] ?? 0 );
    $page_status = sanitize_key( $_POST['reflsub_page_status'] ?? 'publish' );

    // Auto-tags: stored as a comma-separated string on the page, applied to every submission
    $auto_tags_raw = sanitize_text_field( wp_unslash( $_POST['reflsub_auto_tags'] ?? '' ) );
    $auto_tags_val = implode( ', ', array_values( array_filter(
        array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $auto_tags_raw ) ) )
    ) ) );

    // Content type accepts either an existing term name/slug or a brand-new name typed
    // in the combobox — resolve it to a slug, creating the term on the fly when needed.
    $content_type_input = sanitize_text_field( wp_unslash( $_POST['reflsub_content_type_name'] ?? '' ) );
    $content_type_slug  = reflsub_resolve_content_type_slug( $content_type_input );

    if ( ! in_array( $privacy, array( 'publish', 'private', 'pending' ), true ) ) {
        $privacy = 'publish';
    }
    if ( ! in_array( $page_status, array( 'publish', 'draft', 'private' ), true ) ) {
        $page_status = 'publish';
    }

    // Decode + sanitize sections JSON
    $raw_json = wp_unslash( $_POST['reflsub_sections_json'] ?? '' );
    $sections = array();

    if ( $raw_json ) {
        $decoded = json_decode( $raw_json, true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $sec ) {
                if ( ! isset( $sec['type'] ) ) continue;
                $type    = sanitize_key( $sec['type'] );
                $clean   = array( 'type' => $type );

                switch ( $type ) {
                    case 'entry_title':
                        $clean['label']    = sanitize_text_field( $sec['label'] ?? '' );
                        $clean['required'] = ! empty( $sec['required'] );
                        break;
                    case 'prompt':
                        $clean['label']      = sanitize_textarea_field( $sec['label'] ?? '' );
                        $clean['word_limit'] = max( 0, intval( $sec['word_limit'] ?? 0 ) );
                        $clean['required']   = ! empty( $sec['required'] );
                        break;
                    case 'mcq':
                        $clean['question'] = sanitize_textarea_field( $sec['question'] ?? '' );
                        $raw_opts          = is_array( $sec['options'] ?? null ) ? $sec['options'] : array();
                        $clean['options']  = array_values( array_filter( array_map( 'sanitize_text_field', $raw_opts ) ) );
                        $clean['multi']    = ! empty( $sec['multi'] );
                        break;
                    case 'tags':
                        // Flag section — students see a tag input; no values to configure here
                        break;
                    case 'image':
                    case 'video':
                    case 'embed':
                        // Flag sections — no extra data
                        break;
                    case 'pdf':
                        $clean['required'] = ! empty( $sec['required'] );
                        break;
                    case 'audio':
                        $max = intval( $sec['max_minutes'] ?? 5 );
                        $clean['max_minutes'] = min( 30, max( 1, $max ) );
                        $clean['required']    = ! empty( $sec['required'] );
                        break;
                    case 're_reflect':
                        $clean['heading']   = sanitize_text_field( $sec['heading'] ?? '' );
                        $clean['date_from'] = sanitize_text_field( $sec['date_from'] ?? '' );
                        $clean['date_to']   = sanitize_text_field( $sec['date_to'] ?? '' );
                        $raw_tags           = is_array( $sec['tags'] ?? null ) ? $sec['tags'] : array();
                        $clean['tags']      = array_values( array_filter( array_map( 'sanitize_key', $raw_tags ) ) );
                        $allowed_pick       = array( 'random', 'latest', 'oldest' );
                        $clean['pick']      = in_array( $sec['pick'] ?? '', $allowed_pick, true ) ? $sec['pick'] : 'random';
                        break;
                    default:
                        // Unknown type — skip
                        continue 2;
                }

                $sections[] = $clean;
            }
        }
    }

    // Create or update WP page
    $post_data = array(
        'post_title'   => $title ?: 'Reflection Page',
        'post_excerpt' => $intro,
        'post_status'  => $page_status,
        'post_type'    => 'page',
        'post_parent'  => $parent_id,
        'post_content' => '<!-- wp:shortcode -->[reflection_form]<!-- /wp:shortcode -->',
    );

    $is_new = true;
    if ( $page_id ) {
        $existing = get_post( $page_id );
        if ( $existing && $existing->post_type === 'page' ) {
            $post_data['ID'] = $page_id;
            $result = wp_update_post( $post_data, true );
            $is_new = false;
        }
    }

    if ( $is_new ) {
        $result = wp_insert_post( $post_data, true );
    }

    if ( is_wp_error( $result ) ) {
        $error_msg = urlencode( $result->get_error_message() );
        wp_redirect( admin_url( 'admin.php?page=reflsub-build&reflsub_error=' . $error_msg ) );
        exit;
    }

    $saved_id = $result;

    // Save meta
    // wp_slash(): update_post_meta() runs wp_unslash() on its value internally, which would
    // otherwise strip the backslash out of JSON escapes (’, \", \n …) and corrupt the
    // blob — e.g. a curly apostrophe became "weeku2019s", and a literal " broke decode entirely.
    update_post_meta( $saved_id, '_reflsub_sections',        wp_slash( wp_json_encode( $sections ) ) );
    update_post_meta( $saved_id, 'is_reflection_page',       1 );
    update_post_meta( $saved_id, 'submission_privacy',       $privacy );
    update_post_meta( $saved_id, 'allow_resubmission',       $allow_resub );
    update_post_meta( $saved_id, '_reflsub_auto_tags',       $auto_tags_val );
    update_post_meta( $saved_id, '_reflsub_content_type_slug', $content_type_slug );
    // Cast to string so the renderer's "=== '0'" check sees the explicit value.
    update_post_meta( $saved_id, '_reflsub_allow_student_blocks', $allow_student_blocks ? '1' : '0' );

    $saved_flag = $is_new ? 'created' : 'updated';
    wp_redirect( admin_url( 'admin.php?page=activity-builder&reflsub_saved=' . $saved_flag ) );
    exit;
}
