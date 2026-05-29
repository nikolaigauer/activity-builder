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


// ── Prompt label styles on single posts ───────────────────────────────────────
add_action( 'wp_head', function () {
    if ( ! is_singular( 'post' ) ) return;
    ?>
    <style>
    .reflsub-prompt-label {
        font-style: italic;
        font-weight: 600;
        font-size: 0.9em;
        color: #64748b;
        border-left: 3px solid #ace7d4;
        padding-left: 10px;
        margin-bottom: 2px;
    }
    </style>
    <?php
} );


// ─────────────────────────────────────────────────────────────────────────────
// Helper: sanitize an embed code — allows <iframe> only, strips everything else
// ─────────────────────────────────────────────────────────────────────────────

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

    // Post/Redirect/Get — back to the submission page
    wp_redirect( add_query_arg( 'reflection_submitted', '1', $redirect_base ) );
    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Sections-based submission handler
// ─────────────────────────────────────────────────────────────────────────────

function reflsub_handle_sections_submission( $page_id, $user_id, $sections, $redirect_base ) {

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
        }
    }

    if ( ! $has_content ) {
        wp_redirect( add_query_arg( 'reflection_error', 'empty', $redirect_base ) );
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
        $result = wp_update_post( array(
            'ID'           => $edit_post_id,
            'post_title'   => $post_title,
            'post_content' => $post_content,
        ), true );
        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( 'reflection_error', 'save', $redirect_base ) );
            exit;
        }
        $post_id = $edit_post_id;
    } else {
        $privacy     = get_post_meta( $page_id, 'submission_privacy', true ) ?: 'publish';
        $post_status = in_array( $privacy, array( 'publish', 'private', 'pending' ), true )
            ? $privacy : 'publish';

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
    }

    // Per-section meta
    update_post_meta( $post_id, '_reflection_source_page', $page_id );
    foreach ( $prompt_meta as $i => $response ) {
        update_post_meta( $post_id, '_reflsub_response_' . $i, $response );
    }
    foreach ( $mcq_meta as $i => $sel ) {
        update_post_meta( $post_id, '_reflsub_mcq_' . $i, wp_json_encode( $sel ) );
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
        }
    }

    // Persist student-block state so edit mode can replay the blocks.
    if ( $student_state ) {
        update_post_meta( $post_id, '_reflsub_student_blocks', wp_json_encode( $student_state ) );
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

    $redirect_arg = $edit_post_id ? 'reflection_updated' : 'reflection_submitted';
    wp_redirect( add_query_arg( $redirect_arg, '1', $redirect_base ) );
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
        $user        = wp_get_current_user();
        $archive_url = get_author_posts_url( $user->ID );
        ob_start();
        ?>
        <div class="reflection-notice reflection-success">
            <p><strong>Your reflection has been submitted.</strong></p>
            <p>
                <a href="<?php echo esc_url( $archive_url ); ?>">View your posts →</a>
                <?php if ( $allow_resub ) : ?>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">Submit another</a>
                <?php endif; ?>
            </p>
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
            $edit_url = get_edit_post_link( $existing->ID );
            $view_url = ( $existing->post_status === 'publish' )
                ? get_permalink( $existing->ID )
                : null;

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
                    <?php if ( $view_url ) : ?>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url( $view_url ); ?>">View it</a>
                    <?php endif; ?>
                </p>
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

    <style>
        .reflection-form-wrap {
            max-width: 720px;
            margin: 2rem 0;
        }
        .reflection-form .reflection-field {
            margin-bottom: 2rem;
        }
        .reflection-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.6rem;
            font-size: 1.05rem;
            line-height: 1.5;
        }
        .reflection-form textarea,
        .reflection-form input[type="url"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            font-size: 1rem;
            font-family: inherit;
            box-sizing: border-box;
        }
        .reflection-form textarea { resize: vertical; }
        .reflection-form textarea:focus,
        .reflection-form input[type="url"]:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.15);
        }
        .reflection-form input[type="file"] {
            display: block;
            margin-bottom: 0.4rem;
        }
        .reflection-hint {
            margin: 0.3rem 0 0;
            font-size: 0.85rem;
            color: #646970;
        }
        .reflection-submit { margin-top: 1.5rem; }
        .reflection-notice {
            padding: 1rem 1.5rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        .reflection-notice p { margin: 0.25rem 0; }
        .reflection-success   { background: #d5f4e6; border-left: 4px solid #00a32a; }
        .reflection-error     { background: #fce8e8; border-left: 4px solid #d63638; }
        .reflection-info      { background: #f0f6fc; border-left: 4px solid #2271b1; }
        .reflection-duplicate { background: #fff8e5; border-left: 4px solid #dba617; }
    </style>

    <script>
    (function() {
        var form     = document.querySelector('.reflection-form[data-page-id]');
        // Key is scoped to both page and logged-in user — prevents draft leaking
        // between users on shared machines / lab computers.
        var draftKey = form ? 'reflsub_draft_' + form.dataset.pageId + '_' + form.dataset.userId : null;
        // Clean up any legacy un-scoped key left by earlier builds.
        if ( form ) { try { localStorage.removeItem( 'reflsub_draft_' + form.dataset.pageId ); } catch(e) {} }
        if ( draftKey ) {
            if ( window.location.search.indexOf('reflection_submitted=1') !== -1 ) {
                localStorage.removeItem( draftKey );
            } else {
                var saved = null;
                try { saved = JSON.parse( localStorage.getItem( draftKey ) ); } catch(e) {}
                if ( saved && Object.keys(saved).length ) {
                    var anyRestored = false;
                    Object.keys(saved).forEach(function(name) {
                        var el = form.querySelector('[name="' + name + '"]');
                        if ( el && ( el.tagName === 'TEXTAREA' || ( el.tagName === 'INPUT' && el.type === 'text' ) ) ) {
                            el.value = saved[name]; anyRestored = true;
                        }
                    });
                    if ( anyRestored ) {
                        var notice = document.createElement('div');
                        notice.className = 'reflection-notice reflection-info';
                        notice.style.cssText = 'display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1.5rem;';
                        notice.innerHTML = '<p style="margin:0;"><strong>Draft restored.</strong> Your previous text was saved automatically.</p>'
                            + '<button type="button" style="background:none;border:none;color:#2271b1;cursor:pointer;white-space:nowrap;padding:0;font-size:0.9rem;text-decoration:underline;">Discard &amp; start fresh</button>';
                        form.parentNode.insertBefore( notice, form );
                        notice.querySelector('button').addEventListener('click', function() {
                            localStorage.removeItem( draftKey ); notice.remove();
                            form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) { el.value = ''; });
                        });
                    }
                }
                var saveTimer;
                form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) {
                    el.addEventListener('input', function() {
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout(function() {
                            var data = {};
                            form.querySelectorAll('textarea, input[type="text"]').forEach(function(f) {
                                if (f.name) data[f.name] = f.value;
                            });
                            try { localStorage.setItem( draftKey, JSON.stringify(data) ); } catch(e) {}
                        }, 2000);
                    });
                });
            }
        }
        var POST_MAX_BYTES = <?php echo (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ); ?>;
        if ( form ) {
            form.addEventListener('submit', function(e) {
                var total = 0;
                form.querySelectorAll('input[type="file"]').forEach(function(input) {
                    Array.from(input.files || []).forEach(function(f) { total += f.size; });
                });
                if ( total > POST_MAX_BYTES * 0.9 ) {
                    e.preventDefault();
                    var errEl = document.getElementById('reflsub-upload-error');
                    if ( !errEl ) {
                        errEl = document.createElement('div'); errEl.id = 'reflsub-upload-error';
                        errEl.className = 'reflection-notice reflection-error';
                        form.querySelector('.reflection-submit').insertAdjacentElement('beforebegin', errEl);
                    }
                    errEl.innerHTML = '<p><strong>Images too large to upload.</strong> Your selected images total '
                        + (total/1024/1024).toFixed(1) + ' MB — the limit is '
                        + (POST_MAX_BYTES/1024/1024).toFixed(0) + ' MB. Remove some images and try again. '
                        + '<em>Your text has not been lost.</em></p>';
                    errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }
    })();
    </script>
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
        $user        = wp_get_current_user();
        $archive_url = get_author_posts_url( $user->ID );
        ob_start();
        ?>
        <div class="reflection-notice reflection-success">
            <p><strong><?php echo $updated ? 'Your submission has been updated.' : 'Your reflection has been submitted.'; ?></strong></p>
            <p>
                <a href="<?php echo esc_url( $archive_url ); ?>">View your posts →</a>
                <?php if ( $allow_resub && ! $updated ) : ?>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">Submit another</a>
                <?php endif; ?>
            </p>
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
            $view_url  = $existing->post_status === 'publish' ? get_permalink( $existing->ID ) : null;

            ob_start();
            ?>
            <div class="reflection-notice reflection-duplicate">
                <p><strong>You have already submitted a reflection for this page.</strong></p>
                <p>
                    Status: <strong><?php echo esc_html( $status ); ?></strong>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( $edit_link ); ?>">Edit your submission</a>
                    <?php if ( $view_url ) : ?>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url( $view_url ); ?>">View it</a>
                    <?php endif; ?>
                </p>
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

        <form class="reflection-form" method="post"<?php echo $form_enctype; ?> data-page-id="<?php echo esc_attr( $page_id ); ?>" data-user-id="<?php echo esc_attr( $user_id ); ?>">

            <?php wp_nonce_field( 'submit_reflection', 'reflection_nonce' ); ?>
            <input type="hidden" name="reflection_page_id" value="<?php echo esc_attr( $page_id ); ?>">
            <input type="hidden" name="eportfolio_reflection_submit" value="1">
            <?php if ( $edit_post_id ) : ?>
            <input type="hidden" name="reflsub_edit_post_id" value="<?php echo esc_attr( $edit_post_id ); ?>">
            <?php endif; ?>

            <?php if ( $edit_post_id ) : ?>
            <div class="reflection-notice reflection-info" style="margin-bottom:1.5rem;">
                <p>You are editing your submission. Make your changes and click <strong>Update Submission</strong>.</p>
            </div>
            <?php endif; ?>

            <?php foreach ( $sections as $i => $sec ) :
                $type = $sec['type'] ?? '';
                // Media section types render as baked-in slots only when the
                // Student Content Builder is off. When it's on, the "+ Add"
                // palette below replaces them.
                if ( $allow_student_blocks
                     && in_array( $type, array( 'image', 'video', 'embed', 'pdf' ), true ) ) {
                    continue;
                }
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
                          <?php if ( $word_limit ) : ?>data-word-limit="<?php echo esc_attr( $word_limit ); ?>" data-counter-id="<?php echo esc_attr( $counter_id ); ?>"<?php endif; ?>
                          <?php if ( $required ) : ?>required<?php endif; ?>><?php echo esc_textarea( $prefill[ $i ] ?? '' ); ?></textarea>
                <?php if ( $word_limit ) : ?>
                <p class="reflection-hint">
                    <span id="<?php echo esc_attr( $counter_id ); ?>" style="color:#646970;">0 / <?php echo esc_html( $word_limit ); ?> words</span>
                </p>
                <?php endif; ?>
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
            </div>
            <input type="hidden" name="reflsub_student_blocks_data" id="reflsub-student-blocks-data" value="">
            <?php endif; ?>

            <div class="reflection-submit">
                <button type="submit" class="wp-element-button">
                    <?php echo $edit_post_id ? 'Update Submission' : 'Submit'; ?>
                </button>
            </div>

        </form>
    </div>

    <style>
        .reflection-form-wrap { max-width: 720px; margin: 2rem 0; }
        .reflection-intro { margin-bottom: 1.75rem; padding: 1rem 1.25rem; font-size: 1.325rem; line-height: 1.65; color: #1d2327; background: #f6f7f7; border-radius: 8px; }
        .reflection-intro p { margin: 0 0 0.75rem; }
        .reflection-intro p:last-child { margin-bottom: 0; }
        .reflection-form .reflection-field { margin-bottom: 2rem; }
        .reflection-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.6rem;
            font-size: 1.05rem;
            line-height: 1.5;
        }
        .reflection-form textarea,
        .reflection-form input[type="text"],
        .reflection-form input[type="url"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            font-size: 1rem;
            font-family: inherit;
            box-sizing: border-box;
        }
        .reflection-form textarea { resize: vertical; }
        .reflection-form textarea:focus,
        .reflection-form input[type="text"]:focus,
        .reflection-form input[type="url"]:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.15);
        }
        .reflection-form input.reflsub-entry-title {
            padding: 0.95rem 1rem;
            font-size: 1.2rem;
            font-weight: 500;
        }
        .reflection-form input[type="file"] { display: block; margin-bottom: 0.4rem; }
        .reflection-hint { margin: 0.3rem 0 0; font-size: 0.85rem; color: #646970; }

        /* ── Re-reflect card ────────────────────────────────────────── */
        .reflsub-rr-card {
            background: #f8f5ff;
            border-left: 4px solid #7c5cbf;
            border-radius: 6px;
            padding: 1.1rem 1.25rem;
        }
        .reflsub-rr-card-heading {
            margin: 0 0 0.6rem;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #7c5cbf;
        }
        .reflsub-rr-card blockquote.reflsub-rr-card-excerpt {
            margin: 0 0 0.75rem;
            padding: 0;
            border: none;
            font-style: italic;
            font-size: 1rem;
            line-height: 1.65;
            color: #3c434a;
        }
        .reflsub-rr-card-meta {
            margin: 0;
            font-size: 0.85rem;
            color: #646970;
        }
        .reflsub-rr-card-meta a { color: #7c5cbf; }

        /* ── Drop zone ─────────────────────────────────────────────── */
        .reflsub-existing-images {
            margin-bottom: 0.75rem;
            padding: 0.6rem 0.75rem;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 6px;
        }
        .reflsub-existing-label {
            margin: 0 0 0.5rem;
            font-size: 0.85rem;
            color: #166534;
        }
        .reflsub-existing-thumbs {
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .reflsub-existing-wrap {
            position: relative; display: inline-block; line-height: 0;
        }
        .reflsub-existing-wrap img {
            width: 80px; height: 80px; object-fit: cover;
            border-radius: 6px; border: 2px solid #86efac;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
            display: block;
        }
        .reflsub-existing-remove {
            position: absolute; top: -7px; right: -7px;
            width: 20px; height: 20px; border-radius: 50%;
            background: #d63638; color: #fff;
            border: 2px solid #fff; font-size: 13px; line-height: 1;
            cursor: pointer; padding: 0;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .15s;
            box-shadow: 0 1px 3px rgba(0,0,0,.35);
        }
        .reflsub-existing-wrap:hover .reflsub-existing-remove { opacity: 1; }
        .reflsub-drop-zone {
            position: relative;
            border: 2px dashed #c3c4c7;
            border-radius: 8px;
            background: #f9f9f9;
            padding: 2rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .reflsub-drop-zone.is-over {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        .reflsub-drop-zone.has-files {
            border-color: #00a32a;
            background: #f0fdf4;
        }
        .reflsub-drop-inner { pointer-events: none; }
        .reflsub-drop-icon { font-size: 2rem; display: block; margin-bottom: 0.4rem; }
        .reflsub-drop-label { margin: 0 0 0.25rem; font-weight: 600; color: #3c434a; }
        .reflsub-drop-sub { margin: 0; font-size: 0.9rem; color: #646970; }
        .reflsub-drop-browse {
            color: #f59e0b; text-decoration: underline;
            cursor: pointer; pointer-events: all;
            font-weight: 600;
        }
        /* Hide the raw file input; the label triggers it */
        .reflsub-drop-input {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; cursor: pointer;
        }
        .reflsub-drop-previews {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-top: 1rem; justify-content: center;
        }
        .reflsub-preview-wrap {
            position: relative; display: inline-block;
            line-height: 0;
        }
        .reflsub-preview-wrap img {
            width: 80px; height: 80px; object-fit: cover;
            border-radius: 6px; border: 2px solid #fde68a;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
            display: block;
        }
        .reflsub-preview-remove {
            position: absolute; top: -7px; right: -7px;
            width: 20px; height: 20px; border-radius: 50%;
            background: #d63638; color: #fff;
            border: 2px solid #fff; font-size: 13px; line-height: 1;
            cursor: pointer; padding: 0;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .15s;
            box-shadow: 0 1px 3px rgba(0,0,0,.35);
        }
        .reflsub-preview-wrap:hover .reflsub-preview-remove { opacity: 1; }
        .reflsub-drop-count {
            font-size: 0.8rem; color: #00a32a; font-weight: 600; margin-top: 0.5rem;
        }
        .reflection-submit { margin-top: 1.5rem; }
        .reflection-notice {
            padding: 1rem 1.5rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        .reflection-notice p { margin: 0.25rem 0; }
        .reflection-success   { background: #d5f4e6; border-left: 4px solid #00a32a; }
        .reflection-error     { background: #fce8e8; border-left: 4px solid #d63638; }
        .reflection-info      { background: #f0f6fc; border-left: 4px solid #2271b1; }
        .reflection-duplicate { background: #fff8e5; border-left: 4px solid #dba617; }

        /* ── Student-added blocks palette ────────────────────────────── */
        #reflsub-student-blocks { margin-top: 1rem; }
        #reflsub-student-blocks:empty { margin-top: 0; }
        .reflsub-student-block {
            position: relative;
            margin-bottom: 1.25rem;
            padding: 1rem 1.25rem 1.1rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .reflsub-student-block-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 0.6rem;
        }
        .reflsub-student-block-label {
            font-size: 0.8rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em;
            color: #64748b;
        }
        .reflsub-student-block-remove {
            background: none; border: none; padding: 2px 8px;
            color: #d63638; font-size: 0.85rem; font-weight: 600;
            cursor: pointer; border-radius: 4px;
            transition: background .15s;
        }
        .reflsub-student-block-remove:hover { background: #fce8e8; }
        .reflsub-student-block label {
            margin-bottom: 0.35rem; font-size: 0.95rem;
        }

        .reflsub-student-palette {
            display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
            padding: 14px 16px;
            margin: 1.5rem 0 1rem;
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-radius: 8px;
        }
        .reflsub-student-palette-label {
            font-size: 0.85rem; font-weight: 600;
            color: #64748b; margin-right: 4px;
        }
        .reflsub-student-add-btn {
            border: 1.5px solid #cbd5e1;
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, color .15s, border-color .15s;
        }
        .reflsub-student-add-btn:hover {
            background: #1b28b4; color: #fff; border-color: #1b28b4;
        }
    </style>

    <script>
    (function() {
        var MAX_FILE_MB = 15;
        var MAX_FILE_BYTES = MAX_FILE_MB * 1024 * 1024;

        // ── Word counters ──────────────────────────────────────────────────────
        document.querySelectorAll('.reflection-form textarea[data-word-limit]').forEach(function(ta) {
            var limit   = parseInt(ta.dataset.wordLimit, 10);
            var counter = document.getElementById(ta.dataset.counterId);
            if (!counter || !limit) return;
            function update() {
                var words = ta.value.trim() === '' ? 0 : ta.value.trim().split(/\s+/).length;
                counter.textContent = words + ' / ' + limit + ' words';
                counter.style.color = words > limit ? '#d63638' : '#646970';
            }
            ta.addEventListener('input', update);
            update();
        });

        // ── LocalStorage autosave ─────────────────────────────────────────────
        var form     = document.querySelector('.reflection-form[data-page-id]');
        // Key is scoped to both page and logged-in user — prevents draft leaking
        // between users on shared machines / lab computers.
        var draftKey = form ? 'reflsub_draft_' + form.dataset.pageId + '_' + form.dataset.userId : null;
        // Clean up any legacy un-scoped key left by earlier builds.
        if ( form ) { try { localStorage.removeItem( 'reflsub_draft_' + form.dataset.pageId ); } catch(e) {} }

        if ( draftKey ) {
            if ( window.location.search.indexOf('reflection_submitted=1') !== -1 ) {
                // Successful submit — wipe the draft
                localStorage.removeItem( draftKey );
            } else {
                // Restore draft if one exists
                var saved = null;
                try { saved = JSON.parse( localStorage.getItem( draftKey ) ); } catch(e) {}
                if ( saved && Object.keys(saved).length ) {
                    var anyRestored = false;
                    Object.keys(saved).forEach(function(name) {
                        var el = form.querySelector('[name="' + name + '"]');
                        if ( el && ( el.tagName === 'TEXTAREA' || ( el.tagName === 'INPUT' && el.type === 'text' ) ) ) {
                            el.value = saved[name];
                            anyRestored = true;
                        }
                    });
                    if ( anyRestored ) {
                        var notice = document.createElement('div');
                        notice.className = 'reflection-notice reflection-info';
                        notice.style.cssText = 'display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1.5rem;';
                        notice.innerHTML = '<p style="margin:0;"><strong>Draft restored.</strong> Your previous text was saved automatically and has been filled in.</p>'
                            + '<button type="button" style="background:none;border:none;color:#2271b1;cursor:pointer;white-space:nowrap;padding:0;font-size:0.9rem;flex-shrink:0;text-decoration:underline;">Discard &amp; start fresh</button>';
                        form.parentNode.insertBefore( notice, form );
                        notice.querySelector('button').addEventListener('click', function() {
                            localStorage.removeItem( draftKey );
                            notice.remove();
                            form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) { el.value = ''; });
                        });
                    }
                }

                // Save on input, debounced 2 s
                var saveTimer;
                function reflsubSaveDraft() {
                    var data = {};
                    form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) {
                        if ( el.name ) data[el.name] = el.value;
                    });
                    try { localStorage.setItem( draftKey, JSON.stringify(data) ); } catch(e) {}
                }
                form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) {
                    el.addEventListener('input', function() {
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout( reflsubSaveDraft, 2000 );
                    });
                });
            }
        }

        // ── Total upload size guard ────────────────────────────────────────────
        // Catches oversized POSTs client-side so the text fields are never wiped.
        var POST_MAX_BYTES = <?php echo (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ); ?>;
        if ( form ) {
            form.addEventListener('submit', function(e) {
                var total = 0;
                form.querySelectorAll('input[type="file"]').forEach(function(input) {
                    Array.from(input.files || []).forEach(function(f) { total += f.size; });
                });
                // Use 90 % of post_max_size to leave room for text fields in the request body
                if ( total > POST_MAX_BYTES * 0.9 ) {
                    e.preventDefault();
                    var errEl = document.getElementById('reflsub-upload-error');
                    if ( !errEl ) {
                        errEl = document.createElement('div');
                        errEl.id = 'reflsub-upload-error';
                        errEl.className = 'reflection-notice reflection-error';
                        form.querySelector('.reflection-submit').insertAdjacentElement('beforebegin', errEl);
                    }
                    var mb    = ( total / 1024 / 1024 ).toFixed(1);
                    var limit = ( POST_MAX_BYTES / 1024 / 1024 ).toFixed(0);
                    errEl.innerHTML = '<p><strong>Images too large to upload.</strong> Your selected images total '
                        + mb + ' MB — the upload limit is ' + limit + ' MB. '
                        + 'Please remove some images and try again. '
                        + '<em>Your written text has not been lost.</em></p>';
                    errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }

        // ── Drag-and-drop image zones ──────────────────────────────────────────
        // Exposed globally so dynamically-injected student image blocks can wire
        // up their own drop zones via window.reflsubSetupDropZone(zone).
        window.reflsubSetupDropZone = function(zone) {
            if (zone.dataset.reflsubInitialised === '1') return;
            zone.dataset.reflsubInitialised = '1';

            var input         = zone.querySelector('.reflsub-drop-input');
            var previews      = zone.querySelector('.reflsub-drop-previews');
            var acceptedFiles = []; // accumulates files across multiple drops/selects

            function rebuildInput() {
                var dt = new DataTransfer();
                acceptedFiles.forEach(function(f) { dt.items.add(f); });
                input.files = dt.files;
            }

            function updateCount() {
                var countEl = zone.querySelector('.reflsub-drop-count');
                if (!countEl) {
                    countEl = document.createElement('p');
                    countEl.className = 'reflsub-drop-count';
                    zone.appendChild(countEl);
                }
                if (acceptedFiles.length) {
                    countEl.textContent = acceptedFiles.length + ' image' + (acceptedFiles.length > 1 ? 's' : '') + ' ready to upload';
                    zone.classList.add('has-files');
                } else {
                    countEl.textContent = '';
                    zone.classList.remove('has-files');
                }
            }

            function addPreview(file, idx) {
                var wrap = document.createElement('div');
                wrap.className = 'reflsub-preview-wrap';
                wrap.dataset.idx = idx;

                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = file.name;
                    wrap.appendChild(img);
                };
                reader.readAsDataURL(file);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'reflsub-preview-remove';
                btn.setAttribute('aria-label', 'Remove ' + file.name);
                btn.innerHTML = '&times;';
                btn.addEventListener('click', function() {
                    var i = parseInt(wrap.dataset.idx, 10);
                    acceptedFiles.splice(i, 1);
                    wrap.remove();
                    // Re-index remaining wraps
                    previews.querySelectorAll('.reflsub-preview-wrap').forEach(function(w, newIdx) {
                        w.dataset.idx = newIdx;
                    });
                    rebuildInput();
                    updateCount();
                });
                wrap.appendChild(btn);
                previews.appendChild(wrap);
            }

            function addFiles(files) {
                var rejected = [];
                Array.from(files).forEach(function(f) {
                    if (f.size > MAX_FILE_BYTES) {
                        rejected.push(f.name + ' (' + (f.size / 1024 / 1024).toFixed(1) + ' MB)');
                        return;
                    }
                    // Deduplicate by name + size
                    var dupe = acceptedFiles.some(function(a) {
                        return a.name === f.name && a.size === f.size;
                    });
                    if (dupe) return;

                    var idx = acceptedFiles.length;
                    acceptedFiles.push(f);
                    addPreview(f, idx);
                });
                if (rejected.length) {
                    alert('The following file(s) exceed the 15 MB limit and were not added:\n\n' + rejected.join('\n'));
                }
                zone.classList.remove('is-over');
                rebuildInput();
                updateCount();
            }

            // File input change
            input.addEventListener('change', function() {
                if (input.files.length) {
                    addFiles(input.files);
                    // Reset so the same file can be re-added after removal,
                    // then immediately restore the accumulated files so they
                    // are present when the form is submitted.
                    input.value = '';
                    rebuildInput();
                }
            });

            // Drag events
            zone.addEventListener('dragover', function(e) {
                e.preventDefault();
                zone.classList.add('is-over');
            });
            zone.addEventListener('dragleave', function(e) {
                if (!zone.contains(e.relatedTarget)) {
                    zone.classList.remove('is-over');
                }
            });
            zone.addEventListener('drop', function(e) {
                e.preventDefault();
                zone.classList.remove('is-over');
                if (e.dataTransfer.files.length) {
                    addFiles(e.dataTransfer.files);
                }
            });
        };

        // Initial scan: bind any pre-rendered drop zones.
        document.querySelectorAll('.reflsub-drop-zone').forEach(function(zone) {
            window.reflsubSetupDropZone(zone);
        });

        // ── Existing-image removal (edit mode) ────────────────────────────────
        document.querySelectorAll('.reflsub-existing-wrap').forEach(function(wrap) {
            var container = wrap.closest('.reflsub-existing-images');
            var btn = wrap.querySelector('.reflsub-existing-remove');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var hidden = document.getElementById('reflsub-keep-' + wrap.dataset.imgId);
                if (hidden) hidden.remove();
                wrap.remove();
                // Hide the whole section if all existing images were removed
                if (container && !container.querySelector('.reflsub-existing-wrap')) {
                    container.style.display = 'none';
                }
            });
        });

    })();

    // ── Student-added blocks palette ───────────────────────────────────────
    (function() {
        var container = document.getElementById('reflsub-student-blocks');
        var palette   = document.querySelector('.reflsub-student-palette');
        var hidden    = document.getElementById('reflsub-student-blocks-data');
        var form      = document.querySelector('.reflection-form');
        if (!container || !palette || !hidden || !form) return;

        // Seed from data-next-id so dynamic IDs don't collide with server-rendered blocks.
        var nextId = parseInt(container.dataset.nextId, 10) || 1;

        var LABELS = {
            text:  'Paragraph',
            image: 'Image(s)',
            video: 'Video URL',
            embed: 'Embed',
            pdf:   'PDF / File'
        };

        function buildBlock(type) {
            var id    = nextId++;
            var block = document.createElement('div');
            block.className    = 'reflsub-student-block';
            block.dataset.type = type;
            block.dataset.id   = id;

            var body = '';
            if (type === 'text') {
                body = '<textarea rows="5" class="reflsub-student-text" '
                     + 'placeholder="Write your paragraph…  (Leave a blank line between paragraphs.)"></textarea>';
            } else if (type === 'video') {
                body = '<input type="url" class="reflsub-student-video" '
                     + 'placeholder="https://www.youtube.com/watch?v=…">'
                     + '<p class="reflection-hint">Paste a YouTube or Vimeo URL — it will embed in your post.</p>';
            } else if (type === 'embed') {
                body = '<textarea rows="4" class="reflsub-student-embed" '
                     + 'placeholder="Paste your &lt;iframe&gt; embed code here — Kaltura, YouTube, Vimeo, etc."></textarea>'
                     + '<p class="reflection-hint">Only <code>&lt;iframe&gt;</code> tags are accepted; other HTML will be stripped.</p>';
            } else if (type === 'image') {
                body =
                    '<div class="reflsub-drop-zone">'
                        + '<div class="reflsub-drop-inner">'
                            + '<span class="reflsub-drop-icon" aria-hidden="true">🖼️</span>'
                            + '<p class="reflsub-drop-label">Drag &amp; drop images here</p>'
                            + '<p class="reflsub-drop-sub">or <label class="reflsub-drop-browse" for="reflsub-student-image-' + id + '">choose files</label></p>'
                        + '</div>'
                        + '<input type="file" id="reflsub-student-image-' + id + '" '
                        + 'name="reflsub_student_image_' + id + '[]" '
                        + 'accept="image/jpeg,image/png,image/gif,image/webp" multiple '
                        + 'class="reflsub-drop-input" aria-label="Upload images">'
                        + '<div class="reflsub-drop-previews"></div>'
                    + '</div>'
                    + '<p class="reflection-hint">JPEG, PNG, GIF, WebP — max 15 MB per file. Multiple images display as a gallery.</p>';
            } else if (type === 'pdf') {
                body = '<input type="file" name="reflsub_student_pdf_' + id + '" '
                     + 'accept=".pdf,application/pdf">'
                     + '<p class="reflection-hint">PDF only. Max 15 MB.</p>';
            }

            block.innerHTML =
                '<div class="reflsub-student-block-header">' +
                    '<span class="reflsub-student-block-label">' + (LABELS[type] || type) + '</span>' +
                    '<button type="button" class="reflsub-student-block-remove" aria-label="Remove this block">&times; Remove</button>' +
                '</div>' + body;

            // Remove handler is wired via event delegation on the container below,
            // so it works for both JS-built and server-rendered (edit-mode) blocks.

            return block;
        }

        // Delegated Remove-button handler — covers server-rendered blocks too.
        container.addEventListener('click', function(e) {
            var btn = e.target.closest('.reflsub-student-block-remove');
            if (!btn || !container.contains(btn)) return;
            var block = btn.closest('.reflsub-student-block');
            if (block) block.remove();
        });

        palette.querySelectorAll('.reflsub-student-add-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var block = buildBlock(btn.dataset.blockType);
                container.appendChild(block);
                // Wire up the drag-drop zone if this is an image block.
                var zone = block.querySelector('.reflsub-drop-zone');
                if (zone && typeof window.reflsubSetupDropZone === 'function') {
                    window.reflsubSetupDropZone(zone);
                }
                var first = block.querySelector('textarea, input[type="url"]');
                if (first) first.focus();
            });
        });

        // JSON.stringify that preserves non-ASCII as real UTF-8 instead of
        // \uXXXX escapes — keeps PHP's wp_unslash from eating the backslash.
        function jsonStringifyUtf8(data) {
            return JSON.stringify(data).replace(/\\u([0-9a-fA-F]{4})/g, function(_, hex) {
                return String.fromCharCode(parseInt(hex, 16));
            });
        }

        form.addEventListener('submit', function() {
            var blocks = [];
            container.querySelectorAll('.reflsub-student-block').forEach(function(el) {
                var type = el.dataset.type;
                var id   = parseInt(el.dataset.id, 10);
                var b    = { id: id, type: type };
                if (type === 'text') {
                    b.content = (el.querySelector('.reflsub-student-text')  || {}).value || '';
                } else if (type === 'video') {
                    b.content = (el.querySelector('.reflsub-student-video') || {}).value || '';
                } else if (type === 'embed') {
                    b.content = (el.querySelector('.reflsub-student-embed') || {}).value || '';
                }
                // image / pdf blocks carry no JSON content — files arrive via $_FILES
                blocks.push(b);
            });
            hidden.value = jsonStringifyUtf8(blocks);
        });
    })();
    </script>
    <?php

    return ob_get_clean();
}
