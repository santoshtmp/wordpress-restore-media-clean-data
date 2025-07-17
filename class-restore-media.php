<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Download Media Class
 * 
 * This class provides functionality to download media files from a remote URL to the current server.
 * It includes methods for downloading media in bulk, downloading single media files, and cleaning up unused
 * media files from the WordPress media library.
 */
class RMCD_RestoreMedia {

    /**
     * Remote URL to download media from
     * 
     * @var string
     */

    public static $download_log_file = RMCD_PLUGIN_DIR . '/log/download_log.txt';
    public static $download_error_log_file = RMCD_PLUGIN_DIR . '/log/download_error_log.txt';
    public static $media_check_log_file = RMCD_PLUGIN_DIR . '/log/media_check_log.txt';
    public static $media_check_unused_log_file = RMCD_PLUGIN_DIR . '/log/media_check_unused_log.txt';


    public function __construct() {
        add_action('wp_ajax_rmcd_ajaxActionRestoreMedia', [$this, 'rmcd_ajaxActionRestoreMedia']);
        add_action('wp_ajax_rmcd_ajaxActionMediaCheck', [$this, 'rmcd_ajaxActionMediaCheck']);
    }

    /**
     * AJAX handler to download media files
     * 
     * @return void
     */
    function rmcd_ajaxActionRestoreMedia() {

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        // Verify _nonce
        // if (!isset($_POST['_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_nonce'])), 'dmedia_fields_row')) {
        //     echo "Session timeout";
        //     wp_die();
        // }

        $data_wp_send = [];
        $retry_download = isset($_POST['retry_download']) ? $_POST['retry_download'] : false;
        $host_url = isset($_POST['host_url']) ? sanitize_text_field(wp_unslash($_POST['host_url'])) : '';
        $skip_unused_media = isset($_POST['skip_unused_media']) ? (int)$_POST['skip_unused_media'] : 1;
        $page_number = isset($_POST['page_number']) ? $_POST['page_number'] : 1;
        //
        if ($retry_download) {
            $per_page = 1;
            $download_failed_media = [];
            $remaining_lines = [];

            // Read file and extract all Media IDs
            $file = new SplFileObject(self::$download_error_log_file, 'r');
            $count = 0;
            while (!$file->eof()) {
                $line = trim($file->fgets());
                if ($line == '') {
                    continue;
                }
                if ($count >= $per_page) {
                    $remaining_lines[] = $line;
                } else {
                    $download_failed_media[] = $line;
                    $count++;
                }
            }

            // Overwrite the file with the remaining lines
            file_put_contents(self::$download_error_log_file, implode(" \n", $remaining_lines));
            $remaining_lines = [];
            //
            if (count($download_failed_media) > 0) {
                foreach ($download_failed_media as $key => $failed_line) {
                    $line_info = $this->get_download_log_line_info($failed_line);
                    $host_url = parse_url($line_info['remote_url'], PHP_URL_SCHEME) . '://' . parse_url($line_info['remote_url'], PHP_URL_HOST) . '/';
                    $data_wp_send[] = $this->rmcd_media_id_download_process(
                        $line_info['media_id'],
                        $host_url,
                        $line_info['download_number'],
                        $line_info['page_number'],
                        $skip_unused_media,
                        $retry_download
                    );
                }
            }
        } else {

            if (empty($host_url)) {
                wp_send_json_error('Please provide a valid host URL.', 400);
            }
            // Fetch all media attachments
            $total_downloaded = 0;
            $posts_per_page = 1;

            if ($page_number > 1) {
                $total_downloaded = $posts_per_page * ($page_number - 1);
            }
            // if ($page_number == 1) {
            //     file_put_contents(self::$download_error_log_file, '');
            //     file_put_contents(self::$download_log_file, '');
            // }

            $args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $posts_per_page,
                'paged'          => $page_number,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
            ];

            $media_ids = get_posts($args);

            if (!empty($media_ids)) {
                foreach ($media_ids as $media_id) {
                    $total_downloaded++;
                    $data_wp_send[] = $this->rmcd_media_id_download_process(
                        $media_id,
                        $host_url,
                        $total_downloaded,
                        $page_number,
                        $skip_unused_media
                    );
                }
            }
        }

        wp_send_json($data_wp_send);

        // Always die in functions echoing AJAX content
        wp_die();
    }

    /**
     * Process the download of a single media file
     * 
     * This function handles the actual downloading of a media file, checks if it is already downloaded,
     * and logs the download status.
     * 
     * @param int $media_id ID of the media attachment to download
     * @param string $host_url Base URL of the remote server
     * @param int $total_downloaded Total number of media files downloaded so far
     * @param int $page Current page number for pagination
     * @param bool $re_try_download_error Whether to re-try downloading a previously failed media file
     */
    public function rmcd_media_id_download_process($media_id, $host_url, $total_downloaded, $page, $skip_unused_media = 1, $re_try_download_error = false) {

        try {
            $media_meta = [];
            $url = wp_get_attachment_url($media_id);
            $filename = basename($url);
            $url_path = parse_url($url, PHP_URL_PATH);
            $relative_path = dirname($url_path) . '/' . $filename;
            $local_path = ABSPATH .  $relative_path;
            $local_dir = dirname($local_path);
            if (!file_exists($local_dir)) {
                mkdir($local_dir, 0755, true);
            }
            $remote_url = rtrim($host_url, '/') . $relative_path;

            $log_entry = ($re_try_download_error) ? 'Re-Try Download Error' : 'Download';
            $log_entry .= " #$total_downloaded :: Media ID: $media_id :: remote_url: $remote_url :: Page Number: $page ";

            if ($re_try_download_error || !file_exists($local_path)) {
                $status_used = true;
                if ($skip_unused_media) {
                    $check_info = $this->rmcd_check_media_attached_to_post($media_id);
                    $status_used = $check_info['status_used'];
                }
                if ($status_used) {
                    $status = $this->rmcd_download_media_with_curl($remote_url, $local_path);
                    if ($status['status']) {
                        $log_entry .= " :: Message: success ";
                    } else {
                        $log_entry .= " :: Message: Error " . $status['msg'];
                        file_put_contents(self::$download_error_log_file, $log_entry . " \n", FILE_APPEND);
                    }
                    $media_meta = wp_get_attachment_metadata($media_id);
                } else {
                    // Implemented - started from page number 193 , Download #9601
                    $log_entry .= " :: Message: Skipped as media is not used in any post ";
                }
            } else {
                $log_entry .= " :: Message: File already exists ";
                $media_meta = wp_get_attachment_metadata($media_id);
            }

            // Download original image if available
            if (isset($media_meta['original_image'])) {
                $log_entry .= " == Original Image ==  ";
                $original_image_name = basename($media_meta['original_image']);
                $relative_path = dirname($url_path) . '/' . $original_image_name;
                $local_path = ABSPATH .  $relative_path;
                $local_dir = dirname($local_path);
                if (!file_exists($local_dir)) {
                    mkdir($local_dir, 0755, true);
                }

                if ($re_try_download_error || !file_exists($local_path)) {
                    $remote_url = rtrim($host_url, '/') . $relative_path;
                    $status = $this->rmcd_download_media_with_curl($remote_url, $local_path);
                    if ($status['status']) {
                        $log_entry .= " :: Message: success URL " . $remote_url;
                    } else {
                        $log_entry .= " :: Message: Error " . $status['msg'] . ' URL ' . $remote_url;;
                        file_put_contents(self::$download_error_log_file, $log_entry . " \n", FILE_APPEND);
                    }
                } else {
                    $log_entry .= " :: Message: File already exists";
                }
            }

            $log_entry .= " \n";
            file_put_contents(self::$download_log_file, $log_entry, FILE_APPEND);

            return $log_entry;
        } catch (\Throwable $th) {
            $log_entry = "Download #$total_downloaded :: Media ID: $media_id :: remote_url:  :: Page Number: $page :: Message: Error " . $th->getMessage() . "\n";
            file_put_contents(self::$download_error_log_file, $log_entry, FILE_APPEND);
            file_put_contents(self::$download_log_file, $log_entry, FILE_APPEND);

            return $log_entry;
        }
    }

    //
    function rmcd_re_try_failed_download_media_process() {
    }

    /**
     * AJAX handler to check unused media files
     * 
     * This function checks for unused media files in the WordPress media library.
     * It requires the user to have 'manage_options' capability and verifies the nonce.
     * 
     * @return void
     */
    public function rmcd_ajaxActionMediaCheck() {

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }
        $data_wp_send = [];
        //
        $page_number = isset($_POST['page_number']) ? $_POST['page_number'] : 1;
        $delete_unused_media = isset($_POST['delete_unused_media']) ? $_POST['delete_unused_media'] : 0;
        // Fetch all media attachments
        $media_num = 0;
        $return_messgae_list = [];
        $posts_per_page = 50;

        if ($page_number > 1) {
            $media_num = $posts_per_page * ($page_number - 1);
        }
        if ($page_number == 1) {
            file_put_contents(self::$media_check_unused_log_file, '');
            file_put_contents(self::$media_check_log_file, '');
        }

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $posts_per_page,
            'paged'          => $page_number,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $media_ids = get_posts($args);

        if (!empty($media_ids)) {
            foreach ($media_ids as $media_id) {
                $media_num++;
                $log_entry = "Media #$media_num :: Media ID: $media_id :: Page Number: $page_number ";
                //
                $check_info = $this->rmcd_check_media_attached_to_post($media_id);
                // === Status message ===
                if ($check_info['status_used']) {
                    $log_entry .= " :: Status: Used ";
                } else {
                    $log_entry .=  " :: Status: Unused ";
                    if ($delete_unused_media) {
                        $status = wp_delete_attachment($media_id, true); // true = force delete
                        if ($status) {
                            $log_entry .= ":: Delete: success ";
                        } else {
                            $log_entry .= ":: Delete: Error ";
                        }
                    }

                    file_put_contents(self::$media_check_unused_log_file, $log_entry . " \n", FILE_APPEND);
                }

                $log_entry .= " \n";
                file_put_contents(self::$media_check_log_file, $log_entry, FILE_APPEND);

                $data_wp_send[] = $log_entry;
            }
        }

        // $data_wp_send = $this->rmcd_query_media_check($page_number);

        wp_send_json($data_wp_send);

        // Always die in functions echoing AJAX content
        wp_die();
    }

    /**
     * Check if media is attached to any post
     * 
     * This function checks if a media file is attached to any post, featured image, content, or meta.
     * It returns an array with the status and list of attached post IDs.
     * 
     * @param int $media_id ID of the media attachment
     * @return array Status and list of attached post IDs
     */
    function rmcd_check_media_attached_to_post($media_id) {
        global $wpdb;

        try {
            $status_used = false;

            $media_post = get_post($media_id);
            if (!$media_post || $media_post->post_type !== 'attachment') {
                return [
                    'status_used' => false,
                ];
            }

            $media_url = wp_get_attachment_url($media_id);
            $media_url = strtok($media_url, '?'); // remove any query string

            // Check if attached via post_parent
            if ($media_post->post_parent) {
                $status_used = true;
            }

            // Check if used in post content
            if (!$status_used) {
                $relative_media_url = str_replace(home_url(), '', $media_url);
                $post_ids_with_media_in_content = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM $wpdb->posts
                        WHERE (post_content LIKE %s OR post_content LIKE %s)
                        AND post_status != 'auto-draft'",
                        '%' . $wpdb->esc_like($media_url) . '%',
                        '%' . $wpdb->esc_like($relative_media_url) . '%'
                    )
                );
                if (!empty($post_ids_with_media_in_content)) {
                    $status_used = true;
                }
            }
            // Check in postmeta
            if (!$status_used) {
                $post_ids_with_media_in_meta = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT posts.ID 
                        FROM $wpdb->posts AS posts
                        JOIN $wpdb->postmeta AS postmeta ON posts.ID = postmeta.post_id
                        WHERE (postmeta.meta_value = %s OR postmeta.meta_value = %s)
                        AND posts.post_status != 'trash'
                        AND posts.post_type NOT IN ('attachment', 'revision')
                        ",
                        $media_url,
                        $media_id
                    )
                );
                if (!empty($post_ids_with_media_in_meta)) {
                    $status_used = true;
                }
            }
            // Check wp_options (e.g., ACF options, theme/plugin settings)
            if (!$status_used) {
                $option_refs = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM $wpdb->options
                        WHERE option_value = %s OR option_value = %s
                        ",
                        $media_url,
                        (string)$media_id,
                    )
                );
                if (!empty($option_refs)) {
                    $status_used = true;
                }
            }

            // Check theme mods .As this also check on wp_options table but it is used to check theme customizer settings
            if (!$status_used) {
                $theme_mods = get_theme_mods();
                $this->recursive_check_media($theme_mods, $media_id, $media_url);
            }



            return [
                'status_used' => $status_used,
            ];
        } catch (\Throwable $th) {
            return [
                'status_used' => false,
            ];
        }
    }


    /**
     * Download media file using cURL
     * 
     * @param string $url URL of the media file to download
     * @param string $save_path Local path to save the downloaded file
     * @return array Status of the download operation
     */
    function rmcd_download_media_with_curl($url, $save_path) {
        $return_data = [
            'status' => true
        ];
        try {
            $ch = curl_init($url);
            $fp = fopen($save_path, 'w+');

            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);

            $success = curl_exec($ch);

            curl_close($ch);
            fclose($fp);

            if (!$success) {
                $error = curl_error($ch);
                $return_data = [
                    'status' => false,
                    'msg' => $error
                ];
            }
        } catch (\Throwable $th) {
            //throw $th;
            $return_data = [
                'status' => false,
                'msg' => $th->getMessage()
            ];
        }
        return $return_data;
    }


    /**
     * Recursively check if media is referenced in theme mods
     * 
     * This function checks if a media ID or URL is referenced in the theme mods array.
     * It handles nested arrays and returns true if a match is found.
     * 
     * @param array $theme_mods Theme mods array to check
     * @param int|string $media_id Media ID to check
     * @param string $media_url Media URL to check
     * @return bool True if media is referenced, false otherwise
     */
    function recursive_check_media($theme_mods, $media_id, $media_url) {
        // Loop through the theme mods array
        foreach ($theme_mods as $mod_key => $mod_val) {
            // If the value is an array, recursively call the function to check nested arrays
            if (is_array($mod_val)) {
                return $this->recursive_check_media($mod_val, $media_id, $media_url);
            } else {
                // If mod_val is not an array, perform regular checks
                if ((string)$mod_val === (string)$media_id || (is_string($mod_val) && strpos($mod_val, $media_url) !== false)) {
                    return true; // Return true if a match is found
                }
            }
        }

        // Return false if no match was found
        return false;
    }

    /**
     * Get the last entry details from the given log file.
     *
     * @param string $log_file_path Full path to the log file.
     * @return array|null Parsed details or null if file is empty or unreadable.
     */
    function get_last_download_log_info($log_file_path) {
        if (!file_exists($log_file_path)) {
            return null;
        }

        $file = new SplFileObject($log_file_path, 'r');
        $file->seek(PHP_INT_MAX); // Go to the end of the file
        $last_line_number = $file->key();

        for ($i = $last_line_number; $i >= 0; $i--) {
            $file->seek($i);
            $line = trim($file->current());

            // Skip empty lines
            if ($line === '') {
                continue;
            }

            // Only match "Download #" (not "Re-Download")
            if (strpos($line, 'Download #') === 0) {
                return $this->get_download_log_line_info($line);
            }
        }

        return null;
    }

    /**
     * Parse a download log line into structured data.
     *
     * @param string $line The log line to parse.
     * @return array|null Parsed data or null if pattern doesn't match
     */
    function get_download_log_line_info($line) {
        $pattern = '/Download\s+#(?P<download_number>\d+)\s+::\s+Media ID:\s+(?P<media_id>\d+)\s+::\s+remote_url:\s+(?P<remote_url>.+?)\s+::\s+Page Number:\s+(?P<page_number>\d+)\s+::\s+Message:\s+(?P<message>.+)/';

        if (preg_match($pattern, $line, $matches)) {
            return [
                'download_number' => (int) $matches['download_number'],
                'media_id' => (int) $matches['media_id'],
                'remote_url' => $matches['remote_url'],
                'page_number' => (int) $matches['page_number'],
                'message' => $matches['message'],
            ];
        }

        return null;
    }

    /**
     * Parse a "Used Media" log entry line into structured data.
     *
     * @param string $log_file_path
     * @return array|null Parsed data or null if pattern doesn't match
     */
    function get_last_media_check_log_info($log_file_path) {

        if (!file_exists($log_file_path)) {
            return null;
        }

        $lines = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return null;
        }

        $last_line = trim(end($lines));

        $pattern = '/Media\s+#(?P<media_index>\d+)\s+::\s+Media ID:\s+(?P<media_id>\d+)\s+::\s+Page Number:\s+(?P<page_number>\d+)\s+::\s+Status:\s+(?P<status>\w+)(?:\s+::\s+Delete:\s+(?P<delete_status>\w+))?/';


        if (preg_match($pattern, $last_line, $matches)) {
            // $post_ids_array = array_map('intval', explode(',', $matches['post_ids'] ));
            return [
                'media_index' => (int) $matches['media_index'],
                'media_id' => (int) $matches['media_id'],
                'page_number' => (int) $matches['page_number'],
                'status' => $matches['status'],
                'delete_status' => $matches['delete_status'] ?? null,
            ];
        }

        return null;
    }


    //=====================================

}
