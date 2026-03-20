<?php
/**
 * ACF Field Group — Reflection Page Settings
 *
 * Registers the "Reflection Page Settings" field group programmatically.
 * Uses the same field keys and names as the ePortfolio theme so page data
 * is fully portable between sites running either the plugin or the theme.
 *
 * Skips registration gracefully if the ePortfolio theme is active (it owns
 * the same field group in that case and there is no conflict).
 *
 * Requires Advanced Custom Fields (free or Pro).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'acf/init', 'reflsub_register_acf_fields' );
function reflsub_register_acf_fields() {

    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    // If the ePortfolio theme is active it registers the identical group — defer to it.
    if ( function_exists( 'eportfolio_register_acf_fields' ) ) return;

    // Shared conditional logic: only show when the master toggle is on.
    $when_enabled = array(
        array(
            array(
                'field'    => 'field_is_reflection_page',
                'operator' => '==',
                'value'    => '1',
            ),
        ),
    );

    acf_add_local_field_group( array(
        'key'    => 'group_eportfolio_reflection',
        'title'  => 'Reflection Page Settings',
        'fields' => array(

            // ── Master toggle ────────────────────────────────────────────────
            array(
                'key'           => 'field_is_reflection_page',
                'label'         => 'Reflection Submission Page',
                'name'          => 'is_reflection_page',
                'type'          => 'true_false',
                'instructions'  => 'Enable to turn this page into a student submission form.',
                'required'      => 0,
                'ui'            => 1,
                'ui_on_text'    => 'Enabled',
                'ui_off_text'   => 'Disabled',
                'default_value' => 0,
            ),

            // ── Prompts ──────────────────────────────────────────────────────
            array(
                'key'               => 'field_reflection_prompt_1',
                'label'             => 'Prompt 1',
                'name'              => 'reflection_prompt_1',
                'type'              => 'textarea',
                'instructions'      => 'The first question students will respond to.',
                'required'          => 1,
                'rows'              => 3,
                'new_lines'         => 'br',
                'conditional_logic' => $when_enabled,
            ),
            array(
                'key'               => 'field_reflection_prompt_2',
                'label'             => 'Prompt 2 (optional)',
                'name'              => 'reflection_prompt_2',
                'type'              => 'textarea',
                'instructions'      => 'Leave blank to hide this prompt.',
                'required'          => 0,
                'rows'              => 3,
                'new_lines'         => 'br',
                'conditional_logic' => $when_enabled,
            ),
            array(
                'key'               => 'field_reflection_prompt_3',
                'label'             => 'Prompt 3 (optional)',
                'name'              => 'reflection_prompt_3',
                'type'              => 'textarea',
                'instructions'      => 'Leave blank to hide this prompt.',
                'required'          => 0,
                'rows'              => 3,
                'new_lines'         => 'br',
                'conditional_logic' => $when_enabled,
            ),

            // ── Submission privacy ───────────────────────────────────────────
            array(
                'key'               => 'field_submission_privacy',
                'label'             => 'Submission Privacy',
                'name'              => 'submission_privacy',
                'type'              => 'select',
                'instructions'      => 'Controls the visibility of posts created from this page.',
                'required'          => 1,
                'choices'           => array(
                    'publish' => 'Published — live on the student\'s author archive immediately',
                    'private' => 'Private — visible only to the student and admins',
                    'pending' => 'Pending Review — held for instructor approval',
                ),
                'default_value'     => 'publish',
                'return_format'     => 'value',
                'conditional_logic' => $when_enabled,
            ),

            // ── Media options ────────────────────────────────────────────────
            array(
                'key'               => 'field_allow_image_upload',
                'label'             => 'Allow Image Upload',
                'name'              => 'allow_image_upload',
                'type'              => 'true_false',
                'instructions'      => 'Show an image file input on the form. Uploaded image becomes the post\'s featured image.',
                'required'          => 0,
                'ui'                => 1,
                'default_value'     => 0,
                'conditional_logic' => $when_enabled,
            ),
            array(
                'key'               => 'field_allow_video_url',
                'label'             => 'Allow Video URL',
                'name'              => 'allow_video_url',
                'type'              => 'true_false',
                'instructions'      => 'Show a video URL input (YouTube / Vimeo). Embedded in the post automatically.',
                'required'          => 0,
                'ui'                => 1,
                'default_value'     => 0,
                'conditional_logic' => $when_enabled,
            ),
            array(
                'key'               => 'field_allow_embed',
                'label'             => 'Allow Embed Code',
                'name'              => 'allow_embed',
                'type'              => 'true_false',
                'instructions'      => 'Show an embed code textarea on the form. Students can paste Kaltura, YouTube, Vimeo, or any &lt;iframe&gt; embed code.',
                'required'          => 0,
                'ui'                => 1,
                'default_value'     => 0,
                'conditional_logic' => $when_enabled,
            ),

            // ── Resubmission ─────────────────────────────────────────────────
            array(
                'key'               => 'field_allow_resubmission',
                'label'             => 'Allow Multiple Submissions',
                'name'              => 'allow_resubmission',
                'type'              => 'true_false',
                'instructions'      => 'If disabled, a student who has already submitted sees their existing post instead of the form.',
                'required'          => 0,
                'ui'                => 1,
                'default_value'     => 0,
                'conditional_logic' => $when_enabled,
            ),

            // ── Content type label ────────────────────────────────────────────
            array(
                'key'               => 'field_content_type_label',
                'label'             => 'Content Type Tag',
                'name'              => 'content_type_label',
                'type'              => 'text',
                'instructions'      => 'Term auto-assigned to submissions for archive filtering. Requires the content-type taxonomy. Default: Reflection.',
                'required'          => 0,
                'placeholder'       => 'Reflection',
                'conditional_logic' => $when_enabled,
            ),

        ), // end fields

        'location' => array(
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'page',
                ),
            ),
        ),

        'menu_order'            => 10,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'active'                => true,
    ) );
}
