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

        // OneSignal subscription tracking with cleanup
        add_action('wp_ajax_dne_onesignal_prepare_subscription', [$this, 'prepare_onesignal_subscription']);
        add_action('wp_ajax_dne_onesignal_subscribed', [$this, 'track_onesignal_subscription']);
        add_action('wp_ajax_dne_onesignal_unsubscribed', [$this, 'track_onesignal_unsubscription']);
        
        // OneSignal cleanup and verification
        add_action('wp_ajax_dne_cleanup_onesignal_subscriptions', [$this, 'cleanup_onesignal_subscriptions']);
        add_action('wp_ajax_dne_verify_onesignal_subscription', [$this, 'verify_onesignal_subscription']);
        add_action('wp_ajax_dne_clear_onesignal_data', [$this, 'clear_onesignal_data']);
        
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
    public function prepare_onesignal_subscription()
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

        // Initialize REST API helper
        $api = new \DNE\Integrations\OneSignal_API();
        
        if (!$api->is_configured()) {
            wp_send_json_error('OneSignal API not configured');
            return;
        }

        // Cleanup disabled subscriptions before allowing new subscription
        $cleanup_result = $api->cleanup_disabled_subscriptions($user_id);
        
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Ajax] Cleanup result for user ' . $user_id . ': ' . json_encode($cleanup_result));
        }

        // Log the cleanup
        $this->log_preference_update($user_id, [
            'action' => 'onesignal_cleanup',
            'timestamp' => current_time('mysql'),
            'deleted_subscriptions' => $cleanup_result['deleted'],
            'total_found' => $cleanup_result['subscriptions_found']
        ]);

        wp_send_json_success([
            'message' => 'Ready for subscription',
            'cleanup' => $cleanup_result
        ]);
    }

    /**
     * Track OneSignal subscription with REST API verification
     * 
     * @since 1.2.0 - Enhanced with REST API verification
     */
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
        
        // Verify subscription with REST API if subscription ID provided
        if (!empty($subscription_id)) {
            $api = new \DNE\Integrations\OneSignal_API();
            
            if ($api->is_configured()) {
                $verification = $api->verify_subscription($subscription_id, $user_id);
                
                if (!$verification['is_valid']) {
                    if (get_option('dne_debug_mode') === '1') {
                        error_log('[DNE Ajax] Subscription verification failed: ' . json_encode($verification['issues']));
                    }
                    
                    // Try to fix issues
                    if (in_array('External ID not found', $verification['issues']) || 
                        in_array('Subscription does not belong to External ID', $verification['issues'])) {
                        // Set the External ID
                        $api->set_external_id($subscription_id, $user_id);
                    }
                    
                    if (in_array('Subscription is disabled', $verification['issues'])) {
                        // Enable the subscription
                        $api->enable_subscription($subscription_id);
                    }
                }
            }
        }

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
     * Manually cleanup OneSignal subscriptions for a user
     * 
     * @since 1.2.0
     */
    public function cleanup_onesignal_subscriptions()
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

        // Initialize REST API helper
        $api = new \DNE\Integrations\OneSignal_API();
        
        if (!$api->is_configured()) {
            wp_send_json_error('OneSignal API not configured');
            return;
        }

        // Perform cleanup
        $cleanup_result = $api->cleanup_disabled_subscriptions($user_id);
        
        // Log the cleanup
        $this->log_preference_update($user_id, [
            'action' => 'manual_onesignal_cleanup',
            'timestamp' => current_time('mysql'),
            'results' => $cleanup_result
        ]);

        if ($cleanup_result['deleted'] > 0) {
            wp_send_json_success('Cleaned up ' . $cleanup_result['deleted'] . ' disabled subscriptions');
        } else {
            wp_send_json_success('No disabled subscriptions found to clean up');
        }
    }

    /**
     * Save user notification preferences
     * Enhanced to properly handle OneSignal state changes
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

        // Get previous delivery methods to detect changes
        $previous_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
        if (!is_array($previous_methods)) {
            $previous_methods = [];
        }

        // Sanitize and save preferences
        $notifications_enabled = isset($_POST['notifications_enabled']) && $_POST['notifications_enabled'] === '1' ? '1' : '0';
        update_user_meta($user_id, 'notifications_enabled', $notifications_enabled);

        // Save delivery methods with validation
        $allowed_methods = ['email', 'webpush', 'telegram'];
        $delivery_methods = isset($_POST['delivery_methods']) ? (array)$_POST['delivery_methods'] : [];
        $delivery_methods = array_intersect($delivery_methods, $allowed_methods);
        update_user_meta($user_id, 'notification_delivery_methods', $delivery_methods);

        // Check if webpush was removed
        if (in_array('webpush', $previous_methods) && !in_array('webpush', $delivery_methods)) {
            // User unchecked webpush - clear OneSignal data
            delete_user_meta($user_id, 'onesignal_subscribed');
            delete_user_meta($user_id, 'onesignal_external_id_set');
            delete_user_meta($user_id, 'onesignal_subscription_id');
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Ajax] Webpush removed from user ' . $user_id . ' delivery methods');
            }
        }

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
            'previous_methods' => $previous_methods,
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
     * Verify OneSignal subscription with backend
     * Enhanced with v16 REST API verification
     * 
     * @since 1.2.0
     */
    public function verify_onesignal_subscription()
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
        if (empty($subscription_id)) {
            wp_send_json_error('No subscription ID provided');
            return;
        }

        // Initialize REST API helper
        $api = new \DNE\Integrations\OneSignal_API();
        
        if (!$api->is_configured()) {
            wp_send_json_error('OneSignal API not configured');
            return;
        }

        // Verify subscription
        $verification = $api->verify_subscription($subscription_id, $user_id);
        
        if ($verification['is_valid']) {
            // Valid subscription
            update_user_meta($user_id, 'onesignal_subscription_id', $subscription_id);
            update_user_meta($user_id, 'onesignal_subscribed', '1');
            update_user_meta($user_id, 'onesignal_external_id_set', '1');
            
            wp_send_json_success('Subscription verified');
        } else {
            // Invalid subscription
            delete_user_meta($user_id, 'onesignal_subscribed');
            delete_user_meta($user_id, 'onesignal_external_id_set');
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE] OneSignal verification failed: ' . json_encode($verification['issues']));
            }
            
            wp_send_json_error('Subscription verification failed: ' . implode(', ', $verification['issues']));
        }
    }

    /**
     * Clear all OneSignal data for a user
     */
    public function clear_onesignal_data()
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

        // Clear all OneSignal related meta
        delete_user_meta($user_id, 'onesignal_player_id');
        delete_user_meta($user_id, 'onesignal_subscription_id');
        delete_user_meta($user_id, 'onesignal_subscribed');
        delete_user_meta($user_id, 'onesignal_external_id_set');
        delete_user_meta($user_id, 'onesignal_subscription_date');
        
        // Remove webpush from delivery methods
        $delivery_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
        if (is_array($delivery_methods)) {
            $delivery_methods = array_filter($delivery_methods, function($method) {
                return $method !== 'webpush';
            });
            update_user_meta($user_id, 'notification_delivery_methods', array_values($delivery_methods));
        }
        
        // Log the action
        $this->log_preference_update($user_id, [
            'action' => 'onesignal_data_cleared',
            'timestamp' => current_time('mysql')
        ]);
        
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE] OneSignal data cleared for user ' . $user_id);
        }

        wp_send_json_success('OneSignal data cleared');
    }

    /**
     * Save OneSignal player ID (legacy support)
     */
    public function save_onesignal_player_id()
    {
        // Verify nonce (support new and legacy actions)
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'save_player_id') && !wp_verify_nonce($nonce, 'dne_ajax_nonce')) {
            wp_send_json_error('Security verification failed');
            return;
        }

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
            'action' => 'onesignal_player_id_saved',
            'player_id' => substr($player_id, 0, 10) . '...' // Log partial ID for privacy
        ]);

        wp_send_json_success('Player ID saved');
    }

    /**
     * Remove OneSignal player ID (legacy support)
     */
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
        delete_user_meta($user_id, 'onesignal_subscribed');
        delete_user_meta($user_id, 'onesignal_external_id_set');

        wp_send_json_success();
    }

    /**
     * Send test notification (admin only)
     * Enhanced with v16 subscription ID support
     * 
     * @since 1.2.0
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
        }

        if ($method === 'telegram' && $custom_telegram) {
            $original_values['telegram'] = get_user_meta($user_id, 'telegram_chat_id', true);
            update_user_meta($user_id, 'telegram_chat_id', $custom_telegram);
            $restore_needed = true;
        }

        if ($method === 'webpush' && $custom_onesignal) {
            // Store original for restoration
            $original_values['onesignal'] = get_user_meta($user_id, 'onesignal_subscription_id', true);
            $restore_needed = true;
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
                // Pass custom ID directly to send_notification
                $result = $onesignal->send_notification($user_id, $post, $custom_onesignal ?: null);
                break;
        }

        // Restore original values if we overrode them
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

        // Debug logging of result
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
}