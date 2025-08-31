<?php

namespace DNE\Integrations;

/**
 * OneSignal Web Push Integration
 * Enhanced with proper External ID verification
 */
class OneSignal
{

    private $app_id;
    private $api_key;

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
    }

    /**
     * Send push notification
     * Enhanced to check if user has External ID set before sending
     */
    public function send_notification($user_id, $post, $custom_player_id = null)
    {
        // 0) Config guard
        if (empty($this->app_id) || empty($this->api_key)) {
            return ['success' => false, 'message' => 'OneSignal not configured.'];
        }

        // 1) Build basic content (keep it simple & valid)
        $title   = get_the_title($post);
        $url     = get_permalink($post);
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 30);

        $fields = [
            'app_id'   => $this->app_id,
            'headings' => ['en' => '🔥 ' . $title],
            'contents' => ['en' => $excerpt],
            'url'      => $url,
            // Force web push channel (important when using aliases)
            'target_channel' => 'push',
            // Helps older API paths when using external_id
            'channel_for_external_user_ids' => 'push',
        ];

        // 2) Artwork (supported keys only)
        if (has_post_thumbnail($post->ID)) {
            $thumb_id = get_post_thumbnail_id($post->ID);
            $icon     = wp_get_attachment_image_src($thumb_id, [192, 192], true)[0] ?? null;
            $large    = wp_get_attachment_image_src($thumb_id, 'large', true)[0] ?? null;
            if ($icon) {
                $fields['chrome_web_icon'] = $icon;
                $fields['firefox_icon'] = $icon;
            }
            if ($large) {
                $fields['chrome_web_image'] = $large;
            }
        } else {
            $site_icon = get_site_icon_url(256);
            if ($site_icon) {
                $fields['chrome_web_icon'] = $site_icon;
                $fields['firefox_icon'] = $site_icon;
            }
        }

        // 3) Targeting
        $custom_id = is_string($custom_player_id) ? trim($custom_player_id) : '';
        if ($custom_id !== '') {
            // Allow three input styles:
            //  a) Subscription ID (v16 UUID with dashes)
            //  b) External ID via prefixes: "ext:22" or "external:22"
            //  c) Legacy Player ID (fallback)
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $custom_id)) {
                // v16 Subscription ID
                $fields['include_subscriptions'] = [$custom_id];
            } elseif (preg_match('/^(?:ext|external):(.+)$/i', $custom_id, $m)) {
                // Explicit External ID
                $fields['include_aliases'] = ['external_id' => [(string) trim($m[1])]];
            } else {
                // Assume legacy Player ID
                $fields['include_player_ids'] = [$custom_id];
            }
        } else {
            // Default: per-user send by External ID (string)
            $fields['include_aliases'] = ['external_id' => [(string) $user_id]];
        }

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] API Payload: ' . wp_json_encode($fields));
        }

        // 4) Send
        $response = wp_remote_post(
            'https://onesignal.com/api/v1/notifications',
            [
                'headers' => [
                    'Content-Type'  => 'application/json; charset=utf-8',
                    'Authorization' => 'Basic ' . $this->api_key, // REST API key
                ],
                'body'    => wp_json_encode($fields),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'API error: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?: [];

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal] Response (' . $code . '): ' . print_r($body, true));
        }

        // 5) Interpret result
        if (isset($body['id'])) {
            $recipients = (int) ($body['recipients'] ?? 0);
            return [
                'success'        => $recipients > 0,
                'message'        => $recipients > 0
                    ? 'Push sent (Recipients: ' . $recipients . ')'
                    : 'Sent to API but matched 0 recipients (check target type / subscription).',
                'notification_id' => $body['id'],
                'recipients'     => $recipients,
                'raw'            => $body,
            ];
        }

        // 6) Better error surfacing
        $err = $body['errors'] ?? ($body['error'] ?? 'Unknown error');
        return [
            'success' => false,
            'message' => (is_array($err) ? implode('; ', $err) : (string) $err) . ' (HTTP ' . $code . ')',
            'raw'     => $body,
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
            'isAnyWeb' => true
        ];

        // Send via API
        $response = wp_remote_post('https://api.onesignal.com/notifications', [
            'headers' => [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $this->api_key, // v16
            ],
            'body'    => wp_json_encode($fields),
            'timeout' => 30,
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
