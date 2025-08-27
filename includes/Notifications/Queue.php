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
            
            // Queue notification for each delivery method
            foreach ($delivery_methods as $method) {
                if ($this->add_notification($user_id, $post_id, $method)) {
                    $queued++;
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
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_queue}
             WHERE status = 'pending' 
             AND attempts < 3 
             AND scheduled_at <= NOW() 
             ORDER BY scheduled_at ASC 
             LIMIT %d",
            $batch_size
        ));
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
        
        // Get queue counts
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
        
        // Get today's sent count
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
        
        // Delete old processed queue items
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
}