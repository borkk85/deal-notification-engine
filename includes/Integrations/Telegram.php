<?php

namespace DNE\Integrations;

/**
 * Telegram bot integration for notifications
 */
class Telegram
{

    private $bot_token;
    private $bot_username;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Get bot credentials from database settings
        $this->bot_token = get_option('dne_telegram_bot_token', '');
        $this->bot_username = get_option('dne_telegram_bot_username', 'YourBotName');

        // Fall back to wp-config.php if defined there (backwards compatibility)
        if (empty($this->bot_token) && defined('DNE_TELEGRAM_BOT_TOKEN')) {
            $this->bot_token = DNE_TELEGRAM_BOT_TOKEN;
        }
    }

    /**
     * Initialize Telegram integration
     */
    public function init()
    {
        // Register webhook endpoint for Telegram bot
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);

        // Clean up expired verifications daily
        add_action('dne_cleanup_old_logs', [$this, 'cleanup_expired_verifications']);
    }

    /**
     * Register REST API endpoint for Telegram webhook
     */
    public function register_webhook_endpoint()
    {
        register_rest_route('dne/v1', '/telegram-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true' // Public endpoint for Telegram
        ]);
    }

    /**
     * Handle incoming Telegram webhook
     */
    public function handle_webhook($request)
    {
        $data = $request->get_json_params();

        if (!isset($data['message'])) {
            return new \WP_REST_Response(['status' => 'no message'], 200);
        }

        $message = $data['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';

        // Handle /start command with verification
        if (strpos($text, '/start') === 0) {
            $this->handle_start_command($chat_id, $text);
        }

        return new \WP_REST_Response(['status' => 'ok'], 200);
    }

    /**
     * Handle /start command
     */
    private function handle_start_command($chat_id, $text)
    {
        // Extract user ID from start parameter (format: /start verify_USER_ID)
        if (preg_match('/\/start verify_(\d+)/', $text, $matches)) {
            $user_id = intval($matches[1]);
            $this->start_verification($user_id, $chat_id);
        } else {
            // Send welcome message
            $this->send_message(
                $chat_id,
                "Welcome to Deal Notifications Bot! 🎉\n\n" .
                    "To connect your account, please use the verification link from your profile settings."
            );
        }
    }

    /**
     * Start verification process
     */
    private function start_verification($user_id, $chat_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_telegram_verifications';

        // Generate unique verification code
        $verification_code = $this->generate_verification_code();

        // Store verification request
        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'verification_code' => $verification_code,
                'chat_id' => $chat_id,
                'status' => 'pending',
                'expires_at' => date('Y-m-d H:i:s', time() + 600) // 10 minutes
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        // Send verification code to user
        $message = "Your verification code is:\n\n" .
            "🔐 <code>$verification_code</code>\n\n" .
            "Please copy this code and paste it in your profile settings to complete the connection.\n\n" .
            "This code expires in 10 minutes.";

        $this->send_message($chat_id, $message);
    }

    /**
     * Verify user code
     */
    public function verify_user_code($user_id, $code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_telegram_verifications';

        // Find pending verification
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE user_id = %d 
             AND verification_code = %s 
             AND status = 'pending' 
             AND expires_at > NOW()",
            $user_id,
            $code
        ));

        if (!$verification) {
            return [
                'success' => false,
                'message' => 'Invalid or expired verification code'
            ];
        }

        // Update verification status
        $wpdb->update(
            $table,
            ['status' => 'verified'],
            ['id' => $verification->id],
            ['%s'],
            ['%d']
        );

        // Send success message to Telegram
        $this->send_message(
            $verification->chat_id,
            "✅ Your account has been successfully connected!\n\n" .
                "You will now receive personilized deal notifications here."
        );

        return [
            'success' => true,
            'chat_id' => $verification->chat_id
        ];
    }

    /**
     * Send notification to user
     */
    public function send_notification($user_id, $post)
    {
        dne_debug("Telegram send_notification called for user {$user_id}");
        
        // Gate by user preferences/tier first
        $filter = new \DNE\Notifications\Filter();
        $allowed = $filter->user_allows_channel((int)$user_id, 'telegram');
        dne_debug("Telegram user_allows_channel result for user {$user_id}: " . ($allowed ? 'true' : 'false'));
        
        if (!$allowed) {
            dne_debug("DENY telegram for user {$user_id} (preferences/tier)");
            return [
                'success' => false,
                'message' => 'User has Telegram disabled or not allowed by tier'
            ];
        }
        dne_debug("ALLOW telegram for user {$user_id}");

        // Check if Telegram is enabled
        if (get_option('dne_telegram_enabled') !== '1' || empty($this->bot_token)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Telegram] Not configured - Enabled: ' . get_option('dne_telegram_enabled') . ', Token: ' . (empty($this->bot_token) ? 'empty' : 'set'));
            }
            return [
                'success' => false,
                'message' => 'Telegram notifications not configured'
            ];
        }

        // Get user's chat ID
        $chat_id = get_user_meta($user_id, 'telegram_chat_id', true);
        if (empty($chat_id)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Telegram] User ' . $user_id . ' has no Telegram chat ID');
            }
            return [
                'success' => false,
                'message' => 'User has not connected Telegram'
            ];
        }

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Telegram] Sending to chat ID: ' . $chat_id);
        }

        // Prepare message
        $title = get_the_title($post);
        $url = get_permalink($post);
        $excerpt = wp_trim_words($post->post_content, 50);

        // Extract discount if available
        $discount = '';
        if (preg_match('/(\d+)\s*%/', $title . ' ' . $post->post_content, $matches)) {
            $discount = "💰 <b>{$matches[1]}% OFF</b>\n\n";
        }

        $message = "🔥 <b>New Deal Alert!</b>\n\n" .
            $discount .
            "<b>$title</b>\n\n" .
            "$excerpt\n\n" .
            "👉 <a href=\"$url\">View Deal</a>";


        // Send message with detailed error handling
        $result = $this->send_message($chat_id, $message);

        if (is_array($result)) {
            return [
                'success' => (bool) ($result['success'] ?? false),
                'message' => ($result['success'] ?? false)
                    ? 'Sent successfully'
                    : ('Failed to send' . (!empty($result['description']) ? ': ' . $result['description'] : ''))
            ];
        }

        return [
            'success' => (bool) $result,
            'message' => $result ? 'Sent successfully' : 'Failed to send'
        ];
    }

    /**
     * Send message via Telegram API
     */
    private function send_message($chat_id, $text)
    {
        if (empty($this->bot_token)) {
            return ['success' => false, 'description' => 'Bot token is not configured'];
        }

        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";

        $response = wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false
            ],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('Telegram API error: ' . $err);
            return ['success' => false, 'description' => $err];
        }

        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        if (isset($body['ok']) && $body['ok'] === true) {
            return ['success' => true];
        }
        // Use Telegram API description if available
        $desc = '';
        if (is_array($body) && isset($body['description'])) {
            $desc = (string) $body['description'];
        } elseif (is_string($body_raw) && $body_raw !== '') {
            $desc = $body_raw;
        }
        return ['success' => false, 'description' => $desc];
    }

    /**
     * Generate verification code
     */
    private function generate_verification_code()
    {
        return strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
    }

    /**
     * Clean up expired verifications
     */
    public function cleanup_expired_verifications()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_telegram_verifications';

        $wpdb->query(
            "DELETE FROM $table WHERE expires_at < NOW() AND status = 'pending'"
        );
    }
}
