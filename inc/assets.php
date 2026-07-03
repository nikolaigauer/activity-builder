<?php
/**
 * Asset registration and enqueueing
 *
 * Single home for the plugin's CSS/JS plumbing. Stylesheets live in
 * assets/css/ and are registered here once, on both the admin and front-end
 * hooks, then enqueued by handle from wherever they are needed:
 *
 *  - reflsub-tokens          design tokens (--rs-*) — dependency of the rest
 *  - reflsub-admin           all plugin wp-admin screens
 *  - reflsub-form            front-end [reflection_form] output + prompt labels
 *  - reflsub-audio-recorder  shared recorder widget (front-end + admin)
 *
 * Never inline <style>/<script> in shortcode output: it passes through
 * the_content filters (wptexturize) which can corrupt it — see the 2026-06-04
 * externalization of reflection-form.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// Version string for a bundled asset: its file mtime, so browsers always pick up
// edits (and deploys), falling back to the plugin version if the file is missing.
function reflsub_asset_ver( $relpath ) {
    $full = REFLSUB_DIR . ltrim( $relpath, '/' );
    return file_exists( $full ) ? (string) filemtime( $full ) : REFLSUB_VERSION;
}


// ── Registration (both contexts) ──────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'reflsub_register_styles', 1 );
add_action( 'admin_enqueue_scripts', 'reflsub_register_styles', 1 );
function reflsub_register_styles() {
    wp_register_style(
        'reflsub-tokens',
        REFLSUB_URL . 'assets/css/tokens.css',
        array(),
        reflsub_asset_ver( 'assets/css/tokens.css' )
    );
    wp_register_style(
        'reflsub-admin',
        REFLSUB_URL . 'assets/css/admin.css',
        array( 'reflsub-tokens' ),
        reflsub_asset_ver( 'assets/css/admin.css' )
    );
    wp_register_style(
        'reflsub-form',
        REFLSUB_URL . 'assets/css/form.css',
        array( 'reflsub-tokens' ),
        reflsub_asset_ver( 'assets/css/form.css' )
    );
    wp_register_style(
        'reflsub-audio-recorder',
        REFLSUB_URL . 'assets/css/audio-recorder.css',
        array( 'reflsub-tokens' ),
        reflsub_asset_ver( 'assets/css/audio-recorder.css' )
    );
}


// ── Admin screens ──────────────────────────────────────────────────────────────
// Every plugin screen's hook suffix contains either the top-level slug
// ("activity-builder") or a "reflsub-" submenu slug, so one substring check
// covers the whole menu tree without maintaining a slug list.

add_action( 'admin_enqueue_scripts', 'reflsub_admin_enqueue_styles' );
function reflsub_admin_enqueue_styles( $hook ) {
    if ( strpos( $hook, 'reflsub' ) === false && strpos( $hook, 'activity-builder' ) === false ) {
        return;
    }
    wp_enqueue_style( 'reflsub-admin' );
}


// ── Front-end form styles ──────────────────────────────────────────────────────
// Enqueued early (prints in <head>, no unstyled flash) when the queried page
// will render the form OR render post content that contains the static
// .reflsub-prompt-label markup. Submitted posts carry those prompt labels in
// their content, so ANY view that outputs post content needs form.css — not just
// single posts. That includes author/portfolio archives and the blog/home feed
// (the ePortfolio theme renders full post content on /author/ and /portfolio/).
// The render functions still call reflsub_enqueue_form_assets() as a late
// fallback for pages this detection misses (styles then print in the footer).

add_action( 'wp_enqueue_scripts', 'reflsub_maybe_enqueue_form_style' );
function reflsub_maybe_enqueue_form_style() {
    // is_archive() covers author/category/tag/date/post-type archives; is_home()
    // covers the blog posts index. Together with single posts, this loads the
    // prompt-label styling everywhere post content can appear.
    if ( is_singular( 'post' ) || is_author() || is_home() || is_archive() ) {
        wp_enqueue_style( 'reflsub-form' );
        return;
    }
    if ( is_page() ) {
        $page = get_queried_object();
        if ( $page instanceof WP_Post && has_shortcode( (string) $page->post_content, 'reflection_form' ) ) {
            wp_enqueue_style( 'reflsub-form' );
        }
    }
}


// ── Front-end form JS + styles (called from inside the render functions) ──────
// Loaded as real assets (NOT inlined in shortcode output) so they never pass
// through the_content / wptexturize — which would corrupt && into &#038;&#038;.
// Both render paths share the one element-guarded JS file.

function reflsub_enqueue_form_assets() {
    // Shared audio recorder widget (also used by the New Post builder).
    reflsub_enqueue_audio_recorder();

    wp_enqueue_style( 'reflsub-form' );

    if ( wp_script_is( 'reflsub-reflection-form', 'enqueued' ) ) {
        return;
    }
    wp_enqueue_script(
        'reflsub-reflection-form',
        REFLSUB_URL . 'assets/js/reflection-form.js',
        array( 'reflsub-audio-recorder' ), // recorder widget loads first
        reflsub_asset_ver( 'assets/js/reflection-form.js' ),
        true // in footer — runs after the form HTML is in the DOM
    );
    wp_localize_script( 'reflsub-reflection-form', 'reflsubForm', array(
        'postMaxBytes' => (int) wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ),
    ) );
}

// Enqueue the shared audio recorder widget (JS + CSS). Safe to call repeatedly;
// reused by both the activity form and the New Post builder (admin).
function reflsub_enqueue_audio_recorder() {
    if ( ! wp_script_is( 'reflsub-audio-recorder', 'enqueued' ) ) {
        wp_enqueue_script(
            'reflsub-audio-recorder',
            REFLSUB_URL . 'assets/js/audio-recorder.js',
            array(),
            reflsub_asset_ver( 'assets/js/audio-recorder.js' ),
            true
        );
    }
    wp_enqueue_style( 'reflsub-audio-recorder' );
}
