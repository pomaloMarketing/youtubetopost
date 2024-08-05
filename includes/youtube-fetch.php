<?php

function ytp_fetch_and_create_posts() {
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $api_key = getenv('YTP_API_KEY');
    $channel_id = getenv('YTP_CHANNEL_ID');

    if (empty($api_key) || empty($channel_id)) {
        error_log('ytp_fetch_and_create_posts: Missing API key or Channel ID');
        return;
    }

    // Convert July 15, 2024 to ISO 8601 format
    $date_after = '2024-07-15T00:00:00Z';

    $search_api_url = "https://www.googleapis.com/youtube/v3/search?key={$api_key}&channelId={$channel_id}&part=snippet,id&order=date&maxResults=8";
    $search_response = wp_remote_get($search_api_url);

    if (is_wp_error($search_response)) {
        error_log('ytp_fetch_and_create_posts: Error fetching YouTube data: ' . $search_response->get_error_message());
        return;
    }

    $search_body = wp_remote_retrieve_body($search_response);
    error_log('ytp_fetch_and_create_posts: Search API Response: ' . $search_body);
    $search_data = json_decode($search_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('ytp_fetch_and_create_posts: Error decoding JSON response: ' . json_last_error_msg());
        return;
    }

    if (!empty($search_data['items'])) {
        foreach ($search_data['items'] as $item) {
            if ($item['id']['kind'] == 'youtube#video') {
                $video_id = $item['id']['videoId'];
                $title = $item['snippet']['title'];
                $thumbnail_url = $item['snippet']['thumbnails']['high']['url'];
                $published_at = $item['snippet']['publishedAt'];

                // Check if the video is published after July 15, 2024
                if ($published_at <= $date_after) {
                    error_log('ytp_fetch_and_create_posts: Skipping video ID: ' . $video_id . ' because it is before the cutoff date');
                    continue;
                }

                // Check if the post already exists
                $existing_post = get_posts(array(
                    'post_type'  => 'post',
                    'meta_key'   => 'youtube_video_id',
                    'meta_value' => $video_id,
                    'post_status' => 'any'
                ));

                if ($existing_post) {
                    error_log('ytp_fetch_and_create_posts: Post already exists for video ID: ' . $video_id);
                    continue;
                }

                $video_api_url = "https://www.googleapis.com/youtube/v3/videos?key={$api_key}&part=snippet&id={$video_id}";
                $video_response = wp_remote_get($video_api_url);

                if (is_wp_error($video_response)) {
                    error_log('ytp_fetch_and_create_posts: Error fetching video details: ' . $video_response->get_error_message());
                    continue;
                }

                $video_body = wp_remote_retrieve_body($video_response);
                error_log('ytp_fetch_and_create_posts: Video API Response: ' . $video_body);
                $video_data = json_decode($video_body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('ytp_fetch_and_create_posts: Error decoding video details JSON response: ' . json_last_error_msg());
                    continue;
                }

                if (!empty($video_data['items'][0])) {
                    $description = $video_data['items'][0]['snippet']['description'];
                    $iframe_content = '<iframe width="560" height="315" src="https://www.youtube.com/embed/' . $video_id . '" frameborder="0" allowfullscreen></iframe>';
                    error_log('ytp_fetch_and_create_posts: Iframe content: ' . $iframe_content);

                    $post_id = wp_insert_post(array(
                        'post_title'    => $title,
                        'post_content'  => $description,
                        'post_status'   => 'draft',
                        'post_type'     => 'post',
                        'post_author'   => 1,
                        'post_category' => array(get_cat_ID('Podcast'))
                    ));

                    if ($post_id) {
                        error_log('ytp_fetch_and_create_posts: Post created with ID: ' . $post_id);

                        update_field('youtube_iframe', $iframe_content, $post_id);
                        update_post_meta($post_id, 'youtube_video_id', $video_id);

                        $image_set = ytp_set_featured_image($post_id, $thumbnail_url);
                        if (!$image_set) {
                            error_log('ytp_fetch_and_create_posts: Failed to set featured image for post ID: ' . $post_id);
                        }
                    } else {
                        error_log('ytp_fetch_and_create_posts: Failed to create post for video ID: ' . $video_id);
                    }
                } else {
                    error_log('ytp_fetch_and_create_posts: No detailed video data found for video ID: ' . $video_id);
                }
            } else {
                error_log('ytp_fetch_and_create_posts: Item is not a video: ' . print_r($item, true));
            }
        }
    } else {
        error_log('ytp_fetch_and_create_posts: No video items found in response');
    }

    error_log('ytp_fetch_and_create_posts: Finished post fetch process');
}

function ytp_set_featured_image($post_id, $image_url) {
    error_log('ytp_set_featured_image: Attempting to set featured image for post ID: ' . $post_id . ' with image URL: ' . $image_url);

    $image_name = basename($image_url);
    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($image_url);
    if (!$image_data) {
        error_log('ytp_set_featured_image: Failed to download image from URL: ' . $image_url);
        return false;
    }

    $unique_file_name = wp_unique_filename($upload_dir['path'], $image_name);
    $filename = basename($unique_file_name);

    if (wp_mkdir_p($upload_dir['path'])) {
        $file = $upload_dir['path'] . '/' . $filename;
    } else {
        $file = $upload_dir['basedir'] . '/' . $filename;
    }

    file_put_contents($file, $image_data);

    $wp_filetype = wp_check_filetype($filename, null);

    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title' => sanitize_file_name($filename),
        'post_content' => '',
        'post_status' => 'inherit'
    );

    $attach_id = wp_insert_attachment($attachment, $file, $post_id);

    if (is_wp_error($attach_id)) {
        error_log('ytp_set_featured_image: Failed to insert attachment for post ID: ' . $post_id);
        return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $attach_data = wp_generate_attachment_metadata($attach_id, $file);
    wp_update_attachment_metadata($attach_id, $attach_data);

    set_post_thumbnail($post_id, $attach_id);

    error_log('ytp_set_featured_image: Successfully set featured image for post ID: ' . $post_id);
    return true;
}

?>
