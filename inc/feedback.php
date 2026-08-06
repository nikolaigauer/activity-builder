<?php
/**
 * Feedback
 *
 * Instructor view: side-by-side submission + feedback textarea with
 * prev/next navigation through submissions for the same reflection page.
 *
 * Student view: "My Submissions" list with inline feedback display.
 *
 * Meta keys (stored on submission posts):
 *   _reflsub_feedback       — instructor feedback text
 *   _reflsub_feedback_date  — Unix timestamp of last save
 *   _reflsub_feedback_by    — user ID of the instructor who gave feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Admin menu registration ────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_feedback_register' );
function reflsub_feedback_register() {

    if ( current_user_can( 'manage_options' ) ) {
        add_submenu_page(
            'activity-builder',
            'Feedback',
            'Feedback',
            'manage_options',
            'reflsub-feedback',
            'reflsub_render_feedback_page'
        );
    }

    // The student-facing "My Submissions" menu is NOT registered here — it is the
    // top level of the student menu tree and is registered together with New Post
    // in post-form.php (reflsub_post_form_register), so the parent always exists
    // before its submenus. This file still owns the render callback.
}


// ── Save handler ───────────────────────────────────────────────────────────────

add_action( 'admin_post_reflsub_save_feedback', 'reflsub_save_feedback' );
function reflsub_save_feedback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    check_admin_referer( 'reflsub_save_feedback', 'reflsub_feedback_nonce' );

    $post_id  = intval( $_POST['reflsub_feedback_post_id'] ?? 0 );
    $feedback = sanitize_textarea_field( wp_unslash( $_POST['reflsub_feedback_text'] ?? '' ) );
    $approve  = ! empty( $_POST['reflsub_feedback_approve'] );

    if ( ! $post_id ) {
        wp_die( 'Invalid submission.' );
    }

    $post = get_post( $post_id );
    if ( ! $post || ! get_post_meta( $post_id, '_reflection_source_page', true ) ) {
        wp_die( 'Post is not a reflection submission.' );
    }

    update_post_meta( $post_id, '_reflsub_feedback',      $feedback );
    update_post_meta( $post_id, '_reflsub_feedback_date', time() );
    update_post_meta( $post_id, '_reflsub_feedback_by',   get_current_user_id() );

    // Same gate as the Submissions list — "not already published" is far too wide
    // a test, because it also catches private (confidential by instructor choice)
    // and draft (not handed in). Feedback is still saved either way; only the
    // publish is refused.
    if ( $approve && reflsub_submission_can_be_approved( $post_id ) ) {
        wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
    }

    wp_redirect( admin_url( 'admin.php?page=reflsub-feedback&post=' . $post_id . '&reflsub_saved=1' ) );
    exit;
}


// ── Instructor feedback page ───────────────────────────────────────────────────

function reflsub_render_feedback_page() {

    $post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0;

    if ( ! $post_id ) {
        ?>
        <div class="wrap">
            <h1>Feedback</h1>
            <p style="color:#646970; font-size:14px;">
                Select a submission from the
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=activity-builder' ) ); ?>">Submissions list</a>
                to give feedback.
            </p>
        </div>
        <?php
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post || ! get_post_meta( $post_id, '_reflection_source_page', true ) ) {
        echo '<div class="wrap"><p>Submission not found.</p></div>';
        return;
    }

    $author         = get_userdata( $post->post_author );
    $source_page_id = intval( get_post_meta( $post_id, '_reflection_source_page', true ) );
    $source_title   = $source_page_id ? get_the_title( $source_page_id ) : '—';
    $source_url     = $source_page_id ? get_permalink( $source_page_id ) : null;

    $existing_feedback = get_post_meta( $post_id, '_reflsub_feedback',      true );
    $feedback_date     = get_post_meta( $post_id, '_reflsub_feedback_date', true );
    $feedback_by       = get_post_meta( $post_id, '_reflsub_feedback_by',   true );
    $feedback_author   = $feedback_by ? get_userdata( intval( $feedback_by ) ) : null;

    $status_labels = array(
        'publish' => array( 'label' => 'Published',      'color' => '#00a32a' ),
        'pending' => array( 'label' => 'Pending Review', 'color' => '#dba617' ),
        'private' => array( 'label' => 'Private',        'color' => '#2271b1' ),
        'draft'   => array( 'label' => 'Draft',          'color' => '#646970' ),
    );
    $status_info = $status_labels[ $post->post_status ] ?? array( 'label' => ucfirst( $post->post_status ), 'color' => '#646970' );

    // ── Prev/next siblings for the same source page ────────────────────────────
    $siblings = array();
    if ( $source_page_id ) {
        $siblings = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'   => '_reflection_source_page',
                    'value' => $source_page_id,
                ),
            ),
        ) );
    }

    $sibling_ids  = wp_list_pluck( $siblings, 'ID' );
    $current_idx  = array_search( $post_id, $sibling_ids );
    $prev_id      = ( $current_idx > 0 ) ? $sibling_ids[ $current_idx - 1 ] : null;
    $next_id      = ( $current_idx !== false && $current_idx < count( $sibling_ids ) - 1 )
        ? $sibling_ids[ $current_idx + 1 ] : null;

    $submissions_url = admin_url( 'admin.php?page=activity-builder' );
    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:16px; margin-bottom:20px;">
            Feedback
            <a href="<?php echo esc_url( $submissions_url ); ?>" class="page-title-action">← All Submissions</a>
        </h1>

        <?php if ( isset( $_GET['reflsub_saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
            <p>Feedback saved.</p>
        </div>
        <?php endif; ?>

        <div id="reflsub-feedback-layout" style="display:flex; max-width:1400px; align-items:start;">

            <!-- Left: submission content -->
            <div id="reflsub-content-pane" style="flex:0 0 62%; min-width:280px; min-height:1px;">
                <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; overflow:hidden;">

                    <!-- Header bar -->
                    <div style="padding:14px 20px; background:#f6f7f7; border-bottom:1px solid #c3c4c7; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:700; font-size:16px; color:#1d2327; margin-bottom:4px;">
                                <?php echo esc_html( $post->post_title ?: '(untitled)' ); ?>
                            </div>
                            <div style="font-size:12px; color:#646970; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                                <span><?php echo esc_html( $author ? $author->display_name : '—' ); ?></span>
                                <span>·</span>
                                <?php if ( $source_url ) : ?>
                                <a href="<?php echo esc_url( $source_url ); ?>" target="_blank"
                                   style="color:#646970; text-decoration:none;"><?php echo esc_html( $source_title ); ?></a>
                                <?php else : ?>
                                <span><?php echo esc_html( $source_title ); ?></span>
                                <?php endif; ?>
                                <span>·</span>
                                <span><?php echo esc_html( get_the_date( 'M j, Y', $post_id ) ); ?></span>
                                <span>·</span>
                                <span style="color:<?php echo esc_attr( $status_info['color'] ); ?>; font-weight:600;">
                                    <?php echo esc_html( $status_info['label'] ); ?>
                                </span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"
                           style="font-size:12px; color:#646970; text-decoration:none; white-space:nowrap;"
                           target="_blank">Edit in WP →</a>
                    </div>

                    <!-- Post content -->
                    <div style="padding:28px 32px; font-size:15px; line-height:1.75; color:#1d2327;">
                        <?php echo wp_kses_post( apply_filters( 'the_content', $post->post_content ) ); ?>
                    </div>

                </div>
            </div>

            <!-- Drag splitter -->
            <div id="reflsub-splitter" title="Drag to resize"
                 style="flex-shrink:0; width:14px; cursor:col-resize; align-self:stretch;
                        display:flex; align-items:flex-start; justify-content:center; padding-top:80px;">
                <div id="reflsub-splitter-handle"
                     style="width:4px; height:48px; background:#c3c4c7; border-radius:2px;
                            pointer-events:none; transition:background .15s;"></div>
            </div>

            <!-- Right: feedback panel -->
            <div id="reflsub-feedback-pane" style="flex:1; min-width:260px; position:sticky; top:32px;">
                <div style="background:#fff; border:1px solid #c3c4c7; border-radius:6px; overflow:hidden;">

                    <div style="padding:13px 20px; background:#f6f7f7; border-bottom:1px solid #c3c4c7;
                                font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#646970;">
                        Instructor Feedback
                    </div>

                    <div style="padding:20px;">

                        <?php if ( $existing_feedback && $feedback_date ) : ?>
                        <div style="margin-bottom:16px; padding:8px 12px; background:#f0fdf4; border-left:3px solid #00a32a;
                                    border-radius:0 4px 4px 0; font-size:11px; color:#166534; line-height:1.5;">
                            Last saved <?php echo esc_html( date_i18n( 'M j, Y \a\t g:i a', intval( $feedback_date ) ) ); ?>
                            <?php if ( $feedback_author ) : ?>
                                by <?php echo esc_html( $feedback_author->display_name ); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'reflsub_save_feedback', 'reflsub_feedback_nonce' ); ?>
                            <input type="hidden" name="action"                    value="reflsub_save_feedback">
                            <input type="hidden" name="reflsub_feedback_post_id" value="<?php echo esc_attr( $post_id ); ?>">

                            <textarea
                                name="reflsub_feedback_text"
                                rows="12"
                                placeholder="Write your feedback here…"
                                style="width:100%; padding:10px 12px; border:1.5px solid #c3c4c7; border-radius:4px;
                                       font-size:14px; font-family:inherit; box-sizing:border-box; resize:vertical; line-height:1.65;
                                       transition:border-color .15s, box-shadow .15s;"
                                onfocus="this.style.borderColor='#2271b1'; this.style.boxShadow='0 0 0 2px rgba(34,113,177,.15)';"
                                onblur="this.style.borderColor='#c3c4c7'; this.style.boxShadow='';"
                            ><?php echo esc_textarea( $existing_feedback ); ?></textarea>

                            <?php if ( reflsub_submission_can_be_approved( $post ) ) : ?>
                            <label style="display:flex; align-items:center; gap:8px; margin:12px 0 8px;
                                          font-size:13px; cursor:pointer; color:#1d2327; font-weight:normal;">
                                <input type="checkbox" name="reflsub_feedback_approve" value="1">
                                Also approve &amp; publish this submission
                            </label>
                            <?php endif; ?>

                            <button type="submit"
                                    style="width:100%; margin-top:10px; padding:11px; background:#1b28b4; color:#fff;
                                           border:none; border-radius:5px; font-size:14px; font-weight:700; cursor:pointer;
                                           transition:background .15s;"
                                    onmouseover="this.style.background='#141e88';"
                                    onmouseout="this.style.background='#1b28b4';">
                                Save Feedback
                            </button>
                        </form>

                    </div>
                </div>

                <!-- Prev / next nav within the same reflection page -->
                <?php if ( count( $sibling_ids ) > 1 ) : ?>
                <div style="margin-top:14px; display:flex; justify-content:space-between; align-items:center;
                             font-size:12px; color:#646970; padding:0 2px;">
                    <?php if ( $prev_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=reflsub-feedback&post=' . $prev_id ) ); ?>"
                       style="color:#2271b1; text-decoration:none;">← Prev</a>
                    <?php else : ?>
                    <span></span>
                    <?php endif; ?>

                    <span><?php echo intval( $current_idx + 1 ); ?> / <?php echo count( $sibling_ids ); ?> for this page</span>

                    <?php if ( $next_id ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=reflsub-feedback&post=' . $next_id ) ); ?>"
                       style="color:#2271b1; text-decoration:none;">Next →</a>
                    <?php else : ?>
                    <span></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div><!-- /right panel -->

        </div><!-- /flex layout -->
    </div>

    <script>
    (function() {
        var layout   = document.getElementById('reflsub-feedback-layout');
        var leftPane = document.getElementById('reflsub-content-pane');
        var splitter = document.getElementById('reflsub-splitter');
        var handle   = document.getElementById('reflsub-splitter-handle');

        var saved = localStorage.getItem('reflsub_split_pct');
        if (saved) {
            var pct = parseFloat(saved);
            if (pct >= 20 && pct <= 82) leftPane.style.flex = '0 0 ' + pct + '%';
        }

        var dragging = false, startX = 0, startW = 0, totalW = 0;

        splitter.addEventListener('mousedown', function(e) {
            dragging = true;
            startX   = e.clientX;
            startW   = leftPane.getBoundingClientRect().width;
            totalW   = layout.getBoundingClientRect().width;
            document.body.style.cursor     = 'col-resize';
            document.body.style.userSelect = 'none';
            handle.style.background = '#2271b1';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!dragging) return;
            var pct = Math.max(20, Math.min(82, (startW + e.clientX - startX) / totalW * 100));
            leftPane.style.flex = '0 0 ' + pct + '%';
        });

        document.addEventListener('mouseup', function() {
            if (!dragging) return;
            dragging = false;
            document.body.style.cursor = document.body.style.userSelect = '';
            handle.style.background = '#c3c4c7';
            var pct = leftPane.getBoundingClientRect().width / layout.getBoundingClientRect().width * 100;
            localStorage.setItem('reflsub_split_pct', pct.toFixed(1));
        });

        splitter.addEventListener('mouseenter', function() {
            if (!dragging) handle.style.background = '#2271b1';
        });
        splitter.addEventListener('mouseleave', function() {
            if (!dragging) handle.style.background = '#c3c4c7';
        });
    })();
    </script>
    <?php
}


// ── Student "My Submissions" page ──────────────────────────────────────────────

function reflsub_render_my_submissions_page() {

    $user_id = get_current_user_id();

    $submissions = get_posts( array(
        'post_type'      => 'post',
        'author'         => $user_id,
        'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => array(
            array(
                'key'     => '_reflection_source_page',
                'compare' => 'EXISTS',
            ),
        ),
    ) );

    $status_labels = array(
        'publish' => array( 'label' => 'Published',      'color' => '#00a32a' ),
        'pending' => array( 'label' => 'Pending Review', 'color' => '#dba617' ),
        'private' => array( 'label' => 'Private',        'color' => '#2271b1' ),
        'draft'   => array( 'label' => 'Draft',          'color' => '#646970' ),
    );
    ?>
    <div class="wrap">
    <div class="reflsub-app">

        <div class="reflsub-page-header">
            <div>
                <h1>My Submissions</h1>
                <p>Your reflection submissions and any feedback from your instructor.</p>
            </div>
        </div>

        <?php if ( empty( $submissions ) ) : ?>
        <p style="color:#646970; font-style:italic;">You haven't submitted any reflections yet.</p>

        <?php else : ?>
        <div style="display:flex; flex-direction:column; gap:14px; max-width:820px;">

        <?php foreach ( $submissions as $sub ) :
            $source_page_id = intval( get_post_meta( $sub->ID, '_reflection_source_page', true ) );
            $source_title   = $source_page_id ? get_the_title( $source_page_id ) : '—';
            $source_url     = $source_page_id ? get_permalink( $source_page_id ) : null;
            $feedback       = get_post_meta( $sub->ID, '_reflsub_feedback', true );
            $feedback_date  = get_post_meta( $sub->ID, '_reflsub_feedback_date', true );
            $status_info    = $status_labels[ $sub->post_status ] ?? array( 'label' => ucfirst( $sub->post_status ), 'color' => '#646970' );
            $view_link      = reflsub_submission_view_link( $sub );
            $edit_url       = $source_page_id
                ? add_query_arg( 'edit_submission', $sub->ID, get_permalink( $source_page_id ) )
                : null;
        ?>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;
                    box-shadow:0 1px 3px rgba(0,0,0,.06);">

            <div style="padding:14px 20px; display:flex; align-items:center; gap:16px;
                         flex-wrap:wrap; justify-content:space-between; border-bottom:1px solid #e2e8f0;">
                <div>
                    <div style="font-weight:700; font-size:15px; color:#1d2327; margin-bottom:3px;">
                        <?php echo esc_html( $sub->post_title ?: '(untitled)' ); ?>
                    </div>
                    <div style="font-size:12px; color:#646970; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <?php if ( $source_url ) : ?>
                        <a href="<?php echo esc_url( $source_url ); ?>"
                           style="color:#646970;"><?php echo esc_html( $source_title ); ?></a>
                        <?php else : ?>
                        <span><?php echo esc_html( $source_title ); ?></span>
                        <?php endif; ?>
                        <span>·</span>
                        <span><?php echo esc_html( get_the_date( 'M j, Y', $sub->ID ) ); ?></span>
                        <span>·</span>
                        <span style="color:<?php echo esc_attr( $status_info['color'] ); ?>; font-weight:600;">
                            <?php echo esc_html( $status_info['label'] ); ?>
                        </span>
                        <?php if ( $feedback ) : ?>
                        <span style="color:#00a32a; font-weight:600;">● Feedback</span>
                        <?php endif; ?>
                    </div>
                    <?php // Says who can see it, so "why isn't this on my blog?" answers itself. ?>
                    <?php if ( $view_link && $view_link['hint'] ) : ?>
                    <div style="font-size:12px; color:#646970; margin-top:4px;">
                        <?php echo esc_html( $view_link['hint'] ); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:6px; flex-shrink:0;">
                    <?php if ( $view_link ) : ?>
                    <a href="<?php echo esc_url( $view_link['url'] ); ?>"
                       class="button button-small" target="_blank"><?php echo esc_html( $view_link['label'] ); ?></a>
                    <?php endif; ?>
                    <?php if ( $edit_url ) : ?>
                    <?php // An unsubmitted draft is resumed, not edited. ?>
                    <a href="<?php echo esc_url( $edit_url ); ?>"
                       class="button button-small"><?php
                        echo $sub->post_status === 'draft' ? 'Continue' : 'Edit';
                    ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $feedback ) : ?>
            <details>
                <summary style="padding:10px 20px; font-size:13px; font-weight:600; color:#166534; cursor:pointer;
                                 list-style:none; display:flex; align-items:center; gap:6px;
                                 background:#f0fdf4; border-bottom:1px solid #dcfce7; user-select:none;">
                    <span class="reflsub-summary-arrow" style="font-size:10px; transition:transform .15s;">▶</span>
                    Instructor Feedback
                    <?php if ( $feedback_date ) : ?>
                    <span style="font-weight:normal; color:#646970; margin-left:6px;">
                        · <?php echo esc_html( date_i18n( 'M j, Y', intval( $feedback_date ) ) ); ?>
                    </span>
                    <?php endif; ?>
                </summary>
                <div style="padding:18px 20px; font-size:14px; line-height:1.75; color:#1d2327; white-space:pre-wrap;"><?php echo esc_html( $feedback ); ?></div>
            </details>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>

        </div>
        <?php endif; ?>

    </div><!-- .reflsub-app -->
    </div><!-- .wrap -->

    <script>
    // Rotate arrow on details open/close
    document.querySelectorAll('details').forEach(function(d) {
        d.addEventListener('toggle', function() {
            var arrow = d.querySelector('.reflsub-summary-arrow');
            if (arrow) arrow.style.transform = d.open ? 'rotate(90deg)' : '';
        });
    });
    </script>
    <?php
}
