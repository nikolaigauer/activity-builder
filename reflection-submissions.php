<?php
/**
 * Plugin Name:       Reflection Submissions
 * Description:       Frontend reflection submission form for course pages. Instructors configure prompts via ACF; students submit via [reflection_form] shortcode. Requires Advanced Custom Fields.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       reflection-submissions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'REFLSUB_VERSION', '1.0.0' );
define( 'REFLSUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'REFLSUB_URL', plugin_dir_url( __FILE__ ) );


// ── Content-type taxonomy ──────────────────────────────────────────────────────
// Guard: if the ePortfolio theme or hub has already registered this taxonomy, skip.

add_action( 'init', 'reflsub_register_content_type_taxonomy', 0 );
function reflsub_register_content_type_taxonomy() {
    if ( taxonomy_exists( 'content-type' ) ) return;
    register_taxonomy( 'content-type', array( 'post' ), array(
        'labels' => array(
            'name'          => 'Content Types',
            'singular_name' => 'Content Type',
            'add_new_item'  => 'Add New Content Type',
            'edit_item'     => 'Edit Content Type',
        ),
        'hierarchical'      => true,
        'public'            => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_nav_menus' => true,
        'show_in_rest'      => true,
        'rewrite'           => array( 'slug' => 'content-type' ),
        'capabilities'      => array(
            'manage_terms' => 'edit_posts',
            'edit_terms'   => 'edit_posts',
            'delete_terms' => 'edit_posts',
            'assign_terms' => 'edit_posts',
        ),
    ) );
}


// ── Term seeding ───────────────────────────────────────────────────────────────

function reflsub_seed_default_terms() {
    if ( ! taxonomy_exists( 'content-type' ) ) return;
    if ( ! term_exists( 'reflection', 'content-type' ) ) {
        wp_insert_term( 'Reflection', 'content-type', array( 'slug' => 'reflection' ) );
    }
}

// One-time seed for sites where the plugin is activated on an existing install.
add_action( 'init', function () {
    if ( get_option( 'reflsub_terms_seeded' ) ) return;
    reflsub_seed_default_terms();
    update_option( 'reflsub_terms_seeded', '1' );
}, 2 ); // priority 2 — after taxonomy registration at priority 0


// ── Activation / deactivation ──────────────────────────────────────────────────

register_activation_hook( __FILE__, 'reflsub_activate' );
function reflsub_activate() {
    reflsub_register_content_type_taxonomy();
    reflsub_seed_default_terms();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );


// ── Module loader ──────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'reflsub_load_modules' );
function reflsub_load_modules() {
    $modules = array(
        'reflection-form',  // [reflection_form] shortcode + init submission handler
        'admin-page',       // Top-level menu + Submissions submenu + toolbar item
        'page-builder',     // Reflection Pages list + section-based page builder
        'progress',         // Student × task progress grid
        'post-form',        // Student-facing New Post form with tags
        'feedback',         // Instructor feedback view + student My Submissions page
        'setup',            // Site initialisation wizard (parent pages + navigation menus)
    );
    foreach ( $modules as $module ) {
        $file = REFLSUB_DIR . 'inc/' . $module . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
