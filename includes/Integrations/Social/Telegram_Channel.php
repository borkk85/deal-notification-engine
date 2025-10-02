<?php

namespace DNE\Integrations\Social;

/**
 * Telegram Channel broadcaster (separate from user DM notifications)
 */
class Telegram_Channel
{
    private $bot_token;
    private $chat_id;
    private $template;

    public function __construct()
    {
        // Pull settings; allow override token, else inherit bot token from DM settings
        $override = get_option('dne_tg_channel_bot_token', '');
        $this->bot_token = $override !== '' ? $override : get_option('dne_telegram_bot_token', '');
        $this->chat_id   = get_option('dne_tg_channel_chat_id', '');
        $this->template  = get_option('dne_tg_channel_template', "<b>{title}</b>\n{url}");
    }

    public function is_configured(): bool
    {
        return !empty($this->bot_token) && !empty($this->chat_id);
    }

    /**
     * Send a post to Telegram channel (sendPhoto if image exists else sendMessage)
     * @param \WP_Post $post
     * @param array $deal_data Optional precomputed data: ['discount'=>..]
     * @return array [success=>bool, message=>string]
     */
    public function send_post($post, array $deal_data = [])
    {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'Telegram Channel not configured'];
        }

        $title = get_the_title($post);
        $url   = get_permalink($post);
        $discount = isset($deal_data['discount']) ? (int)$deal_data['discount'] : 0;

        $msg = $this->render_template($this->template, [
            'title' => $title,
            'url' => $url,
            'discount' => $discount,
            'price' => $deal_data['price'] ?? get_post_meta($post->ID, '_discount_price', true),
            'old_price' => $deal_data['old_price'] ?? get_post_meta($post->ID, '_original_price', true),
            'currency' => $deal_data['currency'] ?? (has_filter('dne_social_currency') ? apply_filters('dne_social_currency', 'SEK', $post->ID) : 'SEK'),
            'site' => get_bloginfo('name'),
        ]);

        $photo = get_the_post_thumbnail_url($post, 'large');
        $endpoint = empty($photo)
            ? "https://api.telegram.org/bot{$this->bot_token}/sendMessage"
            : "https://api.telegram.org/bot{$this->bot_token}/sendPhoto";

        $body = [
            'chat_id' => $this->chat_id,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ];
        if ($endpoint !== '' && strpos($endpoint, '/sendPhoto') !== false) {
            $body['photo'] = $photo;
            $body['caption'] = $msg;
        } else {
            $body['text'] = $msg;
        }

        $resp = wp_remote_post($endpoint, [
            'body' => $body,
            'timeout' => 15,
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code === 200 && is_array($data) && !empty($data['ok'])) {
            return ['success' => true, 'message' => 'Posted to Telegram channel'];
        }
        $desc = is_array($data) && isset($data['description']) ? (string)$data['description'] : (string)$raw;
        return ['success' => false, 'message' => 'Telegram API error: ' . $desc];
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
        // If discount present, prepend as badge if not in template
        if (strpos($out, '{discount}') === false && !empty($vars['discount'])) {
            $out = '<b>' . intval($vars['discount']) . "% OFF</b>\n" . $out;
        }
        return $out;
    }
}
