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
     * Accumulates posts to process at shutdown to avoid race with late meta/tax writes
     */
    private $deferred_posts = [];
    
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
        // Defer processing until end of request
        add_action('shutdown', [$this, 'process_deferred_posts']);
    }
    
    /**
     * Handle post status transitions - only trigger on actual new publications
     */
    public function handle_post_transition($new_status, $old_status, $post) {
        // Debug all transitions
        dne_debug("Post transition: {$post->ID} from '{$old_status}' to '{$new_status}'");
        
        // Only trigger on new publications (not edits, updates, or timestamp changes)
        if ($new_status === 'publish' && $old_status !== 'publish') {
            dne_debug("New publication detected: {$post->ID} - deferring processing until shutdown...");
            $this->deferred_posts[$post->ID] = true;
        } else {
            dne_debug("Skipping post {$post->ID} - not a new publication");
        }
    }

    /**
     * Run deferred processing at end of request so other plugins can finish writing meta/tax
     */
    public function process_deferred_posts() {
        if (empty($this->deferred_posts)) {
            return;
        }
        foreach (array_keys($this->deferred_posts) as $post_id) {
            $post = get_post($post_id);
            if (!$post) { continue; }
            dne_debug("Running deferred processing for post {$post_id}");
            $this->handle_new_deal($post_id, $post);
        }
        $this->deferred_posts = [];
    }

    /**
     * Handle new deal publication
     * This is the main entry point when a deal is published
     */
    public function handle_new_deal($post_id, $post) {
        dne_debug("handle_new_deal called for post {$post_id}");
        
        // Check if notifications are enabled
        $enabled = get_option('dne_enabled');
        dne_debug("DNE enabled setting: '{$enabled}'");
        if ($enabled !== '1') {
            dne_debug("DNE not enabled - aborting");
            return;
        }
        
        // Verify this is a deal post (you may need to adjust this check)
        dne_debug("Post type: {$post->post_type}");
        if ($post->post_type !== 'post') {
            dne_debug("Not a post type - aborting");
            return;
        }
        
        // Check if post has deal-related categories or tags
        $is_deal = $this->is_deal_post($post_id);
        dne_debug("is_deal_post result: " . ($is_deal ? 'true' : 'false'));
        if (!$is_deal) {
            dne_debug("Not a deal post - aborting");
            return;
        }
        
        // Ensure we don't interfere with OneSignal WP plugin's automatic notifications
        // This plugin handles its own targeted notifications, OneSignal WP handles its own
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Engine] Processing deal post: ' . $post_id . ' (running after OneSignal WP plugin)');
        }
        
        // Get deal details
        $deal_data = $this->extract_deal_data($post_id, $post);
        dne_debug("Deal data extracted: " . wp_json_encode($deal_data));
        
        // Find matching users using Filter
        $matched_users = $this->filter->find_matching_users($deal_data);
        dne_debug("Matched users count: " . count($matched_users) . " - Users: " . wp_json_encode($matched_users));
        
        // Queue notifications using Queue manager
        $queued = $this->queue->add_to_queue($matched_users, $post_id, $this->filter);
        dne_debug("Notifications queued: {$queued}");
        
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
        $process_immediately = get_option('dne_process_immediately');
        dne_debug("Process immediately setting: '{$process_immediately}'");
        if ($process_immediately === '1') {
            dne_debug("Processing queue immediately...");
            $this->process_queue();
        } else {
            dne_debug("Not processing immediately - queue will be processed by cron");
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
            // Try common alternative meta keys
            $alt_keys = [
                'discount_percentage',
                'deal_discount',
                'discount',
                'percentage',
                '_deal_discount',
                '_percentage',
            ];
            foreach ($alt_keys as $k) {
                $v = get_post_meta($post_id, $k, true);
                if ($v !== '' && $v !== null) {
                    if (preg_match('/(\d{1,3})/', (string) $v, $m)) {
                        $pct = intval($m[1]);
                        if ($pct >= 0 && $pct <= 100) { $data['discount'] = $pct; break; }
                    }
                }
            }

            // As a last resort, scan all meta values for a percentage pattern
            if (intval($data['discount']) === 0) {
                $all_meta = get_post_meta($post_id);
                if (is_array($all_meta)) {
                    foreach ($all_meta as $mk => $vals) {
                        if (!is_array($vals)) { $vals = [$vals]; }
                        foreach ($vals as $val) {
                            if (is_scalar($val) && preg_match('/(\d{1,3})\s*%/', (string) $val, $mm)) {
                                $pct = intval($mm[1]);
                                if ($pct >= 0 && $pct <= 100) { $data['discount'] = $pct; break 2; }
                            }
                        }
                    }
                }
            }

            if (intval($data['discount']) === 0) {
                $content = $post->post_title . ' ' . $post->post_content;
                if (preg_match('/(\d{1,3})\s*%/', $content, $matches)) {
                    $data['discount'] = intval($matches[1]);
                }
            }
        }

        // Debug: record discount source and value
        if (function_exists('dne_debug')) {
            dne_debug('Extract discount for post ' . $post_id . ': _discount_percentage=' . var_export($meta_discount, true) . ' -> final=' . $data['discount']);
        }
        
        // Get product categories
        $product_cats = wp_get_object_terms($post_id, 'product_categories', ['fields' => 'ids']);
        if (!is_wp_error($product_cats)) {
            $data['categories'] = $product_cats;
        }
        
        // Get stores (support alternate slugs if primary is empty)
        $stores = wp_get_object_terms($post_id, 'store_type', ['fields' => 'ids']);
        if (is_wp_error($stores) || empty($stores)) {
            foreach (['stores', 'store', 'shop'] as $alt) {
                $try = wp_get_object_terms($post_id, $alt, ['fields' => 'ids']);
                if (!is_wp_error($try) && !empty($try)) { $stores = $try; break; }
            }
        }
        if (!is_wp_error($stores) && !empty($stores)) {
            $data['stores'] = $stores;
        }

        // Debug: record taxonomy terms found
        if (function_exists('dne_debug')) {
            dne_debug('Post ' . $post_id . ' product_categories IDs: ' . json_encode($data['categories']));
            dne_debug('Post ' . $post_id . ' store_type IDs: ' . json_encode($data['stores']));
        }

        return $data;
    }
    
    /**
     * Process notification queue
     */
    public function process_queue() {
        dne_debug("process_queue called");
        
        // Get batch of pending notifications
        $notifications = $this->queue->get_pending_batch();
        dne_debug("Found " . count($notifications) . " pending notifications");
        
        foreach ($notifications as $notification) {
            dne_debug("Processing notification ID {$notification->id} for user {$notification->user_id} via {$notification->delivery_method}");
            $this->send_notification($notification);
        }
        
        dne_debug("process_queue completed");
    }
    
    /**
     * Send individual notification
     */
    private function send_notification($notification) {
        dne_debug("send_notification called for notification ID {$notification->id}");
        
        // Final preference gate to avoid sending if user has disabled the channel
        $finalFilter = new Filter();
        if (!$finalFilter->user_allows_channel((int)$notification->user_id, (string)$notification->delivery_method)) {
            // Consider it handled to avoid retries and log as skipped
            $this->queue->mark_sent($notification->id);
            $this->queue->log_activity([
                'user_id' => $notification->user_id,
                'post_id' => $notification->post_id,
                'delivery_method' => $notification->delivery_method,
                'action' => 'skipped_by_preferences',
                'status' => 'success',
                'details' => [
                    'reason' => 'User disabled channel or not allowed by tier',
                ],
                'sent_at' => current_time('mysql')
            ]);
            dne_debug("Notification {$notification->id} skipped by final gate: user {$notification->user_id} channel {$notification->delivery_method}");
            return;
        }

        // Update attempts
        $this->queue->increment_attempts($notification->id);
        dne_debug("Incremented attempts for notification {$notification->id}");
        
        // Get post data
        $post = get_post($notification->post_id);
        if (!$post) {
            dne_debug("Post {$notification->post_id} not found - marking failed");
            $this->queue->mark_failed($notification->id, 'Post not found');
            return;
        }
        
        // Get user data
        $user = get_userdata($notification->user_id);
        if (!$user) {
            dne_debug("User {$notification->user_id} not found - marking failed");
            $this->queue->mark_failed($notification->id, 'User not found');
            return;
        }
        
        dne_debug("Sending {$notification->delivery_method} notification to user {$notification->user_id} for post {$notification->post_id}");
        
        $success = false;
        $error_message = '';
        
        // Send based on delivery method
        switch ($notification->delivery_method) {
            case 'email':
                dne_debug("Attempting email send...");
                $email_sender = new \DNE\Integrations\Email();
                $result = $email_sender->send($user, $post);
                $success = $result['success'];
                $error_message = $result['message'] ?? '';
                dne_debug("Email result: " . ($success ? 'SUCCESS' : 'FAILED') . " - " . $error_message);
                break;

            case 'telegram':
                dne_debug("Attempting telegram send...");
                $telegram = new \DNE\Integrations\Telegram();
                $result = $telegram->send_notification($user->ID, $post);
                $success = $result['success'];
                $error_message = $result['message'] ?? '';
                dne_debug("Telegram result: " . ($success ? 'SUCCESS' : 'FAILED') . " - " . $error_message);
                break;

            case 'webpush':
                // Optional skip of targeted webpush if broadcast is enabled elsewhere (off by default)
                $skip_targeted = get_option('dne_skip_targeted_webpush_on_broadcast', '0') === '1';
                if ($skip_targeted) {
                    dne_debug("Webpush skipped due to broadcast toggle");
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

                dne_debug("Attempting OneSignal send...");
                $onesignal = new \DNE\Integrations\OneSignal();
                $result = $onesignal->send_notification($user->ID, $post);
                $success = $result['success'];
                $error_message = $result['message'] ?? '';
                dne_debug("OneSignal result: " . ($success ? 'SUCCESS' : 'FAILED') . " - " . $error_message);
                break;

            default:
                $success = false;
                $error_message = 'Unknown delivery method: ' . $notification->delivery_method;
                dne_debug("Unknown delivery method: {$notification->delivery_method}");
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
