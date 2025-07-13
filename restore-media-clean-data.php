<?php

/**
 * Plugin Name: Restore Media & Clean Data
 * Description: Cleans old/unused WordPress data and restores missing media library files to current location from remote wp site.
 * Version: 1.0.0
 * Author: santoshtmp7
 * Text Domain: restore-media-clean-data
 * Domain Path: restore-media-clean-data
 */



// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// define constant named
define('RMCD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RMCD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RMCD_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include the main class file
class RMCD_CleanRepairData {

    public $restore_media;
    public $clean_data;
    public $admin_settings;

    //
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'rmcd_admin_enqueue_scripts']);
        // 
        require_once RMCD_PLUGIN_DIR . 'class-admin-settings.php';
        require_once RMCD_PLUGIN_DIR . 'class-restore-media.php';
        require_once RMCD_PLUGIN_DIR . 'class-clean-data.php';
        //
        $this->restore_media = new RMCD_RestoreMedia();
        $this->clean_data = new RMCD_CleanData();
        $this->admin_settings = new RMCD_Admin_Settings($this->restore_media);
    }

    /**
     * Enqueue admin scripts for Download Media
     * 
     * This function enqueues the JavaScript file for the admin area of the Download Media plugin.
     * It also localizes the script with necessary data for AJAX requests.
     */
    public function rmcd_admin_enqueue_scripts() {
        wp_enqueue_style(
            "rmcd-admin-style",
            RMCD_PLUGIN_URL . 'assets/css/rmcd-admin.css',
            [],
            filemtime(RMCD_PLUGIN_DIR . 'assets/css/rmcd-admin.css')
        );
        wp_enqueue_script(
            'rmcd-admin-script',
            RMCD_PLUGIN_URL . 'assets/js/rmcd-admin.js',
            ['jquery'],
            filemtime(RMCD_PLUGIN_DIR . 'assets/js/rmcd-admin.js'),
            array(
                'in_footer' => true,
                'strategy' => 'defer',
            )
        );
        wp_localize_script('rmcd-admin-script', 'rmcdAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'action_restoreMedia' => 'rmcd_ajaxActionRestoreMedia',
            'action_cleanPostType' => 'rmcd_ajaxActionCleanPostType',
            'action_cleanByDate' => 'rmcd_ajaxActionCleanByDate',
            'action_mediaCheck' => 'rmcd_ajaxActionMediaCheck',
            'nonce'    => wp_create_nonce('rmcd_nonce'),
        ]);
    }

  
}
new RMCD_CleanRepairData();


/**
 * Filter to move generated image files to a new directory structure.
 * This function moves the generated image files to a 'generated' subdirectory
 * while maintaining the original year/month structure.
 * 
 * https://developer.wordpress.org/reference/functions/wp_generate_attachment_metadata/
 * 
 * @param array $metadata The attachment metadata.
 * @param int $attachment_id The attachment ID.
 * @return array The updated metadata with new file paths.
 */
add_filter('wp_generate_attachment_metadata', function ($metadata, $attachment_id) {
    $upload_dir = wp_upload_dir();
    $original_file = get_attached_file($attachment_id);
    $original_dir = dirname($original_file);
    $original_filename = wp_basename($original_file);

    // Extract the original year/month path from the file
    $relative_path = str_replace($upload_dir['basedir'] . '/', '', $original_dir);
    $generated_dir = $upload_dir['basedir'] . '/generated/' . $relative_path;

    if (!empty($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size => $size_data) {
            $old_file_path = $original_dir . '/' . $size_data['file'];
            $new_file_path = $generated_dir . '/' . $size_data['file'];

            // Ensure the target directory exists
            wp_mkdir_p($generated_dir);

            if (file_exists($old_file_path)) {
                rename($old_file_path, $new_file_path);
                // Update metadata path to reflect new location (relative to uploads)
                $metadata['sizes'][$size]['file'] = 'generated/' . $relative_path . '/' . $size_data['file'];
            }
        }
    }

    return $metadata;
}, 10, 2);


/**
 * Filter to update the image URL in the attachment metadata.
 * This ensures that the image URLs point to the correct location after moving files.
 * 
 * https://developer.wordpress.org/reference/functions/wp_get_attachment_image_src/
 *
 * @param array $image The image data array.
 * @param int $attachment_id The attachment ID.
 * @param string $size The size of the image.
 * @return array The updated image data array.
 */
add_filter('wp_get_attachment_image_src', function ($image, $attachment_id, $size) {
    if (!is_array($image)) {
        return $image;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata) || !isset($metadata['sizes']) || !is_array($metadata['sizes'])) {
        return $image;
    }

    $upload_dir = wp_upload_dir();

    // Handle string size (e.g., 'medium', 'large')
    if (is_string($size)) {
        if (!isset($metadata['sizes'][$size]['file'])) {
            return $image;
        }

        // Update to custom path
        $relative_file = $metadata['sizes'][$size]['file'];
        $image[0] = trailingslashit($upload_dir['baseurl'])  . $relative_file;

        return $image;
    }

    // Handle array size (e.g., [800, 600])
    // if (is_array($size) && count($size) === 2) {
    //     $width = $size[0];
    //     $height = $size[1];

    //     foreach ($metadata['sizes'] as $info) {
    //         if (
    //             isset($info['width'], $info['height'], $info['file']) &&
    //             intval($info['width']) === intval($width) &&
    //             intval($info['height']) === intval($height)
    //         ) {
    //             $relative_file = $info['file'];
    //             $image[0] = trailingslashit($upload_dir['baseurl'])  . $relative_file;

    //             return $image;
    //         }
    //     }

    //     // No exact size match found — return default
    //     return $image;
    // }

    // Unknown size format — just return original
    return $image;
}, 10, 3);
