<?php
namespace DNE\Core;

/**
 * Handles plugin activation and deactivation
 */
class Installer {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Create database tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Schedule cron jobs
        self::schedule_cron();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('dne_process_notification_queue');
        wp_clear_scheduled_hook('dne_cleanup_old_logs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Notification queue table
        $table_queue = $wpdb->prefix . 'dne_notification_queue';
        $sql_queue = "CREATE TABLE IF NOT EXISTS $table_queue (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            post_id bigint(20) UNSIGNED NOT NULL,
            delivery_method varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts tinyint DEFAULT 0,
            scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY scheduled_at (scheduled_at)
        ) $charset_collate;";
        
        // Notification log table
        $table_log = $wpdb->prefix . 'dne_notification_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS $table_log (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            post_id bigint(20) UNSIGNED DEFAULT NULL,
            delivery_method varchar(50) DEFAULT NULL,
            action varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'success',
            details longtext DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Telegram verifications table
        $table_telegram = $wpdb->prefix . 'dne_telegram_verifications';
        $sql_telegram = "CREATE TABLE IF NOT EXISTS $table_telegram (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            verification_code varchar(32) NOT NULL,
            chat_id varchar(100) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY verification_code (verification_code),
            KEY user_id (user_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_queue);
        dbDelta($sql_log);
        dbDelta($sql_telegram);
        
        // Update version in database
        update_option('dne_db_version', DNE_VERSION);
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        // General settings
        add_option('dne_enabled', '1');
        add_option('dne_process_immediately', '1');
        add_option('dne_batch_size', 50);
        
        // Email settings
        add_option('dne_email_from_name', get_bloginfo('name'));
        add_option('dne_email_from_address', get_option('admin_email'));
        add_option('dne_email_subject_template', 'New Deal Alert: {title}');
        
        // Telegram settings
        add_option('dne_telegram_enabled', '0');
        add_option('dne_telegram_bot_username', '');
        add_option('dne_telegram_bot_token', '');
        
        // OneSignal settings
        add_option('dne_onesignal_enabled', '0');
        add_option('dne_onesignal_app_id', '');
        add_option('dne_onesignal_api_key', '');
        
        // Debug settings
        add_option('dne_debug_mode', '0');
        add_option('dne_log_notifications', '1');
    }
    
    /**
     * Schedule cron jobs
     */
    private static function schedule_cron() {
        // Process notification queue every 5 minutes
        if (!wp_next_scheduled('dne_process_notification_queue')) {
            wp_schedule_event(time(), 'dne_five_minutes', 'dne_process_notification_queue');
        }
        
        // Clean up old logs daily
        if (!wp_next_scheduled('dne_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'dne_cleanup_old_logs');
        }
        
        // Add custom cron schedule
        add_filter('cron_schedules', function($schedules) {
            $schedules['dne_five_minutes'] = [
                'interval' => 300,
                'display' => __('Every 5 Minutes', 'deal-notification-engine')
            ];
            return $schedules;
        });
    }
}