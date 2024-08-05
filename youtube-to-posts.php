<?php
/*
Plugin Name: YouTube to WordPress Posts
Description: A plugin to fetch YouTube videos and create WordPress posts from them.
Version: 1.0
Author: Andrew Shelton
*/


//Modified from original | add enviromental variables and sanitization


// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register activation hook to schedule the cron job
register_activation_hook(__FILE__, 'ytp_activate_cron_job');
function ytp_activate_cron_job() {
    if (!wp_next_scheduled('ytp_fetch_event')) {
        wp_schedule_event(time(), 'daily', 'ytp_fetch_event');
    }
}

// Register deactivation hook to clear the cron job
register_deactivation_hook(__FILE__, 'ytp_deactivate_cron_job');
function ytp_deactivate_cron_job() {
    wp_clear_scheduled_hook('ytp_fetch_event');
}

// Hook the function to our custom cron event
add_action('ytp_fetch_event', 'ytp_fetch_and_create_posts');

// Include the main functionality file
require_once(plugin_dir_path(__FILE__) . 'includes/youtube-fetch.php');

// Admin page setup
add_action('admin_menu', 'ytp_admin_menu');
function ytp_admin_menu() {
    add_menu_page('YouTube to Posts', 'YouTube to Posts', 'manage_options', 'ytp-settings', 'ytp_settings_page', 'dashicons-video-alt3');
    add_submenu_page('ytp-settings', 'Manual Fetch', 'Manual Fetch', 'manage_options', 'ytp-manual-fetch', 'ytp_manual_fetch_page');
}

function ytp_settings_page() {
    ?>
    <div class="wrap">
        <h1>YouTube to Posts Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ytp_settings_group');
            do_settings_sections('ytp-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function ytp_manual_fetch_page() {
    ?>
    <div class="wrap">
        <h1>Manual Fetch</h1>
        <p>Click the button below to manually fetch and create posts from YouTube videos.</p>
        <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
            <input type="hidden" name="action" value="fetch_youtube_videos">
            <?php submit_button('Fetch YouTube Videos'); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'ytp_admin_init');
function ytp_admin_init() {
    register_setting('ytp_settings_group', 'ytp_api_key');
    register_setting('ytp_settings_group', 'ytp_channel_id');

    add_settings_section('ytp_settings_section', 'YouTube API Settings', 'ytp_settings_section_callback', 'ytp-settings');

    add_settings_field('ytp_api_key', 'API Key', 'ytp_api_key_callback', 'ytp-settings', 'ytp_settings_section');
    add_settings_field('ytp_channel_id', 'Channel ID', 'ytp_channel_id_callback', 'ytp-settings', 'ytp_settings_section');
}

function ytp_settings_section_callback() {
    echo 'Enter your YouTube API settings below:';
}

function ytp_api_key_callback() {
    $api_key = get_option('ytp_api_key');
    echo '<input type="text" name="ytp_api_key" value="' . esc_attr($api_key) . '" />';
}

function ytp_channel_id_callback() {
    $channel_id = get_option('ytp_channel_id');
    echo '<input type="text" name="ytp_channel_id" value="' . esc_attr($channel_id) . '" />';
}

// Handle AJAX request to fetch YouTube videos
add_action('wp_ajax_fetch_youtube_videos', 'ytp_fetch_youtube_videos');
function ytp_fetch_youtube_videos() {
    ytp_fetch_and_create_posts();
    wp_die(); // End the AJAX request properly
}
