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

        // X Unofficial endpoints
        add_action('admin_post_dne_x_login', [$this, 'x_login_unofficial']);
        add_action('admin_post_dne_x_test', [$this, 'x_test_post']);
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

        // Social Settings Section
        add_settings_section(
            'dne_social_settings',
            __('Social Broadcast', 'deal-notification-engine'),
            [$this, 'social_section_callback'],
            'dne-settings'
        );

        // Telegram Channel
        register_setting('dne_settings_group', 'dne_tg_channel_enabled');
        add_settings_field(
            'dne_tg_channel_enabled',
            __('Enable Telegram Channel', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_enabled', 'label' => 'Broadcast newly published deals to a Telegram channel']
        );

        register_setting('dne_settings_group', 'dne_tg_channel_chat_id');
        add_settings_field(
            'dne_tg_channel_chat_id',
            __('Telegram Channel ID', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_chat_id', 'placeholder' => '@your_channel or -100xxxxxxxxxx']
        );

        register_setting('dne_settings_group', 'dne_tg_channel_bot_token', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_tg_channel_bot_token',
            __('Bot Token Override (optional)', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_bot_token', 'description' => 'Leave blank to inherit the main Telegram bot token']
        );

        register_setting('dne_settings_group', 'dne_tg_channel_template');
        add_settings_field(
            'dne_tg_channel_template',
            __('Telegram Message Template', 'deal-notification-engine'),
            [$this, 'render_textarea_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_template', 'placeholder' => "<b>{title}</b>\n{url}"]
        );

        // Facebook Page
        register_setting('dne_settings_group', 'dne_fb_enabled');
        add_settings_field(
            'dne_fb_enabled',
            __('Enable Facebook Page', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_fb_enabled', 'label' => 'Broadcast newly published deals to a Facebook Page']
        );

        register_setting('dne_settings_group', 'dne_fb_page_id');
        add_settings_field(
            'dne_fb_page_id',
            __('Facebook Page ID', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_fb_page_id', 'placeholder' => '123456789012345']
        );

        register_setting('dne_settings_group', 'dne_fb_app_id');
        add_settings_field(
            'dne_fb_app_id',
            __('Facebook App ID (optional)', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_fb_app_id']
        );

        register_setting('dne_settings_group', 'dne_fb_app_secret', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_fb_app_secret',
            __('Facebook App Secret (optional)', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_fb_app_secret']
        );

        register_setting('dne_settings_group', 'dne_fb_page_token', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_fb_page_token',
            __('Facebook Page Access Token', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_fb_page_token', 'description' => 'Long-lived Page access token with pages_manage_posts']
        );

        register_setting('dne_settings_group', 'dne_fb_template');
        add_settings_field(
            'dne_fb_template',
            __('Facebook Message Template', 'deal-notification-engine'),
            [$this, 'render_textarea_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_fb_template', 'placeholder' => "{title}\n{url}"]
        );

        // X (Twitter) Unofficial (twitterapi.io)
        register_setting('dne_settings_group', 'dne_x_enabled');
        add_settings_field(
            'dne_x_enabled',
            __('Enable X (Twitter)', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_enabled', 'label' => 'Broadcast newly published deals to X (twitterapi.io)']
        );

        register_setting('dne_settings_group', 'dne_x_api_key', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_x_api_key',
            __('twitterapi.io API Key', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_api_key']
        );

        register_setting('dne_settings_group', 'dne_x_user_name');
        add_settings_field(
            'dne_x_user_name',
            __('X Username', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_user_name']
        );

        register_setting('dne_settings_group', 'dne_x_email');
        add_settings_field(
            'dne_x_email',
            __('X Email', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_email']
        );

        register_setting('dne_settings_group', 'dne_x_password', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_x_password',
            __('X Password', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_password']
        );

        register_setting('dne_settings_group', 'dne_x_totp_secret', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_x_totp_secret',
            __('X TOTP Secret (2FA)', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_totp_secret']
        );

        register_setting('dne_settings_group', 'dne_x_proxy');
        add_settings_field(
            'dne_x_proxy',
            __('Proxy', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_proxy', 'placeholder' => 'http://user:pass@host:port']
        );

        register_setting('dne_settings_group', 'dne_x_login_cookies', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_x_login_cookies',
            __('Login Cookies (auto-filled)', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_login_cookies', 'description' => 'Filled by Connect/Refresh Session']
        );

        register_setting('dne_settings_group', 'dne_x_template');
        add_settings_field(
            'dne_x_template',
            __('X Tweet Template', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_x_template', 'placeholder' => '{title} {url}']
        );
        register_setting('dne_settings_group', 'dne_tg_channel_enabled');
        add_settings_field(
            'dne_tg_channel_enabled',
            __('Enable Telegram Channel', 'deal-notification-engine'),
            [$this, 'render_checkbox'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_enabled', 'label' => 'Broadcast newly published deals to a Telegram channel']
        );

        register_setting('dne_settings_group', 'dne_tg_channel_chat_id');
        add_settings_field(
            'dne_tg_channel_chat_id',
            __('Telegram Channel ID', 'deal-notification-engine'),
            [$this, 'render_text_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_chat_id', 'placeholder' => '@your_channel or -100xxxxxxxxxx']
        );

        register_setting('dne_settings_group', 'dne_tg_channel_bot_token', [$this, 'sanitize_token']);
        add_settings_field(
            'dne_tg_channel_bot_token',
            __('Bot Token Override (optional)', 'deal-notification-engine'),
            [$this, 'render_password_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_bot_token', 'description' => 'Leave blank to inherit the main Telegram bot token']
        );

        register_setting('dne_settings_group', 'dne_tg_channel_template');
        add_settings_field(
            'dne_tg_channel_template',
            __('Telegram Message Template', 'deal-notification-engine'),
            [$this, 'render_textarea_field'],
            'dne-settings',
            'dne_social_settings',
            ['field' => 'dne_tg_channel_template', 'placeholder' => '<b>{title}</b>\n{url}']
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
        if (isset($_GET['test_tg_channel'])) {
            $this->test_tg_channel_post();
        }
        if (isset($_GET['test_x'])) {
            $this->x_test_post();
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
                    <a href="?page=deal-notifications-settings&test_tg_channel=1" class="button button-secondary">
                        <?php echo esc_html__('Test Telegram Channel Post', 'deal-notification-engine'); ?>
                    </a>
                </p>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('X (Twitter) – Unofficial (twitterapi.io)', 'deal-notification-engine'); ?></h2>
                <p><?php echo esc_html__('Connect/refresh a session using your account credentials and 2FA secret. Uses a residential proxy (required by the API).', 'deal-notification-engine'); ?></p>
                <?php
                // Updated to check for all required credentials
                $has_api = get_option('dne_x_api_key', '') &&
                    get_option('dne_x_user_name', '') &&
                    get_option('dne_x_email', '') &&
                    get_option('dne_x_password', '') &&
                    get_option('dne_x_totp_secret', '') &&
                    get_option('dne_x_proxy', '');

                // Updated to check for session instead of login_cookies
                $has_session = get_option('dne_x_session', '') ? true : false;

                // Display last login result
                $last_result = get_option('dne_x_last_login_result');
                if ($last_result) {
                    $lr = json_decode($last_result, true);
                    if (is_array($lr)) {
                        $ok = !empty($lr['success']);
                        $time = !empty($lr['time']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), intval($lr['time'])) : '';
                        echo '<p><strong>' . esc_html__('Last Connect Result:', 'deal-notification-engine') . '</strong> ';
                        echo $ok ? '<span style="color:green;">' . esc_html__('Success', 'deal-notification-engine') . '</span>' : '<span style="color:#cc0000;">' . esc_html__('Failed', 'deal-notification-engine') . '</span>';
                        if (!empty($lr['message'])) {
                            $msg = is_string($lr['message']) ? $lr['message'] : wp_json_encode($lr['message']);
                            if (mb_strlen($msg) > 200) {
                                $msg = mb_substr($msg, 0, 200) . '…';
                            }
                            echo ' — ' . esc_html($msg);
                        }
                        if ($time) echo ' <em>(' . esc_html($time) . ')</em>';
                        echo '</p>';
                    }
                }

                if ($has_session) {
                    $session_preview = substr(get_option('dne_x_session', ''), 0, 20) . '...';
                    echo '<p><strong>' . esc_html__('Current Session:', 'deal-notification-engine') . '</strong> <code>' . esc_html($session_preview) . '</code> <span style="color:green;">✓</span></p>';
                } else {
                    echo '<p><strong>' . esc_html__('Session Status:', 'deal-notification-engine') . '</strong> <span style="color:#cc0000;">' . esc_html__('No active session', 'deal-notification-engine') . '</span></p>';
                }

                if (!$has_api) {
                    $missing = [];
                    if (!get_option('dne_x_api_key', '')) $missing[] = 'API Key';
                    if (!get_option('dne_x_user_name', '')) $missing[] = 'Username';
                    if (!get_option('dne_x_email', '')) $missing[] = 'Email';
                    if (!get_option('dne_x_password', '')) $missing[] = 'Password';
                    if (!get_option('dne_x_totp_secret', '')) $missing[] = 'TOTP Secret';
                    if (!get_option('dne_x_proxy', '')) $missing[] = 'Proxy';

                    echo '<p style="color:#cc0000;"><strong>' . esc_html__('Missing Required Fields:', 'deal-notification-engine') . '</strong> ' . esc_html(implode(', ', $missing)) . '</p>';
                }
                ?>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dne_x_login'), 'dne_x_login', '_wpnonce')); ?>" class="button button-primary" <?php echo $has_api ? '' : 'disabled'; ?>>
                        <?php echo esc_html__('Connect / Refresh Session', 'deal-notification-engine'); ?>
                    </a>
                    <a href="?page=deal-notifications-settings&test_x=1" class="button button-secondary" <?php echo $has_session ? '' : 'disabled'; ?>>
                        <?php echo esc_html__('Test X Tweet', 'deal-notification-engine'); ?>
                    </a>
                    <?php if ($has_session): ?>
                        <a href="?page=deal-notifications-settings&clear_x_session=1" class="button button-link-delete" style="margin-left: 10px;" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear the current session?', 'deal-notification-engine')); ?>');">
                            <?php echo esc_html__('Clear Session', 'deal-notification-engine'); ?>
                        </a>
                    <?php endif; ?>
                </p>

                <details style="margin-top: 15px;">
                    <summary style="cursor: pointer; font-weight: bold;"><?php echo esc_html__('API Migration Notice', 'deal-notification-engine'); ?></summary>
                    <div style="padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6; margin-top: 10px;">
                        <p><strong><?php echo esc_html__('Important:', 'deal-notification-engine'); ?></strong> <?php echo esc_html__('twitterapi.io has updated their authentication process. The system now uses a two-step login process:', 'deal-notification-engine'); ?></p>
                        <ol>
                            <li><?php echo esc_html__('Initial login with username/email and password', 'deal-notification-engine'); ?></li>
                            <li><?php echo esc_html__('2FA authentication to get session token', 'deal-notification-engine'); ?></li>
                        </ol>
                        <p><?php echo esc_html__('If you had previously stored login cookies, please click "Connect / Refresh Session" to migrate to the new system.', 'deal-notification-engine'); ?></p>
                    </div>
                </details>
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
     * Render a textarea field
     */
    public function render_textarea_field($args)
    {
        $field = $args['field'];
        $value = get_option($field, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        echo '<textarea name="' . esc_attr($field) . '" rows="3" class="large-text" placeholder="' . esc_attr($placeholder) . '">'
            . esc_textarea($value) . '</textarea>';
    }

    /**
     * Social settings section text
     */
    public function social_section_callback()
    {
        echo '<p>' . esc_html__('Configure posts notifications to your social media channels.', 'deal-notification-engine') . '</p>';
    }

    /**
     * Test Telegram Channel post using most recent published post
     */
    private function test_tg_channel_post()
    {
        if (!current_user_can('manage_options')) {
            add_settings_error('dne_settings', 'dne_tgchan_perm', __('Insufficient permissions for Telegram Channel test', 'deal-notification-engine'), 'error');
            return;
        }
        if (get_option('dne_tg_channel_enabled') !== '1') {
            add_settings_error('dne_settings', 'dne_tgchan_disabled', __('Telegram Channel broadcast is disabled', 'deal-notification-engine'), 'error');
            return;
        }
        $chan = new \DNE\Integrations\Social\Telegram_Channel();
        if (!$chan->is_configured()) {
            add_settings_error('dne_settings', 'dne_tgchan_config', __('Telegram Channel is not configured (chat_id or token missing)', 'deal-notification-engine'), 'error');
            return;
        }

        // Find most recent published post
        $post = get_posts([
            'numberposts' => 1,
            'post_status' => 'publish',
            'post_type' => 'post',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        if (empty($post)) {
            add_settings_error('dne_settings', 'dne_tgchan_nopost', __('No published posts found to test with', 'deal-notification-engine'), 'error');
            return;
        }
        $post = $post[0];

        $result = $chan->send_post($post, [
            'discount' => (int) get_post_meta($post->ID, '_discount_percentage', true),
        ]);
        if ($result['success']) {
            add_settings_error('dne_settings', 'dne_tgchan_ok', __('Telegram Channel test sent successfully', 'deal-notification-engine'), 'success');
        } else {
            add_settings_error('dne_settings', 'dne_tgchan_fail', __('Telegram Channel test failed: ', 'deal-notification-engine') . esc_html($result['message']), 'error');
        }
    }

    // Unofficial login via twitterapi.io
    public function x_login_unofficial()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        check_admin_referer('dne_x_login');
        $x = new \DNE\Integrations\Social\X();
        $res = $x->login(true);

        // Persist last result for display after redirect
        $message = is_array($res['message'] ?? null) ? wp_json_encode($res['message']) : (string)($res['message'] ?? '');
        update_option('dne_x_last_login_result', wp_json_encode([
            'success' => !empty($res['success']),
            'message' => $message,
            'time'    => time(),
        ]), false);

        // Visible notice
        if (!empty($res['success'])) {
            add_settings_error('dne_settings', 'dne_x_login', __('X session connected/refreshed successfully.', 'deal-notification-engine'), 'updated');
        } else {
            $msg = $message;
            if (mb_strlen($msg) > 300) {
                $msg = mb_substr($msg, 0, 300) . '…';
            }
            add_settings_error('dne_settings', 'dne_x_login', __('X login failed: ', 'deal-notification-engine') . esc_html($msg), 'error');
        }

        // Debug log (when Debug Mode is ON)
        if (get_option('dne_debug_mode', '0') === '1') {
            $logMsg = '[DNE X Login] success=' . (!empty($res['success']) ? '1' : '0') . ' msg=' . (is_string($message) ? $message : wp_json_encode($message));
            // Truncate to keep logs tidy
            if (strlen($logMsg) > 2000) {
                $logMsg = substr($logMsg, 0, 2000) . '…';
            }
            error_log($logMsg);
        }
        wp_redirect(admin_url('admin.php?page=deal-notifications-settings'));
        exit;
    }

    /**
     * Test X tweet using latest post
     */
    public function x_test_post()
    {
        if (!current_user_can('manage_options')) {
            add_settings_error('dne_settings', 'dne_x_test', __('Insufficient permissions for X test', 'deal-notification-engine'), 'error');
            return;
        }
        $post = get_posts([
            'numberposts' => 1,
            'post_status' => 'publish',
            'post_type' => 'post',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        if (empty($post)) {
            add_settings_error('dne_settings', 'dne_x_test', __('No published posts found to test with', 'deal-notification-engine'), 'error');
            return;
        }
        $post = $post[0];
        $tw = new \DNE\Integrations\Social\X();
        $res = $tw->send_post($post, [
            'discount' => (int) get_post_meta($post->ID, '_discount_percentage', true),
        ]);
        if ($res['success']) {
            add_settings_error('dne_settings', 'dne_x_test', __('Test tweet created successfully', 'deal-notification-engine'), 'updated');
        } else {
            add_settings_error('dne_settings', 'dne_x_test', __('Test tweet failed: ', 'deal-notification-engine') . esc_html($res['message']), 'error');
        }
    }

    // Removed PKCE helpers (not needed in unofficial mode)

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
    private function test_onesignal_connection()
    {
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
            add_settings_error(
                'dne_settings',
                'onesignal_test',
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
