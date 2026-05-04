<?php
/**
 * Setup — Site initialisation wizard
 *
 * Creates parent pages, sample child pages, and wp_navigation menus so
 * instructors can scaffold the standard Reflections / Assignments structure
 * with a single click.
 *
 * Navigation menus are stored as wp_navigation posts (WordPress 6.0+) and
 * appear immediately in the Site Editor under Appearance → Navigation.
 * Each menu contains a link to the parent page + a Page List block scoped
 * to that parent's children — so new child pages auto-appear in the menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Register submenu ───────────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_setup_register' );
function reflsub_setup_register() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    add_submenu_page(
        'activity-builder',
        'Site Setup',
        'Setup',
        'manage_options',
        'reflsub-setup',
        'reflsub_render_setup_page'
    );
}


// ── POST handler ───────────────────────────────────────────────────────────────

add_action( 'admin_post_reflsub_create_menu_set', 'reflsub_handle_create_menu_set' );
function reflsub_handle_create_menu_set() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }
    check_admin_referer( 'reflsub_create_menu_set', 'reflsub_nonce' );

    $set_type     = sanitize_key( $_POST['set_type']     ?? '' ); // reflections|assignments|custom
    $parent_name  = sanitize_text_field( wp_unslash( $_POST['parent_name']  ?? '' ) );
    $nav_name     = sanitize_text_field( wp_unslash( $_POST['nav_name']     ?? '' ) );
    $child_name   = sanitize_text_field( wp_unslash( $_POST['child_name']   ?? '' ) );
    $create_child = ! empty( $_POST['create_child'] );

    $back_url = admin_url( 'admin.php?page=reflsub-setup' );

    if ( ! $parent_name ) {
        wp_redirect( add_query_arg( 'reflsub_notice', 'missing_name', $back_url ) );
        exit;
    }
    if ( ! $nav_name ) {
        $nav_name = $parent_name . ' Menu';
    }

    // 1. Create (or find) the parent page — always published
    $parent_id = reflsub_setup_find_or_create_page( $parent_name, 0, 'publish', '' );
    if ( ! $parent_id || is_wp_error( $parent_id ) ) {
        wp_redirect( add_query_arg( 'reflsub_notice', 'page_failed', $back_url ) );
        exit;
    }

    // 2. Create the sample child page
    $child_id = 0;
    if ( $create_child ) {
        if ( ! $child_name ) {
            $child_name = $parent_name . ' — 1';
        }
        $child_id = reflsub_setup_create_reflection_child( $parent_id, $child_name );
    }

    // 3. Create (or update) the wp_navigation post
    $nav_id = reflsub_setup_create_nav( $nav_name, $parent_id );

    // 4. Persist results
    $record = array(
        'parent_id'   => $parent_id,
        'parent_name' => $parent_name,
        'nav_id'      => is_wp_error( $nav_id ) ? 0 : $nav_id,
        'nav_name'    => $nav_name,
        'child_id'    => is_wp_error( $child_id ) ? 0 : (int) $child_id,
        'child_name'  => $child_name,
        'created_at'  => current_time( 'mysql' ),
    );

    if ( $set_type === 'custom' ) {
        $existing   = get_option( 'reflsub_setup_custom', array() );
        $existing[] = $record;
        update_option( 'reflsub_setup_custom', $existing );
    } else {
        update_option( 'reflsub_setup_' . $set_type, $record );
    }

    wp_redirect( add_query_arg(
        array( 'reflsub_notice' => 'created', 'set_type' => $set_type ),
        $back_url
    ) );
    exit;
}


// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Return an existing published/draft page with this title, or create a new one.
 * Uses WP_Query to avoid the deprecated get_page_by_title().
 */
function reflsub_setup_find_or_create_page( $title, $parent_id, $status, $content ) {
    $q = new WP_Query( array(
        'post_type'      => 'page',
        'post_status'    => array( 'publish', 'draft', 'private' ),
        'title'          => $title,
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ) );
    if ( $q->have_posts() ) {
        $found = $q->posts[0];
        // Promote to desired status if still a draft
        if ( $found->post_status !== $status ) {
            wp_update_post( array( 'ID' => $found->ID, 'post_status' => $status ) );
        }
        return $found->ID;
    }

    return wp_insert_post( array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $status,
        'post_type'    => 'page',
        'post_parent'  => (int) $parent_id,
    ), true );
}

/**
 * Create a sample reflection child page using the builder sections format.
 * Published immediately so it appears in the nav.
 */
function reflsub_setup_create_reflection_child( $parent_id, $title ) {
    $content = '<!-- wp:shortcode -->[reflection_form]<!-- /wp:shortcode -->';
    $post_id = reflsub_setup_find_or_create_page( $title, $parent_id, 'publish', $content );

    if ( $post_id && ! is_wp_error( $post_id ) ) {
        update_post_meta( $post_id, 'is_reflection_page',  1 );
        update_post_meta( $post_id, 'submission_privacy',  'publish' );
        update_post_meta( $post_id, 'allow_resubmission',  0 );

        $sections = wp_json_encode( array(
            array(
                'type'       => 'prompt',
                'label'      => 'Describe your experience with this week\'s material. What stood out to you, and why?',
                'word_limit' => 250,
                'required'   => true,
            ),
        ) );
        update_post_meta( $post_id, '_reflsub_sections', $sections );
    }

    return $post_id;
}

/**
 * Create (or update) a wp_navigation post containing:
 *   - A non-clickable navigation-link label (the parent page name)
 *   - A Page List block nested inside it, scoped to the parent's children
 *
 * This produces a dropdown where hovering the label reveals all child pages.
 * Child pages added later appear automatically — no menu editing needed.
 */
function reflsub_setup_create_nav( $nav_title, $parent_id ) {
    $parent_page  = get_post( $parent_id );
    $parent_label = $parent_page ? $parent_page->post_title : $nav_title;

    $link_attrs = wp_json_encode( array(
        'label'         => $parent_label,
        'url'           => '',
        'opensInNewTab' => false,
        'kind'          => 'custom',
    ) );

    $nav_content = sprintf(
        "<!-- wp:navigation-link %s -->\n<!-- wp:page-list {\"parentPageID\":%d} /-->\n<!-- /wp:navigation-link -->",
        $link_attrs,
        (int) $parent_id
    );

    // Look for an existing wp_navigation with this title
    $q = new WP_Query( array(
        'post_type'      => 'wp_navigation',
        'post_status'    => 'publish',
        'title'          => $nav_title,
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ) );

    if ( $q->have_posts() ) {
        $nav_id = $q->posts[0]->ID;
        wp_update_post( array(
            'ID'           => $nav_id,
            'post_content' => $nav_content,
        ) );
        return $nav_id;
    }

    return wp_insert_post( array(
        'post_type'    => 'wp_navigation',
        'post_title'   => $nav_title,
        'post_content' => $nav_content,
        'post_status'  => 'publish',
    ), true );
}

/**
 * Verify that a saved record's pages and nav still exist (handles manual deletion).
 * Returns a cleaned-up record array.
 */
function reflsub_setup_verify_record( $record ) {
    if ( empty( $record ) ) return null;
    $record['parent_ok'] = ! empty( $record['parent_id'] ) && get_post( $record['parent_id'] ) instanceof WP_Post;
    $record['child_ok']  = ! empty( $record['child_id']  ) && get_post( $record['child_id']  ) instanceof WP_Post;
    $record['nav_ok']    = ! empty( $record['nav_id']    ) && get_post( $record['nav_id']    ) instanceof WP_Post;
    return $record;
}


// ── Render ────────────────────────────────────────────────────────────────────

function reflsub_render_setup_page() {
    // Load saved state for the two primary slots
    $saved_reflections = reflsub_setup_verify_record( get_option( 'reflsub_setup_reflections', array() ) );
    $saved_assignments = reflsub_setup_verify_record( get_option( 'reflsub_setup_assignments', array() ) );
    $saved_custom      = get_option( 'reflsub_setup_custom', array() );

    // Notice from redirect
    $notice   = sanitize_key( $_GET['reflsub_notice'] ?? '' );
    $set_type = sanitize_key( $_GET['set_type']       ?? '' );
    ?>
    <div class="wrap">
        <h1>Site Setup</h1>
        <p style="color:#646970; max-width:680px;">
            Create the page structure and navigation menus for your site.
            Each menu uses a <strong>Page List</strong> block scoped to a parent page —
            new child pages appear in the menu automatically, wherever you choose to place it.
        </p>

        <?php if ( $notice === 'created' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Done!</strong> Pages and navigation menu created. Add the menu to any page or template using a Navigation block in the Site Editor.</p>
        </div>
        <?php elseif ( $notice === 'missing_name' ) : ?>
        <div class="notice notice-error is-dismissible"><p>Please enter a parent page name.</p></div>
        <?php elseif ( $notice === 'page_failed' ) : ?>
        <div class="notice notice-error is-dismissible"><p>Could not create the parent page. Check server error logs.</p></div>
        <?php endif; ?>

        <style>
            .reflsub-setup-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 20px;
                max-width: 1080px;
                margin: 24px 0;
            }
            .reflsub-setup-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                overflow: hidden;
            }
            .reflsub-setup-card-header {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 13px 20px;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
            }
            .reflsub-setup-card-header h2 {
                margin: 0;
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .08em;
                color: #1b28b4;
            }
            .reflsub-setup-card-header .card-icon {
                color: #64748b;
                font-size: 18px;
                line-height: 1;
                display: flex;
                align-items: center;
            }
            .reflsub-setup-card-body {
                padding: 18px 20px;
            }
            .reflsub-setup-field {
                margin-bottom: 14px;
            }
            .reflsub-setup-field label {
                display: block;
                font-weight: 600;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: .04em;
                color: #3c434a;
                margin-bottom: 5px;
            }
            .reflsub-setup-field input[type="text"] {
                width: 100%;
                box-sizing: border-box;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 6px 10px;
                font-size: 13px;
            }
            .reflsub-setup-field input[type="text"]:focus {
                outline: none;
                border-color: #1b28b4;
                box-shadow: 0 0 0 2px rgba(27,40,180,.18);
            }
            .reflsub-setup-child-row {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                margin-bottom: 14px;
                padding: 10px 12px;
                background: #f6f7f7;
                border-radius: 4px;
                border: 1px solid #e2e4e7;
            }
            .reflsub-setup-child-row input[type="checkbox"] {
                margin-top: 3px;
                accent-color: #1b28b4;
            }
            .reflsub-setup-child-row label {
                font-size: 13px;
                font-weight: normal;
                color: #3c434a;
                cursor: pointer;
            }
            .reflsub-setup-child-extra {
                margin-top: 8px;
            }
            .reflsub-setup-child-extra input[type="text"] {
                width: 100%;
                box-sizing: border-box;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 5px 9px;
                font-size: 12px;
            }
            .reflsub-setup-submit-row {
                display: flex;
                justify-content: flex-end;
                padding-top: 4px;
            }
            .reflsub-setup-btn {
                background: #ff4128;
                color: #fff !important;
                border: none;
                border-radius: 6px;
                padding: 8px 18px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                transition: background .15s;
                box-shadow: 0 2px 6px rgba(255,65,40,.35);
            }
            .reflsub-setup-btn:hover {
                background: #d63210;
                color: #fff !important;
            }
            /* Status section */
            .reflsub-setup-status {
                border-top: 1px solid #e2e4e7;
                padding: 14px 20px;
                background: #f6f7f7;
                font-size: 12px;
            }
            .reflsub-setup-status-heading {
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: .05em;
                color: #646970;
                margin-bottom: 8px;
            }
            .reflsub-setup-status-row {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-bottom: 4px;
                color: #3c434a;
            }
            .reflsub-status-dot {
                width: 8px; height: 8px;
                border-radius: 50%;
                flex-shrink: 0;
            }
            .reflsub-status-dot.ok  { background: #00a32a; }
            .reflsub-status-dot.err { background: #d63638; }
          
            /* Custom menus list */
            .reflsub-custom-list {
                margin: 16px 0 0;
                padding: 0;
                list-style: none;
                font-size: 12px;
            }
            .reflsub-custom-list li {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 0;
                border-bottom: 1px solid #e2e4e7;
            }
            .reflsub-custom-list li:last-child { border-bottom: none; }
        </style>

        <div class="reflsub-setup-grid">

            <?php
            // ── Card factory: renders a card with a form + status ─────────────
            $cards = array(
                array(
                    'type'         => 'reflections',
                    'icon'         => 'dashicons-book',
                    'title'        => 'Reflections',
                    'description'  => 'Weekly reflection prompts. Creates a parent page and a sample activity page.',
                    'default_parent' => 'Reflections',
                    'default_nav'    => 'Reflections Menu',
                    'default_child'  => 'Reflections — Week 1',
                    'child_label'    => 'Create sample child page (Week 1)',
                    'saved'          => $saved_reflections,
                ),
                array(
                    'type'         => 'assignments',
                    'icon'         => 'dashicons-list-view',
                    'title'        => 'Assignments',
                    'description'  => 'Assignment activities. Creates a parent page and a sample activity page.',
                    'default_parent' => 'Assignments',
                    'default_nav'    => 'Assignments Menu',
                    'default_child'  => 'Assignment 1',
                    'child_label'    => 'Create sample child page (Assignment 1)',
                    'saved'          => $saved_assignments,
                ),
                array(
                    'type'         => 'custom',
                    'icon'         => 'dashicons-plus-alt2',
                    'title'        => 'Additional Menu',
                    'description'  => 'Create any additional parent page + auto-updating menu.',
                    'default_parent' => '',
                    'default_nav'    => '',
                    'default_child'  => '',
                    'child_label'    => 'Create a sample child page',
                    'saved'          => null, // custom stacks; handled separately
                ),
            );

            foreach ( $cards as $card ) :
                $saved = $card['saved'];
                $has_record = ! empty( $saved );
            ?>
            <div class="reflsub-setup-card">
                <div class="reflsub-setup-card-header">
                    <span class="card-icon"><span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span></span>
                    <h2><?php echo esc_html( $card['title'] ); ?></h2>
                </div>
                <div class="reflsub-setup-card-body">
                    <p style="margin:0 0 14px; color:#646970; font-size:12px; line-height:1.5;">
                        <?php echo esc_html( $card['description'] ); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'reflsub_create_menu_set', 'reflsub_nonce' ); ?>
                        <input type="hidden" name="action"   value="reflsub_create_menu_set">
                        <input type="hidden" name="set_type" value="<?php echo esc_attr( $card['type'] ); ?>">

                        <div class="reflsub-setup-field">
                            <label for="parent_name_<?php echo esc_attr( $card['type'] ); ?>">Parent page name</label>
                            <input type="text"
                                   id="parent_name_<?php echo esc_attr( $card['type'] ); ?>"
                                   name="parent_name"
                                   value="<?php echo esc_attr( $has_record ? $saved['parent_name'] : $card['default_parent'] ); ?>"
                                   placeholder="e.g. Reflections"
                                   required>
                        </div>

                        <div class="reflsub-setup-field">
                            <label for="nav_name_<?php echo esc_attr( $card['type'] ); ?>">Navigation menu name</label>
                            <input type="text"
                                   id="nav_name_<?php echo esc_attr( $card['type'] ); ?>"
                                   name="nav_name"
                                   value="<?php echo esc_attr( $has_record ? $saved['nav_name'] : $card['default_nav'] ); ?>"
                                   placeholder="e.g. Reflections Menu">
                        </div>

                        <div class="reflsub-setup-child-row">
                            <input type="checkbox" id="create_child_<?php echo esc_attr( $card['type'] ); ?>"
                                   name="create_child" value="1" checked>
                            <div>
                                <label for="create_child_<?php echo esc_attr( $card['type'] ); ?>">
                                    <?php echo esc_html( $card['child_label'] ); ?>
                                </label>
                                <div class="reflsub-setup-child-extra">
                                    <input type="text"
                                           name="child_name"
                                           value="<?php echo esc_attr( $card['default_child'] ); ?>"
                                           placeholder="Child page name (optional)">
                                </div>
                            </div>
                        </div>

                        <div class="reflsub-setup-submit-row">
                            <button type="submit" class="reflsub-setup-btn">
                                <?php echo $has_record ? '↺ Recreate' : 'Create →'; ?>
                            </button>
                        </div>
                    </form>
                </div><!-- /.card-body -->

                <?php if ( $has_record ) : ?>
                <div class="reflsub-setup-status">
                    <div class="reflsub-setup-status-heading">Created</div>

                    <?php
                    $items = array(
                        array(
                            'ok'    => $saved['parent_ok'],
                            'label' => 'Parent page: ',
                            'link'  => null,
                            'text'  => esc_html( $saved['parent_name'] ),
                        ),
                        array(
                            'ok'    => $saved['nav_ok'],
                            'label' => 'Navigation: ',
                            'link'  => null,
                            'text'  => esc_html( $saved['nav_name'] ),
                        ),
                    );
                    if ( $saved['parent_ok'] ) {
                        $child_ids   = get_posts( array(
                            'post_type'      => 'page',
                            'post_parent'    => $saved['parent_id'],
                            'post_status'    => array( 'publish', 'draft', 'private' ),
                            'posts_per_page' => -1,
                            'fields'         => 'ids',
                            'no_found_rows'  => true,
                        ) );
                        $child_count = count( $child_ids );
                        $pages_link  = $child_count > 0
                            ? admin_url( 'admin.php?page=reflsub-pages' )
                            : null;
                        $items[] = array(
                            'ok'    => $child_count > 0,
                            'label' => 'Child pages: ',
                            'link'  => $pages_link,
                            'text'  => $child_count > 0
                                ? $child_count . ' page' . ( $child_count !== 1 ? 's' : '' )
                                : 'none yet',
                        );
                    }
                    foreach ( $items as $item ) :
                    ?>
                    <div class="reflsub-setup-status-row">
                        <span class="reflsub-status-dot <?php echo $item['ok'] ? 'ok' : 'err'; ?>"></span>
                        <span>
                            <?php echo esc_html( $item['label'] ); ?>
                            <?php if ( $item['link'] && $item['ok'] ) : ?>
                                <a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank"><?php echo $item['text']; ?></a>
                            <?php else : ?>
                                <?php echo $item['text'] ?: '<em>missing</em>'; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div><!-- /.card -->
            <?php endforeach; ?>

        </div><!-- /.grid -->

        <?php if ( ! empty( $saved_custom ) ) : ?>
        <h2 style="font-size:14px; color:#3c434a; border-top: 1px solid #c3c4c7; padding-top:20px; max-width:1080px;">
            Additional menus created
        </h2>
        <ul class="reflsub-custom-list" style="max-width:600px;">
            <?php foreach ( array_reverse( $saved_custom ) as $c ) :
                $r = reflsub_setup_verify_record( $c );
            ?>
            <li>
                <span class="reflsub-status-dot <?php echo $r['nav_ok'] ? 'ok' : 'err'; ?>"></span>
                <strong><?php echo esc_html( $r['nav_name'] ); ?></strong>
                <?php if ( ! $r['parent_ok'] ) : ?>
                    &nbsp;<span style="color:#d63638;">(parent deleted)</span>
                <?php endif; ?>
                &nbsp;·&nbsp;
                <?php
                $new_page_url = admin_url( 'admin.php?page=reflsub-build' );
                if ( $r['parent_ok'] ) {
                    $new_page_url = add_query_arg( 'parent_id', $r['parent_id'], $new_page_url );
                }
                ?>
                <a href="<?php echo esc_url( $new_page_url ); ?>">+ New Activity Page</a>
                <small style="color:#646970; margin-left:auto;"><?php echo esc_html( substr( $r['created_at'] ?? '', 0, 10 ) ); ?></small>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>


    </div><!-- /.wrap -->
    <?php
}
