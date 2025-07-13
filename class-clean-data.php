<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class RMCD_CleanData {


    public static $delete_log_file = RMCD_PLUGIN_DIR . '/log/delete_log.txt';

    public function __construct() {
        add_action('wp_ajax_rmcd_ajaxActionCleanByDate', [$this, 'rmcd_ajaxActionCleanByDate']);
        add_action('wp_ajax_rmcd_ajaxActionCleanPostType', [$this, 'rmcd_ajaxActionCleanPostType']);
    }

    public function rmcd_ajaxActionCleanPostType() {

        $return_messgae_list = [];

        $page = isset($_POST['page_number']) ? $_POST['page_number'] : 1;
        $posts_per_page = 50;
        $delete_num = 0;

        if ($page > 1) {
            $delete_num = $posts_per_page * ($page - 1);
        }

        $clean_post_type = [];
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);
        foreach ($post_types as $key => $value) {
            $clean_post_type[] = $value->name;
        }
        $clean_post_type[] = 'revision'; // Add revision post type to clean
        $post_statuses = ['draft', 'trash', 'auto-draft', 'pending'];
        $args = [
            'post_type'      => $clean_post_type,
            'post_status'    => $post_statuses,
            'posts_per_page' => $posts_per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ];
        $posts = get_posts($args);
        foreach ($posts as $post_id) {
            $delete_num++;
            $log_entry = "Delete #$delete_num :: Post ID: $post_id :: Post Type: " . get_post_type($post_id) . " :: Page Number: $page ";
            $status =  wp_delete_post($post_id, true); // true = force delete
            if ($status) {
                $log_entry .= ' :: Message: success';
            } else {
                $log_entry .= ' :: Message: error';
            }
            $log_entry .= " \n";
            $return_messgae_list[] = $log_entry;
        }

        wp_send_json($return_messgae_list);

        // Always die in functions echoing AJAX content
        wp_die();
    }



    public function rmcd_ajaxActionCleanByDate() {
        // Check user permissions
        // if (!current_user_can('manage_options')) {
        //     wp_send_json_error('Unauthorized', 403);
        // }

        // Check nonce for security
        // if (!isset($_POST['_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_nonce'])), 'dmedia_fields_row')) {
        //     wp_send_json_error('Invalid nonce', 403);
        // }

        $page_number = isset($_POST['page_number']) ? intval($_POST['page_number']) : 1;
        $old_year_delete = isset($_POST['old_year_delete']) ? $_POST['old_year_delete'] : '2015';

        //
        $clean_post_type = [];
        $post_types = get_post_types(['public' => true], 'objects');
        foreach ($post_types as $key => $value) {
            $clean_post_type[] = $value->name;
        }
        $clean_post_type[] = 'revision';
        $cutoff_date = $old_year_delete . '-01-01 00:00:00';
        $posts_per_page = 50;
        $delete_num = 0;

        if ($page_number > 1) {
            $delete_num = $posts_per_page * ($page_number - 1);
        }
        $return_messgae_list = [];

        $args = [
            'post_type'      => $clean_post_type,
            'posts_per_page' => $posts_per_page,
            'paged'          => $page_number,  // Specify the page number
            'fields'         => 'ids',
            'date_query'     => [
                [
                    'column'   => 'post_date', // this ensures you're filtering by the published/created date
                    'before'   => $cutoff_date,
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'ASC',
        ];
        $posts = get_posts($args);

        if (!empty($posts)) {
            foreach ($posts as $post_id) {
                $delete_num++;
                $post_type = get_post_type($post_id);
                $post_date   = get_the_date('Y-m-d', $post_id); // published/created date
                $log_entry = "Delete #$delete_num :: Post ID: $post_id :: Type:$post_type :: Page Number:$page_number :: Post Date: $post_date :: Cutoff Date: $cutoff_date";
                if ($post_type == 'attachment') {
                    $status = wp_delete_attachment($post_id, true);
                } else {
                    $status = wp_delete_post($post_id, true);
                }
                if ($status) {
                    $log_entry .= " :: Message: Success ";
                } else {
                    $log_entry .= " :: Message: Error ";
                }
                $log_entry .= " \n";
                file_put_contents(self::$delete_log_file, $log_entry, FILE_APPEND);

                $return_messgae_list[] = $log_entry;
            }
        }
        // Send JSON response
        wp_send_json($return_messgae_list);
        // 
        wp_die();
    }
}
