<?php
/*
Plugin Name: JWPlayer Metadata Updater
Description: Updates JWPlayer metadata when a video post is updated. Now with deploy.
Version: 1.0.1
Author: Jeff Kaufman and Joe Diliberto
*/

// Hook to execute when a post is updated
global $old_featured_image;

add_action('pre_post_update', 'check_if_post_changed_was_video', 10, 2);
function check_if_post_changed_was_video($post_id, $data) {
    $post = get_post($post_id);
    if ($post->post_type === 'video' && $post->post_status != 'trash') { 
        get_old_featured_image($post_id, $data, $post);
    }
}
 
function get_old_featured_image($post_id, $data, $post) {
   
    global $old_featured_image;
    global $old_data;
    global $wpdb;
    $old_featured_image = get_the_post_thumbnail_url($post_id,'full');
    $content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id));
    $old_data = (object) array(
        'title' => html_entity_decode(get_the_title($post_id)),
        'description' => html_entity_decode(removeScriptTags($content)),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'publish_start_date' => $post->post_date,
        'post_status' => $post->post_status,
    );

    if (!empty($categories)) {
        $old_data->categories = get_the_category($post_id);
    }

    if (!empty($tags)) {
        $old_data->tags = get_the_tags($post_id);
    }
    
}


add_action('save_post', 'update_jwplayer_metadata', 10, 3);

// Function to update JWPlayer metadata
function update_jwplayer_metadata($post_id, $post, $update) {
    global $wpdb;
    global $old_featured_image;
    // Check if the post type is "video"
    if ($post->post_type === 'video' && $post->post_status != 'trash') {
        $locked_status = get_post_meta($post_id,'_locked_status');
        $content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id));
        if((sizeof($locked_status) == 0 || $locked_status[0] == 'editable') && $content) {
            // Get the JWPlayer video_id
            $media_id = get_media_id_from_guid($post->guid);
            // Check if a valid media ID is found
            if ($media_id && $content) {
                // Update JWPlayer metadata using the $media_id            
                update_jwplayer_metadata_by_id($media_id, $post_id, $post);
            }
        }
        else {
            error_log("JWPlayer metadata updater: " . $post_id . " is locked and can't be edited.");
        }
    }
}

// Function to extract media ID from JWPlayer GUID
function get_media_id_from_guid($guid) {
    // Extract the media ID from the GUID (assuming the format is "https://media_id")
    $matches = [];
    preg_match('/https?:\/\/(\w+)/', $guid, $matches);

    // Return the extracted media ID or false if not found
    return isset($matches[1]) ? $matches[1] : false;
}

// Function to update JWPlayer metadata by media ID
function update_jwplayer_metadata_by_id($media_id, $post_id, $post) {
    global $wpdb;
    global $old_featured_image;
    global $old_data;
    $content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id));
    $api_key = 'ipBfRBa9QPXybf9NAhUj9WInV0dod2JEZEpjRUpXYVhaT05FVlFWME15Y3pKV2NGaGEn';
    $api_url = "https://api.jwplayer.com/v2/sites/oFRcVOjV/media/{$media_id}";
    
    $headers = array(
        'Authorization' => $api_key,
        'content-type' => 'application/json',
        'accept' => 'application/json',
    );

    $update_data = (object) array(
        'title' => html_entity_decode(get_the_title($post_id)),
        'description' => html_entity_decode(removeScriptTags($content)),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'publish_start_date' => $post->post_date,
        'custom_params' => (object) array(
            'publish_state' => ($post->post_status === 'publish') ? 'Publish' : 'Unpublished (draft)',
        ),
    );
    

    $categories = get_the_category($post_id);
    if (!empty($categories)) {
        $update_data->tags = wp_list_pluck($categories, 'name');
    }

    $tags = get_the_tags($post_id);
    $tagArray = wp_list_pluck($tags, 'name');
    if (!empty($tags)) {
        if(!empty($update_data->tags)) {
            $update_data->tags = array_merge($update_data->tags,$tagArray);
        } else {
            $update_data->tags = $tagArray;
        }
    }

    $video_url = get_permalink($post_id);
    $video_link = "<$video_url |$update_data->title>";
    $request_args = array(
        'headers' => $headers,
        'body' => '{"metadata":'.wp_json_encode($update_data).'}',
        'method' => 'PATCH',
    );
    
    // Make the API request
    $response = wp_remote_post($api_url, $request_args);
    // Check for errors and handle the response as needed
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        error_log('JWPlayer metadata updater: ' .wp_json_encode($update_data). ' was updated successfully for video ID: ' . $post_id . ' jwplayer id: '. $media_id);
        // Successfully updated the metadata in JWPlayer, now update the thumbnail if it changed
        $new_featured_image = get_the_post_thumbnail_url($post_id,'full');
        if ($new_featured_image != $old_featured_image) {
            //upload the new thumbnail
            $api_url = "https://api.jwplayer.com/v2/sites/oFRcVOjV/thumbnails/";
            $request_args = array(
                'headers' => $headers,
                'body' => '{"relationships":{"media":[{"id":"'.$media_id.'"}]},"upload":{"source_type":"custom_upload","method":"fetch","thumbnail_type":"static","download_url":"'.$new_featured_image.'"}}',
                'method' => 'POST',
            );
            $response = wp_remote_post($api_url, $request_args);
            if (wp_remote_retrieve_response_code($response) === 201) {
                error_log('JWPlayer metadata updater: ' .$new_featured_image. ' was updated successfully uploaded for video ID: ' . $post_id . ' jwplayer id: '. $media_id);
                $body = json_decode($response["body"]);
                $thumbnail_id = $body->id;

                //wait for thumbnail to stop processing before attaching it to the video
                do {
                    sleep(5);
                    $api_url = "https://api.jwplayer.com/v2/sites/oFRcVOjV/thumbnails/{$thumbnail_id}/";
                    $request_args = array(
                        'headers' => $headers,
                        'method' => 'GET',
                    );
                    $response = wp_remote_post($api_url, $request_args);
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $body = json_decode($response["body"]);
                        error_log("Thumbnail ".$thumbnail_id." is ".$body->status);
                    } else {
                        send_message_to_slack("Error processing new thumbnail for ".$video_link. "."); 
                        error_log('JWPlayer metadata updater failed at thumbnail replacement for thumbnail: '.$thumbnail_id.'. Error: '.wp_remote_retrieve_response_message($response));
                        $video_process_fail = 1;
                        break;
                    }
                } while ($body->status != "ready");
                
                //make sure video processed
                if( !isset($video_process_fail)) {    
                    //attach thumbnail to the video 
                    $api_url = "https://api.jwplayer.com/v2/sites/oFRcVOjV/thumbnails/{$thumbnail_id}/";
                    $request_args = array(
                        'headers' => $headers,
                        'body' => '{"relationships":{"media":[{"is_poster":true}]}}',
                        'method' => 'PATCH',
                    );
                
                    $response = wp_remote_post($api_url, $request_args);              
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {  
                        $response_body = json_decode($response["body"]);
                        $new_url =  $response_body->delivery_url;
                        $new_thumbnail =  $response_body->id;
                        //send a message with changes made to the video
                        //send_message_to_slack("Thumbnail changed for ".$video_link." to ".$new_featured_image.".");                          
                        error_log('JWPlayer metadata updater: Thumbnail'.$thumbnail_id.' processed '.wp_remote_retrieve_response_message($response));
                    } else { 
                        send_message_to_slack("Error changing thumbnail for ".$video_link.".");  
                        error_log('JWPlayer metadata updater failed at thumbnail replacement for: '.$media_id.'. Error: '.wp_remote_retrieve_response_message($response));
                    }
                } else {
                    send_message_to_slack("Error processing new thumbnail for ".$video_link. "."); 
                    error_log('JWPlayer metadata updater failed at thumbnail processing for : '.$media_id);
                }
            } else {
                send_message_to_slack("Error uploading new thumbnail for ".$video_link. "."); 
                error_log('JWPlayer metadata updater failed at thumbnail upload for : '.$media_id.'. Error: '. wp_remote_retrieve_response_message($response));
            }
        } 
    } else { 
        // Handle errors
        send_message_to_slack("Error updating ".$video_link. "."); 
        error_log('JWPlayer metadata updater:'.wp_json_encode($api_url).'. Error: '. wp_remote_retrieve_response_message($response));
    }
}
function removeScriptTags($text) {
    // Remove <script> tags and their content
    $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);

    // Remove any remaining script tags without content
    $text = strip_tags($text);

    return $text;
}

// Function to send a message to Slack
function send_message_to_slack($message) {
    $slack_webhook_url = SLACK_WEBHOOK; // Replace with your Slack webhook URL

    $args = array(
        'body' => json_encode(array('text' => $message)),
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 15,
    );

    $response = wp_remote_post($slack_webhook_url, $args);

    if (is_wp_error($response)) {
        error_log('Error sending message to Slack: ' . $response->get_error_message());
    }
}