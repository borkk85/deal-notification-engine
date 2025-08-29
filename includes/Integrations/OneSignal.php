<?php
namespace DNE\Integrations;

/**
 * OneSignal Web Push Integration
 * Works alongside the official OneSignal WordPress plugin
 */
class OneSignal {
    
    private $app_id;
    private $api_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        // First try to get from our settings
        $this->app_id = get_option('dne_onesignal_app_id', '');
        $this->api_key = get_option('dne_onesignal_api_key', '');
        
        // If not set, try to get from OneSignal plugin settings
        if (empty($this->app_id) || empty($this->api_key)) {
            $onesignal_settings = get_option('OneSignalWPSetting');
            if ($onesignal_settings && is_array($onesignal_settings)) {
                if (empty($this->app_id) && !empty($onesignal_settings['app_id'])) {
                    $this->app_id = $onesignal_settings['app_id'];
                }
                if (empty($this->api_key) && !empty($onesignal_settings['app_rest_api_key'])) {
                    $this->api_key = $onesignal_settings['app_rest_api_key'];
                }
            }
        }
    }
    
    /**
     * Send push notification
     * Can target either a specific player ID (for testing) or user segments
     */
    public function send_notification($user_id, $post, $custom_player_id = null) {
        // Check if OneSignal is enabled
        if (empty($this->app_id) || empty($this->api_key)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] Not configured - App ID: ' . (empty($this->app_id) ? 'empty' : 'set') . 
                         ', API Key: ' . (empty($this->api_key) ? 'empty' : 'set'));
            }
            return [
                'success' => false,
                'message' => 'OneSignal not configured. Please check if OneSignal plugin is installed and configured.'
            ];
        }
        
        // Prepare notification data
        $title = get_the_title($post);
        $url = get_permalink($post);
        $excerpt = wp_trim_words($post->post_content, 30);
        
        // Extract discount if available
        $discount_badge = '';
        if (preg_match('/(\d+)\s*%/', $title . ' ' . $post->post_content, $matches)) {
            $discount_badge = $matches[1] . '% OFF';
        }
        
        // Build notification payload
        $fields = [
            'app_id' => $this->app_id,
            'contents' => ['en' => $excerpt],
            'headings' => ['en' => '🔥 ' . $title],
            'url' => $url,
            'chrome_web_badge' => $discount_badge ? $discount_badge : null,
            'isAnyWeb' => true
        ];
        
        // Add featured image if available
        if (has_post_thumbnail($post->ID)) {
            $post_thumbnail_id = get_post_thumbnail_id($post->ID);
            $thumbnail_size_url = wp_get_attachment_image_src($post_thumbnail_id, array(192, 192), true)[0];
            $large_size_url = wp_get_attachment_image_src($post_thumbnail_id, 'large', true)[0];
            
            $fields['chrome_web_icon'] = $thumbnail_size_url;
            $fields['firefox_icon'] = $thumbnail_size_url;
            $fields['chrome_web_image'] = $large_size_url;
        } else {
            // Use site icon as fallback
            $site_icon = get_site_icon_url(256);
            if ($site_icon) {
                $fields['chrome_web_icon'] = $site_icon;
                $fields['firefox_icon'] = $site_icon;
            }
        }
        
        // Target specific player or segments
        if (!empty($custom_player_id)) {
            // For testing - send to specific player ID
            $fields['include_player_ids'] = [$custom_player_id];
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] Sending to specific player ID: ' . $custom_player_id);
            }
        } else {
            // For production - use External User ID (this is the WordPress user ID)
            $fields['include_aliases'] = [
                'external_id' => [(string)$user_id]
            ];
            
            // Set the target channel
            $fields['target_channel'] = 'push';
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] Targeting user via External ID: ' . $user_id);
            }
        }
        
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] API Payload: ' . print_r($fields, true));
        }
        
        // Determine API key type (Rich or User/REST)
        $auth_header = 'Basic ' . $this->api_key;
        if (strpos($this->api_key, '-') !== false && strlen($this->api_key) < 48) {
            // Looks like a User Auth Key (has dashes and is shorter)
            $auth_header = 'Basic ' . $this->api_key;
        } else if (strlen($this->api_key) === 48) {
            // Standard REST API key
            $auth_header = 'Basic ' . $this->api_key;
        }
        
        // Send via OneSignal API
        $response = wp_remote_post('https://onesignal.com/api/v1/notifications', [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => $auth_header
            ],
            'body' => json_encode($fields),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] WP Error: ' . $response->get_error_message());
            }
            return [
                'success' => false,
                'message' => 'API error: ' . $response->get_error_message()
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] Response Code: ' . $response_code);
            error_log('[DNE OneSignal] Response Body: ' . print_r($body, true));
        }
        
        if (isset($body['id'])) {
            $recipients = isset($body['recipients']) ? $body['recipients'] : 0;
            return [
                'success' => true,
                'message' => 'Push notification sent (Recipients: ' . $recipients . ')',
                'notification_id' => $body['id'],
                'recipients' => $recipients
            ];
        }
        
        // Handle specific error cases
        $error_message = 'Unknown error';
        if (isset($body['errors'])) {
            if (is_array($body['errors'])) {
                $errors = $body['errors'];
                if (in_array('All included players are not subscribed', $errors)) {
                    $error_message = 'User has not subscribed to push notifications or invalid player ID';
                } else if (in_array('Invalid app_id format', $errors)) {
                    $error_message = 'Invalid OneSignal App ID format';
                } else if (in_array('Unauthorized', $errors)) {
                    $error_message = 'Invalid API Key - please check your OneSignal REST API Key';
                } else {
                    $error_message = implode(', ', $errors);
                }
            } else {
                $error_message = $body['errors'];
            }
        } else if ($response_code === 401) {
            $error_message = 'Authentication failed - please verify your OneSignal API key';
        } else if ($response_code === 400) {
            $error_message = 'Bad request - check App ID and notification data';
        }
            
        return [
            'success' => false,
            'message' => $error_message . ' (Code: ' . $response_code . ')',
            'debug' => $body
        ];
    }
    
    /**
     * Send notification to all users (broadcast)
     * Used for general announcements
     */
    public function send_broadcast($post) {
        if (empty($this->app_id) || empty($this->api_key)) {
            return [
                'success' => false,
                'message' => 'OneSignal not configured'
            ];
        }
        
        // Prepare notification data
        $title = get_the_title($post);
        $url = get_permalink($post);
        $excerpt = wp_trim_words($post->post_content, 30);
        
        // OneSignal API payload for broadcast
        $fields = [
            'app_id' => $this->app_id,
            'included_segments' => ['All'], // Send to all subscribed users
            'contents' => ['en' => $excerpt],
            'headings' => ['en' => '🔥 ' . $title],
            'url' => $url,
            'isAnyWeb' => true
        ];
        
        // Send via API
        $response = wp_remote_post('https://onesignal.com/api/v1/notifications', [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . $this->api_key
            ],
            'body' => json_encode($fields),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'API error: ' . $response->get_error_message()
            ];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['id'])) {
            return [
                'success' => true,
                'message' => 'Broadcast sent to all subscribers',
                'notification_id' => $body['id'],
                'recipients' => $body['recipients'] ?? 0
            ];
        }
        
        return [
            'success' => false,
            'message' => isset($body['errors']) ? implode(', ', (array)$body['errors']) : 'Failed to send broadcast'
        ];
    }
}