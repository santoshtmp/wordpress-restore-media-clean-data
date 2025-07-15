<?php


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
    // $original_filename = wp_basename($original_file);
    $media_generated = 'media-generated';
    // Extract the original year/month path from the file
    $relative_path = str_replace($upload_dir['basedir'] . '/', '', $original_dir);
    $generated_dir = $upload_dir['basedir'] . '/' . $media_generated . '/' . $relative_path;

    if (!empty($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $size => $size_data) {
            $old_file_path = $original_dir . '/' . $size_data['file'];
            $new_file_path = $generated_dir . '/' . $size_data['file'];

            // Ensure the target directory exists
            wp_mkdir_p($generated_dir);

            if (file_exists($old_file_path)) {
                rename($old_file_path, $new_file_path);
                // Update metadata path to reflect new location (relative to uploads)
                // $metadata['sizes'][$size]['file'] = $relative_path . '/' . $size_data['file'];
            }
        }
    }

    return $metadata;
}, 10, 2);


add_filter('wp_get_attachment_image_src', 'custom_media_generated_image_src', 10, 3);
add_filter('wp_calculate_image_srcset', 'custom_media_generated_srcset', 10, 5);
add_filter('wp_get_attachment_url', 'custom_media_generated_attachment_url', 10, 2);
add_filter('wp_prepare_attachment_for_js', 'custom_media_generated_js_url', 10, 3);
// add_filter('image_downsize', 'custom_media_generated_image_downsize', 10, 3);

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
function custom_media_generated_image_src($image, $attachment_id, $size) {
    if (!is_array($image)) {
        return $image;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!is_array($metadata) || !isset($metadata['sizes']) || !is_array($metadata['sizes'])) {
        return $image;
    }

    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit($upload_dir['basedir']);
    $base_url   = trailingslashit($upload_dir['baseurl']);
    $generated_path = 'media-generated/' . dirname($metadata['file']);

    // Handle 'full' (original image)
    if ($size === 'full') {
        $filename = basename($metadata['file']);
        $generated_file = $base_dir . $generated_path . '/' . $filename;

        if (file_exists($generated_file)) {
            $image[0] = $base_url . $generated_path . '/' . $filename;
        }

        return $image;
    }

    // Handle named sizes like 'medium', 'large'
    if (is_string($size) && isset($metadata['sizes'][$size])) {
        $relative_file = $metadata['sizes'][$size]['file'];
        $generated_file = $base_dir . $generated_path . '/' . basename($relative_file);

        if (file_exists($generated_file)) {
            $image[0] = $base_url . $generated_path . '/' . basename($relative_file);
        } else {
            if (file_exists($base_dir . dirname($metadata['file']) . '/' . basename($relative_file))) {
                $image[0] = $base_url . dirname($metadata['file']) . '/' . basename($relative_file);
            } else if (isset($metadata['file'])) {
                $image[0] = $base_url . dirname($metadata['file']) . '/' . basename($metadata['file']);
            } else if (isset($metadata['original_image'])) {
                $image[0] = $base_url . dirname($metadata['file']) . '/' . basename($metadata['original_image']);
            }
        }

        return $image;
    }

    // Handle array sizes [width, height]
    if (is_array($size) && count($size) === 2) {
        $width = intval($size[0]);
        $height = intval($size[1]);

        foreach ($metadata['sizes'] as $info) {
            if (
                isset($info['width'], $info['height'], $info['file']) &&
                intval($info['width']) === $width &&
                intval($info['height']) === $height
            ) {
                $relative_file = $info['file'];
                $generated_file = $base_dir . $generated_path . '/' . basename($relative_file);

                if (file_exists($generated_file)) {
                    $image[0] = $base_url . $generated_path . '/' . basename($relative_file);
                } else {

                    if (file_exists($base_dir . dirname($metadata['file']) . '/' . basename($relative_file))) {
                        $image[0] = $base_url . dirname($metadata['file']) . '/' . basename($relative_file);
                    } else if (isset($metadata['file'])) {
                        $image[0] = $base_url . dirname($metadata['file']) . '/' . basename($metadata['file']);
                    } else if (isset($metadata['original_image'])) {
                        $image[0] = $base_url . dirname($metadata['file']) . '/' . basename($metadata['original_image']);
                    }
                }

                return $image;
            }
        }
    }

    return $image;
}

/**
 * Fix srcset URLs to point to media-generated path.
 * 
 * https://developer.wordpress.org/reference/functions/wp_calculate_image_srcset/
 * 
 */
function custom_media_generated_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit($upload_dir['basedir']);
    $base_url   = trailingslashit($upload_dir['baseurl']);

    if (!isset($image_meta['file']) || empty($image_meta['sizes'])) {
        return $sources;
    }

    $generated_path = 'media-generated/' . dirname($image_meta['file']);
    $default_path   = dirname($image_meta['file']);

    foreach ($sources as $key => $source) {
        $filename = wp_basename($source['url']);
        if (file_exists($base_dir . $generated_path . '/' . $filename)) {
            $sources[$key]['url'] = $base_url . $generated_path . '/' . $filename;
        } elseif (file_exists($base_dir . $default_path . '/' . $filename)) {
            $sources[$key]['url'] = $base_url . $default_path . '/' . $filename;
        } elseif (isset($image_meta['file'])) {
            $sources[$key]['url'] = $base_url . $default_path . '/' . basename($image_meta['file']);
        } elseif (isset($image_meta['original_image'])) {
            $sources[$key]['url'] = $base_url . $default_path . '/' . basename($image_meta['original_image']);
        }
    }

    return $sources;
}




/**
 * Filter get_attachment_url to return media-generated if available.
 */
function custom_media_generated_attachment_url($url, $attachment_id) {
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!$metadata || empty($metadata['file'])) return $url;

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']);
    $base_url = trailingslashit($upload_dir['baseurl']);
    $generated_path = 'media-generated/' . dirname($metadata['file']);
    $filename = basename($metadata['file']);
    $generated_file = $base_dir . $generated_path . '/' . $filename;

    if (file_exists($generated_file)) {
        return $base_url . $generated_path . '/' . $filename;
    }

    return $url;
}

/**
 * Replace media library thumbnail in JS with media-generated.
 */
function custom_media_generated_js_url($response, $attachment, $meta) {
    if (!isset($meta['file'])) return $response;

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']);
    $base_url = trailingslashit($upload_dir['baseurl']);
    $generated_path = 'media-generated/' . dirname($meta['file']);

    if (isset($response['sizes']) && is_array($response['sizes'])) {
        foreach ($response['sizes'] as $size_key => &$size_info) {
            $filename = basename($size_info['url']);
            $generated_file = $base_dir . $generated_path . '/' . $filename;

            if (file_exists($generated_file)) {
                $size_info['url'] = $base_url . $generated_path . '/' . $filename;
            }
        }
    }

    return $response;
}

/**
 * Override image_downsize to return generated image.
 * https://developer.wordpress.org/reference/functions/image_downsize/
 */
// function custom_media_generated_image_downsize($out, $id, $size) {
//     $meta = wp_get_attachment_metadata($id);
//     if (!$meta || !isset($meta['file'])) return false;

//     $upload_dir = wp_upload_dir();
//     $base_dir = trailingslashit($upload_dir['basedir']);
//     $base_url = trailingslashit($upload_dir['baseurl']);
//     $generated_path = 'media-generated/' . dirname($meta['file']);

//     // Try to find exact size
//     if (is_string($size) && isset($meta['sizes'][$size]['file'])) {
//         $filename = $meta['sizes'][$size]['file'];
//     } elseif (is_array($size)) {
//         foreach ($meta['sizes'] as $info) {
//             if (
//                 intval($info['width']) === intval($size[0]) &&
//                 intval($info['height']) === intval($size[1])
//             ) {
//                 $filename = $info['file'];
//                 break;
//             }
//         }
//     } else {
//         $filename = basename($meta['file']);
//     }

//     if (!isset($filename)) return false;

//     $file_path = $base_dir . $generated_path . '/' . $filename;
//     if (file_exists($file_path)) {
//         $image_url = $base_url . $generated_path . '/' . $filename;
//         $size_data = getimagesize($file_path);

//         if ($size_data) {
//             return [
//                 $image_url,
//                 intval($size_data[0]),
//                 intval($size_data[1]),
//                 true
//             ];
//         }
//     }

//     return false;
// }
