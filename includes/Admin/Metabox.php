<?php
namespace DNE\Admin;

/**
 * Post metabox for deal notification controls
 */
class Metabox {
    
    /**
     * Initialize metabox
     */
    public function init() {
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post', [$this, 'save_metabox']);
        add_action('admin_notices', [$this, 'show_notification_results']);
    }
    
    /**
     * Add metabox to post editor
     */
    public function add_metabox() {
        add_meta_box(
            'dne_deal_notifications',
            __('Deal Notifications', 'deal-notification-engine'),
            [$this, 'render_metabox'],
            'post',
            'side',
            'default'
        );
    }
    
    /**
     * Render metabox content
     */
    public function render_metabox($post) {
        wp_nonce_field('dne_metabox', 'dne_metabox_nonce');
        
        $is_deal = get_post_meta($post->ID, '_is_deal_post', true);
        $notification_sent = get_post_meta($post->ID, '_dne_notification_sent', true);
        $last_notification = get_post_meta($post->ID, '_dne_last_notification', true);
        
        ?>
        <div style="margin-bottom: 10px;">
            <label>
                <input type="checkbox" name="dne_is_deal" value="1" <?php checked($is_deal, '1'); ?>>
                <?php echo esc_html__('This is a deal post', 'deal-notification-engine'); ?>
            </label>
        </div>
        
        <?php if ($notification_sent): ?>
            <div style="background: #d4edda; padding: 8px; margin-bottom: 10px; border-radius: 3px;">
                <strong>✓ Notifications sent</strong><br>
                <small>Last sent: <?php echo esc_html($last_notification ?: 'Unknown'); ?></small>
            </div>
        <?php endif; ?>
        
        <?php if ($post->post_status === 'publish'): ?>
            <div style="margin-bottom: 10px;">
                <label>
                    <input type="checkbox" name="dne_trigger_notifications" value="1">
                    <strong><?php echo esc_html__('Send notifications now', 'deal-notification-engine'); ?></strong>
                </label>
                <p class="description">
                    <?php echo esc_html__('Check this and update the post to manually trigger notifications.', 'deal-notification-engine'); ?>
                </p>
            </div>
            
            <div style="margin-bottom: 10px;">
                <label>
                    <input type="checkbox" name="dne_force_resend" value="1">
                    <?php echo esc_html__('Force resend (ignore if already sent)', 'deal-notification-engine'); ?>
                </label>
            </div>
        <?php else: ?>
            <p class="description">
                <?php echo esc_html__('Publish the post to send notifications.', 'deal-notification-engine'); ?>
            </p>
        <?php endif; ?>
        
        <div style="background: #f8f9fa; padding: 8px; margin-top: 10px; border-radius: 3px;">
            <strong>Deal Detection:</strong><br>
            <?php
            // Check what makes this a deal
            $reasons = [];
            
            $product_cats = wp_get_object_terms($post->ID, 'product_categories', ['fields' => 'names']);
            if (!is_wp_error($product_cats) && !empty($product_cats)) {
                $reasons[] = 'Has product categories';
            }
            
            $stores = wp_get_object_terms($post->ID, 'store_type', ['fields' => 'names']);
            if (!is_wp_error($stores) && !empty($stores)) {
                $reasons[] = 'Has stores';
            }
            
            $content = $post->post_title . ' ' . $post->post_content;
            if (preg_match('/(\d+)\s*%/', $content, $matches)) {
                $reasons[] = $matches[1] . '% discount found';
            }
            
            if (empty($reasons)) {
                echo '<small style="color: #666;">Not detected as deal</small>';
            } else {
                echo '<small style="color: #28a745;">' . implode(', ', $reasons) . '</small>';
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Save metabox data
     */
    public function save_metabox($post_id) {
        // Check nonce
        if (!isset($_POST['dne_metabox_nonce']) || !wp_verify_nonce($_POST['dne_metabox_nonce'], 'dne_metabox')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save is_deal status
        $is_deal = isset($_POST['dne_is_deal']) ? '1' : '';
        update_post_meta($post_id, '_is_deal_post', $is_deal);
        
        // Handle manual notification trigger
        if (isset($_POST['dne_trigger_notifications'])) {
            $force = isset($_POST['dne_force_resend']);
            
            // Check if already sent (unless forcing)
            if (!$force && get_post_meta($post_id, '_dne_notification_sent', true)) {
                set_transient('dne_notification_result_' . $post_id, [
                    'type' => 'warning',
                    'message' => 'Notifications already sent for this post.'
                ], 30);
                return;
            }
            
            // Trigger notifications
            $post = get_post($post_id);
            $engine = new \DNE\Notifications\Engine();
            
            // Manually call the notification handler
            $engine->handle_new_deal($post_id, $post);
            
            // Mark as sent
            update_post_meta($post_id, '_dne_notification_sent', '1');
            update_post_meta($post_id, '_dne_last_notification', current_time('mysql'));
            
            // Set success message
            set_transient('dne_notification_result_' . $post_id, [
                'type' => 'success',
                'message' => 'Notifications queued successfully!'
            ], 30);
        }
    }
    
    /**
     * Show notification results after save
     */
    public function show_notification_results() {
        global $post;
        
        if (!$post || !isset($_GET['post'])) {
            return;
        }
        
        $result = get_transient('dne_notification_result_' . $post->ID);
        if ($result) {
            delete_transient('dne_notification_result_' . $post->ID);
            
            $class = $result['type'] === 'success' ? 'notice-success' : 'notice-warning';
            ?>
            <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
                <p><strong>Deal Notifications:</strong> <?php echo esc_html($result['message']); ?></p>
            </div>
            <?php
        }
    }
}