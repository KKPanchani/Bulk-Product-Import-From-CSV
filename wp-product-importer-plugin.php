<?php
/**
 * Plugin Name: WP Product Importer
 * Description: A plugin to import products from a CSV file with both scheduled and manual options.
 * Version: 1.0.0
 * Author: Krishna Panchani
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-product-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-image-uploader.php';
// require_once plugin_dir_path(__FILE__) . 'includes/class-wp-csv-handler.php';
// Initialize the plugin
WP_Admin_Settings::init();
WP_Product_Importer::init();
// WP_Image_Uploader::init();

// // Schedule Cron Event
// register_activation_hook(__FILE__, 'wp_product_importer_activate');
// register_deactivation_hook(__FILE__, 'wp_product_importer_deactivate');

// function wp_product_importer_activate() {
//     if (!wp_next_scheduled('wp_product_importer_cron_job')) {
//         wp_schedule_event(time(), 'daily', 'wp_product_importer_cron_job');
//     }
// }

// function wp_product_importer_deactivate() {
//     wp_clear_scheduled_hook('wp_product_importer_cron_job');
// }

// // Add Cron Job Action
// add_action('wp_product_importer_cron_job', 'wp_product_importer_cron_job_function');

// function wp_product_importer_cron_job_function() {
//     WP_Product_Importer::import_product();
// }
// register_deactivation_hook(__FILE__, 'wp_product_importer_deactivate');

function wp_product_importer_deactivate() {
    $timestamp = wp_next_scheduled('wp_product_import_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wp_product_import_cron');
    }
}