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
        // Strict rule per project: post must be in the 'active-deals' category
        if (!function_exists('has_category') || !has_category('active-deals', $post_id)) {
            // Allow manual override via post meta for edge cases
            $override = get_post_meta($post_id, '_is_deal_post', true);
            return $override === '1';
        }

        return true;
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
        
        // Extract discount from post meta first (preferred), fallback to title/content
        $meta_discount = get_post_meta($post_id, '_discount_percentage', true);
        if ($meta_discount !== '' && $meta_discount !== null) {
            $data['discount'] = intval($meta_discount);
        } else {
            $content = $post->post_title . ' ' . $post->post_content;
            if (preg_match('/(\d+)\s*%/', $content, $matches)) {
                $data['discount'] = intval($matches[1]);
            }
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

            case 'webpush':
                // Optional skip of targeted webpush if broadcast is enabled elsewhere (off by default)
                $skip_targeted = get_option('dne_skip_targeted_webpush_on_broadcast', '0') === '1';
                if ($skip_targeted) {
                    // Consider as handled to avoid retries; log as skipped
                    $this->queue->mark_sent($notification->id);
                    $this->queue->log_activity([
                        'user_id' => $notification->user_id,
                        'post_id' => $notification->post_id,
                        'delivery_method' => 'webpush',
                        'action' => 'webpush_skipped_broadcast',
                        'status' => 'success',
                        'details' => ['reason' => 'Skipped due to broadcast toggle']
                    ]);
                    $success = true;
                    break;
                }

                $onesignal = new \DNE\Integrations\OneSignal();
                $result = $onesignal->send_notification($user->ID, $post);
                $success = $result['success'];
                $error_message = $result['message'] ?? '';
                break;

            default:
                $success = false;
                $error_message = 'Unknown delivery method: ' . $notification->delivery_method;
                break;
        }// Update notification status
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
