<?php
/**
 * Admin Page — top-level Activity Builder menu + Submissions list
 *
 *   - Registers the "Activity Builder" top-level menu (admin only); Activity
 *     Pages is the default landing view (rendered by page-builder.php).
 *   - Submissions list (reflsub-submissions): all posts with
 *     _reflection_source_page meta, filterable by status / student / page;
 *     Approve / Trash actions inline.
 *   - "+ New > Activity Page" admin toolbar item.
 *   - reflsub_tip() shared tooltip helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Shared admin UI: hover help tooltip ────────────────────────────────────────
// Returns a dashicon (?) that reveals $text on hover/focus. CSS-only (admin.css,
// ".reflsub-tip"); keyboard-reachable via tabindex. Use $align = 'left' when the
// icon sits near the right edge of a card/column so the bubble grows leftward.
// Keep tooltip copy to 1–2 sentences: behaviour, consequences, fallbacks —
// anything an instructor doesn't need to read on every visit.

function reflsub_tip( $text, $align = 'center' ) {
    $class = 'reflsub-tip' . ( $align === 'left' ? ' reflsub-tip--left' : '' );
    return '<span class="' . esc_attr( $class ) . '" tabindex="0">'
         . '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>'
         . '<span class="reflsub-tip-text" role="tooltip">' . esc_html( $text ) . '</span>'
         . '</span>';
}


// ── Admin menu ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_add_admin_menu' );
function reflsub_add_admin_menu() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    add_menu_page(
        'Activity Builder',
        'Activity Builder',
        'manage_options',
        'activity-builder',
        'reflsub_render_pages_list',
        'dashicons-welcome-write-blog',
        26
    );

    // Primary submenu — matches top-level slug; Activity Pages is the default landing view
    add_submenu_page(
        'activity-builder',
        'Activity Pages',
        'Activity Pages',
        'manage_options',
        'activity-builder',
        'reflsub_render_pages_list'
    );

    add_submenu_page(
        'activity-builder',
        'Submissions',
        'Submissions',
        'manage_options',
        'reflsub-submissions',
        'reflsub_render_submissions_page'
    );
}


// ── Submissions page ───────────────────────────────────────────────────────────

// Approve means one specific thing: "an instructor has reviewed work that was
// waiting for review, and is publishing it". Only `pending` is waiting for
// review, so only `pending` may be approved.
//
// The other statuses are not merely unusual here, they are actively wrong:
//   private — confidential by the instructor's own choice on the activity page.
//             Approving it would publish one student's confidential work and
//             silently contradict that page's Submission Privacy setting.
//   draft   — the student has not handed it in yet. Approving would publish
//             unfinished work on their behalf.
//   publish — already published; nothing to approve.
//
// Both callers must consult this before offering the button AND before acting on
// the POST. Hiding a button is not a control: the form can be replayed, and the
// handler used to publish anything carrying `_reflection_source_page`.
function reflsub_submission_can_be_approved( $post ) {
    $post = get_post( $post );

    return $post
        && $post->post_status === 'pending'
        && get_post_meta( $post->ID, '_reflection_source_page', true )
        && current_user_can( 'edit_post', $post->ID );
}


function reflsub_render_submissions_page() {

    // ── Handle approve / trash actions ────────────────────────────────────────
    if ( isset( $_POST['reflsub_action'] ) && check_admin_referer( 'reflsub_submission_action', 'reflsub_nonce' ) ) {
        $action  = sanitize_text_field( $_POST['reflsub_action'] );
        $post_id = intval( $_POST['reflsub_post_id'] ?? 0 );

        // Verify this is actually a reflection submission before acting
        if ( $post_id && get_post_meta( $post_id, '_reflection_source_page', true ) ) {
            if ( $action === 'approve' ) {
                // Re-checked here, not just where the button is drawn — see the
                // note on reflsub_submission_can_be_approved().
                if ( reflsub_submission_can_be_approved( $post_id ) ) {
                    wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
                    echo '<div class="notice notice-success is-dismissible"><p><strong>Submission approved and published.</strong></p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Not approved.</strong> '
                       . 'Only submissions awaiting review can be approved. Private submissions stay private by '
                       . 'design, and a draft has not been handed in yet — to change a submission\'s status anyway, '
                       . 'open it in the block editor.</p></div>';
                }
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

    // ── Student + page filters ────────────────────────────────────────────────
    $student_filter = isset( $_GET['sub_student'] ) ? intval( $_GET['sub_student'] ) : 0;
    $page_filter    = isset( $_GET['sub_page'] )    ? intval( $_GET['sub_page'] )    : 0;

    $query_status = ( $status_filter === 'all' )
        ? array( 'publish', 'pending', 'private', 'draft' )
        : array( $status_filter );

    $meta_query = $page_filter
        ? array( array( 'key' => '_reflection_source_page', 'value' => $page_filter ) )
        : array( array( 'key' => '_reflection_source_page', 'compare' => 'EXISTS' ) );

    $query_args = array(
        'post_type'      => 'post',
        'post_status'    => $query_status,
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => $meta_query,
    );
    if ( $student_filter ) {
        $query_args['author'] = $student_filter;
    }
    $submissions = get_posts( $query_args );

    // Build source-page options for the filter dropdown (always unfiltered)
    $all_sub_ids = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array( array( 'key' => '_reflection_source_page', 'compare' => 'EXISTS' ) ),
    ) );
    $source_page_options = array();
    foreach ( $all_sub_ids as $sid ) {
        $pid = intval( get_post_meta( $sid, '_reflection_source_page', true ) );
        if ( $pid && ! isset( $source_page_options[ $pid ] ) ) {
            $source_page_options[ $pid ] = get_the_title( $pid );
        }
    }
    asort( $source_page_options );

    $status_labels = array(
        'publish' => array( 'label' => 'Published', 'color' => '#00a32a' ),
        'pending' => array( 'label' => 'Pending',   'color' => '#dba617' ),
        'private' => array( 'label' => 'Private',   'color' => '#2271b1' ),
        'draft'   => array( 'label' => 'Draft',     'color' => '#646970' ),
        'trash'   => array( 'label' => 'Trash',     'color' => '#d63638' ),
    );

    $page_url     = admin_url( 'admin.php?page=reflsub-submissions' );
    $new_page_url = admin_url( 'admin.php?page=reflsub-build' );
    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:16px;">
            Activity Builder
            <a href="<?php echo esc_url( $new_page_url ); ?>" class="page-title-action">
                + New Activity Page
            </a>
        </h1>

        <?php
        // If filtering by student, show a banner with a clear link
        if ( $student_filter ) :
            $filtered_student = get_userdata( $student_filter );
            $clear_student_url = add_query_arg( 'sub_status', $status_filter, $page_url );
            if ( $page_filter ) {
                $clear_student_url = add_query_arg( 'sub_page', $page_filter, $clear_student_url );
            }
        ?>
        <div style="margin-bottom:12px; padding:8px 14px; background:#f0f6fc; border-left:4px solid #2271b1; border-radius:0 4px 4px 0; display:flex; align-items:center; gap:12px;">
            <span>Showing submissions by <strong><?php echo esc_html( $filtered_student ? $filtered_student->display_name : "User #{$student_filter}" ); ?></strong></span>
            <a href="<?php echo esc_url( $clear_student_url ); ?>" class="button button-small">x All students</a>
        </div>
        <?php endif; ?>

        <div style="margin-bottom:16px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">

            <div style="display:flex; gap:4px; flex-wrap:wrap;">
            <?php foreach ( array( 'all' => 'All', 'pending' => 'Pending', 'publish' => 'Published', 'private' => 'Private', 'trash' => 'Trash' ) as $slug => $label ) :
                $tab_url = add_query_arg( 'sub_status', $slug, $page_url );
                if ( $student_filter ) {
                    $tab_url = add_query_arg( 'sub_student', $student_filter, $tab_url );
                }
                if ( $page_filter ) {
                    $tab_url = add_query_arg( 'sub_page', $page_filter, $tab_url );
                }
            ?>
            <a href="<?php echo esc_url( $tab_url ); ?>"
               class="button <?php echo $status_filter === $slug ? 'button-primary' : ''; ?>"
               ><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $source_page_options ) ) : ?>
            <form method="get" style="display:flex; align-items:center; gap:8px; margin:0;">
                <input type="hidden" name="page" value="reflsub-submissions">
                <?php if ( $student_filter ) : ?>
                <input type="hidden" name="sub_student" value="<?php echo esc_attr( $student_filter ); ?>">
                <?php endif; ?>
                <?php if ( $status_filter !== 'all' ) : ?>
                <input type="hidden" name="sub_status" value="<?php echo esc_attr( $status_filter ); ?>">
                <?php endif; ?>
                <label for="reflsub-page-filter" style="font-size:13px; color:#646970; white-space:nowrap;">Page:</label>
                <select id="reflsub-page-filter" name="sub_page" onchange="this.form.submit()"
                        style="height:30px; border:1px solid #8c8f94; border-radius:3px; padding:0 8px; font-size:13px; color:#1d2327;">
                    <option value="">All pages</option>
                    <?php foreach ( $source_page_options as $pid => $ptitle ) : ?>
                    <option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $page_filter, $pid ); ?>>
                        <?php echo esc_html( $ptitle ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $page_filter ) : ?>
                <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'reflsub-submissions', 'sub_status' => $status_filter ), admin_url( 'admin.php' ) ) ); ?>"
                   class="button button-small" title="Clear page filter">x</a>
                <?php endif; ?>
            </form>
            <?php endif; ?>

        </div>

        <?php if ( empty( $submissions ) ) : ?>
        <p style="color:#646970; font-style:italic;">No submissions found with this status.</p>

        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="max-width: 1200px;">
            <thead>
                <tr>
                    <th style="width:22%">Student</th>
                    <th style="width:28%">Submission</th>
                    <th style="width:22%">Page</th>
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
                    <div style="display:flex; flex-direction:column; gap:4px;">
                    <a href="<?php echo esc_url( $feedback_url ); ?>"
                       class="button button-small"
                       style="font-size:11px; height:24px; line-height:22px; padding:0 8px;
                              text-align:center; box-sizing:border-box;
                              <?php echo $has_feedback ? 'color:#00a32a; border-color:#00a32a;' : ''; ?>">
                        <?php echo $has_feedback ? '● Feedback' : 'Feedback'; ?>
                    </a>
                    <?php if ( reflsub_submission_can_be_approved( $sub ) ) : ?>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field( 'reflsub_submission_action', 'reflsub_nonce' ); ?>
                        <input type="hidden" name="reflsub_action"  value="approve">
                        <input type="hidden" name="reflsub_post_id" value="<?php echo esc_attr( $sub->ID ); ?>">
                        <button type="submit" class="button button-primary"
                                style="font-size:11px; height:24px; line-height:22px; padding:0 8px;
                                       width:100%; box-sizing:border-box;">
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
                                style="font-size:11px; height:24px; line-height:22px; padding:0 8px;
                                       color:#d63638; width:100%; box-sizing:border-box;">
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
        'title'  => 'Activity Page',
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
        'post_title'   => 'Activity —',
        'post_content' => '<!-- wp:shortcode -->[reflection_form]<!-- /wp:shortcode -->',
        'post_status'  => 'draft',
        'post_type'    => 'page',
    ), true );

    if ( is_wp_error( $page_id ) ) {
        wp_die( 'Could not create page: ' . esc_html( $page_id->get_error_message() ) );
    }

    update_post_meta( $page_id, 'is_reflection_page',  1 );
    update_post_meta( $page_id, 'submission_privacy',  'publish' );
    update_post_meta( $page_id, 'allow_resubmission',  0 );
    update_post_meta( $page_id, 'reflection_prompt_1', 'Describe your experience with this week\'s material.' );

    wp_redirect( get_edit_post_link( $page_id, 'raw' ) );
    exit;
}
