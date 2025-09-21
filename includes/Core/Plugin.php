<?php

namespace DNE\Core;

/**
 * Main plugin class - Singleton pattern
 */
class Plugin
{

    private static $instance = null;

    /**
     * Plugin components
     */
    private $ajax_handler;
    private $notification_engine;
    private $telegram_integration;
    private $settings;
    // private  $metabox; // disabled (metabox slated for removal) 

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Singleton pattern
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
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
    private function load_dependencies()
    {
        // Core classes are autoloaded

        // Initialize Settings
        $this->settings = new \DNE\Admin\Settings();

        // Initialize AJAX handler
        $this->ajax_handler = new \DNE\Admin\Ajax_Handler();

        // Initialize notification engine
        $this->notification_engine = new \DNE\Notifications\Engine();

        // Initialize Telegram integration
        $this->telegram_integration = new \DNE\Integrations\Telegram();

        // Initialize Metabox (disabled)
        //  $this->metabox = new  \\\\DNE\\\\Admin\\\\Metabox();
    }

    /**
     * Initialize components
     */
    private function init_components()
    {
        // Initialize Settings
        $this->settings->init();

        // Initialize AJAX handlers
        $this->ajax_handler->init();

        // Initialize notification engine
        $this->notification_engine->init();

        // Initialize Telegram
        $this->telegram_integration->init();

        // Initialize Metabox (disabled)
        //  $this->metabox->init(); 
    }

    /**
     * Register plugin hooks
     */
    private function register_hooks()
    {
        // Hook into post status transitions for deal notifications.
        // Use higher priority so other plugins can set meta/taxonomies first.
        // This only triggers on actual new publications, not edits or timestamp changes
        add_action('transition_post_status', [$this->notification_engine, 'handle_post_transition'], 120, 3);

        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
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
    public function render_admin_page()
    {
?>
        <style>
            .card-custom {
                width: 100% !important;
                max-width: none !important;
            }
        </style>
        <div class="wrap">
            <h1><?php echo esc_html__('Deal Notification Engine', 'deal-notification-engine'); ?></h1>

            <div class="notice notice-info">
                <p><?php echo esc_html__('Notification system is active. Configure settings below.', 'deal-notification-engine'); ?></p>
            </div>

            <div class="card-custom card">
                <h2><?php echo esc_html__('User Gating Overview', 'deal-notification-engine'); ?></h2>
                <p><?php echo esc_html__('Snapshot of active users and their effective notification gating.', 'deal-notification-engine'); ?></p>
                <?php
                // Fetch users with notifications enabled
                $users = get_users([
                    'meta_key'   => 'notifications_enabled',
                    'meta_value' => '1',
                    'fields'     => 'all',
                    'number'     => 200, // soft cap for performance
                ]);

                if (!empty($users)) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr>
                            <th>' . esc_html__('User', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Tier', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Saved Methods', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Allowed by Tier', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Effective', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Min %', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Categories', 'deal-notification-engine') . '</th>
                            <th>' . esc_html__('Stores', 'deal-notification-engine') . '</th>
                        </tr></thead><tbody>';

                    foreach ($users as $u) {
                        $saved_methods = get_user_meta($u->ID, 'notification_delivery_methods', true);
                        if (!is_array($saved_methods)) $saved_methods = [];

                        // Derive tier from role naming convention
                        $tier = 0;
                        if (is_array($u->roles)) {
                            foreach ($u->roles as $r) {
                                if (strpos($r, 'um_deal') !== false && strpos($r, 'tier') !== false) {
                                    if (strpos($r, 'tier-3') !== false || strpos($r, 'tier_3') !== false) {
                                        $tier = 3;
                                        break;
                                    }
                                    if (strpos($r, 'tier-2') !== false || strpos($r, 'tier_2') !== false) {
                                        $tier = 2;
                                        break;
                                    }
                                    if (strpos($r, 'tier-1') !== false || strpos($r, 'tier_1') !== false) {
                                        $tier = 1;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($tier === 0) {
                            $tier = 1;
                        } // default safest tier

                        // Allowed by tier mapping (server authority)
                        $allowed_by_tier_map = [
                            1 => ['email'],
                            2 => ['email', 'telegram'],
                            3 => ['email', 'telegram', 'webpush'],
                        ];
                        $allowed_by_tier = $allowed_by_tier_map[$tier];

                        // Effective = intersection of saved and allowed-by-tier
                        $effective = array_values(array_intersect($saved_methods, $allowed_by_tier));

                        // Filters
                        $min_pct = (int) get_user_meta($u->ID, 'user_discount_filter', true);
                        $cat_ids = get_user_meta($u->ID, 'user_category_filter', true);
                        $store_ids = get_user_meta($u->ID, 'user_store_filter', true);
                        if (!is_array($cat_ids)) $cat_ids = [];
                        if (!is_array($store_ids)) $store_ids = [];

                        // Resolve term names
                        $cat_names = [];
                        if (!empty($cat_ids)) {
                            $terms = get_terms([
                                'taxonomy' => 'product_categories',
                                'include'  => array_map('intval', $cat_ids),
                                'hide_empty' => false,
                            ]);
                            if (!is_wp_error($terms)) {
                                foreach ($terms as $t) {
                                    $cat_names[] = $t->name;
                                }
                            }
                        }

                        $store_names = [];
                        if (!empty($store_ids)) {
                            $terms = get_terms([
                                'taxonomy' => 'store_type',
                                'include'  => array_map('intval', $store_ids),
                                'hide_empty' => false,
                            ]);
                            if (!is_wp_error($terms)) {
                                foreach ($terms as $t) {
                                    $store_names[] = $t->name;
                                }
                            }
                        }

                        echo '<tr>';
                        echo '<td>' . esc_html($u->display_name) . ' (#' . intval($u->ID) . ')</td>';
                        echo '<td>' . esc_html($tier) . '</td>';
                        echo '<td>' . esc_html(implode(', ', $saved_methods)) . '</td>';
                        echo '<td>' . esc_html(implode(', ', $allowed_by_tier)) . '</td>';
                        echo '<td>' . esc_html(implode(', ', $effective)) . '</td>';
                        echo '<td>' . ($min_pct ? esc_html($min_pct) : '&ndash;') . '</td>';
                        echo '<td>' . (!empty($cat_names) ? esc_html(implode(', ', $cat_names)) : '&ndash;') . '</td>';
                        echo '<td>' . (!empty($store_names) ? esc_html(implode(', ', $store_names)) : '&ndash;') . '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                } else {
                    echo '<p>' . esc_html__('No users with notifications enabled.', 'deal-notification-engine') . '</p>';
                }
                ?>
            </div>

            <div class="card-custom card">
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

            <div class="card-custom card">
                <h2><?php echo esc_html__('Test Notification', 'deal-notification-engine'); ?></h2>
                <p><?php echo esc_html__('Send a test notification to verify everything is working.', 'deal-notification-engine'); ?></p>

                <table class="form-table">
                    <tr>
                        <th><label for="test-post-id">Post ID</label></th>
                        <td>
                            <input type="number" id="test-post-id" class="small-text" placeholder="123">
                            <span class="description">ID of a published post to use as test content</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test-user-id">User ID</label></th>
                        <td>
                            <input type="number" id="test-user-id" class="small-text" value="<?php echo get_current_user_id(); ?>">
                            <span class="description">User to send the test to (defaults to you)</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test-method">Method</label></th>
                        <td>
                            <select id="test-method">
                                <option value="email">Email</option>
                                <option value="telegram">Telegram</option>
                                <option value="webpush">Web Push (OneSignal)</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="test-custom-field" id="test-custom-email-row" style="display:none;">
                        <th><label for="test-custom-email">Custom Email</label></th>
                        <td>
                            <input type="email" id="test-custom-email" class="regular-text" placeholder="test@example.com">
                            <span class="description">Override user's email for testing (optional)</span>
                        </td>
                    </tr>
                    <tr class="test-custom-field" id="test-custom-telegram-row" style="display:none;">
                        <th><label for="test-custom-telegram">Telegram Chat ID</label></th>
                        <td>
                            <input type="text" id="test-custom-telegram" class="regular-text" placeholder="123456789">
                            <span class="description">Override user's Telegram chat ID for testing (optional)</span>
                        </td>
                    </tr>
                    <tr class="test-custom-field" id="test-custom-onesignal-row" style="display:none;">
                        <th><label for="test-custom-onesignal">OneSignal Player ID</label></th>
                        <td>
                            <input type="text" id="test-custom-onesignal" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                            <span class="description">Override user's OneSignal player ID for testing (optional)</span>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="send-test-notification" class="button button-primary">
                        Send Test Notification
                    </button>
                    <span id="test-result-message" style="margin-left: 10px;"></span>
                </p>
            </div>

            <div class="card-custom card">
                <h2><?php echo esc_html__('Quick Actions', 'deal-notification-engine'); ?></h2>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=deal-notifications-settings'); ?>" class="button button-primary">
                        <?php echo esc_html__('Configure Settings', 'deal-notification-engine'); ?>
                    </a>
                    <a href="<?php echo admin_url('users.php'); ?>" class="button button-secondary">
                        <?php echo esc_html__('Manage Users', 'deal-notification-engine'); ?>
                    </a>
                    <button type="button" class="button button-secondary" onclick="if(confirm('Process all pending notifications now?')) { jQuery.post(ajaxurl, {action: 'dne_process_queue_manually'}, function(r) { alert(r.data || 'Processing started'); location.reload(); }); }">
                        <?php echo esc_html__('Process Queue Now', 'deal-notification-engine'); ?>
                    </button>
                    <button type="button" class="button button-secondary" onclick="if(confirm('Reset failed notifications for retry? This will reset attempt counts.')) { jQuery.post(ajaxurl, {action: 'dne_reset_failed_notifications'}, function(r) { alert(r.data || 'Reset completed'); location.reload(); }); }">
                        <?php echo esc_html__('Reset Failed Notifications', 'deal-notification-engine'); ?>
                    </button>
                    <button type="button" class="button button-secondary" onclick="jQuery.post(ajaxurl, {action: 'dne_debug_settings'}, function(r) { alert('Debug Info:\\n' + JSON.stringify(r.data, null, 2)); });">
                        <?php echo esc_html__('Debug Settings', 'deal-notification-engine'); ?>
                    </button>
                    <button type="button" class="button button-secondary" style="background: #dc3545; border-color: #dc3545; color: white;" onclick="if(confirm('DANGER: This will permanently delete ALL notifications from the queue. Are you sure?')) { jQuery.post(ajaxurl, {action: 'dne_clear_all_notifications'}, function(r) { alert(r.data || 'Cleared'); location.reload(); }); }">
                        <?php echo esc_html__('Clear All Queue', 'deal-notification-engine'); ?>
                    </button>
                </p>
            </div>

            <div class="card-custom card">
                <h2><?php echo esc_html__('Recent Notification Log', 'deal-notification-engine'); ?></h2>
                <?php
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_log'") === $table_log) {
                    $recent_logs = $wpdb->get_results(
                        "SELECT * FROM $table_log ORDER BY created_at DESC LIMIT 10"
                    );

                    if ($recent_logs) {
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>
                            <th>User</th>
                            <th>Post</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Details</th>
                            <th>Time</th>
                        </tr></thead><tbody>';

                        foreach ($recent_logs as $log) {
                            $user = get_userdata($log->user_id);
                            $post = get_post($log->post_id);
                            echo '<tr>';
                            echo '<td>' . ($user ? esc_html($user->display_name) : 'User #' . $log->user_id) . '</td>';
                            echo '<td>' . ($post ? esc_html($post->post_title) : 'Post #' . $log->post_id) . '</td>';
                            echo '<td>' . esc_html($log->delivery_method ?? $log->action) . '</td>';
                            echo '<td>' . esc_html($log->status) . '</td>';
                            // Decode details JSON if present and show core error text
                            $detailText = '';
                            if (!empty($log->details)) {
                                $decoded = json_decode($log->details, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    if (isset($decoded['error'])) {
                                        $detailText = (string) $decoded['error'];
                                    } else {
                                        // Fallback: compact JSON
                                        $compact = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        $detailText = (string) $compact;
                                    }
                                } else {
                                    $detailText = (string) $log->details; // raw string
                                }
                            }
                            if ($detailText !== '') {
                                // Truncate long text for table view
                                if (mb_strlen($detailText) > 160) {
                                    $detailText = mb_substr($detailText, 0, 157) . '...';
                                }
                            }
                            echo '<td>' . esc_html($detailText) . '</td>';
                            echo '<td>' . esc_html($log->created_at) . '</td>';
                            echo '</tr>';
                        }

                        echo '</tbody></table>';
                    } else {
                        echo '<p>No notifications sent yet.</p>';
                    }
                } else {
                    echo '<p>Log table not created yet.</p>';
                }
                ?>
            </div>
        </div>
<?php
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_scripts()
    {
        // Only on pages where user preferences might be shown
        if (!is_user_logged_in()) {
            return;
        }

        // Check if we're on a page that might have notification preferences
        // You may need to adjust this condition based on your theme
        $is_profile_page = is_page() && (
            strpos(get_the_title(), 'Profile') !== false ||
            strpos(get_the_title(), 'Account') !== false ||
            strpos(get_the_title(), 'Settings') !== false ||
            strpos(get_page_template_slug(), 'profile') !== false ||
            strpos(get_page_template_slug(), 'account') !== false
        );

        // Also load on Ultimate Member profile pages
        $is_um_profile = function_exists('um_is_core_page');

        if ($is_profile_page || $is_um_profile || is_page() || is_single()) {
            // Enqueue the script with updated version
            wp_enqueue_script(
                'dne-frontend',
                DNE_PLUGIN_URL . 'assets/js/frontend.js',
                ['jquery'],
                DNE_VERSION . '.3', // Bump version to force cache refresh
                true
            );

            // Prepare user data
            $user_id = get_current_user_id();
            $localize_data = [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dne_ajax_nonce'),
                'user_id' => $user_id,
                'debug_mode' => get_option('dne_debug_mode', '0')
            ];

            // Add user role info for debugging
            if ($user_id) {
                $user = wp_get_current_user();
                $has_deal_role = false;
                foreach ($user->roles as $role) {
                    if (strpos($role, 'um_deal') !== false && strpos($role, 'tier') !== false) {
                        $has_deal_role = true;
                        break;
                    }
                }
                $localize_data['has_deal_role'] = $has_deal_role ? '1' : '0';

                // Check if user has webpush enabled to prevent unnecessary OneSignal initialization
                $delivery_methods = get_user_meta($user_id, 'notification_delivery_methods', true);
                $localize_data['has_webpush'] = (is_array($delivery_methods) && in_array('webpush', $delivery_methods)) ? '1' : '0';
            }

            wp_localize_script('dne-frontend', 'dne_ajax', $localize_data);

            // Only check OneSignal if it's enabled AND user might use it
            if (get_option('dne_onesignal_enabled') === '1' && !empty($localize_data['has_webpush'])) {
                wp_add_inline_script('dne-frontend', '
                    // Only initialize OneSignal checks if user has webpush enabled
                    window.addEventListener("load", function() {
                        if (dne_ajax.has_webpush === "1") {
                            if (typeof OneSignal === "undefined") {
                                console.warn("[DNE] OneSignal SDK not detected. Please ensure OneSignal WordPress plugin is active.");
                            } else if (dne_ajax.debug_mode === "1") {
                                console.log("[DNE] OneSignal SDK detected");
                            }
                        }
                    });
                ', 'before');
            }
        }
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only on our plugin pages
        if (strpos($hook, 'deal-notifications') === false) {
            return;
        }

        wp_enqueue_script('jquery');

        // Add inline script for test notifications
        if ($hook === 'toplevel_page_deal-notifications') {
            wp_add_inline_script('jquery', '
                jQuery(document).ready(function($) {
                    // Show/hide custom fields based on selected method
                    function toggleCustomFields() {
                        var method = $("#test-method").val();
                        $(".test-custom-field").hide();
                        
                        if (method === "email") {
                            $("#test-custom-email-row").show();
                        } else if (method === "telegram") {
                            $("#test-custom-telegram-row").show();
                        } else if (method === "webpush") {
                            $("#test-custom-onesignal-row").show();
                        }
                    }
                    
                    // Initialize on page load
                    toggleCustomFields();
                    
                    // Update when method changes
                    $("#test-method").on("change", toggleCustomFields);
                    
                    // Handle test notification sending
                    $("#send-test-notification").on("click", function() {
                        var button = $(this);
                        var originalText = button.text();
                        
                        button.text("Sending...").prop("disabled", true);
                        
                        var data = {
                            action: "dne_send_test_notification",
                            post_id: $("#test-post-id").val(),
                            user_id: $("#test-user-id").val(),
                            method: $("#test-method").val(),
                            nonce: "' . wp_create_nonce('dne_test_notification') . '"
                        };
                        
                        // Add custom values if present
                        if ($("#test-method").val() === "email") {
                            data.custom_email = $("#test-custom-email").val();
                        } else if ($("#test-method").val() === "telegram") {
                            data.custom_telegram = $("#test-custom-telegram").val();
                        } else if ($("#test-method").val() === "webpush") {
                            data.custom_onesignal = $("#test-custom-onesignal").val();
                        }
                        
                        $.post(ajaxurl, data, function(response) {
                            if (response.success) {
                                alert("Success: " + response.data);
                            } else {
                                alert("Error: " + response.data);
                            }
                        }).always(function() {
                            button.text(originalText).prop("disabled", false);
                        });
                    });
                });
            ');
        }
    }
}
