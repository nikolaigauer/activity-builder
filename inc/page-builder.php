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
        <table class="wp-list-table widefat fixed striped" style="max-width:1100px;">
            <thead>
                <tr>
                    <th style="width:28%">Title</th>
                    <th style="width:22%">Assignment (Parent)</th>
                    <th style="width:10%">Sections</th>
                    <th style="width:12%">Submissions</th>
                    <th style="width:10%">Status</th>
                    <th style="width:18%">Actions</th>
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
                                <span class="reflsub-field-desc">Displayed above the form on the page.</span>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-parent">
                                    Parent Page <span class="reflsub-optional">optional</span>
                                </label>
                                <select id="reflsub-parent" name="reflsub_parent_id">
                                    <option value="0">— Standalone —</option>
                                    <?php foreach ( $all_pages as $p ) : ?>
                                    <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $parent_id, $p->ID ); ?>>
                                        <?php echo esc_html( $p->post_title ?: '(no title) #' . $p->ID ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="reflsub-field-desc">
                                    Nest under a parent page so it auto-appears in the sidebar menu and groups with related pages in the Progress view.
                                </span>
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
                                </label>
                                <input type="text" id="reflsub-auto-tags" name="reflsub_auto_tags"
                                       placeholder="e.g. week-3, photography, critical-thinking"
                                       value="<?php echo esc_attr( $auto_tags_val ); ?>">
                                <span class="reflsub-field-desc">Comma-separated. These tags are silently applied to every submission from this page — students don't see them.</span>
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
                                <span class="reflsub-field-desc">Controls whether students can access the page.</span>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-privacy">Submission Privacy</label>
                                <select id="reflsub-privacy" name="reflsub_privacy">
                                    <option value="publish" <?php selected( $privacy, 'publish' ); ?>>Publish — visible to all</option>
                                    <option value="private" <?php selected( $privacy, 'private' ); ?>>Private — student only</option>
                                    <option value="pending" <?php selected( $privacy, 'pending' ); ?>>Pending Review</option>
                                </select>
                                <span class="reflsub-field-desc">Status applied to each submission.</span>
                            </div>

                            <div class="reflsub-field reflsub-field-check">
                                <label>Resubmission</label>
                                <label class="reflsub-toggle">
                                    <input type="checkbox" name="reflsub_allow_resub" value="1"
                                           <?php checked( $allow_resub, 1 ); ?>>
                                    <span>Allow students to submit more than once</span>
                                </label>
                            </div>

                            <div class="reflsub-field">
                                <label for="reflsub-content-type">
                                    Content Type <span class="reflsub-optional">optional</span>
                                </label>
                                <?php
                                $ct_terms = taxonomy_exists( 'content-type' )
                                    ? get_terms( array( 'taxonomy' => 'content-type', 'hide_empty' => false ) )
                                    : array();
                                if ( ! empty( $ct_terms ) && ! is_wp_error( $ct_terms ) ) : ?>
                                <select id="reflsub-content-type" name="reflsub_content_type_slug">
                                    <option value="">— None —</option>
                                    <?php foreach ( $ct_terms as $ct ) : ?>
                                    <option value="<?php echo esc_attr( $ct->slug ); ?>"
                                            <?php selected( $content_type_slug, $ct->slug ); ?>>
                                        <?php echo esc_html( $ct->name ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else : ?>
                                <input type="text" id="reflsub-content-type" name="reflsub_content_type_slug"
                                       placeholder="e.g. reflection"
                                       value="<?php echo esc_attr( $content_type_slug ); ?>">
                                <?php endif; ?>
                                <span class="reflsub-field-desc">Submissions are tagged with this content type in the student archive. Create terms under Posts → Content Types.</span>
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
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('prompt')">Prompt</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('mcq')">Multiple Choice</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('image')">Image</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('video')">Video URL</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('embed')">Embed</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('tags')">Student Tags</button>
                        <button type="button" class="reflsub-add-btn" onclick="reflsubAddSection('pdf')">PDF / File</button>
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

    <style>
        /* ── Tokens ─────────────────────────────────────────────────── */
        :root {
            /* Brand palette */
            --rs-blue:          #1b28b4;   /* International Klein Blue */
            --rs-blue-dark:     #141e88;
            --rs-sky:           #62b1d8;   /* Fresh Sky */
            --rs-aqua:          #ace7d4;   /* Pearl Aqua */
            --rs-cream:         #f3feca;   /* Cream */
            --rs-fire:          #ff4128;   /* Scarlet Fire */
            /* Semantic aliases used throughout */
            --rs-accent:        #1b28b4;
            --rs-accent-dark:   #141e88;
            --rs-accent-bg:     #f3feca;
            --rs-accent-border: #ace7d4;
            --rs-accent-text:   #f3feca;   /* text on Klein Blue */
            --rs-danger:        #ff4128;
            --rs-danger-bg:     rgba(255,65,40,.08);
            --rs-danger-border: rgba(255,65,40,.28);
            --rs-g50:  #f8fafc;
            --rs-g100: #f1f5f9;
            --rs-g200: #e2e8f0;
            --rs-g500: #64748b;
            --rs-g700: #334155;
            --rs-g900: #0f172a;
            --rs-r:    12px;
            --rs-r-sm: 7px;
            --rs-pill: 999px;
            --rs-shadow-sm: 0 1px 3px rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.04);
            --rs-t: .15s ease;
        }

        /* ── App shell ─────────────────────────────────────────────── */
        .reflsub-app {
            max-width: 1100px;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* ── Two-column builder layout ─────────────────────────────── */
        .reflsub-builder-columns {
            display: grid;
            grid-template-columns: 1fr 260px;
            gap: 32px;
            align-items: start;
        }
        .reflsub-col-sidebar {
            border-left: 1px solid var(--rs-g200);
            padding-left: 32px;
        }
        .reflsub-col-sidebar .reflsub-field input[type="text"],
        .reflsub-col-sidebar .reflsub-field select {
            max-width: 100%;
        }

        /* ── Page header ───────────────────────────────────────────── */
        .reflsub-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            background: #fff;
            border: 1px solid var(--rs-g200);
            border-top: 6px solid var(--rs-fire);
            /*border-radius: var(--rs-r);*/
            box-shadow: var(--rs-shadow-sm);
        }
        .reflsub-page-header h1 {
            font-size: 20px; font-weight: 800;
            color: var(--rs-blue); margin: 0 0 4px; padding: 0; line-height: 1.2;
            text-transform: uppercase; letter-spacing: .06em;
        }
        .reflsub-page-header p {
            font-size: 14px; color: var(--rs-g500); margin: 0;
        }
        .reflsub-back-link {
            flex-shrink: 0;
            color: var(--rs-accent);
            font-size: 13px; font-weight: 500;
            text-decoration: none;
            border: 1px solid var(--rs-accent-border);
            border-radius: var(--rs-pill);
            padding: 7px 16px;
            transition: background var(--rs-t);
            white-space: nowrap;
        }
        .reflsub-back-link:hover { background: rgba(27,40,180,.08); color: var(--rs-accent-dark); }

        /* ── Cards ─────────────────────────────────────────────────── */
        .reflsub-card {
            background: #fff;
            border: 1px solid var(--rs-g200);
            border-radius: var(--rs-r);
            box-shadow: var(--rs-shadow-sm);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .reflsub-card-header {
            padding: 13px 24px;
            font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            color: var(--rs-g500);
            background: var(--rs-g50);
            border-bottom: 1px solid var(--rs-g200);
        }
        .reflsub-card-body { padding: 24px; }
        .reflsub-card-desc { margin: 0 0 20px; font-size: 14px; color: var(--rs-g500); }

        /* ── Fields ────────────────────────────────────────────────── */
        .reflsub-field { margin-bottom: 20px; }
        .reflsub-field:last-child { margin-bottom: 0; }
        .reflsub-field > label {
            display: block; font-size: 15px; font-weight: 600;
            color: var(--rs-g700); margin-bottom: 7px;
        }
        .reflsub-optional {
            font-size: 12px; font-weight: normal; color: var(--rs-g500);
            background: var(--rs-g100); padding: 1px 6px;
            border-radius: 4px; margin-left: 5px;
        }
        .reflsub-field input[type="text"],
        .reflsub-field textarea,
        .reflsub-field select {
            display: block; width: 100%; max-width: 480px;
            padding: 9px 12px; font-size: 14px; font-family: inherit;
            color: var(--rs-g900); background: var(--rs-g50);
            border: 1.5px solid var(--rs-g200); border-radius: var(--rs-r-sm);
            box-sizing: border-box; -webkit-appearance: none; appearance: none;
            transition: border-color var(--rs-t), box-shadow var(--rs-t), background var(--rs-t);
        }
        .reflsub-field select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath fill='%2364748b' d='M5 6L0 0h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px;
        }
        .reflsub-field input:focus,
        .reflsub-field textarea:focus,
        .reflsub-field select:focus {
            border-color: var(--rs-accent); background: #fff;
            box-shadow: 0 0 0 3px rgba(27,40,180,.18); outline: none;
        }
        .reflsub-field textarea { resize: vertical; }
        .reflsub-field-desc { display: block; margin-top: 5px; font-size: 12px; color: var(--rs-g500); }

        /* Intro text fills the left column */
        #reflsub-intro-text { max-width: 100%; }

        /* Quick-create parent page */
        .reflsub-new-parent-toggle {
            display: inline-flex; align-items: center; gap: 4px;
            margin-top: 8px; padding: 0; background: none; border: none;
            color: var(--rs-accent); font-size: 12px; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: color var(--rs-t);
        }
        .reflsub-new-parent-toggle:hover { color: var(--rs-accent-dark); }
        .reflsub-new-parent-wrap {
            display: flex; align-items: center; gap: 8px;
            margin-top: 10px; padding: 12px 14px;
            background: var(--rs-g50); border: 1.5px solid var(--rs-g200);
            border-radius: var(--rs-r-sm); max-width: 480px; box-sizing: border-box;
        }
        .reflsub-new-parent-wrap input[type="text"] {
            flex: 1; min-width: 0; margin: 0; max-width: none;
            padding: 7px 10px; font-size: 13px;
            border: 1.5px solid var(--rs-g200); border-radius: var(--rs-r-sm);
            background: #fff; color: var(--rs-g900);
        }
        .reflsub-new-parent-wrap input[type="text"]:focus {
            border-color: var(--rs-accent); outline: none;
            box-shadow: 0 0 0 3px rgba(27,40,180,.18);
        }
        .reflsub-new-parent-create {
            flex-shrink: 0; padding: 7px 14px; font-size: 13px; font-weight: 600;
            background: var(--rs-accent); color: #fff; border: none;
            border-radius: var(--rs-r-sm); cursor: pointer;
            transition: background var(--rs-t);
        }
        .reflsub-new-parent-create:hover { background: var(--rs-accent-dark); }
        .reflsub-new-parent-create:disabled { opacity: .6; cursor: default; }
        .reflsub-new-parent-msg { font-size: 12px; color: var(--rs-g500); }
        .reflsub-new-parent-msg.error { color: #d63638; }

        /* Two-column field row */
        .reflsub-field-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;
        }
        .reflsub-field-row .reflsub-field { margin-bottom: 0; }

        /* Checkbox / toggle field */
        .reflsub-field-check > label:first-child { margin-bottom: 8px; }
        .reflsub-toggle {
            display: flex; align-items: flex-start; gap: 8px;
            font-size: 14px; color: var(--rs-g700); cursor: pointer; font-weight: normal;
        }
        .reflsub-toggle input { margin-top: 2px; flex-shrink: 0; }

        /* ── Section cards ─────────────────────────────────────────── */
        .reflsub-section {
            background: #fff; border: 1px solid var(--rs-accent-border);
            border-radius: 9px; margin-bottom: 12px; box-shadow: var(--rs-shadow-sm);
        }
        .reflsub-section-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 16px; background: var(--rs-accent-bg);
            border-bottom: 1px solid var(--rs-accent-border);
            border-radius: 9px 9px 0 0;
        }
        .reflsub-section-header span {
            font-weight: 700; font-size: 14px; color: var(--rs-accent-dark);
            text-transform: uppercase; letter-spacing: .06em;
        }
        .reflsub-section-header .button {
            border-radius: var(--rs-pill) !important;
            border-color: var(--rs-danger-border) !important;
            color: var(--rs-danger) !important; background: var(--rs-danger-bg) !important;
            padding: 5px 14px !important; font-size: 12px !important; font-weight: 600 !important;
            line-height: 1.7 !important; height: auto !important; box-shadow: none !important;
            transition: background var(--rs-t) !important;
        }
        .reflsub-section-header .button:hover { background: rgba(255,65,40,.15) !important; }
        .reflsub-section-body { padding: 16px; }
        .reflsub-section-body > label {
            display: block; font-weight: 600; margin-bottom: 5px;
            font-size: 13px; color: var(--rs-g500);
            text-transform: uppercase; letter-spacing: .07em;
        }
        .reflsub-section-body textarea,
        .reflsub-section-body input[type="text"],
        .reflsub-section-body input[type="number"] {
            width: 100%; max-width: 100%; margin-bottom: 10px;
            padding: 10px 12px; border: 1.5px solid var(--rs-g200);
            border-radius: var(--rs-r-sm); font-size: 15px; font-family: inherit;
            background: var(--rs-g50); box-sizing: border-box;
            transition: border-color var(--rs-t), box-shadow var(--rs-t), background var(--rs-t);
        }
        .reflsub-section-body textarea:focus,
        .reflsub-section-body input[type="text"]:focus,
        .reflsub-section-body input[type="number"]:focus {
            border-color: var(--rs-accent); background: #fff;
            box-shadow: 0 0 0 3px rgba(27,40,180,.18); outline: none;
        }
        .reflsub-section-body textarea { resize: vertical; }
        .reflsub-mcq-options { margin-bottom: 8px; }
        .reflsub-mcq-option-row { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
        .reflsub-mcq-option-row input { flex: 1; margin-bottom: 0; }
        .reflsub-section-meta { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-top: 4px; }
        .reflsub-section-meta label {
            text-transform: none; letter-spacing: 0; font-weight: normal;
            font-size: 13px; color: var(--rs-g700); display: flex; align-items: center; gap: 6px;
        }
        .reflsub-section-meta input[type="number"] { width: 80px; margin-bottom: 0; }
        .reflsub-flag-note { color: var(--rs-g500); font-style: italic; font-size: 13px; margin: 0; }

        /* ── Add-section palette ───────────────────────────────────── */
        .reflsub-add-palette {
            display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
            padding: 18px 20px; margin-top: 12px;
            background: var(--rs-g50); border: 1.5px dashed var(--rs-accent-border);
            border-radius: var(--rs-r-sm);
        }
        .reflsub-add-label { font-size: 13px; font-weight: 600; color: var(--rs-g500); margin-right: 2px; }
        .reflsub-add-btn {
            border: 1.5px solid var(--rs-accent-border) !important;
            color: var(--rs-accent-dark) !important; background: #fff !important;
            border-radius: 6px !important; padding: 10px 16px !important;
            font-size: 14px !important; font-weight: 600 !important;
            line-height: 1.5 !important; height: auto !important; box-shadow: none !important;
            cursor: pointer;
            transition: background var(--rs-t), color var(--rs-t), border-color var(--rs-t) !important;
        }
        .reflsub-add-btn:hover {
            background: var(--rs-accent) !important; color: var(--rs-accent-text) !important;
            border-color: var(--rs-accent) !important;
        }

        /* ── Actions bar ───────────────────────────────────────────── */
        .reflsub-actions { display: flex; align-items: center; gap: 12px; padding: 8px 0 24px; }
        .reflsub-btn-primary {
            background: var(--rs-fire) !important; color: #fff !important;
            border: none !important; border-radius: var(--rs-r-sm) !important;
            padding: 10px 26px !important; font-size: 14px !important; font-weight: 700 !important;
            line-height: 1.4 !important; height: auto !important; cursor: pointer;
            box-shadow: 0 2px 8px rgba(255,65,40,.4) !important;
            transition: background var(--rs-t), box-shadow var(--rs-t) !important;
        }
        .reflsub-btn-primary:hover {
            background: #d63210 !important; color: #fff !important;
            box-shadow: 0 4px 14px rgba(255,65,40,.5) !important;
        }
        .reflsub-btn-ghost {
            color: var(--rs-g500); font-size: 13px; text-decoration: none; padding: 10px 4px;
            transition: color var(--rs-t);
        }
        .reflsub-btn-ghost:hover { color: var(--rs-g900); }

        /* ── Section reorder ───────────────────────────────────────── */
        .reflsub-drag-handle {
            cursor: grab; font-size: 18px; color: var(--rs-g500);
            user-select: none; line-height: 1; padding: 0 2px; flex-shrink: 0;
        }
        .reflsub-drag-handle:active { cursor: grabbing; }
        .reflsub-move-btn {
            background: none !important; border: 1px solid transparent !important;
            box-shadow: none !important; color: var(--rs-g500) !important;
            font-size: 15px !important; padding: 2px 5px !important;
            cursor: pointer; line-height: 1; height: auto !important; border-radius: 4px !important;
            transition: color var(--rs-t), border-color var(--rs-t), background var(--rs-t) !important;
        }
        .reflsub-move-btn:hover {
            color: var(--rs-accent) !important; border-color: var(--rs-accent-border) !important;
            background: var(--rs-accent-bg) !important;
        }
        .reflsub-section.reflsub-drag-over {
            border-color: var(--rs-accent) !important;
            box-shadow: 0 0 0 2px rgba(27,40,180,.25) !important;
        }
    </style>

    <script>
    (function() {
        var sectionCount = 0;

        // Section type labels
        var LABELS = {
            prompt: 'Prompt (Text Response)',
            mcq:    'Multiple Choice Question',
            image:  'Image Upload',
            video:  'Video URL',
            embed:  'Embed Code',
            tags:   'Student Tags',
            pdf:        'PDF / File Upload',
            re_reflect: 'Re-reflect on a Past Post'
        };

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
            if (type === 'prompt') {
                var checked = data.required ? ' checked' : '';
                return '<label>Prompt / Question</label>' +
                    '<textarea class="reflsub-prompt-label" rows="3" placeholder="Enter the prompt students will respond to…">' +
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

            if (type === 'image') {
                return '<p class="reflsub-flag-note">Adds an image upload field to the form. No configuration needed.</p>';
            }
            if (type === 'video') {
                return '<p class="reflsub-flag-note">Adds a Video URL field (YouTube / Vimeo). No configuration needed.</p>';
            }
            if (type === 'embed') {
                return '<p class="reflsub-flag-note">Adds an embed code textarea (Kaltura, iFrame, etc.). No configuration needed.</p>';
            }

            if (type === 'tags') {
                return '<p class="reflsub-flag-note">Adds a tag input field to the form so students can add their own tags to their submission. Use the Auto-tags field in Page Settings to apply tags automatically.</p>';
            }

            if (type === 'pdf') {
                var reqChecked = data.required ? ' checked' : '';
                return '<p class="reflsub-flag-note">Adds a PDF upload field to the form. Students may upload one PDF (max 15 MB).</p>' +
                    '<div class="reflsub-section-meta"><label><input type="checkbox" class="reflsub-pdf-required"' + reqChecked + '> Required</label></div>';
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
                    '</div>' +
                    '<p class="reflsub-flag-note" style="margin-top:10px;">When the student opens this form, one of their past posts matching the filters above will appear as a read-only card. If no match is found the card is silently hidden.</p>';
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
    $allow_resub = isset( $_POST['reflsub_allow_resub'] ) ? 1 : 0;
    $parent_id   = intval( $_POST['reflsub_parent_id'] ?? 0 );
    $page_status = sanitize_key( $_POST['reflsub_page_status'] ?? 'publish' );

    // Auto-tags: stored as a comma-separated string on the page, applied to every submission
    $auto_tags_raw = sanitize_text_field( wp_unslash( $_POST['reflsub_auto_tags'] ?? '' ) );
    $auto_tags_val = implode( ', ', array_values( array_filter(
        array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $auto_tags_raw ) ) )
    ) ) );

    $content_type_slug = sanitize_key( $_POST['reflsub_content_type_slug'] ?? '' );

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
    update_post_meta( $saved_id, '_reflsub_sections',        wp_json_encode( $sections ) );
    update_post_meta( $saved_id, 'is_reflection_page',       1 );
    update_post_meta( $saved_id, 'submission_privacy',       $privacy );
    update_post_meta( $saved_id, 'allow_resubmission',       $allow_resub );
    update_post_meta( $saved_id, '_reflsub_auto_tags',       $auto_tags_val );
    update_post_meta( $saved_id, '_reflsub_content_type_slug', $content_type_slug );

    $saved_flag = $is_new ? 'created' : 'updated';
    wp_redirect( admin_url( 'admin.php?page=activity-builder&reflsub_saved=' . $saved_flag ) );
    exit;
}
