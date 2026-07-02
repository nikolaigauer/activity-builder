<?php
/**
 * Progress View
 *
 * Shows a student × task completion grid for a selected assignment.
 * An "assignment" is any WP page that is the parent of one or more
 * pages with is_reflection_page = 1.
 *
 * Submenu: reflsub-progress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ── Admin menu registration ────────────────────────────────────────────────────

add_action( 'admin_menu', 'reflsub_progress_register' );
function reflsub_progress_register() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    add_submenu_page(
        'activity-builder',
        'Progress',
        'Progress',
        'manage_options',
        'reflsub-progress',
        'reflsub_render_progress_page'
    );
}


// ── Progress page ─────────────────────────────────────────────────────────────

function reflsub_render_progress_page() {

    // ── Find all assignments ───────────────────────────────────────────────────
    // An assignment is a page that is the parent of at least one reflection page.
    $task_pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'   => 'is_reflection_page',
                'value' => '1',
            ),
        ),
    ) );

    // Collect distinct non-zero parent IDs
    $assignment_ids = array();
    foreach ( $task_pages as $tp ) {
        if ( $tp->post_parent > 0 ) {
            $assignment_ids[ $tp->post_parent ] = true;
        }
    }
    $assignment_ids = array_keys( $assignment_ids );

    // Also include standalone reflection pages that have no parent
    // (so the progress view can still show them if needed — we'll list them
    // under a "No Assignment" group if selected).

    // Build list of assignments for the dropdown
    $assignments = array();
    foreach ( $assignment_ids as $aid ) {
        $page = get_post( $aid );
        if ( $page ) {
            $assignments[ $aid ] = $page->post_title ?: '(no title) #' . $aid;
        }
    }
    asort( $assignments );

    // Selected assignment
    $selected_id = isset( $_GET['assignment'] ) ? intval( $_GET['assignment'] ) : 0;

    $progress_url = admin_url( 'admin.php?page=reflsub-progress' );
    ?>
    <div class="wrap">
        <h1>Progress</h1>

        <form method="get" class="reflsub-progress-filter">
            <input type="hidden" name="page" value="reflsub-progress">
            <label for="reflsub-assignment-select">Assignment:</label>
            <select id="reflsub-assignment-select" name="assignment" onchange="this.form.submit()">
                <option value="0">— Select an assignment —</option>
                <?php foreach ( $assignments as $aid => $atitle ) : ?>
                <option value="<?php echo esc_attr( $aid ); ?>" <?php selected( $selected_id, $aid ); ?>>
                    <?php echo esc_html( $atitle ); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="button">Go</button></noscript>
        </form>

        <?php if ( empty( $assignments ) ) : ?>
        <p class="reflsub-empty-note">
            No assignments found. An assignment is a page that is the parent of one or more reflection pages.
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=reflsub-build' ) ); ?>">Build a reflection page</a> and set its parent to create an assignment.
        </p>
        <?php elseif ( ! $selected_id ) : ?>
        <p class="reflsub-empty-note">Select an assignment above to see student progress.</p>
        <?php else : ?>

        <?php
        // ── Task columns: child reflection pages of selected assignment ──────
        $tasks = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'any',
            'post_parent'    => $selected_id,
            'posts_per_page' => -1,
            'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
            'meta_query'     => array(
                array(
                    'key'   => 'is_reflection_page',
                    'value' => '1',
                ),
            ),
        ) );

        if ( empty( $tasks ) ) : ?>
        <p class="reflsub-empty-note">
            No reflection pages found under "<strong><?php echo esc_html( get_the_title( $selected_id ) ); ?></strong>".
            Make sure reflection pages have this assignment set as their parent.
        </p>
        <?php else :

        // ── Student rows: users with edit_posts capability ───────────────────
        $students = get_users( array(
            'capability' => 'edit_posts',
            'orderby'    => 'display_name',
            'order'      => 'ASC',
            'number'     => 500,
        ) );

        // Preload all submissions for this assignment's tasks to avoid N×M queries
        $task_ids = wp_list_pluck( $tasks, 'ID' );

        $all_submissions = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => '_reflection_source_page',
                    'value'   => $task_ids,
                    'compare' => 'IN',
                ),
            ),
        ) );

        // Index by author_id → page_id → post
        $submission_index = array();
        foreach ( $all_submissions as $sub ) {
            $src = intval( get_post_meta( $sub->ID, '_reflection_source_page', true ) );
            $submission_index[ $sub->post_author ][ $src ] = $sub;
        }

        ?>

        <h2 class="reflsub-progress-summary">
            Assignment: <em><?php echo esc_html( get_the_title( $selected_id ) ); ?></em>
            &nbsp;·&nbsp; <?php echo count( $tasks ); ?> task<?php echo count( $tasks ) !== 1 ? 's' : ''; ?>
            &nbsp;·&nbsp; <?php echo count( $students ); ?> student<?php echo count( $students ) !== 1 ? 's' : ''; ?>
        </h2>

        <div class="reflsub-progress-scroll">
        <table class="wp-list-table widefat fixed reflsub-progress-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <?php foreach ( $tasks as $task ) : ?>
                    <th>
                        <a href="<?php echo esc_url( get_permalink( $task->ID ) ); ?>" target="_blank"
                           title="View page">
                            <?php echo esc_html( $task->post_title ?: '(no title)' ); ?>
                        </a>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $students as $student ) :
                $student_subs = $submission_index[ $student->ID ] ?? array();
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html( $student->display_name ); ?></strong><br>
                    <small><?php echo esc_html( $student->user_login ); ?></small>
                </td>
                <?php foreach ( $tasks as $task ) :
                    $sub = $student_subs[ $task->ID ] ?? null;
                ?>
                <td>
                    <?php if ( $sub ) :
                        $sub_url    = get_permalink( $sub->ID );
                        $sub_date   = get_the_date( 'M j', $sub->ID );
                        $sub_status = ucfirst( $sub->post_status );
                    ?>
                    <a href="<?php echo esc_url( $sub_url ); ?>"
                       target="_blank"
                       title="<?php echo esc_attr( $sub_status ); ?> — <?php echo esc_attr( $sub_date ); ?>"
                       class="reflsub-status-check <?php echo esc_attr( 'is-' . $sub->post_status ); ?>">
                        &#10003;
                    </a><br>
                    <small class="reflsub-status-date"><?php echo esc_html( $sub_date ); ?></small>
                    <?php else : ?>
                    <span class="reflsub-status-none">—</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="reflsub-progress-legend">
            &#10003; = submitted (click to edit). Color indicates status:
            <span class="reflsub-swatch is-publish">&#9632;</span> Published
            <span class="reflsub-swatch is-pending">&#9632;</span> Pending
            <span class="reflsub-swatch is-private">&#9632;</span> Private
            <span class="reflsub-swatch is-draft">&#9632;</span> Draft
        </p>

        <?php endif; // tasks exist ?>
        <?php endif; // selected_id ?>
    </div>
    <?php
}
