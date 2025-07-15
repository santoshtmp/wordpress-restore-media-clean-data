<?php


// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 
 */
class RMCD_Admin_Settings {

    public $restore_media;
    public static $rmcd_admin_page_slug = 'download-media';


    public function __construct($object_restore_media) {
        add_action('admin_menu', [$this, 'rmcd_add_admin_menu']);
        add_filter('plugin_action_links_' . RMCD_PLUGIN_BASENAME, [$this, 'rmcd_admin_settings_link']);
        $this->restore_media = $object_restore_media;
    }

    /**
     * Add admin menu for Download Media
     * 
     * This function adds a submenu page under the Media section in the WordPress admin dashboard.
     * It allows users to access the media download functionality.
     */
    public function rmcd_add_admin_menu() {

        // Top-level menu
        add_menu_page(
            'Clean Data & Media',                // Page title
            'Clean Data & Media',                // Menu title
            'manage_options',             // Capability
            self::$rmcd_admin_page_slug,             // Slug — match with first submenu
            [$this, 'rmcd_download_check_media_admin_page'], // Callback             
            'dashicons-download',         // Icon
            25
        );

        // // First submenu — same slug as top-level, so it becomes the main page
        // add_submenu_page(
        //     'download-media',             // Parent slug (matches top menu)
        //     'Download Media',             // Page title
        //     'Download Media',             // Submenu title
        //     'manage_options',             // Capability
        //     'download-media',             // Slug — same as top menu
        //     [$this->admin_settings, 'rmcd_download_check_media_admin_page'], // Callback
        //     // Callback
        // );

        // // Additional submenu (optional)
        // add_submenu_page(
        //     'download-media',
        //     'Clean Data',
        //     'Clean Data',
        //     'manage_options',
        //     'clean-post-media-data',
        //     [$this->clean_data, 'rmcd_clean_data_admin_page'], // Callback for another function
        // );
    }

    /**
     * Get the URL for the settings page
     *
     * @return string The URL for the settings page
     */
    public static function rmcd_get_settings_page_url() {
        return 'admin.php?page=' . self::$rmcd_admin_page_slug;
    }

    // Hook into the plugin action links filter
    public function rmcd_admin_settings_link($links) {
        // Create the settings link
        $settings_link = '<a href="' . self::rmcd_get_settings_page_url() . '">Settings</a>';
        // Append the link to the existing links array
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * 
     */
    public function rmcd_download_check_media_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="rmcd-page wrap">';
        echo '<h1>Restore/Check Media and Clean Data</h1>';

        $this->rmcd_bulk_restore_process();
        $this->rmcd_single_restore_process();
        $this->rmcd_check_media_process();
        $this->rmcd_clean_post_type();
        $this->rmcd_delete_old_posts_before_date();


        echo '</div>';
    }

    /**
     * Handle the download of a single media file
     * 
     * This function generates a form that allows users to enter a remote media URL
     * and download it to the current server.
     */
    public function rmcd_single_restore_process() {

        echo '<div class="download-single-media">';
        echo '<h2>Restore - Download Single Media</h2>';
        echo '<form method="post">';
        wp_nonce_field('dmedia_single_nonce_action');
        $remote_url = isset($_POST['remote_download_url']) ? sanitize_text_field(wp_unslash($_POST['remote_download_url'])) : '';
        echo '<input type="url" name="remote_download_url" id="remote_download_url" value="' . $remote_url . '" placeholder="Enter remote media URL" class="page-number-input" required style=" min-width: 70%;">';
        submit_button('Download Media', 'primary', 'rmcd_single_restore_process');
        echo '</form>';

        if (isset($_POST['rmcd_single_restore_process']) && check_admin_referer('dmedia_single_nonce_action')) {
            $remote_url = isset($_POST['remote_download_url']) ? sanitize_text_field(wp_unslash($_POST['remote_download_url'])) : '';
            if ($remote_url) {
                // $parsed_url = parse_url($remote_url);
                $filename = basename($remote_url);
                $url_path = parse_url($remote_url, PHP_URL_PATH);
                $relative_path = dirname($url_path) . '/' . $filename;
                $local_path = ABSPATH .  $relative_path;
                $local_dir = dirname($local_path);
                if (!file_exists($local_dir)) {
                    mkdir($local_dir, 0755, true);
                }
                $status = $this->restore_media->rmcd_download_media_with_curl($remote_url, $local_path);
                if ($status['status']) {
                    echo '<p>Media downloaded successfully: <a href="' . home_url($relative_path) . '">' . esc_html(basename($local_path)) . '</a></p>';
                } else {
                    echo '<p>Error downloading media: ' . esc_html($status['msg']) . '</p>';
                }
            } else {
                echo '<p>Please enter a valid remote media URL.</p>';
            }
        }
        echo '</div>'; // Close download-single-media div
    }

    /**
     * Display form to download media in bulk
     * 
     * This function generates a form that allows users to specify the starting page number
     * for downloading media files in bulk from the specified origin URL.
     */
    public function rmcd_bulk_restore_process() {
        $page_number = 0;
        $host_url = isset($_POST['host_url']) ? sanitize_text_field(wp_unslash($_POST['host_url'])) : '';
        $skip_unused_media = 'checked';
        $retry_download = 0;
        $log_title = 'Download Media Log';
        if (isset($_POST['re_try_failed_dmedia_download']) && check_admin_referer('rmcd_nonce_action')) {
            $retry_download = 1;
            $log_title = 'Re-try Download Media Log';
        }
        if (
            (isset($_POST['start_dmedia_download']) || isset($_POST['re_try_failed_dmedia_download'])) &&
            check_admin_referer('rmcd_nonce_action')
        ) {
            $skip_unused_media = (isset($_POST['skip_unused_media']) && $_POST['skip_unused_media'] == '1') ? 'checked' : '';
        }
        //
        echo '<div class="bulk-restore-media">';
        echo '<h2>Bulk Restore - Download Media </h2>';

        $last_download_log_info = $this->restore_media->get_last_download_log_info($this->restore_media::$download_log_file);
        $download_in_progress = false;
        if ($last_download_log_info && is_array($last_download_log_info)) {
            if ($last_download_log_info['page_number'] * 50 == $last_download_log_info['download_number']) {
                $page_number = $next_start_page_number = $last_download_log_info['page_number'] + 1;
                $remote_url = $last_download_log_info['remote_url'];
                $host_url = parse_url($remote_url, PHP_URL_SCHEME) . '://' . parse_url($remote_url, PHP_URL_HOST) . '/';
                echo '
                <div class="download-log-info" style="margin-bottom: 20px;">
                <p>Last Downloaded Info:</p>
                <ul>
                <li> Download index: <strong>' . $last_download_log_info['download_number'] . '</strong> </li>
                <li> Media ID: <strong>' . $last_download_log_info['media_id'] . '</strong><li>
                <li> Page Number: <strong>' . $last_download_log_info['page_number'] . '</strong> </li>
                <li> Message: <strong>' . $last_download_log_info['message'] . '</strong></li>
                </ul>
                <p> Now start downloading media from page number <strong>' . $next_start_page_number . '</strong> </p>
                </div>
                ';
            } else {
                $download_in_progress = true;
                echo '
                <p>Download is in progress.....</p>
                <p>Visit after some time, If it take too log time then please check download log.</p>
                ';
            }
        } else {
            echo '<p>No media downloaded yet.</p>';
        }
        $page_number = isset($_POST['page_number']) ? $_POST['page_number'] :  $page_number;

        if (!$download_in_progress) {

            echo '<form method="post">';
            wp_nonce_field('rmcd_nonce_action');
            echo '
            <table class="fields-table">
                <tr>
                    <th scope="row">
                        <label for="host_url">Domain URL</label>
                    </th>
                    <td>
                        <input type="url" name="host_url" id="host_url" value="' . $host_url . '" placeholder="Enter host url" class="host-url-input" required style=" min-width: 70%;">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="page_number">Start Page Number</label>
                    </th>
                    <td>
                        <input type="number" name="page_number" id="page_number" value="' . $page_number . '" min="1" class="page-number-input" placeholder="">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="skip_unused_media">Skip Unused Media </label>
                    </th>
                    <td>
                        <input type="checkbox" name="skip_unused_media" id="skip_unused_media" value="1" ' . $skip_unused_media . '>
                    </td>
                </tr>
            </table>
            ';
            echo '<input type="hidden" name="retry_download" id="retry_download" value="' . $retry_download . '">';
            echo '<div class="download-media-btn-wrapper">';
            submit_button('Start Download Media', 'primary', 'start_dmedia_download');
            if (file_exists($this->restore_media::$download_error_log_file) && filesize($this->restore_media::$download_error_log_file) > 0) {
                submit_button('Re-try Failed Download Media', 'primary', 're_try_failed_dmedia_download');
            }
            echo '</div>';
            echo '</form>';
        }

        if (
            (isset($_POST['start_dmedia_download']) || isset($_POST['re_try_failed_dmedia_download'])) &&
            check_admin_referer('rmcd_nonce_action')
        ) {
            echo '
            <div class="start_dmedia_download">
                <ul id="download_media_list" class="loading_list_wrapper" >
                    <li>' . $log_title . '</li>
                    <li> ================= </li>
                </ul>
                <p id="dmedia-loading-more">Loading ... </p>
            </div>
            ';
        }
        echo '</div>'; // Close bulk-restore-media divß
    }

    /**
     * Display form to check used and unused media files
     * 
     * This function generates a form that allows users to check for used and unused media files
     * in the WordPress media library. It provides an input field for the page number to start checking.
     */
    public function rmcd_check_media_process() {
        $check_media_pnumber = isset($_POST['check_media_pnumber']) ? $_POST['check_media_pnumber'] : 0;
        $delete_unused_checked = (isset($_POST['delete_unused_media']) && $_POST['delete_unused_media'] == '1') ? 'checked' : '';


        echo '<div class="used-unused-media">';
        echo '<h2>Check Used and Unused Media</h2>';
        echo '<p>Check the media library for used and unused media files.</p>';
        $last_media_check_log_info = $this->restore_media->get_last_media_check_log_info($this->restore_media::$media_check_log_file);
        if ($last_media_check_log_info && is_array($last_media_check_log_info)) {
            $check_media_pnumber = $next_pnum = $last_media_check_log_info['page_number'] + 1;
            echo '
            <div class="download-log-info" style="margin-bottom: 20px;">
            <p>Last Media Check Info:</p>
            <ul>
            <li> Media Index: <strong>' . $last_media_check_log_info['media_index'] . '</strong><li>
            <li> Media ID: <strong>' . $last_media_check_log_info['media_id'] . '</strong><li>
            <li> Page Number: <strong>' . $last_media_check_log_info['page_number'] . '</strong> </li>
            <li>Status: <strong>' . $last_media_check_log_info['status'] . '</strong></li>
            </ul>
            <p> Now start checking media from page number <strong>' . $next_pnum . '</strong> </p>
            </div>
            ';
        } else {
            echo '<p>No media checked yet.</p>';
        }
        echo '<form method="post">';
        wp_nonce_field('check_used_unused_media');
        echo '
        <p>
            <label for="check_media_pnumber">Start Page Number</label>
            <input type="number" name="check_media_pnumber" id="check_media_pnumber" value="' . $check_media_pnumber . '" min="1" class="page-number-input" placeholder="">
        </p>
        <p>
            <label for="delete_unused_media"> Delete unused media after checking </label>
            <input type="checkbox" name="delete_unused_media" id="delete_unused_media" value="1" ' . $delete_unused_checked . '>
        </p>
        ';
        submit_button('Check Media', 'primary', 'check_media');
        echo '</form>';
        if (isset($_POST['check_media']) && check_admin_referer('check_used_unused_media')) {
            echo '<ul id="check-media-wrapper-list" class="loading_list_wrapper" style=""> 
            <ul id="check_used_unused_media_list" style="word-wrap: break-word;"></ul>
            </ul>
            <p id="check_used_unused_media_loading_more">Loading ... </p>';
        }
        echo '</div>'; // Close used-unused-media div
    }

    /**
     * Display form to clean post types
     * 
     * This function generates a form that allows users to select post types
     * for which they want to delete drafts and trashed posts.
     */
    public function rmcd_clean_post_type() {
        echo '<h2>Clean Post Types Data</h2>';
        echo '<form method="post">';
        wp_nonce_field('clean_post_type_nonce_action');
        echo '<p class="description">This will delete all drafts and trashed posts for all post type. It will check 50 item per page and process the delete.</p>';
        echo "<p>Start Page number</p>";
        $clean_post_type_page_number = isset($_POST['clean_post_type_page_number']) ? $_POST['clean_post_type_page_number'] : 0;
        echo '<input type="number" name="clean_post_type_page_number" id="clean_post_type_page_number" value="' . $clean_post_type_page_number . '" min="1" class="page-number-input" placeholder="">';

        submit_button('Clean Post Type', 'primary', 'old_clean_post_type_submit');
        echo '</form>';

        if (isset($_POST['old_clean_post_type_submit']) && check_admin_referer('clean_post_type_nonce_action')) {
            echo '
            <div class="clean_post_type_data">
                <ul id="clean_post_type_data_list">
                    <li>Clean Post Type Log</li>
                    <li> ================= </li>
                </ul>
                <p id="clean_post_type_data_more">Loading ... </p>
            </div>
            ';
        }
    }


    /**
     * Delete old posts before a specific date
     * 
     * This function deletes all posts of specified post types that were created before a given date.
     * It is useful for cleaning up old content that is no longer relevant.
     */
    function rmcd_delete_old_posts_before_date() {

        $old_year_delete = isset($_POST['old_year_delete']) ? $_POST['old_year_delete'] : '2015';
        $old_date_page_number = isset($_POST['old_date_page_number']) ? $_POST['old_date_page_number'] : 0;

        echo '<h2>Clean By Date and page number</h2>';
        echo '<form method="post">';
        wp_nonce_field('old_clean_post_type_nonce_action');
        echo "<p>Delecte old posts before " . $old_year_delete . '</p>';
        echo '<input type="number" name="old_year_delete" id="old_year_delete" value="' . $old_year_delete . '" min="2010" class="page-number-input" placeholder="">';
        echo "<p>Start Page number</p>";
        echo '<input type="number" name="old_date_page_number" id="old_date_page_number" value="' . $old_date_page_number . '" min="1" class="page-number-input" placeholder="">';
        submit_button('Clean Old Data', 'primary', 'clean_post_type_submit');
        echo '</form>';

        if (isset($_POST['clean_post_type_submit']) && check_admin_referer('old_clean_post_type_nonce_action')) {
            echo '
            <div class="start_data_clean">
                <ul id="start_data_clean_list">
                    <li>Delete Log</li>
                    <li> ================= </li>
                </ul>
                <p id="data-clean-loading-more">Loading ... </p>
            </div>
            ';
        }
    }



    /**
     * 
     */
}
