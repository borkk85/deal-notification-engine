<?php

namespace DNE\Integrations;

/**
 * Email notification handler
 */
class Email
{

    /**
     * Send email notification
     */
    /**
     * Send email notification
     */
    public function send($user, $post)
    {
        // Gate by user preferences/tier before doing work
        $filter = new \DNE\Notifications\Filter();
        $user_id = is_object($user) && isset($user->ID) ? (int) $user->ID : (int) $user;
        if (!$filter->user_allows_channel($user_id, 'email')) {
            dne_debug("DENY email for user {$user_id} (preferences/tier)");
            return [
                'success' => false,
                'message' => 'User has email disabled or not allowed by tier'
            ];
        }
        dne_debug("ALLOW email for user {$user_id}");

        // Get email settings
        $from_name = get_option('dne_email_from_name', get_bloginfo('name'));
        $from_email = get_option('dne_email_from_address', get_option('admin_email'));
        $subject_template = get_option('dne_email_subject_template', 'New Deal Alert: {title}');

        // Prepare email data
        $to = $user->user_email;
        $subject = str_replace('{title}', get_the_title($post), $subject_template);

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Email] Sending to: ' . $to);
            error_log('[DNE Email] Subject: ' . $subject);
            error_log('[DNE Email] From: ' . $from_name . ' <' . $from_email . '>');
        }

        // Build email content
        $message = $this->build_email_content($user, $post);

        // Set headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: $from_name <$from_email>"
        ];

        // Send email
        $sent = wp_mail($to, $subject, $message, $headers);

        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE Email] Send result: ' . ($sent ? 'SUCCESS' : 'FAILED'));
            if (!$sent) {
                // Try to get more info about mail configuration
                error_log('[DNE Email] PHP mail() available: ' . (function_exists('mail') ? 'yes' : 'no'));
                error_log('[DNE Email] WordPress SMTP configured: ' . (defined('SMTP_HOST') ? 'yes' : 'no'));
            }
        }

        return [
            'success' => $sent,
            'message' => $sent ? 'Email sent successfully' : 'Failed to send email (check SMTP configuration)'
        ];
    }

    /**
     * Build HTML email content
     */
    private function build_email_content($user, $post)
    {
        $title = get_the_title($post);
        $url = get_permalink($post);
        $excerpt = wp_trim_words($post->post_content, 100);
        $featured_image = get_the_post_thumbnail_url($post, 'large');

        // Extract discount if available
        $discount_text = '';
        if (preg_match('/(\d+)\s*%/', $title . ' ' . $post->post_content, $matches)) {
            $discount_text = "<div style='background: #ff6b6b; color: white; padding: 10px; text-align: center; font-size: 24px; font-weight: bold; margin-bottom: 20px;'>{$matches[1]}% OFF</div>";
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($title) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <div style="max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;">
            <h1 style="color: white; margin: 0; font-size: 28px;">🔥 New Deal Alert!</h1>
        </div>
        
        <!-- Content -->
        <div style="padding: 30px;">
            ' . $discount_text . '
            
            <h2 style="color: #333; margin-bottom: 20px;">' . esc_html($title) . '</h2>';

        if ($featured_image) {
            $html .= '<img src="' . esc_url($featured_image) . '" alt="' . esc_attr($title) . '" style="width: 100%; height: auto; margin-bottom: 20px; border-radius: 4px;">';
        }

        $html .= '
            <div style="color: #666; line-height: 1.6; margin-bottom: 30px;">
                ' . wp_kses_post($excerpt) . '
            </div>
            
            <!-- CTA Button -->
            <div style="text-align: center;">
                <a href="' . esc_url($url) . '" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 50px; font-size: 16px; font-weight: bold;">View Deal</a>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px;">
            <p>Hi ' . esc_html($user->display_name) . ', you\'re receiving this because you\'ve subscribed to deal notifications.</p>
            <p>To update your preferences, please visit your profile settings.</p>
            <p style="margin-top: 10px;">
                &copy; ' . date('Y') . ' ' . esc_html(get_bloginfo('name')) . '. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }
}
