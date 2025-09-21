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

        // Reset failed notifications (admin only)
        add_action('wp_ajax_dne_reset_failed_notifications', [$this, 'reset_failed_notifications']);

        // Debug settings (admin only)
        add_action('wp_ajax_dne_debug_settings', [$this, 'debug_settings']);

        // Clear all notifications (admin only)
        add_action('wp_ajax_dne_clear_all_notifications', [$this, 'clear_all_notifications']);

        add_action('wp_ajax_dne_submit_feedback', [$this, 'submit_feedback']);
        add_action('wp_ajax_nopriv_dne_submit_feedback', [$this, 'submit_feedback']);
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
            $delivery_methods = array_filter($delivery_methods, function ($method) {
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
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!$nonce || (!wp_verify_nonce($nonce, 'telegram_verify') && !wp_verify_nonce($nonce, 'dne_ajax_nonce'))) {
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dne_ajax_nonce')) {
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
     * Submit user feedback
     */
    public function submit_feedback() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dne_feedback_submit')) {
            wp_send_json_error('Security verification failed');
            return;
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $feedback_type = isset($_POST['feedback_type']) ? sanitize_text_field($_POST['feedback_type']) : '';
        $feedback_message = isset($_POST['feedback_message']) ? sanitize_textarea_field($_POST['feedback_message']) : '';

        // Check permissions
        if ($user_id !== get_current_user_id() && !current_user_can('edit_user', $user_id)) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Validate input
        if (empty($feedback_type)) {
            wp_send_json_error('Please select a feedback type');
            return;
        }

        if (empty($feedback_message)) {
            wp_send_json_error('Please enter your feedback message');
            return;
        }

        if (strlen($feedback_message) < 10) {
            wp_send_json_error('Please provide more detailed feedback (at least 10 characters)');
            return;
        }

        // Validate feedback type
        $allowed_types = ['satisfaction', 'suggestion', 'complaint', 'question'];
        if (!in_array($feedback_type, $allowed_types)) {
            wp_send_json_error('Invalid feedback type');
            return;
        }

        // Rate limiting - max 5 feedback submissions per hour per user
        $rate_limit_key = 'feedback_submissions_' . $user_id;
        $submissions = (int) get_transient($rate_limit_key);
        if ($submissions >= 5) {
            wp_send_json_error('Too many submissions. Please wait before submitting more feedback.');
            return;
        }

        // Save feedback to database
        global $wpdb;
        $table = $wpdb->prefix . 'dne_user_feedback';

        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'type' => $feedback_type,
                'message' => $feedback_message,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            wp_send_json_error('Failed to save feedback. Please try again.');
            return;
        }

        // Update rate limiting counter
        set_transient($rate_limit_key, $submissions + 1, HOUR_IN_SECONDS);

        // Log the feedback submission (optional - uses existing log table)
        if (class_exists('DNE\Notifications\Queue')) {
            $queue = new \DNE\Notifications\Queue();
            $queue->log_activity([
                'user_id' => $user_id,
                'action' => 'feedback_submitted',
                'status' => 'success',
                'details' => [
                    'type' => $feedback_type,
                    'message_length' => strlen($feedback_message)
                ]
            ]);
        }

        // Debug logging
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Feedback] New feedback submitted - User: ' . $user_id . ', Type: ' . $feedback_type);
        }

        wp_send_json_success('Thank you for your feedback! We appreciate you taking the time to help us improve.');
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
        check_ajax_referer('dne_ajax_nonce', 'nonce');
        // $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        // $nonce_ok = $nonce && (wp_verify_nonce($nonce, 'dne_ajax_nonce') || wp_verify_nonce($nonce, 'dne_ajax_nonce'));
        // if (!$nonce_ok) {
        //     wp_send_json_error('Security verification failed');
        //     return;
        // }

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
        $discount = isset($_POST['user_discount_filter']) ? (int) $_POST['user_discount_filter'] : 0;
        // $discount = isset($_POST['user_discount_filter']) ? sanitize_text_field($_POST['user_discount_filter']) : '';
        $cats     = isset($_POST['user_category_filter']) && is_array($_POST['user_category_filter']) ? (array) $_POST['user_category_filter'] : [];
        $stores   = isset($_POST['user_store_filter']) && is_array($_POST['user_store_filter']) ? (array) $_POST['user_store_filter'] : [];

        // Sanitize delivery methods to known values
        $allowed_methods = ['email', 'webpush', 'telegram'];
        $delivery = array_values(array_intersect($allowed_methods, array_map('strval', $delivery)));

        if ($discount > 0) {
            if ($discount < 10) $discount = 10;
            if ($discount > 90) $discount = 90;
            // $discount = $discount - ($discount % 5); 
        }


        // Sanitize lists to integers (as strings)
        $valid_categories = array_values(array_filter(array_map('intval', $cats), function ($v) {
            return $v > 0;
        }));
        $valid_stores     = array_values(array_filter(array_map('intval', $stores), function ($v) {
            return $v > 0;
        }));

        // ---- Tier clamp (authoritative) ----
        // Tier allow-list for channels
        $tier_level = 1;
        $user_obj = get_userdata($user_id);
        if ($user_obj && is_array($user_obj->roles)) {
            foreach ($user_obj->roles as $r) {
                if (strpos($r, 'um_deal') !== false && strpos($r, 'tier') !== false) {
                    if (strpos($r, 'tier-3') !== false || strpos($r, 'tier_3') !== false) {
                        $tier_level = 3;
                        break;
                    }
                    if (strpos($r, 'tier-2') !== false || strpos($r, 'tier_2') !== false) {
                        $tier_level = 2;
                        break;
                    }
                }
            }
        }
        // Allowed channels per tier (authoritative clamp)
        // 1: email only
        // 2: email + telegram
        // 3: email + telegram + webpush
        $allowed_by_tier = [
            1 => ['email'],
            2 => ['email', 'telegram'],
            3 => ['email', 'telegram', 'webpush'],
        ][$tier_level];

        // Keep only channels allowed for this tier
        $delivery = array_values(array_intersect($delivery, $allowed_by_tier));

        // Same limits as filter.php
        $tier_limits = [
            1 => ['total' => 1, 'categories' => 1, 'stores' => 1],
            2 => ['total' => 7, 'categories' => 3, 'stores' => 3],
            3 => ['total' => 999, 'categories' => 999, 'stores' => 999],
        ];
        $limits = $tier_limits[$tier_level];

        // De-dup & normalize
        $valid_categories = array_values(array_unique($valid_categories));
        $valid_stores     = array_values(array_unique($valid_stores));

        // Bucket caps first
        if (count($valid_categories) > $limits['categories']) {
            $valid_categories = array_slice($valid_categories, 0, $limits['categories']);
        }
        if (count($valid_stores) > $limits['stores']) {
            $valid_stores = array_slice($valid_stores, 0, $limits['stores']);
        }

        // Compute "total filters" where discount counts as 1 if set
        $filter_count = ($discount > 0 ? 1 : 0) + count($valid_categories) + count($valid_stores);

        // If over total, trim stores first, then categories, then discount last
        while ($filter_count > $limits['total'] && !empty($valid_stores)) {
            array_pop($valid_stores);
            $filter_count--;
        }
        while ($filter_count > $limits['total'] && !empty($valid_categories)) {
            array_pop($valid_categories);
            $filter_count--;
        }
        if ($filter_count > $limits['total'] && $discount > 0) {
            $discount = 0;
            $filter_count--;
        }

        // Persist
        update_user_meta($user_id, 'notifications_enabled', $enabled);
        update_user_meta($user_id, 'notification_delivery_methods', $delivery);
        update_user_meta($user_id, 'user_discount_filter', $discount);
        // update_user_meta($user_id, 'user_discount_filter', $discount);
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

        // Snapshot gating state after save
        if (!function_exists('dne_debug')) {
            function dne_debug($msg)
            {
                if (defined('WP_DEBUG') && WP_DEBUG && get_option('dne_debug_mode', '0') === '1') error_log('[DNE] ' . $msg);
            }
        }
        $filter = new \DNE\Notifications\Filter();
        dne_debug("post-save user {$user_id}: enabled={$enabled} methods=" . json_encode($delivery)
            . " allow_email="   . ($filter->user_allows_channel($user_id, 'email')    ? '1' : '0')
            . " allow_telegram=" . ($filter->user_allows_channel($user_id, 'telegram') ? '1' : '0')
            . " allow_webpush=" . ($filter->user_allows_channel($user_id, 'webpush')  ? '1' : '0'));


        wp_send_json_success('Preferences saved successfully');
    }

    /**
     * Log preference updates for monitoring (best-effort, no hard failure)
     */
    private function log_preference_update($user_id, $data)
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'dne_notification_log';
        $payload = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // table exists?
        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
        if ($exists !== $table) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE Ajax] Pref log skipped, table missing: ' . $table);
            }
            return;
        }

        // which columns does this table have?
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

        // CASE 1: legacy schema (event/data)
        if (in_array('event', $cols, true) && in_array('data', $cols, true)) {
            $wpdb->insert($table, [
                'user_id'    => (int) $user_id,
                'event'      => 'preference_update',
                'data'       => $payload,
                'created_at' => current_time('mysql'),
            ], ['%d', '%s', '%s', '%s']);

            // CASE 2: current schema (action/details) — matches your screenshot
        } else {
            $wpdb->insert($table, [
                'user_id'         => (int) $user_id,
                'post_id'         => null,                 // keep NULL like existing rows
                'delivery_method' => null,                 // N/A for preference updates
                'action'          => 'preference_update',
                'status'          => 'success',
                'sent_at'         => null,
                'details'         => $payload,             // <-- your same $payload here
                'created_at'      => current_time('mysql'),
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        }

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Ajax] Pref log for user ' . $user_id . ': ' . $payload);
        }
    }

    /**
     * Process notification queue manually (admin only)
     */
    public function process_queue_manually()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        dne_debug("Manual queue processing triggered");

        // FIXED: Direct call instead of action hook
        $engine = new \DNE\Notifications\Engine();
        $engine->process_queue();

        dne_debug("Manual queue processing completed");

        // Return quick stats if possible
        if (class_exists('DNE\\Notifications\\Queue')) {
            $queue = new \DNE\Notifications\Queue();
            $stats = $queue->get_statistics();
            wp_send_json_success(sprintf(
                'Queue processed. Pending: %d, Sent today: %d, Failed: %d',
                intval($stats['pending'] ?? 0),
                intval($stats['sent_today'] ?? 0),
                intval($stats['failed'] ?? 0)
            ));
        }

        wp_send_json_success('Queue processing triggered');
    }

    /**
     * Reset failed notifications for retry (admin only)
     */
    public function reset_failed_notifications()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        dne_debug("Resetting failed notifications");

        $queue = new \DNE\Notifications\Queue();
        $reset_count = $queue->reset_failed_notifications();

        wp_send_json_success("Reset {$reset_count} failed notifications for retry");
    }

    /**
     * Debug plugin settings and configuration (admin only)
     */
    public function debug_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Check key settings
        $settings = [
            'dne_enabled' => get_option('dne_enabled', 'NOT_SET'),
            'dne_process_immediately' => get_option('dne_process_immediately', 'NOT_SET'),
            'dne_debug_mode' => get_option('dne_debug_mode', 'NOT_SET'),
        ];

        // Check WordPress debug
        $wp_debug = defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false';

        // Check if hook is registered
        global $wp_filter;
        $hook_registered = isset($wp_filter['transition_post_status']) ? 'yes' : 'no';

        $debug_info = [
            'Plugin Settings' => $settings,
            'WP_DEBUG' => $wp_debug,
            'Post transition hook registered' => $hook_registered,
            'Current time' => current_time('mysql'),
        ];

        wp_send_json_success($debug_info);
    }

    /**
     * Clear all notifications from queue (admin only)
     */
    public function clear_all_notifications()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        dne_debug("Clearing all notifications from queue");

        $queue = new \DNE\Notifications\Queue();
        $result = $queue->clear_all_notifications();

        $message = sprintf(
            'Cleared %d notifications (Pending: %d, Sent: %d, Failed: %d)',
            $result['deleted'],
            $result['stats']['pending'],
            $result['stats']['sent'],
            $result['stats']['failed']
        );

        wp_send_json_success($message);
    }
}
