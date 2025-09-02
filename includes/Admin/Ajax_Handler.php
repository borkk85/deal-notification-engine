<?php

namespace DNE\Admin;

/**
 * Handles all AJAX requests for the plugin
 * Enhanced with OneSignal v16 REST API integration
 * 
 * @since 1.2.0 - Added subscription cleanup and REST API integration
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

        // OneSignal subscription tracking (simple mode)
        add_action('wp_ajax_dne_onesignal_subscribed', [$this, 'track_onesignal_subscription']);
        add_action('wp_ajax_dne_onesignal_unsubscribed', [$this, 'track_onesignal_unsubscription']);
        
        // Legacy OneSignal player ID actions (for backwards compatibility)
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

    /**
     * Prepare OneSignal subscription by cleaning up disabled subscriptions
     * This prevents "subscription poisoning" issues
     * 
     * @since 1.2.0
     */
    // Removed: prepare_onesignal_subscription (simple mode)
public function track_onesignal_subscription()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dne_ajax_nonce')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
            return;
        }

        $subscription_id = sanitize_text_field($_POST['subscription_id'] ?? '');
        // Simple mode: no backend verification/repair here

        // Store subscription ID
        update_user_meta($user_id, 'onesignal_subscription_id', $subscription_id);
        
        // Mark user as having OneSignal enabled
        update_user_meta($user_id, 'onesignal_subscribed', '1');
        update_user_meta($user_id, 'onesignal_subscription_date', current_time('mysql'));
        update_user_meta($user_id, 'onesignal_external_id_set', '1');
        
        // Ensure webpush is in their delivery methods
        $delivery_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
        if (!is_array($delivery_methods)) {
            $delivery_methods = [];
        }
        
        if (!in_array('webpush', $delivery_methods)) {
            $delivery_methods[] = 'webpush';
            update_user_meta($user_id, 'notification_delivery_methods', $delivery_methods);
        }

        // Log the subscription
        $this->log_preference_update($user_id, [
            'action' => 'onesignal_subscribed',
            'timestamp' => current_time('mysql'),
            'subscription_id' => $subscription_id ? substr($subscription_id, 0, 10) . '...' : 'not provided'
        ]);

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Ajax] OneSignal subscription tracked for user ' . $user_id);
        }

        wp_send_json_success('Subscription tracked');
    }

    /**
     * Track OneSignal unsubscription
     * Uses proper opt-out method (notification_types: -2)
     * 
     * @since 1.2.0 - Enhanced with proper opt-out
     */
    public function track_onesignal_unsubscription()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dne_ajax_nonce')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
            return;
        }

        // Clear OneSignal metadata
        delete_user_meta($user_id, 'onesignal_subscribed');
        delete_user_meta($user_id, 'onesignal_subscription_id');
        delete_user_meta($user_id, 'onesignal_player_id'); // Legacy
        delete_user_meta($user_id, 'onesignal_external_id_set');
        update_user_meta($user_id, 'onesignal_unsubscription_date', current_time('mysql'));
        
        // Remove webpush from delivery methods
        $delivery_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
        if (is_array($delivery_methods)) {
            $delivery_methods = array_filter($delivery_methods, function($method) {
                return $method !== 'webpush';
            });
            update_user_meta($user_id, 'notification_delivery_methods', array_values($delivery_methods));
        }

        // Log the unsubscription
        $this->log_preference_update($user_id, [
            'action' => 'onesignal_unsubscribed',
            'timestamp' => current_time('mysql')
        ]);

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Ajax] OneSignal unsubscription tracked for user ' . $user_id);
        }

        wp_send_json_success('Unsubscription tracked');
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
        $attempts = (int) get_transient($attempts_key);
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
            update_user_meta($user_id, 'notification_delivery_methods', array_values($delivery_methods));
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
     * Send a test notification (admin only)
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
        $method  = sanitize_text_field($_POST['method'] ?? 'email');

        // Custom overrides for testing
        $custom_email     = sanitize_email($_POST['custom_email'] ?? '');
        $custom_telegram  = sanitize_text_field($_POST['custom_telegram'] ?? '');
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

        // Optional temp overrides for test
        $restore_needed = false;
        $original_values = [];
        if ($method === 'email' && $custom_email) {
            $original_values['email'] = $user->user_email;
            $user->user_email = $custom_email;
            $restore_needed = true;
        }
        if ($method === 'telegram' && $custom_telegram) {
            $original_values['telegram'] = get_user_meta($user_id, 'telegram_chat_id', true);
            update_user_meta($user_id, 'telegram_chat_id', $custom_telegram);
            $restore_needed = true;
        }

        // Dispatch
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
        }

        // Restore overrides
        if ($restore_needed) {
            if (isset($original_values['email'])) {
                $user->user_email = $original_values['email'];
            }
            if (isset($original_values['telegram'])) {
                if ($original_values['telegram']) {
                    update_user_meta($user_id, 'telegram_chat_id', $original_values['telegram']);
                } else {
                    delete_user_meta($user_id, 'telegram_chat_id');
                }
            }
        }

        // Debug result
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Test] Result: ' . ($result['success'] ? 'SUCCESS' : 'FAILED'));
            error_log('[DNE Test] Message: ' . $result['message']);
        }

        if ($result['success']) {
            wp_send_json_success('Test notification sent: ' . $result['message']);
        } else {
            wp_send_json_error('Failed to send: ' . $result['message']);
        }
    }

    /**
     * Manually cleanup OneSignal subscriptions for a user
     * 
     * @since 1.2.0
     */
    // Removed: cleanup_onesignal_subscriptions (simple mode)
    // Removed: clear_onesignal_data (simple mode)

    /**
     * Save notification preferences (simple mode)
     */
    public function save_preferences()
    {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        $nonce_ok = $nonce && (wp_verify_nonce($nonce, 'deal_notifications_save') || wp_verify_nonce($nonce, 'deal_notifications_nonce'));
        if (!$nonce_ok) {
            wp_send_json_error('Security verification failed');
            return;
        }

        // Resolve user and permissions
        $current = get_current_user_id();
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : $current;
        if (!$user_id) {
            wp_send_json_error('Not logged in');
            return;
        }
        if ($user_id !== $current && !current_user_can('edit_user', $user_id)) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Inputs
        $enabled = isset($_POST['notifications_enabled']) && $_POST['notifications_enabled'] === '1' ? '1' : '0';
        $delivery = isset($_POST['delivery_methods']) && is_array($_POST['delivery_methods']) ? (array) $_POST['delivery_methods'] : [];
        $discount = isset($_POST['user_discount_filter']) ? sanitize_text_field($_POST['user_discount_filter']) : '';
        $cats     = isset($_POST['user_category_filter']) && is_array($_POST['user_category_filter']) ? (array) $_POST['user_category_filter'] : [];
        $stores   = isset($_POST['user_store_filter']) && is_array($_POST['user_store_filter']) ? (array) $_POST['user_store_filter'] : [];

        // Sanitize delivery methods to known values
        $allowed_methods = ['email', 'webpush', 'telegram'];
        $delivery = array_values(array_intersect($allowed_methods, array_map('strval', $delivery)));

        // Sanitize lists to integers (as strings)
        $valid_categories = array_values(array_filter(array_map('intval', $cats), function($v){ return $v > 0; }));
        $valid_stores     = array_values(array_filter(array_map('intval', $stores), function($v){ return $v > 0; }));

        // Persist
        update_user_meta($user_id, 'notifications_enabled', $enabled);
        update_user_meta($user_id, 'notification_delivery_methods', $delivery);
        update_user_meta($user_id, 'user_discount_filter', $discount);
        update_user_meta($user_id, 'user_category_filter', $valid_categories);
        update_user_meta($user_id, 'user_store_filter', $valid_stores);

        // Log (best-effort)
        $this->log_preference_update($user_id, [
            'action' => 'preferences_saved',
            'timestamp' => current_time('mysql'),
            'enabled' => $enabled,
            'methods' => $delivery,
            'discount' => $discount,
            'categories' => $valid_categories,
            'stores' => $valid_stores
        ]);

        wp_send_json_success('Preferences saved successfully');
    }

    /**
     * Log preference updates for monitoring (best-effort, no hard failure)
     */
    private function log_preference_update($user_id, $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_log';
        $payload = wp_json_encode($data);
        // Insert only if table exists; ignore failures
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists === $table) {
            // Try a generic schema: user_id, event, data, created_at
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'event' => 'preferences',
                'data' => $payload,
                'created_at' => current_time('mysql')
            ]);
        }
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Ajax] Pref log for user ' . $user_id . ': ' . $payload);
        }
    }
}
