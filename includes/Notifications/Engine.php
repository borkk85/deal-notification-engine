<?php
namespace DNE\Notifications;

/**
 * Main notification processing engine - orchestrates the notification process
 */
class Engine {
    
    /**
     * Filter instance
     */
    private $filter;
    
    /**
     * Queue instance
     */
    private $queue;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->filter = new Filter();
        $this->queue = new Queue();
    }
    
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
        
        // Find matching users using Filter
        $matched_users = $this->filter->find_matching_users($deal_data);
        
        // Queue notifications using Queue manager
        $queued = $this->queue->add_to_queue($matched_users, $post_id, $this->filter);
        
        // Log the queue operation
        if ($queued > 0) {
            $this->queue->log_activity([
                'post_id' => $post_id,
                'action' => 'notifications_queued',
                'status' => 'success',
                'details' => [
                    'users_matched' => count($matched_users),
                    'notifications_queued' => $queued
                ]
            ]);
        }
        
        // Process immediately if configured
        if (get_option('dne_process_immediately') === '1') {
            $this->process_queue();
        }
    }
    
    /**
     * Check if post is a deal
     */
    private function is_deal_post($post_id) {
        // Allow all posts to be considered deals if they have:
        // 1. Deal-related categories/tags
        // 2. Product categories taxonomy
        // 3. Store type taxonomy
        // 4. Discount percentage in content
        
        // Check for product categories (your custom taxonomy)
        $product_cats = wp_get_object_terms($post_id, 'product_categories', ['fields' => 'ids']);
        if (!is_wp_error($product_cats) && !empty($product_cats)) {
            return true; // Has product categories = likely a deal
        }
        
        // Check for stores (your custom taxonomy)
        $stores = wp_get_object_terms($post_id, 'store_type', ['fields' => 'ids']);
        if (!is_wp_error($stores) && !empty($stores)) {
            return true; // Has store = likely a deal
        }
        
        // Check for discount percentage in content
        $post = get_post($post_id);
        if ($post) {
            $content = $post->post_title . ' ' . $post->post_content;
            if (preg_match('/\d+\s*%/', $content)) {
                return true; // Has percentage = likely a deal
            }
        }
        
        // Check for deal-related categories
        $categories = wp_get_post_categories($post_id);
        if (!empty($categories)) {
            $deal_categories = get_terms([
                'taxonomy' => 'category',
                'name__like' => 'deal',
                'fields' => 'ids',
                'hide_empty' => false
            ]);
            
            if (!is_wp_error($deal_categories) && array_intersect($categories, $deal_categories)) {
                return true;
            }
        }
        
        // Check for deal-related tags
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        $deal_keywords = ['deal', 'offer', 'discount', 'sale', 'promo', 'coupon', 'save'];
        
        foreach ($tags as $tag) {
            foreach ($deal_keywords as $keyword) {
                if (stripos($tag, $keyword) !== false) {
                    return true;
                }
            }
        }
        
        // Check post title for deal keywords
        $title = get_the_title($post_id);
        foreach ($deal_keywords as $keyword) {
            if (stripos($title, $keyword) !== false) {
                return true;
            }
        }
        
        // Allow manual override via post meta
        $is_deal = get_post_meta($post_id, '_is_deal_post', true);
        if ($is_deal === '1') {
            return true;
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
     * Process notification queue
     */
    public function process_queue() {
        // Get batch of pending notifications
        $notifications = $this->queue->get_pending_batch();
        
        foreach ($notifications as $notification) {
            $this->send_notification($notification);
        }
    }
    
    /**
     * Send individual notification
     */
    private function send_notification($notification) {
        // Update attempts
        $this->queue->increment_attempts($notification->id);
        
        // Get post data
        $post = get_post($notification->post_id);
        if (!$post) {
            $this->queue->mark_failed($notification->id, 'Post not found');
            return;
        }
        
        // Get user data
        $user = get_userdata($notification->user_id);
        if (!$user) {
            $this->queue->mark_failed($notification->id, 'User not found');
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
                
            // case 'webpush':
            //     $onesignal = new \DNE\Integrations\OneSignal();
            //     $result = $onesignal->send_notification($user->ID, $post);
            //     $success = $result['success'];
            //     $error_message = $result['message'] ?? '';
            //     break;
                
            default:
                $success = false;
                $error_message = 'Unknown delivery method: ' . $notification->delivery_method;
                break;
        }
        
        // Update notification status
        if ($success) {
            $this->queue->mark_sent($notification->id);
            
            // Log success
            $this->queue->log_activity([
                'user_id' => $notification->user_id,
                'post_id' => $notification->post_id,
                'delivery_method' => $notification->delivery_method,
                'action' => 'notification_sent',
                'status' => 'success',
                'sent_at' => current_time('mysql')
            ]);
        } else {
            // Check if max attempts reached
            if ($notification->attempts >= 2) {
                $this->queue->mark_failed($notification->id, $error_message);
                
                // Log failure
                $this->queue->log_activity([
                    'user_id' => $notification->user_id,
                    'post_id' => $notification->post_id,
                    'delivery_method' => $notification->delivery_method,
                    'action' => 'notification_failed',
                    'status' => 'failed',
                    'details' => ['error' => $error_message]
                ]);
            } else {
                // Update error message for retry
                $this->queue->update_error($notification->id, $error_message);
            }
        }
    }
    
    /**
     * Clean up old logs
     */
    public function cleanup_logs() {
        $this->queue->cleanup();
    }
}