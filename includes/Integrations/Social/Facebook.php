<?php

namespace DNE\Integrations\Social;

/**
 * Facebook Page broadcaster
 */
class Facebook
{
    private $page_id;
    private $page_token;
    private $template;

    public function __construct()
    {
        $this->page_id    = get_option('dne_fb_page_id', '');
        $this->page_token = get_option('dne_fb_page_token', '');
        $this->template   = get_option('dne_fb_template', "{title}\n{url}");
    }

    public function is_configured(): bool
    {
        return !empty($this->page_id) && !empty($this->page_token);
    }

    /**
     * Post to Facebook Page (prefer link post; fallback to photo if needed)
     * @param \WP_Post $post
     * @param array $deal_data
     * @return array
     */
    public function send_post($post, array $deal_data = [])
    {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'Facebook not configured'];
        }

        $title = get_the_title($post);
        $url   = get_permalink($post);
        $discount = isset($deal_data['discount']) ? (int)$deal_data['discount'] : 0;
        $price = $deal_data['price'] ?? get_post_meta($post->ID, '_discount_price', true);
        $old   = $deal_data['old_price'] ?? get_post_meta($post->ID, '_original_price', true);
        $currency = $deal_data['currency'] ?? (has_filter('dne_social_currency') ? apply_filters('dne_social_currency', 'SEK', $post->ID) : 'SEK');
        $msg = $this->render_template($this->template, [
            'title' => $title,
            'url' => $url,
            'discount' => $discount,
            'price' => $price,
            'old_price' => $old,
            'currency' => $currency,
            'site' => get_bloginfo('name'),
        ]);

        // Try link post first
        $endpoint = 'https://graph.facebook.com/v19.0/' . rawurlencode($this->page_id) . '/feed';
        $resp = wp_remote_post($endpoint, [
            'timeout' => 20,
            'body' => [
                'message' => $msg,
                'link' => $url,
                'access_token' => $this->page_token,
            ],
        ]);

        if (!is_wp_error($resp)) {
            $code = wp_remote_retrieve_response_code($resp);
            $raw  = wp_remote_retrieve_body($resp);
            $data = json_decode($raw, true);
            if ($code === 200 && isset($data['id'])) {
                return ['success' => true, 'message' => 'Posted to Facebook Page'];
            }
        }

        // Fallback to photo post with featured image
        $photo = get_the_post_thumbnail_url($post, 'large');
        if ($photo) {
            $endpoint = 'https://graph.facebook.com/v19.0/' . rawurlencode($this->page_id) . '/photos';
            $resp = wp_remote_post($endpoint, [
                'timeout' => 20,
                'body' => [
                    'url' => $photo,
                    'caption' => $msg,
                    'access_token' => $this->page_token,
                ],
            ]);
            if (!is_wp_error($resp)) {
                $code = wp_remote_retrieve_response_code($resp);
                $raw  = wp_remote_retrieve_body($resp);
                $data = json_decode($raw, true);
                if ($code === 200 && isset($data['id'])) {
                    return ['success' => true, 'message' => 'Posted photo to Facebook Page'];
                }
                return ['success' => false, 'message' => 'Facebook API error: ' . (string)$raw];
            }
            return ['success' => false, 'message' => $resp->get_error_message()];
        }

        // No success and no photo fallback
        return ['success' => false, 'message' => 'Facebook post failed (no response/invalid and no photo fallback)'];
    }

    private function render_template(string $tpl, array $vars): string
    {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        // Support underscore meta-style placeholders for prices
        $out = str_replace('{_discount_price}', (string)($vars['price'] ?? ''), $out);
        $out = str_replace('{_original_price}', (string)($vars['old_price'] ?? ''), $out);
        $out = str_replace('{price}', (string)($vars['price'] ?? ''), $out);
        $out = str_replace('{old_price}', (string)($vars['old_price'] ?? ''), $out);
        $out = str_replace('{currency}', (string)($vars['currency'] ?? 'SEK'), $out);
        if (strpos($out, '{discount}') === false && !empty($vars['discount'])) {
            $out = intval($vars['discount']) . "% OFF\n" . $out;
        }
        return $out;
    }
}
