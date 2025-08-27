<?php

namespace DNE\Admin;

/**
 * Plugin Settings Page
 */
class Settings
{

    /**
     * Initialize settings
     */
    public function init()
    {
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Settings page is now added directly in Plugin.php

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . DNE_PLUGIN_BASENAME, [$this, 'add_settings_link']);
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        // General Settings Section
        add_settings_section(
            'dne_general_settings',
            __('General Settings', 'deal-notification-engine'),
            [$this, 'general_section_callback'],
            'dne-settings'
        );

        register_setting('dne_settings_group', 'dne_enabled');
        add_settings_field(
            'dne_enabled',
            __('Enable Notifications', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_general_settings',
            ['field' => 'dne_enabled', 'label' => 'Enable the notification system']
        );

        register_setting('dne_settings_group', 'dne_process_immediately');
        add_settings_field(
            'dne_process_immediately',
            __('Send Immediately', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_general_settings',
            ['field' => 'dne_process_immediately', 'label' => 'Send notifications immediately when deals are published']
        );

        // Email Settings Section
        add_settings_section(
            'dne_email_settings',
            __('Email Settings', 'deal-notification-engine'),
            [$this, 'email_section_callback'],
            'dne-settings'
        );

        register_setting('dne_settings_group', 'dne_email_from_name');
        add_settings_field(
            'dne_email_from_name',
            __('From Name', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_email_settings',
            ['field' => 'dne_email_from_name', 'placeholder' => get_bloginfo('name')]
        );

        register_setting('dne_settings_group', 'dne_email_from_address');
        add_settings_field(
            'dne_email_from_address',
            __('From Email', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_email_settings',
            ['field' => 'dne_email_from_address', 'placeholder' => get_option('admin_email'), 'type' => 'email']
        );

        // Telegram Settings Section
        add_settings_section(
            'dne_telegram_settings',
            __('Telegram Settings', 'deal-notification-engine'),
            [$this, 'telegram_section_callback'],
            'dne-settings'
        );

        register_setting('dne_settings_group', 'dne_telegram_enabled');
        add_settings_field(
            'dne_telegram_enabled',
            __('Enable Telegram', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_telegram_settings',
            ['field' => 'dne_telegram_enabled', 'label' => 'Enable Telegram notifications']
        );

        register_setting('dne_settings_group', 'dne_telegram_bot_token', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_telegram_bot_token',
            __('Bot Token', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_telegram_settings',
            [
                'field' => 'dne_telegram_bot_token',
                'description' => 'Get this from @BotFather on Telegram',
                'placeholder' => '123456789:ABCdefGHIjklmNOPqrstUVwxyz'
            ]
        );

        register_setting('dne_settings_group', 'dne_telegram_bot_username');
        add_settings_field(
            'dne_telegram_bot_username',
            __('Bot Username', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_telegram_settings',
            [
                'field' => 'dne_telegram_bot_username',
                'placeholder' => 'YourBotName',
                'description' => 'Username without @ symbol'
            ]
        );

        // OneSignal Settings Section
        add_settings_section(
            'dne_onesignal_settings',
            __('OneSignal Settings', 'deal-notification-engine'),
            [$this, 'onesignal_section_callback'],
            'dne-settings'
        );

        register_setting('dne_settings_group', 'dne_onesignal_enabled');
        add_settings_field(
            'dne_onesignal_enabled',
            __('Enable OneSignal', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_onesignal_settings',
            ['field' => 'dne_onesignal_enabled', 'label' => 'Enable web push notifications via OneSignal']
        );

        register_setting('dne_settings_group', 'dne_onesignal_app_id');
        add_settings_field(
            'dne_onesignal_app_id',
            __('App ID', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_onesignal_settings',
            [
                'field' => 'dne_onesignal_app_id',
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                'description' => 'Your OneSignal App ID'
            ]
        );

        register_setting('dne_settings_group', 'dne_onesignal_api_key', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_onesignal_api_key',
            __('REST API Key', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_onesignal_settings',
            [
                'field' => 'dne_onesignal_api_key',
                'description' => 'Your OneSignal REST API Key',
                'placeholder' => 'Your API Key'
            ]
        );

        // Advanced Settings Section
        add_settings_section(
            'dne_advanced_settings',
            __('Advanced Settings', 'deal-notification-engine'),
            [$this, 'advanced_section_callback'],
            'dne-settings'
        );

        register_setting('dne_settings_group', 'dne_batch_size');
        add_settings_field(
            'dne_batch_size',
            __('Batch Size', 'deal-notification-engine'),
            [$this, 'render_number_field'],
            'dne-settings',
            'dne_advanced_settings',
            [
                'field' => 'dne_batch_size',
                'default' => 50,
                'min' => 10,
                'max' => 200,
                'description' => 'Number of notifications to process per batch'
            ]
        );

        register_setting('dne_settings_group', 'dne_debug_mode');
        add_settings_field(
            'dne_debug_mode',
            __('Debug Mode', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_advanced_settings',
            ['field' => 'dne_debug_mode', 'label' => 'Enable debug logging']
        );
    }


    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        // Check if we should test connections
        if (isset($_GET['test_telegram'])) {
            $this->test_telegram_connection();
        }
        if (isset($_GET['test_onesignal'])) {
            $this->test_onesignal_connection();
        }
?>
        <div class="wrap">
            <h1><?php echo esc_html__('Deal Notification Settings', 'deal-notification-engine'); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('dne_settings_group');
                do_settings_sections('dne-settings');
                submit_button();
                ?>
            </form>

            <!-- Connection Test Buttons -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Test Connections', 'deal-notification-engine'); ?></h2>
                <p>
                    <a href="?page=deal-notifications-settings&test_telegram=1" class="button button-secondary">
                        <?php echo esc_html__('Test Telegram Connection', 'deal-notification-engine'); ?>
                    </a>
                    <a href="?page=deal-notifications-settings&test_onesignal=1" class="button button-secondary">
                        <?php echo esc_html__('Test OneSignal Connection', 'deal-notification-engine'); ?>
                    </a>
                </p>
            </div>

            <!-- Webhook URL Info -->
            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Webhook Information', 'deal-notification-engine'); ?></h2>
                <p><strong>Telegram Webhook URL:</strong></p>
                <code style="background: #f0f0f0; padding: 5px; display: block; margin: 10px 0;">
                    <?php echo esc_url(rest_url('dne/v1/telegram-webhook')); ?>
                </code>
                <p class="description">
                    Set this URL in your Telegram bot using the setWebhook API method.
                </p>
            </div>
        </div>
    <?php
    }

    /**
     * Section callbacks
     */
    public function general_section_callback()
    {
        echo '<p>' . esc_html__('Configure general notification settings.', 'deal-notification-engine') . '</p>';
    }

    public function email_section_callback()
    {
        echo '<p>' . esc_html__('Configure email notification settings.', 'deal-notification-engine') . '</p>';
    }

    public function telegram_section_callback()
    {
        echo '<p>' . esc_html__('Configure Telegram bot settings. You need to create a bot using @BotFather on Telegram.', 'deal-notification-engine') . '</p>';
    }

    public function onesignal_section_callback()
    {
        echo '<p>' . esc_html__('Configure OneSignal for web push notifications. Sign up at onesignal.com for free.', 'deal-notification-engine') . '</p>';
    }

    public function advanced_section_callback()
    {
        echo '<p>' . esc_html__('Advanced configuration options.', 'deal-notification-engine') . '</p>';
    }

    /**
     * Field renderers
     */
    public function render_checkbox($args)
    {
        $field = $args['field'];
        $value = get_option($field, '0');
        $label = isset($args['label']) ? $args['label'] : '';
    ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($field); ?>" value="1" <?php checked($value, '1'); ?>>
            <?php echo esc_html($label); ?>
        </label>
    <?php
    }

    public function render_text_field($args)
    {
        $field = $args['field'];
        $value = get_option($field, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $type = isset($args['type']) ? $args['type'] : 'text';
    ?>
        <input type="<?php echo esc_attr($type); ?>"
            name="<?php echo esc_attr($field); ?>"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr($placeholder); ?>"
            class="regular-text">
        <?php
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function render_password_field($args)
    {
        $field = $args['field'];
        $value = get_option($field, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        ?>
        <input type="password"
            name="<?php echo esc_attr($field); ?>"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr($placeholder); ?>"
            class="regular-text"
            autocomplete="new-password">
        <?php
        if ($value) {
            echo '<p class="description" style="color: green;">✓ ' . esc_html__('Token is saved', 'deal-notification-engine') . '</p>';
        }
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function render_number_field($args)
    {
        $field = $args['field'];
        $value = get_option($field, $args['default'] ?? 50);
        $min = $args['min'] ?? 1;
        $max = $args['max'] ?? 999;
        ?>
        <input type="number"
            name="<?php echo esc_attr($field); ?>"
            value="<?php echo esc_attr($value); ?>"
            min="<?php echo esc_attr($min); ?>"
            max="<?php echo esc_attr($max); ?>"
            class="small-text">
<?php
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Sanitize token/API key inputs
     */
    public function sanitize_token($input)
    {
        // Remove whitespace but preserve the token structure
        return trim(sanitize_text_field($input));
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=deal-notifications-settings') . '">' . __('Settings', 'deal-notification-engine') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Test Telegram connection
     */
    private function test_telegram_connection()
    {
        $bot_token = get_option('dne_telegram_bot_token');

        if (empty($bot_token)) {
            add_settings_error('dne_settings', 'telegram_test', __('Please save your Telegram bot token first.', 'deal-notification-engine'), 'error');
            return;
        }

        $response = wp_remote_get("https://api.telegram.org/bot{$bot_token}/getMe");

        if (is_wp_error($response)) {
            add_settings_error('dne_settings', 'telegram_test', __('Connection failed: ', 'deal-notification-engine') . $response->get_error_message(), 'error');
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['ok']) && $body['ok'] === true) {
            $bot_name = $body['result']['username'] ?? 'Unknown';
            add_settings_error(
                'dne_settings',
                'telegram_test',
                sprintf(__('Successfully connected to Telegram bot: @%s', 'deal-notification-engine'), $bot_name),
                'success'
            );

            // Auto-update bot username
            if (isset($body['result']['username'])) {
                update_option('dne_telegram_bot_username', $body['result']['username']);
            }
        } else {
            add_settings_error('dne_settings', 'telegram_test', __('Invalid bot token. Please check your credentials.', 'deal-notification-engine'), 'error');
        }
    }

    /**
     * Test OneSignal connection
     */
    private function test_onesignal_connection() {
        $app_id = get_option('dne_onesignal_app_id');
        $api_key = get_option('dne_onesignal_api_key');
        
        if (empty($app_id) || empty($api_key)) {
            add_settings_error('dne_settings', 'onesignal_test', __('Please save your OneSignal credentials first.', 'deal-notification-engine'), 'error');
            return;
        }
        
        // Debug logging
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal Test] Testing with App ID: ' . $app_id);
            error_log('[DNE OneSignal Test] API Key length: ' . strlen($api_key));
            error_log('[DNE OneSignal Test] API Key (first 10 chars): ' . substr($api_key, 0, 10) . '...');
            error_log('[DNE OneSignal Test] API Key (last 10 chars): ...' . substr($api_key, -10));
            // Check for common issues
            if (strpos($api_key, ' ') !== false) {
                error_log('[DNE OneSignal Test] WARNING: API Key contains spaces!');
            }
            if (strlen($api_key) < 40) {
                error_log('[DNE OneSignal Test] WARNING: API Key seems too short (typical length is 48 chars)');
            }
        }
        
        // Use the correct endpoint - viewing app details
        $response = wp_remote_get(
            "https://onesignal.com/api/v1/apps/{$app_id}",
            [
                'headers' => [
                    'Authorization' => 'Basic ' . $api_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 30
            ]
        );
        
        if (is_wp_error($response)) {
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal Test] WP Error: ' . $response->get_error_message());
            }
            add_settings_error('dne_settings', 'onesignal_test', __('Connection failed: ', 'deal-notification-engine') . $response->get_error_message(), 'error');
            return;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Debug logging
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal Test] Response Code: ' . $code);
            error_log('[DNE OneSignal Test] Response Body: ' . $body);
            
            // Log the actual header being sent (for debugging)
            error_log('[DNE OneSignal Test] Authorization header: Basic ' . substr($api_key, 0, 10) . '...[hidden]');
        }
        
        if ($code === 200) {
            $data = json_decode($body, true);
            $app_name = $data['name'] ?? 'Unknown';
            $players = $data['players'] ?? 0;
            add_settings_error('dne_settings', 'onesignal_test', 
                sprintf(__('Successfully connected to OneSignal app: %s (Players: %d)', 'deal-notification-engine'), $app_name, $players), 
                'success'
            );
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['errors']) ? implode(', ', (array)$error_data['errors']) : 'Unknown error (Code: ' . $code . ')';
            
            if (get_option('dne_debug_mode') === '1') {
                error_log('[DNE OneSignal Test] Error details: ' . print_r($error_data, true));
            }
            
            add_settings_error('dne_settings', 'onesignal_test', __('OneSignal API Error: ', 'deal-notification-engine') . $error_message, 'error');
        }
    }
}
