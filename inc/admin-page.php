<?php
/**
 * Admin Page — Reflection Submissions
 *
 * Adds a "Reflections" top-level menu (admin only) with:
 *   - Submissions list: all posts with _reflection_source_page meta,
 *     filterable by status; Approve / Trash actions inline.
 *   - "New Reflection Page" admin toolbar item and handler: creates a draft
 *     page with [reflection_form] shortcode baked in and sensible ACF defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Admin menu ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_add_admin_menu' );
function reflsub_add_admin_menu() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    add_menu_page(
        'Reflections',
        'Reflections',
        'manage_options',
        'reflection-submissions',
        'reflsub_render_submissions_page',
        'dashicons-welcome-write-blog',
        26
    );

    // Primary submenu — matches top-level slug to avoid duplicate label
    add_submenu_page(
        'reflection-submissions',
        'Submissions',
        'Submissions',
        'manage_options',
        'reflection-submissions',
        'reflsub_render_submissions_page'
    );
}


// ── Submissions page ───────────────────────────────────────────────────────────

function reflsub_render_submissions_page() {

    // ── Handle approve / trash actions ────────────────────────────────────────
    if ( isset( $_POST['reflsub_action'] ) && check_admin_referer( 'reflsub_submission_action', 'reflsub_nonce' ) ) {
        $action  = sanitize_text_field( $_POST['reflsub_action'] );
        $post_id = intval( $_POST['reflsub_post_id'] ?? 0 );

        // Verify this is actually a reflection submission before acting
        if ( $post_id && get_post_meta( $post_id, '_reflection_source_page', true ) ) {
            if ( $action === 'approve' ) {
                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
                echo '<div class="notice notice-success is-dismissible"><p><strong>Submission approved and published.</strong></p></div>';
            } elseif ( $action === 'trash' ) {
                wp_trash_post( $post_id );
                echo '<div class="notice notice-success is-dismissible"><p><strong>Submission moved to trash.</strong></p></div>';
            }
        }
    }

    // ── Status filter ─────────────────────────────────────────────────────────
    $valid_statuses = array( 'pending', 'all', 'publish', 'private', 'trash' );
    $status_filter  = isset( $_GET['sub_status'] ) && in_array( $_GET['sub_status'], $valid_statuses )
        ? sanitize_key( $_GET['sub_status'] )
        : 'all'; // default: show everything

    // ── Student filter ────────────────────────────────────────────────────────
    $student_filter = isset( $_GET['sub_student'] ) ? intval( $_GET['sub_student'] ) : 0;

    $query_status = ( $status_filter === 'all' )
        ? array( 'publish', 'pending', 'private', 'draft' )
        : array( $status_filter );

    $query_args = array(
        'post_type'      => 'post',
        'post_status'    => $query_status,
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => array(
            array(
                'key'     => '_reflection_source_page',
                'compare' => 'EXISTS',
            ),
        ),
    );
    if ( $student_filter ) {
        $query_args['author'] = $student_filter;
    }
    $submissions = get_posts( $query_args );

    $status_labels = array(
        'publish' => array( 'label' => 'Published', 'color' => '#00a32a' ),
        'pending' => array( 'label' => 'Pending',   'color' => '#dba617' ),
        'private' => array( 'label' => 'Private',   'color' => '#2271b1' ),
        'draft'   => array( 'label' => 'Draft',     'color' => '#646970' ),
        'trash'   => array( 'label' => 'Trash',     'color' => '#d63638' ),
    );

    $page_url     = admin_url( 'admin.php?page=reflection-submissions' );
    $new_page_url = admin_url( 'admin.php?page=reflsub-build' );
    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:16px;">
            Reflection Submissions
            <a href="<?php echo esc_url( $new_page_url ); ?>" class="page-title-action">
                + New Reflection Page
            </a>
        </h1>

        <?php
        // If filtering by student, show a banner with a clear link
        if ( $student_filter ) :
            $filtered_student = get_userdata( $student_filter );
            $clear_student_url = add_query_arg( 'sub_status', $status_filter, $page_url );
        ?>
        <div style="margin-bottom:12px; padding:8px 14px; background:#f0f6fc; border-left:4px solid #2271b1; border-radius:0 4px 4px 0; display:flex; align-items:center; gap:12px;">
            <span>Showing submissions by <strong><?php echo esc_html( $filtered_student ? $filtered_student->display_name : "User #{$student_filter}" ); ?></strong></span>
            <a href="<?php echo esc_url( $clear_student_url ); ?>" class="button button-small">✕ All students</a>
        </div>
        <?php endif; ?>

        <div style="margin-bottom: 16px;">
            <?php foreach ( array( 'all' => 'All', 'pending' => 'Pending', 'publish' => 'Published', 'private' => 'Private', 'trash' => 'Trash' ) as $slug => $label ) :
                $tab_url = add_query_arg( 'sub_status', $slug, $page_url );
                if ( $student_filter ) {
                    $tab_url = add_query_arg( 'sub_student', $student_filter, $tab_url );
                }
            ?>
            <a href="<?php echo esc_url( $tab_url ); ?>"
               class="button <?php echo $status_filter === $slug ? 'button-primary' : ''; ?>"
               style="margin-right: 4px;"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $submissions ) ) : ?>
        <p style="color:#646970; font-style:italic;">No submissions found with this status.</p>

        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="max-width: 1200px;">
            <thead>
                <tr>
                    <th style="width:22%">Student</th>
                    <th style="width:28%">Submission</th>
                    <th style="width:22%">Week / Page</th>
                    <th style="width:12%">Date</th>
                    <th style="width:8%">Status</th>
                    <th style="width:8%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $submissions as $sub ) :
                $author       = get_userdata( $sub->post_author );
                $source_page  = intval( get_post_meta( $sub->ID, '_reflection_source_page', true ) );
                $source_title = $source_page ? get_the_title( $source_page ) : '—';
                $source_url   = $source_page ? get_permalink( $source_page ) : null;
                $front_url    = get_permalink( $sub->ID );
                $editor_url   = get_edit_post_link( $sub->ID );
                $status_info  = $status_labels[ $sub->post_status ] ?? array( 'label' => $sub->post_status, 'color' => '#646970' );
            ?>
            <?php
                $student_url = add_query_arg( array(
                    'sub_student' => $sub->post_author,
                    'sub_status'  => $status_filter,
                ), $page_url );
            ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url( $student_url ); ?>" style="font-weight:600; text-decoration:none; color:inherit;">
                        <?php echo esc_html( $author ? $author->display_name : '—' ); ?>
                    </a><br>
                    <small style="color:#646970;"><?php echo esc_html( $author ? $author->user_login : '' ); ?></small>
                </td>
                <?php
                    $has_feedback   = (bool) get_post_meta( $sub->ID, '_reflsub_feedback', true );
                    $feedback_url   = admin_url( 'admin.php?page=reflsub-feedback&post=' . $sub->ID );
                ?>
                <td>
                    <a href="<?php echo esc_url( $front_url ); ?>" target="_blank" style="font-weight:600;">
                        <?php echo esc_html( $sub->post_title ); ?>
                    </a>
                    <?php if ( $has_feedback ) : ?>
                    <br><small style="color:#00a32a; font-weight:600;">● Feedback given</small>
                    <?php endif; ?>
                    <?php if ( $editor_url ) : ?>
                    <br><small><a href="<?php echo esc_url( $editor_url ); ?>" style="color:#646970;">Edit in WP →</a></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( $source_url ) : ?>
                        <a href="<?php echo esc_url( $source_url ); ?>" target="_blank"><?php echo esc_html( $source_title ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $source_title ); ?>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px; color:#646970;">
                    <?php echo esc_html( get_the_date( 'M j, Y', $sub->ID ) ); ?>
                </td>
                <td>
                    <span style="color:<?php echo esc_attr( $status_info['color'] ); ?>; font-weight:600; font-size:12px;">
                        <?php echo esc_html( $status_info['label'] ); ?>
                    </span>
                </td>
                <td>
                    <div style="display:flex; gap:4px; flex-wrap:wrap;">
                    <a href="<?php echo esc_url( $feedback_url ); ?>"
                       class="button button-small"
                       style="font-size:11px; height:24px; line-height:22px; padding:0 8px;
                              <?php echo $has_feedback ? 'color:#00a32a; border-color:#00a32a;' : ''; ?>">
                        <?php echo $has_feedback ? '● Feedback' : 'Feedback'; ?>
                    </a>
                    <?php if ( in_array( $sub->post_status, array( 'pending', 'private', 'draft' ) ) ) : ?>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field( 'reflsub_submission_action', 'reflsub_nonce' ); ?>
                        <input type="hidden" name="reflsub_action"  value="approve">
                        <input type="hidden" name="reflsub_post_id" value="<?php echo esc_attr( $sub->ID ); ?>">
                        <button type="submit" class="button button-primary"
                                style="font-size:11px; height:24px; line-height:22px; padding:0 8px;">
                            Approve
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ( $sub->post_status !== 'trash' ) : ?>
                    <form method="post" style="margin:0;"
                          onsubmit="return confirm('Move this submission to trash?');">
                        <?php wp_nonce_field( 'reflsub_submission_action', 'reflsub_nonce' ); ?>
                        <input type="hidden" name="reflsub_action"  value="trash">
                        <input type="hidden" name="reflsub_post_id" value="<?php echo esc_attr( $sub->ID ); ?>">
                        <button type="submit" class="button"
                                style="font-size:11px; height:24px; line-height:22px; padding:0 8px; color:#d63638;">
                            Trash
                        </button>
                    </form>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:8px; color:#646970; font-size:12px;">
            <?php echo count( $submissions ); ?> submission<?php echo count( $submissions ) !== 1 ? 's' : ''; ?> shown.
        </p>
        <?php endif; ?>
    </div>
    <?php
}


// ── Admin toolbar: "+ New > Reflection Page" (admins only) ────────────────────

add_action( 'admin_bar_menu', 'reflsub_toolbar_new_reflection_page', 999 );
function reflsub_toolbar_new_reflection_page( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $url = admin_url( 'admin.php?page=reflsub-build' );
    $wp_admin_bar->add_node( array(
        'parent' => 'new-content',
        'id'     => 'reflsub-new-reflection-page',
        'title'  => 'Reflection Page',
        'href'   => $url,
    ) );
}


// ── Handler: create a new Reflection Page draft ───────────────────────────────

add_action( 'admin_post_reflsub_new_reflection_page', 'reflsub_create_reflection_page' );
function reflsub_create_reflection_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Sorry, you do not have permission to do this.' );
    }
    check_admin_referer( 'reflsub_new_reflection_page' );

    $page_id = wp_insert_post( array(
        'post_title'   => 'Reflection — Week',
        'post_content' => '<!-- wp:shortcode -->[reflection_form]<!-- /wp:shortcode -->',
        'post_status'  => 'draft',
        'post_type'    => 'page',
    ), true );

    if ( is_wp_error( $page_id ) ) {
        wp_die( 'Could not create page: ' . esc_html( $page_id->get_error_message() ) );
    }

    // Set ACF field values. update_field() stores the field-key → meta-key mapping ACF needs.
    if ( function_exists( 'update_field' ) ) {
        update_field( 'is_reflection_page',  1,         $page_id );
        update_field( 'submission_privacy',  'publish', $page_id );
        update_field( 'allow_resubmission',  0,         $page_id );
        update_field( 'reflection_prompt_1', 'Describe your experience with this week\'s material.', $page_id );
    } else {
        // Fallback: ACF not yet loaded at this point (rare edge case)
        update_post_meta( $page_id, 'is_reflection_page',  1 );
        update_post_meta( $page_id, 'submission_privacy',  'publish' );
        update_post_meta( $page_id, 'allow_resubmission',  0 );
        update_post_meta( $page_id, 'reflection_prompt_1', 'Describe your experience with this week\'s material.' );
    }

    wp_redirect( get_edit_post_link( $page_id, 'raw' ) );
    exit;
}
