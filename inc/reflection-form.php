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
            '<h3>%s</h3><p>%s</p>',
            esc_html( $prompt_1 ),
            nl2br( esc_html( $response_1 ) )
        );
    } elseif ( $response_1 ) {
        $content_parts[] = '<p>' . nl2br( esc_html( $response_1 ) ) . '</p>';
    }

    if ( $prompt_2 && $response_2 ) {
        $content_parts[] = sprintf(
            '<h3>%s</h3><p>%s</p>',
            esc_html( $prompt_2 ),
            nl2br( esc_html( $response_2 ) )
        );
    } elseif ( $response_2 ) {
        $content_parts[] = '<p>' . nl2br( esc_html( $response_2 ) ) . '</p>';
    }

    if ( $prompt_3 && $response_3 ) {
        $content_parts[] = sprintf(
            '<h3>%s</h3><p>%s</p>',
            esc_html( $prompt_3 ),
            nl2br( esc_html( $response_3 ) )
        );
    } elseif ( $response_3 ) {
        $content_parts[] = '<p>' . nl2br( esc_html( $response_3 ) ) . '</p>';
    }

    // Video embed appended to content
    if ( $video_url ) {
        $embed = wp_oembed_get( $video_url );
        $content_parts[] = $embed
            ? $embed
            : '<p><a href="' . esc_url( $video_url ) . '">' . esc_html( $video_url ) . '</a></p>';
    }

    // Raw embed code (Kaltura, iFrame, etc.) appended to content
    if ( $embed_code ) {
        $content_parts[] = $embed_code;
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

    // ── Content-type taxonomy ─────────────────────────────────────────────────
    if ( taxonomy_exists( 'content-type' ) ) {
        $ct_label = trim( get_post_meta( $page_id, 'content_type_label', true ) ) ?: 'Reflection';
        $term = term_exists( $ct_label, 'content-type' );

        if ( ! $term ) {
            $term = wp_insert_term( $ct_label, 'content-type' );
        }

        if ( ! is_wp_error( $term ) ) {
            $term_id = is_array( $term ) ? $term['term_id'] : $term;
            wp_set_post_terms( $post_id, array( intval( $term_id ) ), 'content-type', true );
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
    $image_sec_idx  = -1; // section index of the image upload, or -1 if none
    $pdf_sec_idx    = -1; // section index of the PDF upload, or -1 if none
    $prompt_meta    = array(); // i => raw response text (stored as meta after post creation)
    $mcq_meta       = array(); // i => sanitized selected options array
    $video_meta     = '';
    $embed_meta     = '';

    foreach ( $sections as $i => $sec ) {
        $type = $sec['type'] ?? '';

        if ( $type === 'prompt' ) {
            $response = sanitize_textarea_field( wp_unslash( $_POST[ 'section_response_' . $i ] ?? '' ) );
            $prompt_meta[ $i ] = $response;
            if ( $response !== '' ) {
                $has_content = true;
                $label = $sec['label'] ?? '';
                $ordered_parts[ $i ] = $label
                    ? sprintf( '<h3>%s</h3><p>%s</p>', esc_html( $label ), nl2br( esc_html( $response ) ) )
                    : '<p>' . nl2br( esc_html( $response ) ) . '</p>';
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
                    ? '<h4>' . esc_html( $question ) . '</h4><ul>' . $items_html . '</ul>'
                    : '<ul>' . $items_html . '</ul>';
            }
        }

        if ( $type === 'image' ) {
            $img_names = $_FILES['section_image']['name'] ?? array();
            if ( is_array( $img_names ) && ! empty( $img_names[0] ) ) {
                $has_content   = true;
                $image_sec_idx = $i;
                $ordered_parts[ $i ] = null; // placeholder — filled after post creation
            }
        }

        if ( $type === 'video' ) {
            $url = esc_url_raw( trim( wp_unslash( $_POST['section_video'] ?? '' ) ) );
            if ( $url ) {
                $has_content = true;
                $video_meta  = $url;
                $oembedded   = wp_oembed_get( $url );
                $ordered_parts[ $i ] = $oembedded
                    ? $oembedded
                    : '<p><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
            }
        }

        if ( $type === 'embed' ) {
            $code = reflsub_sanitize_embed_code( wp_unslash( $_POST['section_embed'] ?? '' ) );
            if ( $code ) {
                $has_content       = true;
                $embed_meta        = $code;
                $ordered_parts[ $i ] = $code;
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
            $pdf_name = $_FILES['section_pdf']['name'] ?? '';
            if ( $pdf_name !== '' && ( $_FILES['section_pdf']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
                if ( ( $_FILES['section_pdf']['size'] ?? 0 ) <= 15 * 1024 * 1024 ) {
                    $has_content = true;
                    $pdf_sec_idx = $i;
                    $ordered_parts[ $i ] = null; // placeholder — filled after post creation
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

    // Create new post or update existing
    if ( $edit_post_id ) {
        $result = wp_update_post( array(
            'ID'           => $edit_post_id,
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
            'post_title'   => get_the_title( $page_id ),
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

    // Image upload — resolve placeholder at the correct section position
    if ( $image_sec_idx >= 0 ) {
        $uploaded_ids = reflsub_upload_multiple_images( 'section_image', $post_id );
        if ( ! empty( $uploaded_ids ) ) {
            $image_block = reflsub_build_image_block( $uploaded_ids );
            if ( $image_block ) {
                $ordered_parts[ $image_sec_idx ] = $image_block;
            }
        }
    }

    // PDF upload — resolve placeholder at the correct section position
    if ( $pdf_sec_idx >= 0 ) {
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

    // Reassemble all parts in section order now that uploads are resolved
    ksort( $ordered_parts );
    $final_parts   = array_filter( $ordered_parts ); // drop any remaining nulls (failed uploads)
    $final_content = implode( "\n\n", $final_parts );
    if ( $final_content !== $post_content ) {
        wp_update_post( array( 'ID' => $post_id, 'post_content' => $final_content ) );
    }

    // Content-type taxonomy — tag as Reflection
    if ( taxonomy_exists( 'content-type' ) ) {
        $ct_label = 'Reflection';
        $term     = term_exists( $ct_label, 'content-type' );
        if ( ! $term ) {
            $term = wp_insert_term( $ct_label, 'content-type' );
        }
        if ( ! is_wp_error( $term ) ) {
            $term_id = is_array( $term ) ? $term['term_id'] : $term;
            wp_set_post_terms( $post_id, array( intval( $term_id ) ), 'content-type', true );
        }
    }

    // Auto-tags from sections
    if ( ! empty( $auto_tags ) ) {
        wp_set_post_tags( $post_id, $auto_tags, true );
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

        <form class="reflection-form" method="post"<?php echo $form_enctype; ?> data-page-id="<?php echo esc_attr( $page_id ); ?>">

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
        var draftKey = form ? 'reflsub_draft_' + form.dataset.pageId : null;
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

    // Determine if the form needs multipart (image or PDF upload present)
    $has_upload_section = false;
    foreach ( $sections as $sec ) {
        $t = $sec['type'] ?? '';
        if ( $t === 'image' || $t === 'pdf' ) {
            $has_upload_section = true;
            break;
        }
    }
    $form_enctype = $has_upload_section ? ' enctype="multipart/form-data"' : '';

    // ── Pre-fill values for edit mode ──────────────────────────────────────────
    $prefill = array();
    if ( $edit_post_id ) {
        foreach ( $sections as $i => $sec ) {
            $t = $sec['type'] ?? '';
            if ( $t === 'prompt' ) {
                $prefill[ $i ] = get_post_meta( $edit_post_id, '_reflsub_response_' . $i, true );
            } elseif ( $t === 'mcq' ) {
                $raw           = get_post_meta( $edit_post_id, '_reflsub_mcq_' . $i, true );
                $prefill[ $i ] = $raw ? (array) json_decode( $raw, true ) : array();
            } elseif ( $t === 'video' ) {
                $prefill[ $i ] = get_post_meta( $edit_post_id, '_reflection_video_url', true );
            } elseif ( $t === 'embed' ) {
                $prefill[ $i ] = get_post_meta( $edit_post_id, '_reflection_embed', true );
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

        <form class="reflection-form" method="post"<?php echo $form_enctype; ?> data-page-id="<?php echo esc_attr( $page_id ); ?>">

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
            ?>

            <?php if ( $type === 'prompt' ) :
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

            <?php elseif ( $type === 'image' ) : ?>
            <div class="reflection-field">
                <label>Upload Image(s)</label>
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
                <p class="reflection-hint">JPEG, PNG, GIF, WebP · Max 15 MB per file · Multiple images will display as a gallery.</p>
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
            ?>
            <div class="reflection-field">
                <label for="section_pdf">
                    Upload PDF / File
                    <?php if ( $pdf_required ) : ?><span style="color:#d63638;" aria-label="required">*</span><?php endif; ?>
                </label>
                <input type="file"
                       id="section_pdf"
                       name="section_pdf"
                       accept=".pdf,application/pdf"
                       <?php if ( $pdf_required ) : ?>required<?php endif; ?>>
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
                       placeholder="Comma-separated tags…">
                <p class="reflection-hint">Add tags to help categorize your post.</p>
            </div>

            <?php endif; ?>

            <?php endforeach; ?>

            <div class="reflection-submit">
                <button type="submit" class="wp-element-button">
                    <?php echo $edit_post_id ? 'Update Submission' : 'Submit'; ?>
                </button>
            </div>

        </form>
    </div>

    <style>
        .reflection-form-wrap { max-width: 720px; margin: 2rem 0; }
        .reflection-intro { margin-bottom: 1.75rem; font-size: 1rem; line-height: 1.7; color: #3c434a; }
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
        .reflection-form input[type="file"] { display: block; margin-bottom: 0.4rem; }
        .reflection-hint { margin: 0.3rem 0 0; font-size: 0.85rem; color: #646970; }

        /* ── Drop zone ─────────────────────────────────────────────── */
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
        var draftKey = form ? 'reflsub_draft_' + form.dataset.pageId : null;

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
        document.querySelectorAll('.reflsub-drop-zone').forEach(function(zone) {
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
                    // Reset the input so the same file can be re-added after removal
                    input.value = '';
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
        });
    })();
    </script>
    <?php

    return ob_get_clean();
}
