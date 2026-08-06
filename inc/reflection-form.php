<?php
/**
 * Reflection Form
 *
 * Frontend form for student reflection submissions.
 * Shortcode: [reflection_form]
 *
 * Features:
 *   - 1–3 instructor-authored prompts rendered as labelled textareas
 *   - Optional image upload (set as featured image)
 *   - Optional video URL (YouTube/Vimeo, embedded in post content)
 *   - Optional embed code (Kaltura, iFrame, etc.)
 *   - Duplicate submission guard (configurable per page via ACF)
 *   - Auto-assigns content-type taxonomy term for archive filtering
 *   - Post status controlled by page's "Submission Privacy" ACF field
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Allow browser-recorded audio MIME types for the Audio Recording section.
// MediaRecorder emits audio/webm (Chrome/Firefox) or audio/mp4 (Safari/iOS);
// the .webm/.m4a/.ogg extensions are mapped here so wp_handle_upload accepts them.
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'upload_mimes', 'reflsub_allow_audio_mimes' );
function reflsub_allow_audio_mimes( $mimes ) {
    // webm / mp4 / ogg are already in core's default allow-list; add the audio-only
    // sibling extensions defensively without clobbering core's existing mappings.
    if ( empty( $mimes['weba'] ) ) $mimes['weba'] = 'audio/webm';
    if ( empty( $mimes['m4a'] ) )  $mimes['m4a']  = 'audio/mp4';
    return $mimes;
}

// Asset helpers (reflsub_asset_ver, reflsub_enqueue_form_assets,
// reflsub_enqueue_audio_recorder) live in inc/assets.php.

// ─────────────────────────────────────────────────────────────────────────────
// Helper: sanitize an embed code — allows <iframe> only, strips everything else
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// Editing a submission: point students at the activity form, not the block editor
// ─────────────────────────────────────────────────────────────────────────────
//
// A student viewing their own post (author archive, single post, admin bar) gets
// an "Edit" link that lands them in Gutenberg — a different, much larger editor
// than the one they submitted with, showing their answers as raw blocks with the
// prompt structure invisible. For a non-expert that is a dead end.
//
// The links are retargeted rather than the destination being blocked: the block
// editor stays fully available (Posts list, direct URL, and untouched for anyone
// who can manage_options), so nothing is taken away from instructors or from a
// student who deliberately goes looking for it.

add_filter( 'get_edit_post_link', 'reflsub_retarget_submission_edit_link', 10, 3 );
function reflsub_retarget_submission_edit_link( $link, $post_id, $context = '' ) {
    // Instructors/admins keep the raw editor links — they have an explicit
    // "Edit in WP →" affordance in the Submissions list and often need it.
    if ( ! $link || current_user_can( 'manage_options' ) ) {
        return $link;
    }

    $source_page_id = (int) get_post_meta( $post_id, '_reflection_source_page', true );
    if ( ! $source_page_id ) {
        return $link; // not an Activity Builder submission
    }

    $source = get_post( $source_page_id );
    if ( ! $source || $source->post_status === 'trash' ) {
        return $link; // activity page is gone — the block editor is better than nothing
    }

    $url = add_query_arg( 'edit_submission', $post_id, get_permalink( $source_page_id ) );

    // WP passes 'display' when the URL is about to be echoed into HTML.
    return ( $context === 'display' ) ? esc_url( $url ) : $url;
}


// Because the main "Edit" action now points at the activity form for these users,
// the block editor would otherwise have no visible route from the Posts list.
// This adds it back as an explicit second choice: the friendly editor stays the
// default, the powerful one stays one click away and clearly labelled.
// Only added where the Edit link was actually retargeted — for anyone who can
// manage_options, "Edit" already goes to the block editor.
add_filter( 'post_row_actions', 'reflsub_add_block_editor_row_action', 10, 2 );
function reflsub_add_block_editor_row_action( $actions, $post ) {
    if ( $post->post_type !== 'post' || current_user_can( 'manage_options' ) ) {
        return $actions;
    }
    if ( ! get_post_meta( $post->ID, '_reflection_source_page', true ) ) {
        return $actions; // not an Activity Builder submission
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }

    $actions['reflsub_block_editor'] = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) ),
        esc_html( 'Block editor' )
    );

    return $actions;
}


// Anyone who reaches the block editor for a submission anyway — via the Posts
// list, a bookmark, or as an admin — gets a way back to the activity form.
add_action( 'admin_notices', 'reflsub_submission_editor_notice' );
function reflsub_submission_editor_notice() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== 'post' ) {
        return;
    }

    $post = get_post();
    if ( ! $post ) {
        return;
    }

    $source_page_id = (int) get_post_meta( $post->ID, '_reflection_source_page', true );
    if ( ! $source_page_id || ! get_post( $source_page_id ) ) {
        return;
    }

    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html( 'This post was submitted through Activity Builder. Editing it in the activity form keeps its prompts and structure intact.' ),
        esc_url( add_query_arg( 'edit_submission', $post->ID, get_permalink( $source_page_id ) ) ),
        esc_html( 'Open in the activity form →' )
    );
}


// A student must always be able to read back their own work, whatever status it
// is sitting in — that is the whole point of the submission list. WordPress
// already permits this: map_meta_cap() collapses `read_post` to plain `read` for
// the post's own author, and preview covers the statuses with no public URL. So
// the only thing that ever varies is which URL to build and what to honestly
// call it, which is what this function centralises. It previously lived as a
// `post_status === 'publish' ? permalink : null` ternary copied into three
// screens, which silently left private and pending submissions with no route at
// all.
//
// Returns null when there is no sensible "go and look at it" link, so callers
// can keep using `if ( $link )` to decide whether to render a button.
//
// @return array{url:string,label:string,hint:string}|null
function reflsub_submission_view_link( $post ) {
    $post = get_post( $post );

    // Also guards get_permalink() below: for a private post it only returns the
    // real permalink when the *current* user may read it, and falls back to the
    // plain ?p= form otherwise.
    if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
        return null;
    }

    switch ( $post->post_status ) {

        case 'publish':
            return array(
                'url'   => get_permalink( $post->ID ),
                'label' => 'View',
                'hint'  => '',
            );

        case 'private':
            // Published, but audience-restricted. It has a normal permalink and
            // deliberately never appears on the student's public author archive.
            return array(
                'url'   => get_permalink( $post->ID ),
                'label' => 'View',
                'hint'  => 'Only you and your instructor can see this.',
            );

        case 'pending':
            // No public URL yet, so preview is the only honest route. Preview is
            // an edit-side capability, hence the second check.
            if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                return null;
            }
            return array(
                'url'   => get_preview_post_link( $post->ID ),
                'label' => 'Preview',
                'hint'  => 'Not published yet — visible to you and your instructor while it awaits approval.',
            );
    }

    // Drafts fall through deliberately. A draft is unsubmitted work, and the
    // established affordance for it across this plugin is "Continue", not
    // "View" — see the draft branch in the duplicate guard below. (The preview
    // URL does work for drafts if that ever becomes wanted.)
    return null;
}


// Which submission is the success screen reporting on? The ID rides back on the
// query string after the Post/Redirect/Get, so treat it as untrusted: it must be
// a real post, owned by whoever is looking, and belong to this activity page.
// Anything else returns null and the caller falls back to its generic links —
// which is also what happens to an old bookmarked `?reflection_submitted=1`.
function reflsub_resolve_submitted_post( $page_id ) {
    $raw = 0;
    if ( isset( $_GET['reflection_submitted'] ) ) {
        $raw = intval( $_GET['reflection_submitted'] );
    } elseif ( isset( $_GET['reflection_updated'] ) ) {
        $raw = intval( $_GET['reflection_updated'] );
    }

    if ( $raw < 1 ) {
        return null;
    }

    $post = get_post( $raw );
    if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
        return null;
    }

    if ( (int) get_post_meta( $post->ID, '_reflection_source_page', true ) !== (int) $page_id ) {
        return null;
    }

    return $post;
}


function reflsub_sanitize_embed_code( $raw ) {
    $allowed = array(
        'iframe' => array(
            'src'             => array(),
            'width'           => array(),
            'height'          => array(),
            'frameborder'     => array(),
            'allowfullscreen' => array(),
            'allow'           => array(),
            'title'           => array(),
            'style'           => array(),
            'class'           => array(),
            'id'              => array(),
            'name'            => array(),
            'referrerpolicy'  => array(), // present in YouTube embed codes since ~2023
            'loading'         => array(), // lazy-loading support
            'sandbox'         => array(), // some platforms (Kaltura etc.) use this
        ),
    );
    return wp_kses( trim( $raw ), $allowed );
}


// ─────────────────────────────────────────────────────────────────────────────
// Helper: upload multiple images from a multi-file input and return attachment IDs
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_upload_multiple_images( $field_name, $post_id ) {
    $ids   = array();
    $files = $_FILES[ $field_name ] ?? array();

    if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) {
        return $ids;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $count = count( $files['name'] );
    for ( $i = 0; $i < $count; $i++ ) {
        if ( empty( $files['name'][ $i ] ) || $files['error'][ $i ] !== UPLOAD_ERR_OK ) {
            continue;
        }
        // Temporarily restructure into a single-file $_FILES entry
        $_FILES['reflsub_img_single'] = array(
            'name'     => $files['name'][ $i ],
            'type'     => $files['type'][ $i ],
            'tmp_name' => $files['tmp_name'][ $i ],
            'error'    => $files['error'][ $i ],
            'size'     => $files['size'][ $i ],
        );
        $attachment_id = media_handle_upload( 'reflsub_img_single', $post_id );
        if ( ! is_wp_error( $attachment_id ) ) {
            $ids[] = $attachment_id;
        }
    }
    unset( $_FILES['reflsub_img_single'] );

    return $ids;
}


// ─────────────────────────────────────────────────────────────────────────────
// Helper: build a wp:image or wp:gallery block from attachment IDs
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_build_image_block( array $ids ) {
    if ( empty( $ids ) ) {
        return '';
    }

    $make_image = function( $id ) {
        $src = wp_get_attachment_image_url( $id, 'large' );
        $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
        if ( ! $src ) return '';
        return sprintf(
            "<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n" .
            "<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/></figure>\n" .
            "<!-- /wp:image -->",
            $id, esc_url( $src ), esc_attr( $alt ), $id
        );
    };

    if ( count( $ids ) === 1 ) {
        return $make_image( $ids[0] );
    }

    // Gallery (nested format, WP 6.0+)
    $inner = '';
    foreach ( $ids as $id ) {
        $block = $make_image( $id );
        if ( $block ) $inner .= $block . "\n";
    }

    return sprintf(
        "<!-- wp:gallery {\"linkTo\":\"none\"} -->\n" .
        "<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">\n%s</figure>\n" .
        "<!-- /wp:gallery -->",
        $inner
    );
}


// ─────────────────────────────────────────────────────────────────────────────
// Helper: render one server-side student block (used in edit mode to replay
// previously-saved blocks so the student can edit instead of starting over).
// The markup matches the JS-built block exactly so client handlers (Remove
// via delegation, drop-zone init, existing-image removal) all work uniformly.
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_render_student_block( $block_id, $state ) {
    $type   = $state['type'] ?? '';
    $labels = array(
        'text'  => 'Paragraph',
        'image' => 'Image(s)',
        'video' => 'Video URL',
        'embed' => 'Embed',
        'pdf'   => 'PDF / File',
    );
    $label = $labels[ $type ] ?? $type;

    ob_start();
    ?>
    <div class="reflsub-student-block" data-type="<?php echo esc_attr( $type ); ?>" data-id="<?php echo (int) $block_id; ?>">
        <div class="reflsub-student-block-header">
            <span class="reflsub-student-block-label"><?php echo esc_html( $label ); ?></span>
            <button type="button" class="reflsub-student-block-remove" aria-label="Remove this block">&times; Remove</button>
        </div>
        <?php if ( $type === 'text' ) : ?>
            <textarea rows="5" class="reflsub-student-text"
                      placeholder="Write your paragraph…  (Leave a blank line between paragraphs.)"><?php echo esc_textarea( $state['content'] ?? '' ); ?></textarea>
        <?php elseif ( $type === 'video' ) : ?>
            <input type="url" class="reflsub-student-video"
                   value="<?php echo esc_attr( $state['content'] ?? '' ); ?>"
                   placeholder="https://www.youtube.com/watch?v=…">
            <p class="reflection-hint">Paste a YouTube or Vimeo URL — it will embed in your post.</p>
        <?php elseif ( $type === 'embed' ) : ?>
            <textarea rows="4" class="reflsub-student-embed"
                      placeholder="Paste your &lt;iframe&gt; embed code here…"><?php echo esc_textarea( $state['content'] ?? '' ); ?></textarea>
            <p class="reflection-hint">Only <code>&lt;iframe&gt;</code> tags are accepted; other HTML will be stripped.</p>
        <?php elseif ( $type === 'image' ) :
            $ids = array_values( array_filter( array_map( 'intval', (array) ( $state['ids'] ?? array() ) ) ) );
        ?>
            <?php if ( ! empty( $ids ) ) : ?>
            <div class="reflsub-existing-images">
                <p class="reflsub-existing-label">Currently uploaded — click &times; to remove:</p>
                <div class="reflsub-existing-thumbs">
                    <?php foreach ( $ids as $att_id ) :
                        $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' );
                        $alt   = get_the_title( $att_id );
                        if ( ! $thumb ) continue;
                    ?>
                    <div class="reflsub-existing-wrap" data-img-id="<?php echo esc_attr( $att_id ); ?>">
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $alt ); ?>">
                        <button type="button" class="reflsub-existing-remove" aria-label="Remove <?php echo esc_attr( $alt ); ?>">&times;</button>
                        <input type="hidden"
                               name="reflsub_student_image_<?php echo (int) $block_id; ?>_keep[]"
                               value="<?php echo esc_attr( $att_id ); ?>"
                               id="reflsub-keep-<?php echo esc_attr( $att_id ); ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="reflsub-drop-zone">
                <div class="reflsub-drop-inner">
                    <span class="reflsub-drop-icon" aria-hidden="true">&#x1F5BC;&#xFE0F;</span>
                    <p class="reflsub-drop-label">Drag &amp; drop <?php echo ! empty( $ids ) ? 'more ' : ''; ?>images here</p>
                    <p class="reflsub-drop-sub">or <label class="reflsub-drop-browse" for="reflsub-student-image-<?php echo (int) $block_id; ?>">choose files</label></p>
                </div>
                <input type="file" id="reflsub-student-image-<?php echo (int) $block_id; ?>"
                       name="reflsub_student_image_<?php echo (int) $block_id; ?>[]"
                       accept="image/jpeg,image/png,image/gif,image/webp" multiple
                       class="reflsub-drop-input" aria-label="Upload images">
                <div class="reflsub-drop-previews"></div>
            </div>
            <p class="reflection-hint">JPEG, PNG, GIF, WebP — max 15 MB per file. Multiple images display as a gallery.</p>
        <?php elseif ( $type === 'pdf' ) :
            $att_id    = intval( $state['id'] ?? 0 );
            $pdf_url   = $att_id ? wp_get_attachment_url( $att_id ) : '';
            $pdf_title = ( $att_id && $pdf_url ) ? ( get_the_title( $att_id ) ?: basename( $pdf_url ) ) : '';
        ?>
            <?php if ( $att_id && $pdf_url ) : ?>
            <p class="reflection-hint" style="margin-bottom:.5em;">
                Currently attached:
                <a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $pdf_title ); ?></a>
                — leave the picker empty to keep it, or choose a new file to replace it.
            </p>
            <input type="hidden" name="reflsub_student_pdf_<?php echo (int) $block_id; ?>_keep" value="<?php echo esc_attr( $att_id ); ?>">
            <?php endif; ?>
            <input type="file" name="reflsub_student_pdf_<?php echo (int) $block_id; ?>" accept=".pdf,application/pdf">
            <p class="reflection-hint">PDF only. Max 15 MB.</p>
        <?php elseif ( $type === 'audio' ) :
            $att_id    = intval( $state['id'] ?? 0 );
            $audio_url = $att_id ? wp_get_attachment_url( $att_id ) : '';
        ?>
            <div class="reflsub-audio-recorder" data-max-seconds="300" data-required="0">
                <?php if ( $att_id && $audio_url ) : ?>
                <div class="reflsub-audio-existing">
                    <p class="reflection-hint" style="margin-bottom:.5em;">Current recording — record again to replace it.</p>
                    <audio controls preload="metadata" src="<?php echo esc_url( $audio_url ); ?>"></audio>
                    <input type="hidden" name="reflsub_student_audio_<?php echo (int) $block_id; ?>_keep" value="<?php echo esc_attr( $att_id ); ?>">
                </div>
                <?php endif; ?>
                <input type="file" class="reflsub-audio-input" name="reflsub_student_audio_<?php echo (int) $block_id; ?>" accept="audio/*" hidden>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ─────────────────────────────────────────────────────────────────────────────
// Helper: check if the current user has already submitted for a page
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_get_existing_submission( $user_id, $page_id ) {
    $posts = get_posts( array(
        'post_type'      => 'post',
        'author'         => $user_id,
        'post_status'    => array( 'publish', 'private', 'pending', 'draft' ),
        'posts_per_page' => 1,
        'meta_query'     => array(
            array(
                'key'   => '_reflection_source_page',
                'value' => $page_id,
            ),
        ),
    ) );

    return ! empty( $posts ) ? $posts[0] : null;
}


// ─────────────────────────────────────────────────────────────────────────────
// Submission handler — fires on init so headers are not yet sent
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'init', 'reflsub_handle_reflection_submission' );
function reflsub_handle_reflection_submission() {

    if ( ! isset( $_POST['eportfolio_reflection_submit'] ) ) {
        return;
    }

    // Security
    if ( ! isset( $_POST['reflection_nonce'] ) ||
         ! wp_verify_nonce( $_POST['reflection_nonce'], 'submit_reflection' ) ) {
        wp_die( 'Security check failed. Please go back and try again.' );
    }

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to submit a reflection.' );
    }

    // Multisite: being logged in to the network is not the same as belonging to
    // this site — reject users who aren't members of the current blog.
    if ( is_multisite() && ! is_user_member_of_blog() ) {
        wp_die( 'You must be a member of this site to submit a reflection.' );
    }

    // Submitting creates a post authored by this user, so it requires the
    // capability to author posts. Community visitors are auto-provisioned as
    // Subscribers, who must never be able to submit; students are onboarded as
    // Author. Matches the edit_posts gate already used on the admin menus.
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( 'Your account does not have permission to submit work on this site.' );
    }

    $page_id = intval( $_POST['reflection_page_id'] ?? 0 );
    if ( ! $page_id || ! get_post( $page_id ) ) {
        wp_die( 'Invalid page reference.' );
    }

    $redirect_base = get_permalink( $page_id );
    $user_id       = get_current_user_id();

    // ── Route to sections handler or legacy ACF handler ───────────────────────
    $raw_sections = get_post_meta( $page_id, '_reflsub_sections', true );
    $sections     = $raw_sections ? json_decode( $raw_sections, true ) : null;

    if ( ! empty( $sections ) && is_array( $sections ) ) {
        reflsub_handle_sections_submission( $page_id, $user_id, $sections, $redirect_base );
        return; // handler calls exit
    }

    // ── Legacy path (pre-builder pages, data stored as plain post meta) ──────────
    // ── Duplicate guard ───────────────────────────────────────────────────────
    $allow_resubmission = get_post_meta( $page_id, 'allow_resubmission', true );
    if ( ! $allow_resubmission ) {
        $existing = reflsub_get_existing_submission( $user_id, $page_id );
        if ( $existing ) {
            wp_redirect( add_query_arg( 'reflection_error', 'duplicate', $redirect_base ) );
            exit;
        }
    }

    // ── Sanitize text responses ───────────────────────────────────────────────
    $response_1 = sanitize_textarea_field( $_POST['reflection_response_1'] ?? '' );
    $response_2 = sanitize_textarea_field( $_POST['reflection_response_2'] ?? '' );
    $response_3 = sanitize_textarea_field( $_POST['reflection_response_3'] ?? '' );

    $video_url = '';
    if ( get_post_meta( $page_id, 'allow_video_url', true ) ) {
        $video_url = esc_url_raw( trim( $_POST['reflection_video_url'] ?? '' ) );
    }

    $embed_code = '';
    if ( get_post_meta( $page_id, 'allow_embed', true ) ) {
        $embed_code = reflsub_sanitize_embed_code( $_POST['reflection_embed'] ?? '' );
    }

    $has_image = get_post_meta( $page_id, 'allow_image_upload', true ) &&
                 ! empty( $_FILES['reflection_image']['name'] );
    $has_text  = $response_1 || $response_2 || $response_3;

    if ( ! $has_text && ! $has_image && ! $video_url && ! $embed_code ) {
        wp_redirect( add_query_arg( 'reflection_error', 'empty', $redirect_base ) );
        exit;
    }

    // ── Build post content ────────────────────────────────────────────────────
    $prompt_1 = get_post_meta( $page_id, 'reflection_prompt_1', true );
    $prompt_2 = get_post_meta( $page_id, 'reflection_prompt_2', true );
    $prompt_3 = get_post_meta( $page_id, 'reflection_prompt_3', true );

    $content_parts = array();

    if ( $prompt_1 && $response_1 ) {
        $content_parts[] = sprintf(
            "<!-- wp:paragraph {\"className\":\"reflsub-prompt-label\"} -->\n<p class=\"reflsub-prompt-label\">%s</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
            esc_html( $prompt_1 ), nl2br( esc_html( $response_1 ) )
        );
    } elseif ( $response_1 ) {
        $content_parts[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", nl2br( esc_html( $response_1 ) ) );
    }

    if ( $prompt_2 && $response_2 ) {
        $content_parts[] = sprintf(
            "<!-- wp:paragraph {\"className\":\"reflsub-prompt-label\"} -->\n<p class=\"reflsub-prompt-label\">%s</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
            esc_html( $prompt_2 ), nl2br( esc_html( $response_2 ) )
        );
    } elseif ( $response_2 ) {
        $content_parts[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", nl2br( esc_html( $response_2 ) ) );
    }

    if ( $prompt_3 && $response_3 ) {
        $content_parts[] = sprintf(
            "<!-- wp:paragraph {\"className\":\"reflsub-prompt-label\"} -->\n<p class=\"reflsub-prompt-label\">%s</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
            esc_html( $prompt_3 ), nl2br( esc_html( $response_3 ) )
        );
    } elseif ( $response_3 ) {
        $content_parts[] = sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", nl2br( esc_html( $response_3 ) ) );
    }

    if ( $video_url ) {
        $content_parts[] = sprintf(
            "<!-- wp:embed {\"url\":\"%s\",\"type\":\"video\",\"responsive\":true} -->\n<figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">\n%s\n</div></figure>\n<!-- /wp:embed -->",
            esc_url( $video_url ), esc_url( $video_url )
        );
    }

    if ( $embed_code ) {
        $content_parts[] = sprintf( "<!-- wp:html -->\n%s\n<!-- /wp:html -->", $embed_code );
    }

    $post_content = implode( "\n\n", $content_parts );

    // ── Post status and title ─────────────────────────────────────────────────
    $privacy     = get_post_meta( $page_id, 'submission_privacy', true ) ?: 'publish';
    $post_status = in_array( $privacy, array( 'publish', 'private', 'pending' ), true )
        ? $privacy
        : 'publish';

    $post_title = get_the_title( $page_id );

    // ── Create post ───────────────────────────────────────────────────────────
    $post_id = wp_insert_post( array(
        'post_title'   => $post_title,
        'post_content' => $post_content,
        'post_status'  => $post_status,
        'post_author'  => $user_id,
        'post_type'    => 'post',
    ), true );

    if ( is_wp_error( $post_id ) ) {
        wp_redirect( add_query_arg( 'reflection_error', 'save', $redirect_base ) );
        exit;
    }

    // ── Post meta ─────────────────────────────────────────────────────────────
    update_post_meta( $post_id, '_reflection_source_page', $page_id );
    update_post_meta( $post_id, '_reflection_response_1',  $response_1 );
    update_post_meta( $post_id, '_reflection_response_2',  $response_2 );
    update_post_meta( $post_id, '_reflection_response_3',  $response_3 );

    if ( $video_url ) {
        update_post_meta( $post_id, '_reflection_video_url', $video_url );
    }

    if ( $embed_code ) {
        update_post_meta( $post_id, '_reflection_embed', $embed_code );
    }

    // ── Image upload ──────────────────────────────────────────────────────────
    if ( $has_image ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'reflection_image', $post_id );
        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    // Post/Redirect/Get — back to the submission page. Carries the new post's ID
    // rather than a bare '1' so the success screen can link straight at the work
    // that was just saved; a private or pending submission will not show up on the
    // author archive, which used to be the only link offered there.
    wp_redirect( add_query_arg( 'reflection_submitted', $post_id, $redirect_base ) );
    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Sections-based submission handler
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_handle_sections_submission( $page_id, $user_id, $sections, $redirect_base ) {

    // Defence in depth: the caller already gates on edit_posts, but this function
    // is what actually inserts a post, so it re-checks rather than trusting that
    // every future call site remembers to.
    if ( ! user_can( $user_id, 'edit_posts' ) ) {
        wp_die( 'Your account does not have permission to submit work on this site.' );
    }

    // ── Save-as-draft vs submit ────────────────────────────────────────────────
    // Two submit buttons share the name reflsub_submit_action; anything other than
    // an explicit "draft" is treated as a real submission, so an older cached form
    // with no button name still submits normally.
    // NB: deliberately NOT "reflsub_action" — admin-page.php already uses that
    // name for the Submissions list approve/trash actions.
    $is_draft = ( sanitize_key( $_POST['reflsub_submit_action'] ?? 'submit' ) === 'draft' );

    // Status a real submission lands in, from the instructor's page setting.
    // Needed in both the insert and the update path (a draft being submitted for
    // the first time has to be promoted into it).
    $privacy       = get_post_meta( $page_id, 'submission_privacy', true ) ?: 'publish';
    $submit_status = in_array( $privacy, array( 'publish', 'private', 'pending' ), true )
        ? $privacy : 'publish';

    // ── Edit mode detection ────────────────────────────────────────────────────
    $edit_post_id = intval( $_POST['reflsub_edit_post_id'] ?? 0 );
    if ( $edit_post_id > 0 ) {
        $ep = get_post( $edit_post_id );
        if ( ! $ep
            || (int) $ep->post_author !== $user_id
            || (int) get_post_meta( $edit_post_id, '_reflection_source_page', true ) !== $page_id ) {
            $edit_post_id = 0; // invalid — fall through to new-submission path
        }
    }

    // Duplicate guard (bypassed when editing an existing submission)
    if ( ! $edit_post_id ) {
        $allow_resub = (int) get_post_meta( $page_id, 'allow_resubmission', true );
        if ( ! $allow_resub ) {
            $existing = reflsub_get_existing_submission( $user_id, $page_id );
            if ( $existing ) {
                wp_redirect( add_query_arg( 'reflection_error', 'duplicate', $redirect_base ) );
                exit;
            }
        }
    }

    // Keyed by section index so we can reassemble in correct order after uploads.
    // Upload slots start as null and are filled in after the post is created.
    $ordered_parts  = array();
    $has_content    = false;

    // Seed auto-tags from the page-level setting (instructor-configured, invisible to students)
    $auto_tags_raw = get_post_meta( $page_id, '_reflsub_auto_tags', true );
    $auto_tags     = $auto_tags_raw
        ? array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $auto_tags_raw ) ) ) ) )
        : array();
    $image_sec_idx      = -1;    // section index of the image upload, or -1 if none
    $pdf_sec_idx        = -1;    // section index of the PDF section, or -1 if none
    $pdf_upload_pending = false; // true only when a new PDF file was actually submitted
    $audio_sec_idx        = -1;    // section index of the audio recording, or -1 if none
    $audio_upload_pending = false; // true only when a new audio recording was submitted
    $prompt_meta    = array(); // i => raw response text (stored as meta after post creation)
    $mcq_meta       = array(); // i => sanitized selected options array
    $video_meta     = '';
    $embed_meta     = '';
    $entry_title    = '';        // from entry_title section, used as post title if present

    foreach ( $sections as $i => $sec ) {
        $type = $sec['type'] ?? '';

        if ( $type === 'entry_title' ) {
            $val = sanitize_text_field( wp_unslash( $_POST[ 'section_entry_title_' . $i ] ?? '' ) );
            if ( $val !== '' ) {
                $entry_title = $val;
                $has_content = true;
            }
            continue;
        }

        if ( $type === 'prompt' ) {
            $response = sanitize_textarea_field( wp_unslash( $_POST[ 'section_response_' . $i ] ?? '' ) );
            $prompt_meta[ $i ] = $response;
            if ( $response !== '' ) {
                $has_content = true;
                $label = $sec['label'] ?? '';
                $ordered_parts[ $i ] = $label
                    ? sprintf(
                        "<!-- wp:paragraph {\"className\":\"reflsub-prompt-label\"} -->\n<p class=\"reflsub-prompt-label\">%s</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
                        esc_html( $label ), nl2br( esc_html( $response ) )
                    )
                    : sprintf( "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->", nl2br( esc_html( $response ) ) );
            }
        }

        if ( $type === 'mcq' ) {
            $raw_selected  = isset( $_POST[ 'section_mcq_' . $i ] ) ? (array) $_POST[ 'section_mcq_' . $i ] : array();
            $valid_options = is_array( $sec['options'] ?? null ) ? $sec['options'] : array();
            $selected      = array_values( array_intersect(
                array_map( 'sanitize_text_field', $raw_selected ),
                $valid_options
            ) );
            $mcq_meta[ $i ] = $selected;
            if ( ! empty( $selected ) ) {
                $has_content = true;
                $question    = $sec['question'] ?? '';
                $items_html  = implode( '', array_map( function( $opt ) {
                    return '<li>' . esc_html( $opt ) . '</li>';
                }, $selected ) );
                $ordered_parts[ $i ] = $question
                    ? sprintf(
                        "<!-- wp:paragraph {\"className\":\"reflsub-prompt-label\"} -->\n<p class=\"reflsub-prompt-label\">%s</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\">%s</ul>\n<!-- /wp:list -->",
                        esc_html( $question ), $items_html
                    )
                    : sprintf( "<!-- wp:list -->\n<ul class=\"wp-block-list\">%s</ul>\n<!-- /wp:list -->", $items_html );
            }
        }

        if ( $type === 'image' ) {
            // Record the section position whenever an image block is configured —
            // even with no new upload — so the kept-image rebuild below runs in
            // edit mode. Without this, leaving the image alone while editing text
            // silently drops the original images from the post.
            $image_sec_idx  = $i;
            $img_names      = $_FILES['section_image']['name'] ?? array();
            $has_new_image  = is_array( $img_names ) && ! empty( $img_names[0] );
            $has_kept_image = $edit_post_id && ! empty( $_POST['reflsub_keep_image_ids'] );
            if ( $has_new_image || $has_kept_image ) {
                $has_content         = true;
                $ordered_parts[ $i ] = null; // placeholder — filled after post creation
            }
        }

        if ( $type === 'video' ) {
            $url = esc_url_raw( trim( wp_unslash( $_POST['section_video'] ?? '' ) ) );
            if ( $url ) {
                $has_content         = true;
                $video_meta          = $url;
                $ordered_parts[ $i ] = sprintf(
                    "<!-- wp:embed {\"url\":\"%s\",\"type\":\"video\",\"responsive\":true} -->\n<figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">\n%s\n</div></figure>\n<!-- /wp:embed -->",
                    esc_url( $url ), esc_url( $url )
                );
            }
        }

        if ( $type === 'embed' ) {
            $code = reflsub_sanitize_embed_code( wp_unslash( $_POST['section_embed'] ?? '' ) );
            if ( $code ) {
                $has_content         = true;
                $embed_meta          = $code;
                $ordered_parts[ $i ] = sprintf( "<!-- wp:html -->\n%s\n<!-- /wp:html -->", $code );
            }
        }

        if ( $type === 'tags' ) {
            // Student-submitted tags from the visible tag input field
            $student_tags_raw = sanitize_text_field( wp_unslash( $_POST[ 'section_tags_' . $i ] ?? '' ) );
            if ( $student_tags_raw !== '' ) {
                $parsed = array_values( array_filter(
                    array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $student_tags_raw ) ) )
                ) );
                $auto_tags = array_merge( $auto_tags, $parsed );
            }
        }

        if ( $type === 'pdf' ) {
            $pdf_sec_idx = $i; // always record position, regardless of whether a file was submitted
            $pdf_name    = $_FILES['section_pdf']['name'] ?? '';
            if ( $pdf_name !== '' && ( $_FILES['section_pdf']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
                if ( ( $_FILES['section_pdf']['size'] ?? 0 ) <= 15 * 1024 * 1024 ) {
                    $has_content        = true;
                    $pdf_upload_pending = true;
                    $ordered_parts[ $i ] = null; // placeholder — filled after post creation
                }
            }
        }

        if ( $type === 'audio' ) {
            $audio_sec_idx = $i; // always record position, regardless of whether a recording was submitted
            $audio_name    = $_FILES['section_audio']['name'] ?? '';
            if ( $audio_name !== '' && ( $_FILES['section_audio']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
                // Hard server-side ceiling: 30-min cap at ~1 MB/min Opus leaves generous headroom at 60 MB.
                if ( ( $_FILES['section_audio']['size'] ?? 0 ) <= 60 * 1024 * 1024 ) {
                    $has_content          = true;
                    $audio_upload_pending = true;
                    $ordered_parts[ $i ]  = null; // placeholder — filled after post creation
                }
            }
        }
    }

    // Edit mode: if no new PDF was submitted but the student has an existing one,
    // rebuild the block from the kept attachment so the post content preserves it.
    if ( $edit_post_id && $pdf_sec_idx >= 0 && ! $pdf_upload_pending && ! empty( $_POST['reflsub_keep_pdf_id'] ) ) {
        $keep_pdf_id = intval( $_POST['reflsub_keep_pdf_id'] );
        $keep_att    = get_post( $keep_pdf_id );
        if ( $keep_att && $keep_att->post_type === 'attachment' && (int) $keep_att->post_parent === $edit_post_id ) {
            $keep_pdf_url   = wp_get_attachment_url( $keep_pdf_id );
            $keep_pdf_title = get_the_title( $keep_pdf_id ) ?: basename( $keep_pdf_url );
            $ordered_parts[ $pdf_sec_idx ] = sprintf(
                '<!-- wp:file {"id":%d,"href":"%s"} --><div class="wp-block-file"><a href="%s">%s</a><a href="%s" class="wp-block-file__button" download>Download</a></div><!-- /wp:file -->',
                $keep_pdf_id,
                esc_url( $keep_pdf_url ),
                esc_url( $keep_pdf_url ),
                esc_html( $keep_pdf_title ),
                esc_url( $keep_pdf_url )
            );
            $has_content = true; // kept PDF counts as valid submission content
        }
    }

    // Edit mode: if no new recording was submitted but the student has an existing one,
    // rebuild the audio block from the kept attachment so the post content preserves it.
    if ( $edit_post_id && $audio_sec_idx >= 0 && ! $audio_upload_pending && ! empty( $_POST['reflsub_keep_audio_id'] ) ) {
        $keep_audio_id = intval( $_POST['reflsub_keep_audio_id'] );
        $keep_att      = get_post( $keep_audio_id );
        if ( $keep_att && $keep_att->post_type === 'attachment' && (int) $keep_att->post_parent === $edit_post_id ) {
            $keep_audio_url = wp_get_attachment_url( $keep_audio_id );
            $ordered_parts[ $audio_sec_idx ] = sprintf(
                '<!-- wp:audio {"id":%d} --><figure class="wp-block-audio"><audio controls src="%s"></audio></figure><!-- /wp:audio -->',
                $keep_audio_id,
                esc_url( $keep_audio_url )
            );
            $has_content = true; // kept recording counts as valid submission content
        }
    }

    // ── Student-added blocks: parse JSON now so we can also factor them into
    //    the has_content check; the actual block markup is appended further
    //    down, after the post (and any attachment parents) is created.
    $student_blocks_raw = wp_unslash( $_POST['reflsub_student_blocks_data'] ?? '' );
    $student_blocks     = $student_blocks_raw ? json_decode( $student_blocks_raw, true ) : array();
    if ( ! is_array( $student_blocks ) ) {
        $student_blocks = array();
    }
    if ( ! $has_content && ! empty( $student_blocks ) ) {
        foreach ( $student_blocks as $sb ) {
            $sb_type = $sb['type'] ?? '';
            $sb_id   = intval( $sb['id'] ?? 0 );
            if ( in_array( $sb_type, array( 'text', 'video', 'embed' ), true )
                 && trim( $sb['content'] ?? '' ) !== '' ) {
                $has_content = true;
                break;
            }
            if ( $sb_type === 'image' && $sb_id ) {
                $names = $_FILES[ 'reflsub_student_image_' . $sb_id ]['name'] ?? array();
                $kept  = $_POST[ 'reflsub_student_image_' . $sb_id . '_keep' ] ?? array();
                if ( ( is_array( $names ) && ! empty( $names[0] ) ) || ! empty( $kept ) ) {
                    $has_content = true;
                    break;
                }
            }
            if ( $sb_type === 'pdf' && $sb_id ) {
                $pdf_name = $_FILES[ 'reflsub_student_pdf_' . $sb_id ]['name'] ?? '';
                $kept     = intval( $_POST[ 'reflsub_student_pdf_' . $sb_id . '_keep' ] ?? 0 );
                if ( $pdf_name !== '' || $kept > 0 ) {
                    $has_content = true;
                    break;
                }
            }
            if ( $sb_type === 'audio' && $sb_id ) {
                $audio_name = $_FILES[ 'reflsub_student_audio_' . $sb_id ]['name'] ?? '';
                $kept       = intval( $_POST[ 'reflsub_student_audio_' . $sb_id . '_keep' ] ?? 0 );
                if ( $audio_name !== '' || $kept > 0 ) {
                    $has_content = true;
                    break;
                }
            }
        }
    }

    if ( ! $has_content ) {
        // A draft is allowed to be incomplete, but there is still nothing to store
        // if every field is empty — say that rather than "fill in a response".
        wp_redirect( add_query_arg( 'reflection_error', $is_draft ? 'empty_draft' : 'empty', $redirect_base ) );
        exit;
    }

    // Build text-only content (null upload placeholders excluded) preserving section order.
    ksort( $ordered_parts );
    $text_parts   = array_filter( $ordered_parts, function( $p ) { return $p !== null; } );
    $post_content = implode( "\n\n", $text_parts );

    // Determine post title: student-provided > date fallback (multi-submit) > page title
    if ( $entry_title ) {
        $post_title = $entry_title;
    } elseif ( (int) get_post_meta( $page_id, 'allow_resubmission', true ) ) {
        $post_title = get_the_title( $page_id ) . ' — ' . date_i18n( 'F j, Y' );
    } else {
        $post_title = get_the_title( $page_id );
    }

    // Create new post or update existing
    if ( $edit_post_id ) {
        $update = array(
            'ID'           => $edit_post_id,
            'post_title'   => $post_title,
            'post_content' => $post_content,
        );

        // Status transitions on edit. Anything already submitted keeps whatever
        // status it has — an instructor may have approved it (pending → publish),
        // and re-editing must not silently undo that.
        $current_status = get_post_status( $edit_post_id );
        if ( $is_draft ) {
            // Re-saving a draft keeps it a draft. Saving an already-submitted post
            // as a draft would retract it from the instructor, so that is refused.
            if ( $current_status === 'draft' ) {
                $update['post_status'] = 'draft';
            }
        } elseif ( $current_status === 'draft' ) {
            // Draft being handed in for the first time — promote it into the
            // status the instructor configured for this page.
            $update['post_status'] = $submit_status;
        }

        $result = wp_update_post( $update, true );
        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( 'reflection_error', 'save', $redirect_base ) );
            exit;
        }
        $post_id = $edit_post_id;
    } else {
        $post_id = wp_insert_post( array(
            'post_title'   => $post_title,
            'post_content' => $post_content,
            'post_status'  => $is_draft ? 'draft' : $submit_status,
            'post_author'  => $user_id,
            'post_type'    => 'post',
        ), true );

        if ( is_wp_error( $post_id ) ) {
            wp_redirect( add_query_arg( 'reflection_error', 'save', $redirect_base ) );
            exit;
        }
    }

    // Per-section meta
    update_post_meta( $post_id, '_reflection_source_page', $page_id );
    foreach ( $prompt_meta as $i => $response ) {
        update_post_meta( $post_id, '_reflsub_response_' . $i, $response );
    }
    foreach ( $mcq_meta as $i => $sel ) {
        // wp_slash(): update_post_meta() unslashes internally; protect JSON escapes from corruption.
        update_post_meta( $post_id, '_reflsub_mcq_' . $i, wp_slash( wp_json_encode( $sel ) ) );
    }
    if ( $video_meta ) update_post_meta( $post_id, '_reflection_video_url', $video_meta );
    if ( $embed_meta ) update_post_meta( $post_id, '_reflection_embed', $embed_meta );

    // Image upload — resolve placeholder at the correct section position.
    // In edit mode, kept_ids are the existing images the student chose to keep
    // (hidden inputs reflsub_keep_image_ids[]); new uploads are appended after.
    if ( $image_sec_idx >= 0 ) {
        $kept_ids = array();
        if ( $edit_post_id && ! empty( $_POST['reflsub_keep_image_ids'] ) ) {
            foreach ( (array) $_POST['reflsub_keep_image_ids'] as $kid ) {
                $kid = intval( $kid );
                $att = get_post( $kid );
                // Only allow attachments that belong to this post (prevents spoofing).
                if ( $att && $att->post_type === 'attachment' && (int) $att->post_parent === $post_id ) {
                    $kept_ids[] = $kid;
                }
            }
        }

        $uploaded_ids = reflsub_upload_multiple_images( 'section_image', $post_id );
        $final_ids    = array_merge( $kept_ids, $uploaded_ids );

        if ( ! empty( $final_ids ) ) {
            $image_block = reflsub_build_image_block( $final_ids );
            if ( $image_block ) {
                $ordered_parts[ $image_sec_idx ] = $image_block;
            }
        }
        // If $final_ids is empty the placeholder null is stripped by array_filter below — intentional.
    }

    // PDF upload — resolve placeholder at the correct section position (new upload only)
    if ( $pdf_upload_pending ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $pdf_id = media_handle_upload( 'section_pdf', $post_id );
        if ( ! is_wp_error( $pdf_id ) ) {
            $pdf_url   = wp_get_attachment_url( $pdf_id );
            $pdf_title = get_the_title( $pdf_id ) ?: basename( $pdf_url );
            $ordered_parts[ $pdf_sec_idx ] = sprintf(
                '<!-- wp:file {"id":%d,"href":"%s"} --><div class="wp-block-file"><a href="%s">%s</a><a href="%s" class="wp-block-file__button" download>Download</a></div><!-- /wp:file -->',
                $pdf_id,
                esc_url( $pdf_url ),
                esc_url( $pdf_url ),
                esc_html( $pdf_title ),
                esc_url( $pdf_url )
            );
        }
    }

    // Audio recording upload — resolve placeholder at the correct section position (new upload only)
    if ( $audio_upload_pending ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $audio_id = media_handle_upload( 'section_audio', $post_id );
        if ( ! is_wp_error( $audio_id ) ) {
            $audio_url = wp_get_attachment_url( $audio_id );
            $ordered_parts[ $audio_sec_idx ] = sprintf(
                '<!-- wp:audio {"id":%d} --><figure class="wp-block-audio"><audio controls src="%s"></audio></figure><!-- /wp:audio -->',
                $audio_id,
                esc_url( $audio_url )
            );
        }
    }

    // ── Student-added blocks → append after instructor sections ───────────
    // Sort keys start at 100000 so these always land after any instructor
    // section keys (which use small integers based on $sections array index).
    // $student_state mirrors the rendered blocks so edit mode can replay them.
    $student_state = array();
    foreach ( $student_blocks as $sb_idx => $sb ) {
        $sb_type = $sb['type'] ?? '';
        $sb_id   = intval( $sb['id'] ?? 0 );
        $sort_k  = 100000 + $sb_idx;

        if ( $sb_type === 'text' ) {
            $text = sanitize_textarea_field( wp_unslash( $sb['content'] ?? '' ) );
            if ( $text === '' ) continue;
            $paragraphs = preg_split( '/\n{2,}/', $text );
            $para_html  = array();
            foreach ( $paragraphs as $p ) {
                $p = trim( $p );
                if ( $p === '' ) continue;
                $para_html[] = sprintf(
                    "<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
                    nl2br( esc_html( $p ) )
                );
            }
            if ( $para_html ) {
                $ordered_parts[ $sort_k ] = implode( "\n\n", $para_html );
                $student_state[] = array( 'type' => 'text', 'content' => $text );
            }
        } elseif ( $sb_type === 'video' ) {
            $url = esc_url_raw( trim( wp_unslash( $sb['content'] ?? '' ) ) );
            if ( ! $url ) continue;
            $ordered_parts[ $sort_k ] = sprintf(
                "<!-- wp:embed {\"url\":\"%s\",\"type\":\"video\",\"responsive\":true} -->\n<figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">\n%s\n</div></figure>\n<!-- /wp:embed -->",
                esc_url( $url ), esc_url( $url )
            );
            $student_state[] = array( 'type' => 'video', 'content' => $url );
        } elseif ( $sb_type === 'embed' ) {
            $raw  = wp_unslash( $sb['content'] ?? '' );
            $code = reflsub_sanitize_embed_code( $raw );
            if ( ! $code ) continue;
            $ordered_parts[ $sort_k ] = sprintf( "<!-- wp:html -->\n%s\n<!-- /wp:html -->", $code );
            // Store the *sanitized* code so edit-mode round-trip can't re-introduce
            // tags the sanitizer would have stripped.
            $student_state[] = array( 'type' => 'embed', 'content' => $code );
        } elseif ( $sb_type === 'image' && $sb_id ) {
            $field = 'reflsub_student_image_' . $sb_id;

            // Existing attachments the student chose to keep (edit mode only).
            $kept_ids = array();
            $keep_raw = $_POST[ $field . '_keep' ] ?? array();
            if ( is_array( $keep_raw ) ) {
                foreach ( $keep_raw as $kid ) {
                    $kid = intval( $kid );
                    $att = get_post( $kid );
                    if ( $att && $att->post_type === 'attachment'
                         && (int) $att->post_parent === $post_id ) {
                        $kept_ids[] = $kid;
                    }
                }
            }

            // New uploads in this block, if any.
            $new_ids = array();
            $names   = $_FILES[ $field ]['name'] ?? array();
            if ( is_array( $names ) && ! empty( $names[0] ) ) {
                $new_ids = reflsub_upload_multiple_images( $field, $post_id );
            }

            $all_ids = array_merge( $kept_ids, $new_ids );
            if ( $all_ids ) {
                $img_block = reflsub_build_image_block( $all_ids );
                if ( $img_block ) {
                    $ordered_parts[ $sort_k ] = $img_block;
                }
                $student_state[] = array( 'type' => 'image', 'ids' => $all_ids );
            }
        } elseif ( $sb_type === 'pdf' && $sb_id ) {
            $field   = 'reflsub_student_pdf_' . $sb_id;
            $pdf_aid = 0;

            // Try a new upload first; fall back to the kept PDF if none was given.
            if ( ! empty( $_FILES[ $field ]['name'] )
                 && ( $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK
                 && ( $_FILES[ $field ]['size'] ?? 0 ) <= 15 * 1024 * 1024 ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                $uploaded = media_handle_upload( $field, $post_id );
                if ( ! is_wp_error( $uploaded ) ) {
                    $pdf_aid = $uploaded;
                }
            } else {
                $kept = intval( $_POST[ $field . '_keep' ] ?? 0 );
                if ( $kept ) {
                    $att = get_post( $kept );
                    if ( $att && $att->post_type === 'attachment'
                         && (int) $att->post_parent === $post_id ) {
                        $pdf_aid = $kept;
                    }
                }
            }

            if ( $pdf_aid ) {
                $pdf_url   = wp_get_attachment_url( $pdf_aid );
                $pdf_title = get_the_title( $pdf_aid ) ?: basename( $pdf_url );
                $ordered_parts[ $sort_k ] = sprintf(
                    '<!-- wp:file {"id":%d,"href":"%s"} --><div class="wp-block-file"><a href="%s">%s</a><a href="%s" class="wp-block-file__button" download>Download</a></div><!-- /wp:file -->',
                    $pdf_aid,
                    esc_url( $pdf_url ),
                    esc_url( $pdf_url ),
                    esc_html( $pdf_title ),
                    esc_url( $pdf_url )
                );
                $student_state[] = array( 'type' => 'pdf', 'id' => $pdf_aid );
            }
        } elseif ( $sb_type === 'audio' && $sb_id ) {
            $field     = 'reflsub_student_audio_' . $sb_id;
            $audio_aid = 0;

            // Try a new recording first; fall back to the kept recording if none was given.
            if ( ! empty( $_FILES[ $field ]['name'] )
                 && ( $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK
                 && ( $_FILES[ $field ]['size'] ?? 0 ) <= 60 * 1024 * 1024 ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                $uploaded = media_handle_upload( $field, $post_id );
                if ( ! is_wp_error( $uploaded ) ) {
                    $audio_aid = $uploaded;
                }
            } else {
                $kept = intval( $_POST[ $field . '_keep' ] ?? 0 );
                if ( $kept ) {
                    $att = get_post( $kept );
                    if ( $att && $att->post_type === 'attachment'
                         && (int) $att->post_parent === $post_id ) {
                        $audio_aid = $kept;
                    }
                }
            }

            if ( $audio_aid ) {
                $audio_url = wp_get_attachment_url( $audio_aid );
                $ordered_parts[ $sort_k ] = sprintf(
                    '<!-- wp:audio {"id":%d} --><figure class="wp-block-audio"><audio controls src="%s"></audio></figure><!-- /wp:audio -->',
                    $audio_aid,
                    esc_url( $audio_url )
                );
                $student_state[] = array( 'type' => 'audio', 'id' => $audio_aid );
            }
        }
    }

    // Persist student-block state so edit mode can replay the blocks.
    if ( $student_state ) {
        // wp_slash(): update_post_meta() unslashes internally; protect JSON escapes from corruption.
        update_post_meta( $post_id, '_reflsub_student_blocks', wp_slash( wp_json_encode( $student_state ) ) );
    } else {
        delete_post_meta( $post_id, '_reflsub_student_blocks' );
    }

    // Reassemble all parts in section order now that uploads are resolved
    ksort( $ordered_parts );
    $final_parts   = array_filter( $ordered_parts ); // drop any remaining nulls (failed uploads)
    $final_content = implode( "\n\n", $final_parts );
    if ( $final_content !== $post_content ) {
        wp_update_post( array( 'ID' => $post_id, 'post_content' => $final_content ) );
    }

    // Tags — always replace (not append) so edits can remove stale student tags.
    // $auto_tags already contains page-level auto-tags + any student-submitted tags.
    wp_set_post_tags( $post_id, $auto_tags );

    // Content-type taxonomy — tag the submission with the type configured on the activity page.
    $ct_slug = get_post_meta( $page_id, '_reflsub_content_type_slug', true );
    if ( $ct_slug && taxonomy_exists( 'content-type' ) ) {
        $ct_term = get_term_by( 'slug', $ct_slug, 'content-type' );
        if ( $ct_term && ! is_wp_error( $ct_term ) ) {
            wp_set_object_terms( $post_id, $ct_term->term_id, 'content-type' );
        }
    }

    // Route on the status the post actually ended up in, not on the button alone,
    // so a refused draft-save (see the status transitions above) still reports the
    // truth to the student.
    if ( $is_draft && get_post_status( $post_id ) === 'draft' ) {
        // Land back in edit mode with the work still on screen: "save and keep
        // going" is the common case, and a bare confirmation page would break it.
        wp_redirect( add_query_arg( array(
            'edit_submission'        => $post_id,
            'reflection_draft_saved' => '1',
        ), $redirect_base ) );
        exit;
    }

    // The ID (not a bare '1') so the success screen can link at the actual post —
    // see the note on the legacy handler's redirect above.
    $redirect_arg = $edit_post_id ? 'reflection_updated' : 'reflection_submitted';
    wp_redirect( add_query_arg( $redirect_arg, $post_id, $redirect_base ) );
    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Shortcode: [reflection_form]
// ─────────────────────────────────────────────────────────────────────────────

add_shortcode( 'reflection_form', 'reflsub_reflection_form_shortcode' );
function reflsub_reflection_form_shortcode( $atts ) {

    if ( ! is_page() ) {
        return '';
    }

    $page_id = get_the_ID();

    // Guard: page must be flagged as a reflection page
    $is_reflection = get_post_meta( $page_id, 'is_reflection_page', true );
    if ( ! $is_reflection ) {
        return '';
    }

    // ── Route: new sections format vs legacy ACF ───────────────────────────────
    $raw_sections     = get_post_meta( $page_id, '_reflsub_sections', true );
    $has_builder_meta = ( $raw_sections !== false && $raw_sections !== '' );
    $sections         = $has_builder_meta ? json_decode( $raw_sections, true ) : null;

    if ( $has_builder_meta ) {
        $allow_resub = (int) get_post_meta( $page_id, 'allow_resubmission', true );

        if ( ! empty( $sections ) && is_array( $sections ) ) {
            return reflsub_render_sections_form( $sections, $page_id, $allow_resub );
        }

        // Builder page with no sections saved — don't silently fall through to ACF.
        // Show a clear message to admins; show nothing to students (form not ready).
        if ( current_user_can( 'manage_options' ) ) {
            $edit_url = admin_url( 'admin.php?page=reflsub-build&edit=' . $page_id );
            return '<p style="padding:1rem 1.25rem; background:#fff8e5; border-left:4px solid #dba617; border-radius:0 4px 4px 0; font-size:14px; line-height:1.5;">'
                . '<strong>No form sections configured.</strong> '
                . '<a href="' . esc_url( $edit_url ) . '">Add sections in the builder →</a>'
                . '</p>';
        }
        return '';
    }

    // ── Legacy path (pre-builder pages, data stored as plain post meta) ──────────
    $prompt_1    = get_post_meta( $page_id, 'reflection_prompt_1', true );
    $prompt_2    = get_post_meta( $page_id, 'reflection_prompt_2', true );
    $prompt_3    = get_post_meta( $page_id, 'reflection_prompt_3', true );
    $allow_image = get_post_meta( $page_id, 'allow_image_upload',  true );
    $allow_video = get_post_meta( $page_id, 'allow_video_url',     true );
    $allow_embed = get_post_meta( $page_id, 'allow_embed',         true );
    $allow_resub = get_post_meta( $page_id, 'allow_resubmission',  true );

    // ── Success state ──────────────────────────────────────────────────────────
    if ( isset( $_GET['reflection_submitted'] ) ) {
        $submitted = reflsub_resolve_submitted_post( $page_id );
        $view_link = $submitted ? reflsub_submission_view_link( $submitted ) : null;
        $mine_url  = admin_url( 'admin.php?page=reflsub-my-submissions' );
        // See the sections-path success branch: the JS owns localStorage cleanup,
        // so it must load on the success page as well.
        reflsub_enqueue_form_assets( $page_id );
        ob_start();
        ?>
        <div class="reflection-notice reflection-success">
            <p><strong>Your reflection has been submitted.</strong></p>
            <p>
                <?php if ( $view_link ) : ?>
                <a href="<?php echo esc_url( $view_link['url'] ); ?>">View your submission →</a>
                &nbsp;·&nbsp;
                <?php endif; ?>
                <a href="<?php echo esc_url( $mine_url ); ?>">All my submissions →</a>
                <?php if ( $allow_resub ) : ?>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">Start another entry</a>
                <?php endif; ?>
            </p>
            <?php if ( $view_link && $view_link['hint'] ) : ?>
            <p class="reflection-notice-hint"><?php echo esc_html( $view_link['hint'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Not logged in ──────────────────────────────────────────────────────────
    if ( ! is_user_logged_in() ) {
        return sprintf(
            '<div class="reflection-notice reflection-info"><p>Please <a href="%s">log in</a> to submit your reflection.</p></div>',
            esc_url( wp_login_url( get_permalink( $page_id ) ) )
        );
    }

    // ── Insufficient role ──────────────────────────────────────────────────────
    // Same gate as the sections path: Subscribers cannot author posts.
    if ( ! current_user_can( 'edit_posts' ) ) {
        return '<div class="reflection-notice reflection-info">'
            . '<p><strong>You don\'t have a student account on this site.</strong></p>'
            . '<p>Your account can read this page but not submit work. If you should be able to '
            . 'submit, ask your instructor to add you as an Author.</p>'
            . '</div>';
    }

    $user_id = get_current_user_id();

    // ── Duplicate guard — show existing submission instead of form ─────────────
    if ( ! $allow_resub ) {
        $existing = reflsub_get_existing_submission( $user_id, $page_id );

        if ( $existing ) {
            $status_labels = array(
                'publish' => 'Published',
                'private' => 'Private',
                'pending' => 'Pending Review',
                'draft'   => 'Draft',
            );
            $status   = $status_labels[ $existing->post_status ] ?? ucfirst( $existing->post_status );
            $edit_url  = get_edit_post_link( $existing->ID );
            $view_link = reflsub_submission_view_link( $existing );

            ob_start();
            ?>
            <div class="reflection-notice reflection-duplicate">
                <p><strong>You have already submitted a reflection for this week.</strong></p>
                <p>
                    Status: <strong><?php echo esc_html( $status ); ?></strong>
                    &nbsp;·&nbsp;
                    <?php if ( $edit_url ) : ?>
                        <a href="<?php echo esc_url( $edit_url ); ?>">Edit your submission</a>
                    <?php endif; ?>
                    <?php if ( $view_link ) : ?>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url( $view_link['url'] ); ?>"><?php
                            echo esc_html( $view_link['label'] === 'Preview' ? 'Preview it' : 'View it' );
                        ?></a>
                    <?php endif; ?>
                </p>
                <?php if ( $view_link && $view_link['hint'] ) : ?>
                <p class="reflection-notice-hint"><?php echo esc_html( $view_link['hint'] ); ?></p>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }
    }

    // ── Error messages (redirected back after failed validation) ───────────────
    $error_msg = '';
    if ( isset( $_GET['reflection_error'] ) ) {
        switch ( $_GET['reflection_error'] ) {
            case 'empty':
                $error_msg = 'Please fill in at least one response before submitting.';
                break;
            case 'duplicate':
                $error_msg = 'You have already submitted a reflection for this page.';
                break;
            default:
                $error_msg = 'Something went wrong. Please try again.';
        }
    }

    // ── Render form ────────────────────────────────────────────────────────────
    $form_enctype = $allow_image ? ' enctype="multipart/form-data"' : '';

    ob_start();
    ?>
    <div class="reflection-form-wrap">

        <?php if ( $error_msg ) : ?>
        <div class="reflection-notice reflection-error">
            <p><?php echo esc_html( $error_msg ); ?></p>
        </div>
        <?php endif; ?>

        <form class="reflection-form" method="post"<?php echo $form_enctype; ?> data-page-id="<?php echo esc_attr( $page_id ); ?>" data-user-id="<?php echo esc_attr( $user_id ); ?>">

            <?php wp_nonce_field( 'submit_reflection', 'reflection_nonce' ); ?>
            <input type="hidden" name="reflection_page_id" value="<?php echo esc_attr( $page_id ); ?>">
            <input type="hidden" name="eportfolio_reflection_submit" value="1">

            <?php if ( $prompt_1 ) : ?>
            <div class="reflection-field">
                <label for="reflection_response_1"><?php echo wp_kses_post( $prompt_1 ); ?></label>
                <textarea id="reflection_response_1" name="reflection_response_1"
                          rows="6" placeholder="Write your response here…"></textarea>
            </div>
            <?php endif; ?>

            <?php if ( $prompt_2 ) : ?>
            <div class="reflection-field">
                <label for="reflection_response_2"><?php echo wp_kses_post( $prompt_2 ); ?></label>
                <textarea id="reflection_response_2" name="reflection_response_2"
                          rows="6" placeholder="Write your response here…"></textarea>
            </div>
            <?php endif; ?>

            <?php if ( $prompt_3 ) : ?>
            <div class="reflection-field">
                <label for="reflection_response_3"><?php echo wp_kses_post( $prompt_3 ); ?></label>
                <textarea id="reflection_response_3" name="reflection_response_3"
                          rows="6" placeholder="Write your response here…"></textarea>
            </div>
            <?php endif; ?>

            <?php if ( $allow_image ) : ?>
            <div class="reflection-field">
                <label for="reflection_image">Upload an Image</label>
                <input type="file" id="reflection_image" name="reflection_image"
                       accept="image/jpeg,image/png,image/gif,image/webp">
                <p class="reflection-hint">JPEG, PNG, GIF or WebP. Becomes the featured image of your post.</p>
            </div>
            <?php endif; ?>

            <?php if ( $allow_video ) : ?>
            <div class="reflection-field">
                <label for="reflection_video_url">Video URL</label>
                <input type="url" id="reflection_video_url" name="reflection_video_url"
                       placeholder="https://www.youtube.com/watch?v=…">
                <p class="reflection-hint">Paste a YouTube or Vimeo link. It will be embedded in your post.</p>
            </div>
            <?php endif; ?>

            <?php if ( $allow_embed ) : ?>
            <div class="reflection-field">
                <label for="reflection_embed">Embed Code</label>
                <textarea id="reflection_embed" name="reflection_embed"
                          rows="5"
                          placeholder="Paste your embed code here — Kaltura, YouTube, Vimeo, or any &lt;iframe&gt;…"></textarea>
                <p class="reflection-hint">Paste the full <code>&lt;iframe&gt;</code> embed code from Kaltura, MediaSpace, YouTube, Vimeo, or similar. Only <code>&lt;iframe&gt;</code> tags are accepted; other HTML will be stripped.</p>
            </div>
            <?php endif; ?>

            <div class="reflection-submit">
                <button type="submit" class="wp-element-button">Submit</button>
            </div>

        </form>
    </div>


    <?php reflsub_enqueue_form_assets( $page_id ); ?>
    <?php

    return ob_get_clean();
}


// ─────────────────────────────────────────────────────────────────────────────
// Render sections-based form
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_render_sections_form( $sections, $page_id, $allow_resub ) {

    // ── Success / update state ─────────────────────────────────────────────────
    if ( isset( $_GET['reflection_submitted'] ) || isset( $_GET['reflection_updated'] ) ) {
        $updated     = isset( $_GET['reflection_updated'] );
        $submitted   = reflsub_resolve_submitted_post( $page_id );
        $view_link   = $submitted ? reflsub_submission_view_link( $submitted ) : null;
        $mine_url    = admin_url( 'admin.php?page=reflsub-my-submissions' );
        // The JS carries the localStorage autosave cleanup, and this branch is the
        // only place that knows the submission landed — so the assets have to load
        // here too. Without this the draft key survives a successful submit and is
        // restored into the next submission on resubmission-enabled pages.
        reflsub_enqueue_form_assets( $page_id );
        ob_start();
        ?>
        <div class="reflection-notice reflection-success">
            <p><strong><?php echo $updated ? 'Your submission has been updated.' : 'Your reflection has been submitted.'; ?></strong></p>
            <p>
                <?php if ( $view_link ) : ?>
                <a href="<?php echo esc_url( $view_link['url'] ); ?>">View your submission →</a>
                &nbsp;·&nbsp;
                <?php endif; ?>
                <a href="<?php echo esc_url( $mine_url ); ?>">All my submissions →</a>
                <?php if ( $allow_resub && ! $updated ) : ?>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">Start another entry</a>
                <?php endif; ?>
            </p>
            <?php if ( $view_link && $view_link['hint'] ) : ?>
            <p class="reflection-notice-hint"><?php echo esc_html( $view_link['hint'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Not logged in ──────────────────────────────────────────────────────────
    if ( ! is_user_logged_in() ) {
        return sprintf(
            '<div class="reflection-notice reflection-info"><p>Please <a href="%s">log in</a> to submit your reflection.</p></div>',
            esc_url( wp_login_url( get_permalink( $page_id ) ) )
        );
    }

    // ── Insufficient role ──────────────────────────────────────────────────────
    // Logged in is not enough: community visitors are auto-provisioned as
    // Subscribers, who cannot author posts. Show a plain explanation rather than
    // a form that would fail on submit.
    if ( ! current_user_can( 'edit_posts' ) ) {
        return '<div class="reflection-notice reflection-info">'
            . '<p><strong>You don\'t have a student account on this site.</strong></p>'
            . '<p>Your account can read this page but not submit work. If you should be able to '
            . 'submit, ask your instructor to add you as an Author.</p>'
            . '</div>';
    }

    $user_id = get_current_user_id();

    // ── Edit mode detection ────────────────────────────────────────────────────
    $edit_post_id = 0;
    if ( isset( $_GET['edit_submission'] ) ) {
        $candidate = intval( $_GET['edit_submission'] );
        if ( $candidate > 0 ) {
            $ep = get_post( $candidate );
            if ( $ep
                && (int) $ep->post_author === $user_id
                && (int) get_post_meta( $candidate, '_reflection_source_page', true ) === $page_id ) {
                $edit_post_id = $candidate;
            }
        }
    }

    // An unsubmitted draft is still "in progress", so the form presents as a first
    // submission (Submit, not Update Submission) rather than as editing something
    // the instructor has already seen.
    $editing_draft = $edit_post_id && get_post_status( $edit_post_id ) === 'draft';

    // ── Duplicate guard (bypassed when editing an existing submission) ──────────
    if ( ! $allow_resub && ! $edit_post_id ) {
        $existing = reflsub_get_existing_submission( $user_id, $page_id );
        if ( $existing ) {
            $status_labels = array(
                'publish' => 'Published',
                'private' => 'Private',
                'pending' => 'Pending Review',
                'draft'   => 'Draft',
            );
            $status    = $status_labels[ $existing->post_status ] ?? ucfirst( $existing->post_status );
            $edit_link = add_query_arg( 'edit_submission', $existing->ID, get_permalink( $page_id ) );
            $view_link = reflsub_submission_view_link( $existing );

            // A saved draft is not a submission. reflsub_get_existing_submission()
            // counts drafts (deliberately — one work-in-progress per page), so
            // without this branch a student who saved a draft would be told they
            // had already submitted.
            $is_draft = ( $existing->post_status === 'draft' );

            ob_start();
            ?>
            <div class="reflection-notice <?php echo $is_draft ? 'reflection-info' : 'reflection-duplicate'; ?>">
                <?php if ( $is_draft ) : ?>
                <p><strong>You have a saved draft for this page.</strong></p>
                <p>
                    It has not been submitted yet.
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( $edit_link ); ?>">Continue your draft</a>
                </p>
                <?php else : ?>
                <p><strong>You have already submitted a reflection for this page.</strong></p>
                <p>
                    Status: <strong><?php echo esc_html( $status ); ?></strong>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( $edit_link ); ?>">Edit your submission</a>
                    <?php if ( $view_link ) : ?>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url( $view_link['url'] ); ?>"><?php
                            echo esc_html( $view_link['label'] === 'Preview' ? 'Preview it' : 'View it' );
                        ?></a>
                    <?php endif; ?>
                </p>
                <?php if ( $view_link && $view_link['hint'] ) : ?>
                <p class="reflection-notice-hint"><?php echo esc_html( $view_link['hint'] ); ?></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }
    }

    // ── Error message ──────────────────────────────────────────────────────────
    $error_msg = '';
    if ( isset( $_GET['reflection_error'] ) ) {
        switch ( $_GET['reflection_error'] ) {
            case 'empty':
                $error_msg = 'Please fill in at least one response before submitting.';
                break;
            case 'empty_draft':
                $error_msg = 'There is nothing to save yet — write something first, then save your draft.';
                break;
            case 'duplicate':
                $error_msg = 'You have already submitted a reflection for this page.';
                break;
            default:
                $error_msg = 'Something went wrong. Please try again.';
        }
    }

    // The student-added blocks palette can include image / PDF uploads, so the
    // form is always multipart regardless of which instructor sections are present.
    $form_enctype = ' enctype="multipart/form-data"';

    // Per-page toggle: when off, the palette is hidden and the renderer falls
    // back to baking in instructor image/video/embed/pdf sections. Missing meta
    // defaults to allow (so existing pages aren't surprised by a hard lockdown).
    $asb_raw              = get_post_meta( $page_id, '_reflsub_allow_student_blocks', true );
    $allow_student_blocks = ( $asb_raw === '0' ) ? false : true;

    // ── Pre-fill values for edit mode ──────────────────────────────────────────
    $prefill = array();
    if ( $edit_post_id ) {
        foreach ( $sections as $i => $sec ) {
            $t = $sec['type'] ?? '';
            if ( $t === 'entry_title' ) {
                $prefill[ $i ] = get_the_title( $edit_post_id );
            } elseif ( $t === 'prompt' ) {
                $prefill[ $i ] = get_post_meta( $edit_post_id, '_reflsub_response_' . $i, true );
            } elseif ( $t === 'mcq' ) {
                $raw           = get_post_meta( $edit_post_id, '_reflsub_mcq_' . $i, true );
                $prefill[ $i ] = $raw ? (array) json_decode( $raw, true ) : array();
            } elseif ( $t === 'video' ) {
                $prefill[ $i ] = get_post_meta( $edit_post_id, '_reflection_video_url', true );
            } elseif ( $t === 'embed' ) {
                $prefill[ $i ] = get_post_meta( $edit_post_id, '_reflection_embed', true );
            } elseif ( $t === 'tags' ) {
                // Show the student their own previously-submitted tags (page auto-tags excluded).
                $all_tags      = wp_get_post_tags( $edit_post_id, array( 'fields' => 'names' ) );
                $page_auto_raw = get_post_meta( $page_id, '_reflsub_auto_tags', true );
                $page_auto_lc  = $page_auto_raw
                    ? array_map( 'strtolower', array_map( 'trim', explode( ',', $page_auto_raw ) ) )
                    : array();
                $student_tags  = array_values( array_filter( $all_tags, function( $name ) use ( $page_auto_lc ) {
                    return ! in_array( strtolower( $name ), $page_auto_lc, true );
                } ) );
                $prefill[ $i ] = implode( ', ', $student_tags );
            }
        }
    }

    // ── Unfinished draft on a resubmission-enabled page ────────────────────────
    // The duplicate guard above is skipped entirely when resubmission is allowed,
    // so a saved draft would never be surfaced here: the student would land on a
    // blank form and their unfinished work would only be findable from My
    // Submissions. Offer it as a banner rather than a wall, since starting a new
    // entry is legitimate on these pages.
    $pending_draft = null;
    if ( ! $edit_post_id ) {
        $drafts = get_posts( array(
            'post_type'      => 'post',
            'author'         => $user_id,
            'post_status'    => 'draft',
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => array(
                array( 'key' => '_reflection_source_page', 'value' => $page_id ),
            ),
        ) );
        $pending_draft = $drafts ? $drafts[0] : null;
    }

    // Intro text stored in post_excerpt by the builder
    $page_post = get_post( $page_id );
    $intro     = $page_post ? trim( $page_post->post_excerpt ) : '';

    ob_start();
    ?>
    <div class="reflection-form-wrap">

        <?php if ( $intro ) : ?>
        <div class="reflection-intro">
            <?php echo wpautop( esc_html( $intro ) ); ?>
        </div>
        <?php endif; ?>

        <?php if ( $error_msg ) : ?>
        <div class="reflection-notice reflection-error">
            <p><?php echo esc_html( $error_msg ); ?></p>
        </div>
        <?php endif; ?>

        <?php if ( $pending_draft ) : ?>
        <div class="reflection-notice reflection-info">
            <p>
                <strong>You have an unfinished draft for this page.</strong>
                <a href="<?php echo esc_url( add_query_arg( 'edit_submission', $pending_draft->ID, get_permalink( $page_id ) ) ); ?>">Continue your draft</a>
                — or start a new entry below.
            </p>
        </div>
        <?php endif; ?>

        <form class="reflection-form" method="post"<?php echo $form_enctype; ?> data-page-id="<?php echo esc_attr( $page_id ); ?>" data-user-id="<?php echo esc_attr( $user_id ); ?>">

            <?php wp_nonce_field( 'submit_reflection', 'reflection_nonce' ); ?>
            <input type="hidden" name="reflection_page_id" value="<?php echo esc_attr( $page_id ); ?>">
            <input type="hidden" name="eportfolio_reflection_submit" value="1">
            <?php if ( $edit_post_id ) : ?>
            <input type="hidden" name="reflsub_edit_post_id" value="<?php echo esc_attr( $edit_post_id ); ?>">
            <?php endif; ?>

            <?php if ( isset( $_GET['reflection_draft_saved'] ) ) : ?>
            <div class="reflection-notice reflection-success" style="margin-bottom:1.5rem;">
                <p><strong>Draft saved.</strong> You can close this page and come back to finish later —
                   your work is stored on the site, not just in this browser.
                   Nothing is sent to your instructor until you click <strong>Submit</strong>.</p>
            </div>
            <?php elseif ( $editing_draft ) : ?>
            <div class="reflection-notice reflection-info" style="margin-bottom:1.5rem;">
                <p>You are continuing a saved draft. It has not been submitted yet — click
                   <strong>Submit</strong> when you are ready to hand it in.</p>
            </div>
            <?php elseif ( $edit_post_id ) : ?>
            <div class="reflection-notice reflection-info" style="margin-bottom:1.5rem;">
                <p>You are editing your submission. Make your changes and click <strong>Update Submission</strong>.</p>
            </div>
            <?php endif; ?>

            <?php foreach ( $sections as $i => $sec ) :
                $type = $sec['type'] ?? '';
                // Student Tools are additive: the instructor's structured sections —
                // including baked-in image/video/embed/pdf slots — always render in the
                // designed order so a deliberate activity layout is never collapsed. When
                // Student Tools is on, the "+ Add" palette below simply appends optional
                // extra blocks after the structured form.
            ?>

            <?php if ( $type === 'entry_title' ) :
                $label    = $sec['label'] ?: 'Entry Title';
                $required = ! empty( $sec['required'] );
                $field_id = 'section_entry_title_' . $i;
            ?>
            <div class="reflection-field">
                <label for="<?php echo esc_attr( $field_id ); ?>">
                    <?php echo esc_html( $label ); ?>
                    <?php if ( $required ) : ?><span style="color:#d63638;" aria-label="required">*</span><?php endif; ?>
                </label>
                <input type="text"
                       id="<?php echo esc_attr( $field_id ); ?>"
                       name="<?php echo esc_attr( $field_id ); ?>"
                       class="reflsub-entry-title"
                       value="<?php echo esc_attr( $prefill[ $i ] ?? '' ); ?>"
                       placeholder="Give your entry a title…"
                       <?php if ( $required ) : ?>required<?php endif; ?>>
            </div>

            <?php elseif ( $type === 'prompt' ) :
                $label      = $sec['label'] ?? '';
                $word_limit = intval( $sec['word_limit'] ?? 0 );
                $required   = ! empty( $sec['required'] );
                $field_id   = 'section_response_' . $i;
                $counter_id = 'reflsub_wc_' . $i;
            ?>
            <div class="reflection-field">
                <?php if ( $label ) : ?>
                <label for="<?php echo esc_attr( $field_id ); ?>">
                    <?php echo wp_kses_post( $label ); ?>
                    <?php if ( $required ) : ?><span style="color:#d63638;" aria-label="required">*</span><?php endif; ?>
                </label>
                <?php endif; ?>
                <textarea id="<?php echo esc_attr( $field_id ); ?>"
                          name="<?php echo esc_attr( $field_id ); ?>"
                          rows="6"
                          placeholder="Write your response here…"
                          data-counter-id="<?php echo esc_attr( $counter_id ); ?>"
                          <?php if ( $word_limit ) : ?>data-word-limit="<?php echo esc_attr( $word_limit ); ?>"<?php endif; ?>
                          <?php if ( $required ) : ?>required<?php endif; ?>><?php echo esc_textarea( $prefill[ $i ] ?? '' ); ?></textarea>
                <?php // Counter renders unconditionally — a running word count is useful
                      // for long-form writing even when the instructor set no limit.
                      // JS fills in the live value; this is the no-JS/pre-hydration state. ?>
                <p class="reflection-hint">
                    <span id="<?php echo esc_attr( $counter_id ); ?>" style="color:#646970;"><?php
                        echo $word_limit ? '0 / ' . esc_html( $word_limit ) . ' words' : '0 words';
                    ?></span>
                </p>
            </div>

            <?php elseif ( $type === 'mcq' ) :
                $question = $sec['question'] ?? '';
                $options  = is_array( $sec['options'] ?? null ) ? $sec['options'] : array();
                $multi    = ! empty( $sec['multi'] );
                $input_type = $multi ? 'checkbox' : 'radio';
                $field_name = 'section_mcq_' . $i . ( $multi ? '[]' : '[]' );
            ?>
            <div class="reflection-field">
                <?php if ( $question ) : ?>
                <label><?php echo wp_kses_post( $question ); ?></label>
                <?php endif; ?>
                <fieldset style="border:none; padding:0; margin:0;">
                    <legend class="screen-reader-text"><?php echo esc_html( $question ); ?></legend>
                    <?php foreach ( $options as $opt_i => $option ) :
                        if ( $option === '' ) continue;
                        $opt_id = 'section_mcq_' . $i . '_' . $opt_i;
                    ?>
                    <label for="<?php echo esc_attr( $opt_id ); ?>" style="font-weight:normal; margin-bottom:0.5rem; display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="<?php echo esc_attr( $input_type ); ?>"
                               id="<?php echo esc_attr( $opt_id ); ?>"
                               name="section_mcq_<?php echo esc_attr( $i ); ?>[]"
                               value="<?php echo esc_attr( $option ); ?>"
                               <?php if ( is_array( $prefill[ $i ] ?? null ) && in_array( $option, $prefill[ $i ], true ) ) : ?>checked<?php endif; ?>>
                        <?php echo esc_html( $option ); ?>
                    </label>
                    <?php endforeach; ?>
                </fieldset>
                <?php if ( $multi ) : ?>
                <p class="reflection-hint">Select all that apply.</p>
                <?php endif; ?>
            </div>

            <?php elseif ( $type === 'image' ) :
                $existing_img_ids = $edit_post_id ? get_posts( array(
                    'post_type'      => 'attachment',
                    'post_parent'    => $edit_post_id,
                    'post_mime_type' => 'image',
                    'posts_per_page' => -1,
                    'post_status'    => 'inherit',
                    'orderby'        => 'date',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                ) ) : array();
            ?>
            <div class="reflection-field">
                <label>Upload Image(s)</label>
                <?php if ( ! empty( $existing_img_ids ) ) : ?>
                <div class="reflsub-existing-images" id="reflsub-existing-<?php echo $i; ?>">
                    <p class="reflsub-existing-label">Currently uploaded — click &times; to remove:</p>
                    <div class="reflsub-existing-thumbs">
                        <?php foreach ( $existing_img_ids as $img_id ) :
                            $thumb = wp_get_attachment_image_url( $img_id, 'thumbnail' );
                            $alt   = get_the_title( $img_id );
                            if ( ! $thumb ) continue;
                        ?>
                        <div class="reflsub-existing-wrap" data-img-id="<?php echo esc_attr( $img_id ); ?>">
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $alt ); ?>">
                            <button type="button" class="reflsub-existing-remove" aria-label="Remove <?php echo esc_attr( $alt ); ?>">&times;</button>
                            <input type="hidden"
                                   name="reflsub_keep_image_ids[]"
                                   value="<?php echo esc_attr( $img_id ); ?>"
                                   id="reflsub-keep-<?php echo esc_attr( $img_id ); ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="reflsub-drop-zone" id="reflsub-drop-zone-<?php echo $i; ?>">
                    <div class="reflsub-drop-inner">
                        <span class="reflsub-drop-icon" aria-hidden="true">🖼️</span>
                        <p class="reflsub-drop-label">Drag &amp; drop images here</p>
                        <p class="reflsub-drop-sub">or <label class="reflsub-drop-browse" for="section_image_<?php echo $i; ?>">choose files</label></p>
                    </div>
                    <input type="file"
                           id="section_image_<?php echo $i; ?>"
                           name="section_image[]"
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           multiple
                           class="reflsub-drop-input"
                           aria-label="Upload images">
                    <div class="reflsub-drop-previews" id="reflsub-previews-<?php echo $i; ?>"></div>
                </div>
                <p class="reflection-hint">JPEG, PNG, GIF, WebP · Max 15 MB per file · Multiple images display as a gallery.<?php if ( ! empty( $existing_img_ids ) ) : ?> New uploads are added alongside any kept images.<?php endif; ?></p>
            </div>

            <?php elseif ( $type === 'video' ) : ?>
            <div class="reflection-field">
                <label for="section_video">Video URL</label>
                <input type="url" id="section_video" name="section_video"
                       placeholder="https://www.youtube.com/watch?v=…"
                       value="<?php echo esc_attr( $prefill[ $i ] ?? '' ); ?>">
                <p class="reflection-hint">Paste a YouTube or Vimeo link. It will be embedded in your post.</p>
            </div>

            <?php elseif ( $type === 'embed' ) : ?>
            <div class="reflection-field">
                <label for="section_embed">Embed Code</label>
                <textarea id="section_embed" name="section_embed"
                          rows="5"
                          placeholder="Paste your embed code here — Kaltura, YouTube, Vimeo, or any &lt;iframe&gt;…"><?php echo esc_textarea( $prefill[ $i ] ?? '' ); ?></textarea>
                <p class="reflection-hint">Only <code>&lt;iframe&gt;</code> tags are accepted; other HTML will be stripped.</p>
            </div>

            <?php elseif ( $type === 'pdf' ) :
                $pdf_required = ! empty( $sec['required'] );
                // In edit mode, look up the previously attached PDF.
                $existing_pdf = null;
                if ( $edit_post_id ) {
                    $existing_pdfs = get_posts( array(
                        'post_type'      => 'attachment',
                        'post_parent'    => $edit_post_id,
                        'post_mime_type' => 'application/pdf',
                        'posts_per_page' => 1,
                        'post_status'    => 'inherit',
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                    ) );
                    $existing_pdf = ! empty( $existing_pdfs ) ? $existing_pdfs[0] : null;
                }
            ?>
            <div class="reflection-field">
                <label for="section_pdf">
                    Upload PDF / File
                    <?php if ( $pdf_required ) : ?><span style="color:#d63638;" aria-label="required">*</span><?php endif; ?>
                </label>
                <?php if ( $existing_pdf ) : ?>
                <p class="reflection-hint" style="margin-bottom:.5em;">
                    Currently attached:
                    <a href="<?php echo esc_url( wp_get_attachment_url( $existing_pdf->ID ) ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $existing_pdf->post_title ?: basename( wp_get_attachment_url( $existing_pdf->ID ) ) ); ?>
                    </a>
                    — leave the picker empty to keep it, or choose a new file to replace it.
                </p>
                <input type="hidden" name="reflsub_keep_pdf_id" value="<?php echo esc_attr( $existing_pdf->ID ); ?>">
                <?php endif; ?>
                <input type="file"
                       id="section_pdf"
                       name="section_pdf"
                       accept=".pdf,application/pdf"
                       <?php if ( $pdf_required && ! $existing_pdf ) : ?>required<?php endif; ?>>
                <p class="reflection-hint">PDF only. Maximum 15 MB.</p>
            </div>

            <?php elseif ( $type === 'audio' ) :
                $audio_required = ! empty( $sec['required'] );
                $audio_max_min  = max( 1, min( 30, intval( $sec['max_minutes'] ?? 5 ) ) );
                $audio_max_sec  = $audio_max_min * 60;
                // In edit mode, look up the previously attached audio recording.
                $existing_audio = null;
                if ( $edit_post_id ) {
                    $existing_audios = get_posts( array(
                        'post_type'      => 'attachment',
                        'post_parent'    => $edit_post_id,
                        'post_mime_type' => 'audio',
                        'posts_per_page' => 1,
                        'post_status'    => 'inherit',
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                    ) );
                    $existing_audio = ! empty( $existing_audios ) ? $existing_audios[0] : null;
                }
            ?>
            <div class="reflection-field">
                <label>
                    Audio response
                    <?php if ( $audio_required ) : ?><span style="color:#d63638;" aria-label="required">*</span><?php endif; ?>
                </label>
                <div class="reflsub-audio-recorder"
                     data-max-seconds="<?php echo esc_attr( $audio_max_sec ); ?>"
                     data-required="<?php echo $audio_required ? '1' : '0'; ?>">
                    <?php if ( $existing_audio ) : ?>
                    <div class="reflsub-audio-existing">
                        <p class="reflection-hint" style="margin-bottom:.5em;">Current recording — record again to replace it.</p>
                        <audio controls preload="metadata" src="<?php echo esc_url( wp_get_attachment_url( $existing_audio->ID ) ); ?>"></audio>
                        <input type="hidden" name="reflsub_keep_audio_id" value="<?php echo esc_attr( $existing_audio->ID ); ?>">
                    </div>
                    <?php endif; ?>
                    <input type="file" class="reflsub-audio-input" name="section_audio" accept="audio/*" hidden>
                </div>
            </div>

            <?php elseif ( $type === 'tags' ) : ?>
            <div class="reflection-field">
                <label for="section_tags_<?php echo $i; ?>">
                    Tags <span class="reflection-optional" style="font-size:.85em; font-weight:normal; color:#646970;">(optional)</span>
                </label>
                <input type="text"
                       id="section_tags_<?php echo $i; ?>"
                       name="section_tags_<?php echo $i; ?>"
                       value="<?php echo esc_attr( $prefill[ $i ] ?? '' ); ?>"
                       placeholder="Comma-separated tags…">
                <p class="reflection-hint">Add tags to help categorize your post.</p>
            </div>

            <?php elseif ( $type === 're_reflect' ) :
                $rr_heading   = $sec['heading']   ?? '';
                $rr_date_from = $sec['date_from'] ?? '';
                $rr_date_to   = $sec['date_to']   ?? '';
                $rr_tags      = is_array( $sec['tags'] ?? null ) ? $sec['tags'] : array();
                $rr_pick      = $sec['pick']       ?? 'random';

                // Build query args scoped to this student's submissions
                $rr_args = array(
                    'post_type'      => 'post',
                    'author'         => $user_id,
                    'post_status'    => array( 'publish', 'private' ),
                    'posts_per_page' => $rr_pick === 'random' ? 20 : 1,
                    'orderby'        => $rr_pick === 'oldest' ? 'date' : 'date',
                    'order'          => $rr_pick === 'oldest' ? 'ASC' : 'DESC',
                    'meta_query'     => array(
                        array(
                            'key'     => '_reflection_source_page',
                            'compare' => 'EXISTS',
                        ),
                    ),
                );

                // Date range filter
                $rr_date_query = array();
                if ( $rr_date_from ) {
                    $rr_date_query['after'] = $rr_date_from;
                }
                if ( $rr_date_to ) {
                    $rr_date_query['before'] = $rr_date_to;
                }
                if ( $rr_date_query ) {
                    $rr_date_query['inclusive'] = true;
                    $rr_args['date_query']      = array( $rr_date_query );
                }

                // Tag filter
                if ( ! empty( $rr_tags ) ) {
                    $rr_args['tag_slug__in'] = $rr_tags;
                }

                $rr_posts = get_posts( $rr_args );

                // Pick one from the result set
                $rr_post = null;
                if ( ! empty( $rr_posts ) ) {
                    $rr_post = $rr_pick === 'random'
                        ? $rr_posts[ array_rand( $rr_posts ) ]
                        : $rr_posts[0];
                }
            ?>

            <?php if ( $rr_post ) : ?>
            <div class="reflection-field reflsub-rr-card">
                <p class="reflsub-rr-card-heading">
                    <?php echo esc_html( $rr_heading ?: 'Before you write, look back at this…' ); ?>
                </p>
                <blockquote class="reflsub-rr-card-excerpt">
                    <?php
                    // Strip instructor prompt labels so only the student's words appear.
                    $rr_raw = get_the_content( null, false, $rr_post );
                    $rr_raw = preg_replace( '/<p\s+class="reflsub-prompt-label"[^>]*>.*?<\/p>/si', '', $rr_raw );
                    $rr_raw = preg_replace( '/<h[34][^>]*>.*?<\/h[34]>/si', '', $rr_raw ); // legacy posts
                    echo esc_html( wp_trim_words( wp_strip_all_tags( $rr_raw ), 50, '…' ) );
                    ?>
                </blockquote>
                <p class="reflsub-rr-card-meta">
                    <?php echo esc_html( get_the_date( 'F j, Y', $rr_post ) ); ?>
                    &nbsp;&mdash;&nbsp;
                    <a href="<?php echo esc_url( get_permalink( $rr_post ) ); ?>" target="_blank" rel="noopener">View full post ↗</a>
                </p>
            </div>
            <?php endif; // $rr_post ?>

            <?php endif; ?>

            <?php endforeach; ?>

            <?php if ( $allow_student_blocks ) : ?>
            <!-- Student-added blocks palette: students dynamically add paragraphs,
                 images, video, embed, or PDF as needed. Order is preserved.
                 In edit mode, previously-saved student blocks are server-rendered
                 here so the student can update rather than start from scratch. -->
            <?php
            $existing_state = array();
            if ( $edit_post_id ) {
                $state_raw = get_post_meta( $edit_post_id, '_reflsub_student_blocks', true );
                if ( $state_raw ) {
                    $decoded = json_decode( $state_raw, true );
                    if ( is_array( $decoded ) ) {
                        $existing_state = $decoded;
                    }
                }
            }
            $next_student_id = count( $existing_state ) + 1;
            ?>
            <div id="reflsub-student-blocks" data-next-id="<?php echo (int) $next_student_id; ?>">
                <?php foreach ( $existing_state as $b_idx => $b_state ) {
                    echo reflsub_render_student_block( $b_idx + 1, $b_state );
                } ?>
            </div>
            <div class="reflsub-student-palette">
                <span class="reflsub-student-palette-label">+ Add</span>
                <button type="button" data-block-type="text"  class="reflsub-student-add-btn">Paragraph</button>
                <button type="button" data-block-type="image" class="reflsub-student-add-btn">Image(s)</button>
                <button type="button" data-block-type="video" class="reflsub-student-add-btn">Video URL</button>
                <button type="button" data-block-type="embed" class="reflsub-student-add-btn">Embed</button>
                <button type="button" data-block-type="pdf"   class="reflsub-student-add-btn">PDF / File</button>
                <button type="button" data-block-type="audio" class="reflsub-student-add-btn">Audio</button>
            </div>
            <input type="hidden" name="reflsub_student_blocks_data" id="reflsub-student-blocks-data" value="">
            <?php endif; ?>

            <div class="reflection-submit">
                <button type="submit" name="reflsub_submit_action" value="submit" class="wp-element-button">
                    <?php echo ( $edit_post_id && ! $editing_draft ) ? 'Update Submission' : 'Submit'; ?>
                </button>
                <?php // Only offered while the work is still unsubmitted. Once a submission
                      // exists, "Save as Draft" would read as retracting it from the
                      // instructor — which this deliberately does not do.
                      // formnovalidate: a draft is explicitly incomplete work, so required
                      // fields must not block saving it. The handler still refuses a save
                      // with nothing in it at all.
                      if ( ! $edit_post_id || $editing_draft ) : ?>
                <button type="submit" name="reflsub_submit_action" value="draft"
                        class="reflsub-draft-btn" formnovalidate>
                    Save as Draft
                </button>
                <p class="reflection-hint reflsub-draft-hint">
                    Saving a draft stores your work on the site so you can finish it on another
                    device or another day. Your instructor does not see it until you submit.
                </p>
                <?php endif; ?>
            </div>

        </form>
    </div>


    <?php reflsub_enqueue_form_assets( $page_id ); ?>
    <?php

    return ob_get_clean();
}
