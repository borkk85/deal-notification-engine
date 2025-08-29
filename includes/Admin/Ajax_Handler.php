<?php

namespace DNE\Admin;

/**
 * Handles all AJAX requests for the plugin
 * Migrated from theme for better security
 */
class Ajax_Handler
{

    /**
     * Initialize AJAX handlers
     */
    public function init()
    {
        // Save notification preferences
        add_action('wp_ajax_save_deal_notification_preferences', [$this, 'save_preferences']);
        add_action('wp_ajax_nopriv_save_deal_notification_preferences', [$this, 'save_preferences']);

        // Telegram verification
        add_action('wp_ajax_verify_telegram_connection', [$this, 'verify_telegram']);
        add_action('wp_ajax_nopriv_verify_telegram_connection', [$this, 'verify_telegram']);

        // Telegram disconnection
        add_action('wp_ajax_disconnect_telegram', [$this, 'disconnect_telegram']);
        add_action('wp_ajax_nopriv_disconnect_telegram', [$this, 'disconnect_telegram']);

        // OneSignal player ID
        add_action('wp_ajax_dne_save_onesignal_player_id', [$this, 'save_onesignal_player_id']);
        add_action('wp_ajax_nopriv_dne_save_onesignal_player_id', [$this, 'save_onesignal_player_id']);
        add_action('wp_ajax_save_onesignal_player_id', [$this, 'save_onesignal_player_id']);
        add_action('wp_ajax_nopriv_save_onesignal_player_id', [$this, 'save_onesignal_player_id']);
        add_action('wp_ajax_remove_onesignal_player_id', [$this, 'remove_onesignal_player_id']);
        

        // Test notification (admin only)
        add_action('wp_ajax_dne_send_test_notification', [$this, 'send_test_notification']);

        // Process queue manually (admin only)
        add_action('wp_ajax_dne_process_queue_manually', [$this, 'process_queue_manually']);
    }

    public function remove_onesignal_player_id()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'remove_player_id')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        if (!$user_id || $user_id !== get_current_user_id()) {
            wp_send_json_error('Invalid user ID');
            return;
        }

        delete_user_meta($user_id, 'onesignal_player_id');

        wp_send_json_success();
    }

    /**
     * Save user notification preferences
     * Enhanced security over theme version
     */
    public function save_preferences()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'deal_notifications_save')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        // Get and validate user ID
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
            return;
        }

        // Check permissions - user can only edit their own preferences
        if ($user_id !== get_current_user_id() && !current_user_can('edit_user', $user_id)) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Validate user has deal tier role
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error('User not found');
            return;
        }

        $has_deal_role = false;
        foreach ($user->roles as $role) {
            if (strpos($role, 'um_deal') !== false && strpos($role, 'tier') !== false) {
                $has_deal_role = true;
                break;
            }
        }

        if (!$has_deal_role) {
            wp_send_json_error('User does not have deal notification privileges');
            return;
        }

        // Sanitize and save preferences
        $notifications_enabled = isset($_POST['notifications_enabled']) && $_POST['notifications_enabled'] === '1' ? '1' : '0';
        update_user_meta($user_id, 'notifications_enabled', $notifications_enabled);

        // Save delivery methods with validation
        $allowed_methods = ['email', 'webpush', 'telegram'];
        $delivery_methods = isset($_POST['delivery_methods']) ? (array)$_POST['delivery_methods'] : [];
        $delivery_methods = array_intersect($delivery_methods, $allowed_methods);
        update_user_meta($user_id, 'notification_delivery_methods', $delivery_methods);

        // Save and validate discount filter (10-90%, steps of 5)
        $discount = isset($_POST['user_discount_filter']) ? intval($_POST['user_discount_filter']) : '';
        if ($discount !== '' && ($discount < 10 || $discount > 90 || $discount % 5 !== 0)) {
            $discount = ''; // Reset if invalid
        }
        update_user_meta($user_id, 'user_discount_filter', $discount);

        // Save category filter (ensure they're valid term IDs)
        $categories = isset($_POST['user_category_filter']) ? array_map('intval', (array)$_POST['user_category_filter']) : [];
        $valid_categories = [];
        foreach ($categories as $cat_id) {
            if (term_exists($cat_id, 'product_categories')) {
                $valid_categories[] = $cat_id;
            }
        }
        update_user_meta($user_id, 'user_category_filter', $valid_categories);

        // Save store filter (ensure they're valid term IDs)
        $stores = isset($_POST['user_store_filter']) ? array_map('intval', (array)$_POST['user_store_filter']) : [];
        $valid_stores = [];
        foreach ($stores as $store_id) {
            if (term_exists($store_id, 'store_type')) {
                $valid_stores[] = $store_id;
            }
        }
        update_user_meta($user_id, 'user_store_filter', $valid_stores);

        // Log the update for admin monitoring
        $this->log_preference_update($user_id, [
            'enabled' => $notifications_enabled,
            'methods' => $delivery_methods,
            'filters' => [
                'discount' => $discount,
                'categories' => count($valid_categories),
                'stores' => count($valid_stores)
            ]
        ]);

        wp_send_json_success('Preferences saved successfully');
    }

    /**
     * Verify Telegram connection
     */
    public function verify_telegram()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'telegram_verify')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $verification_code = isset($_POST['verification_code']) ? sanitize_text_field($_POST['verification_code']) : '';

        // Check permissions
        if ($user_id !== get_current_user_id() && !current_user_can('edit_user', $user_id)) {
            wp_send_json_error('Permission denied');
            return;
        }

        if (empty($verification_code)) {
            wp_send_json_error('Verification code is required');
            return;
        }

        // Rate limiting - max 5 attempts per hour
        $attempts_key = 'telegram_verify_attempts_' . $user_id;
        $attempts = get_transient($attempts_key);
        if ($attempts >= 5) {
            wp_send_json_error('Too many attempts. Please try again later.');
            return;
        }
        set_transient($attempts_key, $attempts + 1, HOUR_IN_SECONDS);

        // Verify with Telegram integration
        $telegram = new \DNE\Integrations\Telegram();
        $result = $telegram->verify_user_code($user_id, $verification_code);

        if ($result['success']) {
            // Clear rate limit on success
            delete_transient($attempts_key);

            // Save Telegram chat ID
            update_user_meta($user_id, 'telegram_chat_id', $result['chat_id']);
            update_user_meta($user_id, 'telegram_verified', '1');

            wp_send_json_success('Telegram connected successfully');
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Disconnect Telegram
     */
    public function disconnect_telegram()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'telegram_disconnect')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        // Check permissions
        if ($user_id !== get_current_user_id() && !current_user_can('edit_user', $user_id)) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Clear Telegram data
        delete_user_meta($user_id, 'telegram_chat_id');
        delete_user_meta($user_id, 'telegram_verified');

        // Remove telegram from delivery methods
        $delivery_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
        if (is_array($delivery_methods)) {
            $delivery_methods = array_filter($delivery_methods, function ($method) {
                return $method !== 'telegram';
            });
            update_user_meta($user_id, 'notification_delivery_methods', $delivery_methods);
        }

        // Clean up any pending verifications
        global $wpdb;
        $table = $wpdb->prefix . 'dne_telegram_verifications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->delete($table, ['user_id' => $user_id]);
        }

        wp_send_json_success('Telegram disconnected successfully');
    }

    /**
     * Log preference updates for monitoring
     */
    private function log_preference_update($user_id, $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_log';

        // Only log if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->insert(
                $table,
                [
                    'user_id' => $user_id,
                    'action' => 'preference_update',
                    'details' => json_encode($data),
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s']
            );
        }
    }

    /**
     * Save OneSignal player ID
     */
    public function save_onesignal_player_id()
    {
        // Verify nonce
        // if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dne_ajax_nonce')) {
        // Verify nonce (support new and legacy actions)
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'save_player_id') && !wp_verify_nonce($nonce, 'dne_ajax_nonce')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        // $user_id = get_current_user_id();
        $user_id = absint($_POST['user_id'] ?? 0);
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        $player_id = sanitize_text_field($_POST['player_id'] ?? '');
        if (empty($player_id)) {
            wp_send_json_error('Invalid player ID');
            return;
        }

        update_user_meta($user_id, 'onesignal_player_id', $player_id);

        // Log the subscription
        $this->log_preference_update($user_id, [
            'action' => 'onesignal_subscribed',
            'player_id' => substr($player_id, 0, 10) . '...' // Log partial ID for privacy
        ]);

        wp_send_json_success('Player ID saved');
    }

    /**
     * Send test notification (admin only)
     */
    public function send_test_notification()
    {
        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dne_test_notification')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? get_current_user_id());
        $method = sanitize_text_field($_POST['method'] ?? 'email');

        // Get custom overrides for testing
        $custom_email = sanitize_email($_POST['custom_email'] ?? '');
        $custom_telegram = sanitize_text_field($_POST['custom_telegram'] ?? '');
        $custom_onesignal = sanitize_text_field($_POST['custom_onesignal'] ?? '');

        // Debug logging
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Test] Starting test notification - Method: ' . $method . ', Post: ' . $post_id . ', User: ' . $user_id);
            if ($custom_email) error_log('[DNE Test] Custom email: ' . $custom_email);
            if ($custom_telegram) error_log('[DNE Test] Custom Telegram ID: ' . $custom_telegram);
            if ($custom_onesignal) error_log('[DNE Test] Custom OneSignal ID: ' . $custom_onesignal);
        }

        // Get post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
            return;
        }

        // Get user
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error('User not found');
            return;
        }

        // Temporarily override user meta for testing if custom values provided
        $original_values = [];
        $restore_needed = false;

        if ($method === 'email' && $custom_email) {
            $original_values['email'] = $user->user_email;
            $user->user_email = $custom_email;
            $restore_needed = true;
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Test] Overriding email from ' . $original_values['email'] . ' to ' . $custom_email);
            }
        }

        if ($method === 'telegram' && $custom_telegram) {
            $original_values['telegram'] = get_user_meta($user_id, 'telegram_chat_id', true);
            update_user_meta($user_id, 'telegram_chat_id', $custom_telegram);
            $restore_needed = true;
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Test] Temporarily setting Telegram chat ID to: ' . $custom_telegram);
            }
        }

        if ($method === 'webpush' && $custom_onesignal) {
            $original_values['onesignal'] = get_user_meta($user_id, 'onesignal_player_id', true);
            update_user_meta($user_id, 'onesignal_player_id', $custom_onesignal);
            $restore_needed = true;
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Test] Temporarily setting OneSignal player ID to: ' . $custom_onesignal);
            }
        }

        // Send notification based on method
        $result = ['success' => false, 'message' => 'Method not implemented'];

        switch ($method) {
            case 'email':
                $email = new \DNE\Integrations\Email();
                $result = $email->send($user, $post);
                break;

            case 'telegram':
                $telegram = new \DNE\Integrations\Telegram();
                $result = $telegram->send_notification($user_id, $post);
                break;

            case 'webpush':
                $onesignal = new \DNE\Integrations\OneSignal();
                $result = $onesignal->send_notification($user_id, $post, $custom_onesignal ?: null);
                break;

                // case 'webpush':
                //     $onesignal = new \DNE\Integrations\OneSignal();
                //     $result = $onesignal->send_notification($user_id, $post);
                //     break;
        }

        // Restore original values if we overrode them
        if ($restore_needed) {
            if (isset($original_values['email'])) {
                $user->user_email = $original_values['email'];
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE Test] Restored original email');
                }
            }
            if (isset($original_values['telegram'])) {
                if ($original_values['telegram']) {
                    update_user_meta($user_id, 'telegram_chat_id', $original_values['telegram']);
                } else {
                    delete_user_meta($user_id, 'telegram_chat_id');
                }
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE Test] Restored original Telegram chat ID');
                }
            }
            if (isset($original_values['onesignal'])) {
                if ($original_values['onesignal']) {
                    update_user_meta($user_id, 'onesignal_player_id', $original_values['onesignal']);
                } else {
                    delete_user_meta($user_id, 'onesignal_player_id');
                }
                if (get_option('dne_debug_mode') === '1') {
                    error_log('[DNE Test] Restored original OneSignal player ID');
                }
            }
        }

        // Debug logging of result
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Test] Result: ' . ($result['success'] ? 'SUCCESS' : 'FAILED'));
            error_log('[DNE Test] Message: ' . $result['message']);
            if (isset($result['debug'])) {
                error_log('[DNE Test] Debug info: ' . print_r($result['debug'], true));
            }
        }

        if ($result['success']) {
            wp_send_json_success('Test notification sent: ' . $result['message']);
        } else {
            $response = ['data' => 'Failed to send: ' . $result['message']];
            if (get_option('dne_debug_mode') === '1' && isset($result['debug'])) {
                $response['debug'] = $result['debug'];
            }
            wp_send_json_error($response['data']);
        }
    }

    /**
     * Process notification queue manually (admin only)
     */
    public function process_queue_manually()
    {
        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Process the queue
        $engine = new \DNE\Notifications\Engine();
        $engine->process_queue();

        wp_send_json_success('Queue processing initiated');
    }
}
