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
        $this->check_generate_log_files();
        // 
        require_once RMCD_PLUGIN_DIR . 'class-admin-settings.php';
        require_once RMCD_PLUGIN_DIR . 'class-restore-media.php';
        require_once RMCD_PLUGIN_DIR . 'class-clean-data.php';
        require_once RMCD_PLUGIN_DIR . 'class-custom-media-path.php';
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

    /**
     * 
     */
    public function check_generate_log_files() {
        $log_files = [
            RMCD_RestoreMedia::$download_log_file,
            RMCD_RestoreMedia::$download_error_log_file,
            RMCD_RestoreMedia::$media_check_log_file,
            RMCD_RestoreMedia::$media_check_unused_log_file,
            RMCD_CleanData::$delete_log_file,
        ];

        foreach ($log_files as $file) {
            // Create the parent directory if it doesn't exist
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            // Create the log file if it doesn't exist
            if (!file_exists($file)) {
                file_put_contents($file, ""); // create empty file
            }
        }
    }
}
new RMCD_CleanRepairData();
