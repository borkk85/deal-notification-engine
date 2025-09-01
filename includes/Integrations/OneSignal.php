<?php

namespace DNE\Integrations;

/**
 * OneSignal Web Push Integration
 * Fixed for v16 API with proper External ID targeting
 * 
 * @since 1.2.0 - Complete v16 rewrite with REST API integration
 */
class OneSignal
{

    private $app_id;
    private $api_key;
    private $api_helper;

    /**
     * Constructor
     */
    public function __construct()
    {
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
        
        // Initialize REST API helper
        $this->api_helper = new OneSignal_API();
    }

    /**
     * Send push notification
     * Fixed for v16 with proper External ID targeting
     * 
     * @param int $user_id WordPress user ID  
     * @param WP_Post $post Post object
     * @param string|null $custom_target Custom subscription ID or External ID for testing
     * @return array Result with success status and message
     */
    public function send_notification($user_id, $post, $custom_target = null)
    {
        // Check configuration
        if (empty($this->app_id) || empty($this->api_key)) {
            return [
                'success' => false,
                'message' => 'OneSignal not configured'
            ];
        }

        // Build notification content
        $title = get_the_title($post);
        $url = get_permalink($post);
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 30);

        // Extract discount if available
        $discount_badge = '';
        if (preg_match('/(\d+)\s*%/', $title . ' ' . $post->post_content, $matches)) {
            $discount_badge = $matches[1] . '% OFF - ';
        }

        // Build notification payload
        $fields = [
            'app_id' => $this->app_id,
            'headings' => [
                'en' => '🔥 ' . $discount_badge . $title
            ],
            'contents' => [
                'en' => $excerpt
            ],
            'url' => $url,
            // Force web push channel
            'target_channel' => 'push',
            'channel_for_external_user_ids' => 'push'
        ];

        // Add images if available
        if (has_post_thumbnail($post->ID)) {
            $thumb_id = get_post_thumbnail_id($post->ID);
            
            // Icon (192x192)
            $icon_url = wp_get_attachment_image_src($thumb_id, [192, 192], true);
            if ($icon_url && isset($icon_url[0])) {
                $fields['chrome_web_icon'] = $icon_url[0];
                $fields['firefox_icon'] = $icon_url[0];
            }
            
            // Large image
            $large_url = wp_get_attachment_image_src($thumb_id, 'large', true);
            if ($large_url && isset($large_url[0])) {
                $fields['chrome_web_image'] = $large_url[0];
            }
        } else {
            // Use site icon as fallback
            $site_icon = get_site_icon_url(256);
            if ($site_icon) {
                $fields['chrome_web_icon'] = $site_icon;
                $fields['firefox_icon'] = $site_icon;
            }
        }

        // Handle targeting
        if (!empty($custom_target)) {
            // Custom target provided (for testing)
            $custom_target = trim($custom_target);
            
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $custom_target)) {
                // Looks like a subscription ID (UUID v4 format)
                // First check if this might be the new v16 subscription ID format
                $fields['include_subscription_ids'] = [$custom_target];
                
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE OneSignal] Using subscription ID targeting: ' . $custom_target);
                }
            } elseif (strpos($custom_target, 'ext:') === 0 || strpos($custom_target, 'external:') === 0) {
                // Explicit external ID format
                $external_id = preg_replace('/^(ext|external):/', '', $custom_target);
                $fields['include_aliases'] = [
                    'external_id' => [(string) $external_id]
                ];
                
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE OneSignal] Using explicit External ID targeting: ' . $external_id);
                }
            } else {
                // Assume it's a subscription ID or player ID
                $fields['include_subscription_ids'] = [$custom_target];
                
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE OneSignal] Using direct ID targeting: ' . $custom_target);
                }
            }
        } else {
            // Normal user targeting by External ID
            // This is the primary method for v16
            $fields['include_aliases'] = [
                'external_id' => [(string) $user_id]
            ];
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] Using External ID targeting for user: ' . $user_id);
            }
        }

        // Add web push specific options
        $fields['web_push_topic'] = 'deal-' . $post->ID;
        $fields['ttl'] = 86400; // 24 hours
        $fields['priority'] = 10; // High priority

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] Sending notification with payload: ' . wp_json_encode($fields));
        }

        // Send the notification
        $response = wp_remote_post(
            'https://api.onesignal.com/notifications',
            [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Authorization' => 'Basic ' . $this->api_key
                ],
                'body' => wp_json_encode($fields),
                'timeout' => 30
            ]
        );

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] WP Error: ' . $error_message);
            }
            
            return [
                'success' => false,
                'message' => 'API request failed: ' . $error_message
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] Response Code: ' . $code);
            error_log('[DNE OneSignal] Response Body: ' . $body);
        }

        // Check for success
        if ($code === 200 && isset($data['id'])) {
            $recipients = isset($data['recipients']) ? intval($data['recipients']) : 0;
            
            if ($recipients > 0) {
                return [
                    'success' => true,
                    'message' => sprintf('Push notification sent to %d recipient(s)', $recipients),
                    'notification_id' => $data['id'],
                    'recipients' => $recipients
                ];
            } else {
                // Notification created but no recipients
                // This might indicate the user needs to resubscribe
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE OneSignal] Notification created but 0 recipients - user may need to resubscribe');
                }
                
                return [
                    'success' => false,
                    'message' => 'No active subscriptions found for this user. User may need to resubscribe.',
                    'notification_id' => $data['id']
                ];
            }
        }

        // Handle errors
        $error_message = 'Unknown error';
        
        if (isset($data['errors'])) {
            if (is_array($data['errors'])) {
                $error_message = implode(', ', $data['errors']);
            } else {
                $error_message = (string) $data['errors'];
            }
        } elseif (isset($data['error'])) {
            $error_message = (string) $data['error'];
        }

        return [
            'success' => false,
            'message' => 'OneSignal API error: ' . $error_message . ' (HTTP ' . $code . ')'
        ];
    }

    /**
     * Send notification to all users (broadcast)
     * Used for general announcements
     */
    public function send_broadcast($post)
    {
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
            'target_channel' => 'push'
        ];

        // Send via API
        $response = wp_remote_post('https://api.onesignal.com/notifications', [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . $this->api_key
            ],
            'body' => wp_json_encode($fields),
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
    
    /**
     * Check and cleanup user subscriptions before sending
     * This prevents notification failures due to disabled subscriptions
     * 
     * @param int $user_id WordPress user ID
     * @return bool Whether user has valid subscriptions
     */
    public function prepare_user_for_notification($user_id)
    {
        if (!$this->api_helper->is_configured()) {
            return false;
        }
        
        // Get user's subscriptions
        $subscriptions = $this->api_helper->get_subscriptions_by_external_id($user_id);
        
        if (empty($subscriptions)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] No subscriptions found for user ' . $user_id);
            }
            return false;
        }
        
        // Check if user has any active subscriptions
        $has_active = false;
        foreach ($subscriptions as $subscription) {
            if (isset($subscription['notification_types']) && $subscription['notification_types'] > 0) {
                $has_active = true;
                break;
            }
        }
        
        if (!$has_active) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] User ' . $user_id . ' has no active subscriptions');
            }
            
            // Cleanup disabled subscriptions
            $cleanup_result = $this->api_helper->cleanup_disabled_subscriptions($user_id);
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] Cleaned up ' . $cleanup_result['deleted'] . ' disabled subscriptions');
            }
            
            return false;
        }
        
        return true;
    }
}