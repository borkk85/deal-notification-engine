<?php
namespace DNE\Admin;

/**
 * Handles all AJAX requests for the plugin
 * Migrated from theme for better security
 */
class Ajax_Handler {
    
    /**
     * Initialize AJAX handlers
     */
    public function init() {
        // Save notification preferences
        add_action('wp_ajax_save_deal_notification_preferences', [$this, 'save_preferences']);
        add_action('wp_ajax_nopriv_save_deal_notification_preferences', [$this, 'save_preferences']);
        
        // Telegram verification
        add_action('wp_ajax_verify_telegram_connection', [$this, 'verify_telegram']);
        add_action('wp_ajax_nopriv_verify_telegram_connection', [$this, 'verify_telegram']);
        
        // Telegram disconnection
        add_action('wp_ajax_disconnect_telegram', [$this, 'disconnect_telegram']);
        add_action('wp_ajax_nopriv_disconnect_telegram', [$this, 'disconnect_telegram']);
    }
    
    /**
     * Save user notification preferences
     * Enhanced security over theme version
     */
    public function save_preferences() {
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
    public function verify_telegram() {
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
    public function disconnect_telegram() {
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
            $delivery_methods = array_filter($delivery_methods, function($method) {
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
    private function log_preference_update($user_id, $data) {
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