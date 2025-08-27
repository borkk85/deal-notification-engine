<?php
namespace DNE\Integrations;

/**
 * OneSignal Web Push Integration
 */
class OneSignal {
    
    private $app_id;
    private $api_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->app_id = get_option('dne_onesignal_app_id', '');
        $this->api_key = get_option('dne_onesignal_api_key', '');
    }
    
    /**
     * Send push notification to user
     */
    public function send_notification($user_id, $post) {
        // Check if OneSignal is enabled
        if (get_option('dne_onesignal_enabled') !== '1' || empty($this->app_id) || empty($this->api_key)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] Not configured - Enabled: ' . get_option('dne_onesignal_enabled') . 
                         ', App ID: ' . (empty($this->app_id) ? 'empty' : 'set') . 
                         ', API Key: ' . (empty($this->api_key) ? 'empty' : 'set'));
            }
            return [
                'success' => false,
                'message' => 'OneSignal not configured'
            ];
        }
        
        // Get user's OneSignal player ID (this would be set when user subscribes to push)
        $player_id = get_user_meta($user_id, 'onesignal_player_id', true);
        
        if (empty($player_id)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal] User ' . $user_id . ' has no OneSignal player ID');
            }
            return [
                'success' => false,
                'message' => 'User has not subscribed to push notifications'
            ];
        }
        
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] Sending to player ID: ' . $player_id);
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
        
        // OneSignal API payload
        $fields = [
            'app_id' => $this->app_id,
            'include_player_ids' => [$player_id],
            'contents' => ['en' => $excerpt],
            'headings' => ['en' => '🔥 ' . $title],
            'url' => $url,
            'chrome_web_badge' => $discount_badge ? $discount_badge : null,
            'chrome_web_icon' => get_site_icon_url(256),
            'firefox_icon' => get_site_icon_url(256)
        ];
        
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] API Payload: ' . print_r($fields, true));
        }
        
        // Send via OneSignal API
        $response = wp_remote_post('https://onesignal.com/api/v1/notifications', [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . $this->api_key
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
            return [
                'success' => true,
                'message' => 'Push notification sent',
                'notification_id' => $body['id']
            ];
        }
        
        $error_message = isset($body['errors']) ? 
            (is_array($body['errors']) ? implode(', ', $body['errors']) : $body['errors']) : 
            'Unknown error (Code: ' . $response_code . ')';
            
        return [
            'success' => false,
            'message' => $error_message,
            'debug' => $body
        ];
    }
    
    /**
     * Initialize OneSignal on frontend
     * This should be called in theme or via plugin frontend scripts
     */
    public static function get_init_script() {
        $app_id = get_option('dne_onesignal_app_id', '');
        
        if (empty($app_id) || get_option('dne_onesignal_enabled') !== '1') {
            return '';
        }
        
        return "
        <script src='https://cdn.onesignal.com/sdks/OneSignalSDK.js' async=''></script>
        <script>
            window.OneSignal = window.OneSignal || [];
            OneSignal.push(function() {
                OneSignal.init({
                    appId: '" . esc_js($app_id) . "',
                    notifyButton: {
                        enable: true,
                        position: 'bottom-right',
                        text: {
                            'tip.state.unsubscribed': 'Subscribe to deal notifications',
                            'tip.state.subscribed': 'You are subscribed to notifications',
                            'tip.state.blocked': 'You have blocked notifications',
                            'message.prenotify': 'Click to subscribe to deal notifications',
                            'message.action.subscribed': 'Thanks for subscribing!',
                            'message.action.resubscribed': 'You are subscribed to notifications'
                        }
                    },
                    welcomeNotification: {
                        title: 'Welcome!',
                        message: 'Thanks for subscribing to deal notifications!'
                    }
                });
                
                // Store player ID when user subscribes
                OneSignal.on('subscriptionChange', function(isSubscribed) {
                    if (isSubscribed) {
                        OneSignal.getUserId(function(playerId) {
                            if (playerId && typeof dne_ajax !== 'undefined') {
                                jQuery.post(dne_ajax.ajax_url, {
                                    action: 'dne_save_onesignal_player_id',
                                    player_id: playerId,
                                    nonce: dne_ajax.nonce
                                });
                            }
                        });
                    }
                });
            });
        </script>";
    }
}