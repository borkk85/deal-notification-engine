<?php
namespace DNE\Notifications;

/**
 * Notification queue management
 */
class Queue {
    
    /**
     * Database table name
     */
    private $table_queue;
    private $table_log;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_queue = $wpdb->prefix . 'dne_notification_queue';
        $this->table_log = $wpdb->prefix . 'dne_notification_log';
    }
    
    /**
     * Add notifications to queue
     * 
     * @param array $user_ids User IDs to notify
     * @param int $post_id Post ID
     * @param Filter $filter Filter instance to get delivery methods
     * @return int Number of notifications queued
     */
    public function add_to_queue($user_ids, $post_id, Filter $filter = null) {
        global $wpdb;
        
        if (!$filter) {
            $filter = new Filter();
        }
        
        $queued = 0;
        
        foreach ($user_ids as $user_id) {
            // Get user's delivery methods
            $delivery_methods = $filter->get_user_delivery_methods($user_id);
            dne_debug("User {$user_id} delivery methods: " . wp_json_encode($delivery_methods));
            
            // Queue notification for each delivery method
            foreach ($delivery_methods as $method) {
                dne_debug("Queuing {$method} notification for user {$user_id}");
                if ($this->add_notification($user_id, $post_id, $method)) {
                    $queued++;
                    dne_debug("Successfully queued {$method} for user {$user_id}");
                } else {
                    dne_debug("Failed to queue {$method} for user {$user_id} (duplicate?)");
                }
            }
        }
        
        return $queued;
    }
    
    /**
     * Add single notification to queue
     * 
     * @param int $user_id User ID
     * @param int $post_id Post ID
     * @param string $delivery_method Delivery method
     * @return bool Success
     */
    private function add_notification($user_id, $post_id, $delivery_method) {
        global $wpdb;
        
        // Check if already queued (prevent duplicates)
        $exists = $this->notification_exists($user_id, $post_id, $delivery_method);
        
        if ($exists) {
            return false;
        }
        
        // Insert into queue
        $result = $wpdb->insert(
            $this->table_queue,
            [
                'user_id' => $user_id,
                'post_id' => $post_id,
                'delivery_method' => $delivery_method,
                'status' => 'pending',
                'scheduled_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Check if notification already exists
     * 
     * @param int $user_id User ID
     * @param int $post_id Post ID
     * @param string $delivery_method Delivery method
     * @return bool
     */
    private function notification_exists($user_id, $post_id, $delivery_method) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_queue} 
             WHERE user_id = %d 
             AND post_id = %d 
             AND delivery_method = %s 
             AND status IN ('pending', 'sent')",
            $user_id, $post_id, $delivery_method
        ));
        
        return !empty($exists);
    }
    
    /**
     * Get batch of pending notifications
     * 
     * @param int $batch_size Number of notifications to retrieve
     * @return array Notification objects
     */
    public function get_pending_batch($batch_size = null) {
        global $wpdb;
        
        if (!$batch_size) {
            $batch_size = get_option('dne_batch_size', 50);
        }
        
        // Debug: Check total queue contents first
        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue}");
        dne_debug("Total notifications in queue: {$total_count}");
        
        // Debug: Check by status
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'pending'");
        $high_attempts = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'pending' AND attempts >= 3");
        $future_scheduled = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'pending' AND scheduled_at > NOW()");
        
        dne_debug("Pending notifications: {$pending_count}");
        dne_debug("High attempts (>=3): {$high_attempts}");
        dne_debug("Future scheduled: {$future_scheduled}");
        
        // Align selection to support both legacy (local time) and new (UTC) scheduled_at values
        $now_utc   = current_time('mysql', true);
        $now_local = current_time('mysql');
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_queue}
             WHERE status = 'pending' 
             AND attempts < 3 
             AND (scheduled_at <= %s OR scheduled_at <= %s)
             ORDER BY scheduled_at ASC 
             LIMIT %d",
            $now_utc,
            $now_local,
            $batch_size
        );
        
        dne_debug("Query: " . $query);
        
        $results = $wpdb->get_results($query);
        dne_debug("Query returned " . count($results) . " results");
        
        return $results;
    }
    
    /**
     * Update notification attempt count
     * 
     * @param int $notification_id Notification ID
     * @return bool Success
     */
    public function increment_attempts($notification_id) {
        global $wpdb;
        
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_queue} 
             SET attempts = attempts + 1 
             WHERE id = %d",
            $notification_id
        )) !== false;
    }
    
    /**
     * Mark notification as sent
     * 
     * @param int $notification_id Notification ID
     * @return bool Success
     */
    public function mark_sent($notification_id) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_queue,
            [
                'status' => 'sent',
                'processed_at' => current_time('mysql')
            ],
            ['id' => $notification_id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Mark notification as failed
     * 
     * @param int $notification_id Notification ID
     * @param string $error_message Error message
     * @return bool Success
     */
    public function mark_failed($notification_id, $error_message = '') {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_queue,
            [
                'status' => 'failed',
                'error_message' => $error_message,
                'processed_at' => current_time('mysql')
            ],
            ['id' => $notification_id],
            ['%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Update error message for retry
     * 
     * @param int $notification_id Notification ID
     * @param string $error_message Error message
     * @return bool Success
     */
    public function update_error($notification_id, $error_message) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_queue,
            ['error_message' => $error_message],
            ['id' => $notification_id],
            ['%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Log notification activity
     * 
     * @param array $data Log data
     * @return bool Success
     */
    public function log_activity($data) {
        global $wpdb;
        
        $defaults = [
            'user_id' => null,
            'post_id' => null,
            'delivery_method' => null,
            'action' => 'notification_sent',
            'status' => 'success',
            'details' => null,
            'sent_at' => null,
            'created_at' => current_time('mysql')
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Handle details array/object
        if (is_array($data['details']) || is_object($data['details'])) {
            $data['details'] = json_encode($data['details']);
        }
        
        return $wpdb->insert(
            $this->table_log,
            $data,
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        ) !== false;
    }
    
    /**
     * Get queue statistics
     * 
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = [
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'sent_today' => 0
        ];
        
        $queue_stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$this->table_queue} 
             GROUP BY status"
        );
        
        foreach ($queue_stats as $stat) {
            if (isset($stats[$stat->status])) {
                $stats[$stat->status] = intval($stat->count);
            }
        }
        
        $stats['sent_today'] = intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_log} 
             WHERE DATE(sent_at) = CURDATE() 
             AND status = 'success'"
        ));
        
        return $stats;
    }
    
    /**
     * Clean up old entries
     * 
     * @param int $log_days Days to keep logs
     * @param int $queue_days Days to keep processed queue items
     * @return array Deleted counts
     */
    public function cleanup($log_days = 30, $queue_days = 7) {
        global $wpdb;
        
        // Delete old logs
        $logs_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_log} 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $log_days
        ));
        
        $queue_deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_queue} 
             WHERE status IN ('sent', 'failed') 
             AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $queue_days
        ));
        
        return [
            'logs_deleted' => $logs_deleted,
            'queue_deleted' => $queue_deleted
        ];
    }
    
    /**
     * Force clear all pending notifications
     * Admin function for testing
     * 
     * @return int Number cleared
     */
    public function clear_pending() {
        global $wpdb;
        
        return $wpdb->query(
            "DELETE FROM {$this->table_queue} WHERE status = 'pending'"
        );
    }
    
    /**
     * Reset failed notifications for retry
     * Admin function for testing - resets attempt count
     * 
     * @return int Number reset
     */
    public function reset_failed_notifications() {
        global $wpdb;
        
        $count = $wpdb->query(
            "UPDATE {$this->table_queue} 
             SET attempts = 0, error_message = '' 
             WHERE status = 'pending' AND attempts >= 3"
        );
        
        dne_debug("Reset {$count} failed notifications for retry");
        return $count;
    }
    
    /**
     * Clear all notifications from queue
     * Admin function for maintenance
     * 
     * @return array Counts of cleared notifications
     */
    public function clear_all_notifications() {
        global $wpdb;
        
        // Count by status before clearing
        $stats = [
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'pending'"),
            'sent' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'sent'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue} WHERE status = 'failed'"),
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_queue}")
        ];
        
        // Clear all notifications
        $deleted = $wpdb->query("DELETE FROM {$this->table_queue}");
        
        dne_debug("Cleared {$deleted} total notifications from queue");
        
        return [
            'deleted' => $deleted,
            'stats' => $stats
        ];
    }
}
