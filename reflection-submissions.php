<?php
/**
 * Plugin Name:       Activity Builder
 * Plugin URI:        https://github.com/nikolaigauer/reflection-submissions
 * Description:       ePortfolio reflection plugin for higher education. Instructors build structured prompt pages via a section-based builder; students submit responses via the [reflection_form] shortcode. No external dependencies.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Nikolai Gauer
 * Author URI:        https://github.com/nikolaigauer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       activity-builder
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


// ── Activation / deactivation ──────────────────────────────────────────────────

register_activation_hook( __FILE__, 'reflsub_activate' );
function reflsub_activate() {
    reflsub_register_content_type_taxonomy();
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
