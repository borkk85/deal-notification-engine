<?php
namespace DNE\Notifications;

/**
 * Main notification processing engine
 */
class Engine {
    
    /**
     * Initialize the engine
     */
    public function init() {
        // Hook into cron for queue processing
        add_action('dne_process_notification_queue', [$this, 'process_queue']);
        add_action('dne_cleanup_old_logs', [$this, 'cleanup_logs']);
    }
    
    /**
     * Handle new deal publication
     * This is the main entry point when a deal is published
     */
    public function handle_new_deal($post_id, $post) {
        // Check if notifications are enabled
        if (get_option('dne_enabled') !== '1') {
            return;
        }
        
        // Verify this is a deal post (you may need to adjust this check)
        if ($post->post_type !== 'post') {
            return;
        }
        
        // Check if post has deal-related categories or tags
        if (!$this->is_deal_post($post_id)) {
            return;
        }
        
        // Get deal details
        $deal_data = $this->extract_deal_data($post_id, $post);
        
        // Find matching users
        $matched_users = $this->find_matching_users($deal_data);
        
        // Queue notifications for matched users
        $this->queue_notifications($matched_users, $post_id);
        
        // Process immediately if configured
        if (get_option('dne_process_immediately') === '1') {
            $this->process_queue();
        }
    }
    
    /**
     * Check if post is a deal
     */
    private function is_deal_post($post_id) {
        // Check for deal-related categories
        $categories = wp_get_post_categories($post_id);
        $deal_categories = get_terms([
            'taxonomy' => 'category',
            'name__like' => 'deal',
            'fields' => 'ids'
        ]);
        
        if (array_intersect($categories, $deal_categories)) {
            return true;
        }
        
        // Check for deal-related tags
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        $deal_keywords = ['deal', 'offer', 'discount', 'sale', 'promo'];
        
        foreach ($tags as $tag) {
            foreach ($deal_keywords as $keyword) {
                if (stripos($tag, $keyword) !== false) {
                    return true;
                }
            }
        }
        
        // Check post title
        $title = get_the_title($post_id);
        foreach ($deal_keywords as $keyword) {
            if (stripos($title, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract deal data from post
     */
    private function extract_deal_data($post_id, $post) {
        $data = [
            'post_id' => $post_id,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'excerpt' => wp_trim_words($post->post_content, 30),
            'discount' => 0,
            'categories' => [],
            'stores' => []
        ];
        
        // Extract discount percentage from title or content
        $content = $post->post_title . ' ' . $post->post_content;
        if (preg_match('/(\d+)\s*%/', $content, $matches)) {
            $data['discount'] = intval($matches[1]);
        }
        
        // Get product categories
        $product_cats = wp_get_object_terms($post_id, 'product_categories', ['fields' => 'ids']);
        if (!is_wp_error($product_cats)) {
            $data['categories'] = $product_cats;
        }
        
        // Get stores
        $stores = wp_get_object_terms($post_id, 'store_type', ['fields' => 'ids']);
        if (!is_wp_error($stores)) {
            $data['stores'] = $stores;
        }
        
        return $data;
    }
    
    /**
     * Find users whose preferences match the deal
     */
    private function find_matching_users($deal_data) {
        global $wpdb;
        $matched_users = [];
        
        // Get all users with deal tier roles
        $users = get_users([
            'role__in' => ['um_deal-tier-1', 'um_deal-tier_1', 'um_deal-tier-2', 'um_deal-tier_2', 'um_deal-tier-3', 'um_deal-tier_3'],
            'meta_key' => 'notifications_enabled',
            'meta_value' => '1'
        ]);
        
        foreach ($users as $user) {
            // Check if user's filters match the deal
            if ($this->user_matches_deal($user->ID, $deal_data)) {
                $matched_users[] = $user->ID;
            }
        }
        
        return $matched_users;
    }
    
    /**
     * Check if user's preferences match the deal
     */
    private function user_matches_deal($user_id, $deal_data) {
        // Get user preferences
        $discount_filter = get_user_meta($user_id, 'user_discount_filter', true);
        $category_filter = get_user_meta($user_id, 'user_category_filter', true);
        $store_filter = get_user_meta($user_id, 'user_store_filter', true);
        
        // Check discount filter
        if (!empty($discount_filter) && $deal_data['discount'] < intval($discount_filter)) {
            return false;
        }
        
        // Check category filter
        if (!empty($category_filter) && is_array($category_filter)) {
            if (empty(array_intersect($category_filter, $deal_data['categories']))) {
                return false;
            }
        }
        
        // Check store filter
        if (!empty($store_filter) && is_array($store_filter)) {
            if (empty(array_intersect($store_filter, $deal_data['stores']))) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Queue notifications for matched users
     */
    private function queue_notifications($user_ids, $post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_queue';
        
        foreach ($user_ids as $user_id) {
            // Get user's delivery methods
            $delivery_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
            if (empty($delivery_methods)) {
                $delivery_methods = ['email']; // Default to email
            }
            
            // Queue notification for each delivery method
            foreach ($delivery_methods as $method) {
                // Check if notification already queued (prevent duplicates)
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE user_id = %d AND post_id = %d AND delivery_method = %s AND status = 'pending'",
                    $user_id, $post_id, $method
                ));
                
                if (!$exists) {
                    $wpdb->insert(
                        $table,
                        [
                            'user_id' => $user_id,
                            'post_id' => $post_id,
                            'delivery_method' => $method,
                            'status' => 'pending',
                            'scheduled_at' => current_time('mysql')
                        ],
                        ['%d', '%d', '%s', '%s', '%s']
                    );
                }
            }
        }
    }
    
    /**
     * Process notification queue
     */
    public function process_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_queue';
        
        // Get batch of pending notifications
        $batch_size = get_option('dne_batch_size', 50);
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE status = 'pending' 
             AND attempts < 3 
             AND scheduled_at <= NOW() 
             ORDER BY scheduled_at ASC 
             LIMIT %d",
            $batch_size
        ));
        
        foreach ($notifications as $notification) {
            $this->send_notification($notification);
        }
    }
    
    /**
     * Send individual notification
     */
    private function send_notification($notification) {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_queue';
        $log_table = $wpdb->prefix . 'dne_notification_log';
        
        // Update attempts
        $wpdb->update(
            $table,
            ['attempts' => $notification->attempts + 1],
            ['id' => $notification->id],
            ['%d'],
            ['%d']
        );
        
        // Get post data
        $post = get_post($notification->post_id);
        if (!$post) {
            $this->mark_notification_failed($notification->id, 'Post not found');
            return;
        }
        
        // Get user data
        $user = get_userdata($notification->user_id);
        if (!$user) {
            $this->mark_notification_failed($notification->id, 'User not found');
            return;
        }
        
        $success = false;
        $error_message = '';
        
        // Send based on delivery method
        switch ($notification->delivery_method) {
            case 'email':
                $email_sender = new \DNE\Integrations\Email();
                $result = $email_sender->send($user, $post);
                $success = $result['success'];
                $error_message = $result['message'] ?? '';
                break;
                
            case 'telegram':
                $telegram = new \DNE\Integrations\Telegram();
                $result = $telegram->send_notification($user->ID, $post);
                $success = $result['success'];
                $error_message = $result['message'] ?? '';
                break;
                
            case 'webpush':
                // OneSignal integration (stub for now)
                $success = false;
                $error_message = 'Web push not yet implemented';
                break;
        }
        
        // Update notification status
        if ($success) {
            $wpdb->update(
                $table,
                [
                    'status' => 'sent',
                    'processed_at' => current_time('mysql')
                ],
                ['id' => $notification->id],
                ['%s', '%s'],
                ['%d']
            );
            
            // Log success
            $wpdb->insert(
                $log_table,
                [
                    'user_id' => $notification->user_id,
                    'post_id' => $notification->post_id,
                    'delivery_method' => $notification->delivery_method,
                    'action' => 'notification_sent',
                    'status' => 'success',
                    'sent_at' => current_time('mysql')
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );
        } else {
            // Check if max attempts reached
            if ($notification->attempts >= 2) {
                $this->mark_notification_failed($notification->id, $error_message);
            } else {
                // Update error message for retry
                $wpdb->update(
                    $table,
                    ['error_message' => $error_message],
                    ['id' => $notification->id],
                    ['%s'],
                    ['%d']
                );
            }
        }
    }
    
    /**
     * Mark notification as failed
     */
    private function mark_notification_failed($notification_id, $error_message) {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_queue';
        
        $wpdb->update(
            $table,
            [
                'status' => 'failed',
                'error_message' => $error_message,
                'processed_at' => current_time('mysql')
            ],
            ['id' => $notification_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }
    
    /**
     * Clean up old logs
     */
    public function cleanup_logs() {
        global $wpdb;
        $log_table = $wpdb->prefix . 'dne_notification_log';
        $queue_table = $wpdb->prefix . 'dne_notification_queue';
        
        // Delete logs older than 30 days
        $wpdb->query(
            "DELETE FROM $log_table WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        // Delete processed queue items older than 7 days
        $wpdb->query(
            "DELETE FROM $queue_table WHERE status IN ('sent', 'failed') AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
    }
}