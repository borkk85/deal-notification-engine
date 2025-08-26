<?php
namespace DNE\Core;

/**
 * Main plugin class - Singleton pattern
 */
class Plugin {
    
    private static $instance = null;
    
    /**
     * Plugin components
     */
    private $ajax_handler;
    private $notification_engine;
    private $telegram_integration;
    private $settings;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        // Singleton pattern
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components
        $this->init_components();
        
        // Register hooks
        $this->register_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Core classes are autoloaded
        
        // Initialize Settings
        $this->settings = new \DNE\Admin\Settings();
        
        // Initialize AJAX handler
        $this->ajax_handler = new \DNE\Admin\Ajax_Handler();
        
        // Initialize notification engine
        $this->notification_engine = new \DNE\Notifications\Engine();
        
        // Initialize Telegram integration
        $this->telegram_integration = new \DNE\Integrations\Telegram();
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize Settings
        $this->settings->init();
        
        // Initialize AJAX handlers
        $this->ajax_handler->init();
        
        // Initialize notification engine
        $this->notification_engine->init();
        
        // Initialize Telegram
        $this->telegram_integration->init();
    }
    
    /**
     * Register plugin hooks
     */
    private function register_hooks() {
        // Hook into post publication for deal notifications
        add_action('publish_post', [$this->notification_engine, 'handle_new_deal'], 10, 2);
        
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Add main menu page
        add_menu_page(
            __('Deal Notifications', 'deal-notification-engine'),
            __('Deal Notifications', 'deal-notification-engine'),
            'manage_options',
            'deal-notifications',
            [$this, 'render_admin_page'],
            'dashicons-megaphone',
            30
        );
        
        // Add Settings as submenu directly here
        add_submenu_page(
            'deal-notifications',
            __('Settings', 'deal-notification-engine'),
            __('Settings', 'deal-notification-engine'),
            'manage_options',
            'deal-notifications-settings',
            [$this->settings, 'render_settings_page']
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Deal Notification Engine', 'deal-notification-engine'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php echo esc_html__('Notification system is active. Configure settings below.', 'deal-notification-engine'); ?></p>
            </div>
            
            <div class="card">
                <h2><?php echo esc_html__('Statistics', 'deal-notification-engine'); ?></h2>
                <?php
                global $wpdb;
                $table_queue = $wpdb->prefix . 'dne_notification_queue';
                $table_log = $wpdb->prefix . 'dne_notification_log';
                
                // Get stats if tables exist
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_queue'") === $table_queue) {
                    $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_queue WHERE status = 'pending'");
                    $sent_today = $wpdb->get_var("SELECT COUNT(*) FROM $table_log WHERE DATE(sent_at) = CURDATE()");
                    ?>
                    <p><strong>Pending Notifications:</strong> <?php echo intval($pending); ?></p>
                    <p><strong>Sent Today:</strong> <?php echo intval($sent_today); ?></p>
                    <?php
                } else {
                    echo '<p>' . esc_html__('Database tables not yet created. Please deactivate and reactivate the plugin.', 'deal-notification-engine') . '</p>';
                }
                ?>
            </div>
            
            <div class="card">
                <h2><?php echo esc_html__('Quick Actions', 'deal-notification-engine'); ?></h2>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=deal-notifications-settings'); ?>" class="button button-primary">
                        <?php echo esc_html__('Configure Settings', 'deal-notification-engine'); ?>
                    </a>
                    <a href="<?php echo admin_url('users.php'); ?>" class="button button-secondary">
                        <?php echo esc_html__('Manage Users', 'deal-notification-engine'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts() {
        // Only on pages where needed
        if (is_page() || is_single()) {
            wp_enqueue_script(
                'dne-frontend',
                DNE_PLUGIN_URL . 'assets/js/frontend.js',
                ['jquery'],
                DNE_VERSION,
                true
            );
            
            wp_localize_script('dne-frontend', 'dne_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dne_ajax_nonce')
            ]);
        }
    }
}