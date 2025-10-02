<?php

namespace DNE\Integrations\Social;

/**
 * X (Twitter) broadcaster via twitterapi.io (Unofficial API)
 * Single-step user_login_v2 only (no two-step endpoints, no totp_code generation).
 */
class X
{
    private $api_key;
    private $session; // login_cookies/session returned by login_v2
    private $user_name;
    private $email;
    private $password;
    private $totp_secret; // base32 secret from X 2FA (Authentication app)
    private $proxy;
    private $template;

    public function __construct()
    {
        $this->api_key     = get_option('dne_x_api_key', '');
        // Read either option for compatibility with earlier builds
        $this->session     = get_option('dne_x_login_cookies', '');
        if (empty($this->session)) {
            $this->session = get_option('dne_x_session', '');
        }
        $this->user_name   = get_option('dne_x_user_name', '');
        $this->email       = get_option('dne_x_email', '');
        $this->password    = get_option('dne_x_password', '');
        $this->totp_secret = get_option('dne_x_totp_secret', '');
        $this->proxy       = get_option('dne_x_proxy', '');
        $this->template    = get_option('dne_x_template', '{title} {url}');
    }

    public function is_configured(): bool
    {
        if (empty($this->api_key)) return false;
        if (!empty($this->session)) return true;
        return (!empty($this->user_name) && !empty($this->email) && !empty($this->password)
            && !empty($this->totp_secret) && !empty($this->proxy));
    }

    public function send_post($post, array $deal_data = [])
    {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'X/Twitter not configured'];
        }

        if (empty($this->session)) {
            $login = $this->login();
            if (!$login['success']) {
                return $login;
            }
        }

        $title    = get_the_title($post);
        $url      = get_permalink($post);
        $discount = isset($deal_data['discount']) ? (int)$deal_data['discount'] : 0;
        $price = $deal_data['price'] ?? get_post_meta($post->ID, '_discount_price', true);
        $old   = $deal_data['old_price'] ?? get_post_meta($post->ID, '_original_price', true);
        $currency = $deal_data['currency'] ?? (has_filter('dne_social_currency') ? apply_filters('dne_social_currency', 'SEK', $post->ID) : 'SEK');
        $text = $this->render_template($this->template, [
            'title' => $title,
            'url' => $url,
            'discount' => $discount,
            'price' => $price,
            'old_price' => $old,
            'currency' => $currency,
            'site' => get_bloginfo('name'),
        ]);

        $endpoint = 'https://api.twitterapi.io/twitter/create_tweet_v2';
        $resp = wp_remote_post($endpoint, [
            'timeout' => 45,
            'headers' => [
                'x-api-key'    => $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'login_cookies' => $this->session,
                'tweet_text'    => $text,
                'proxy'         => $this->proxy,
            ]),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code === 200 && is_array($data) && ($data['status'] ?? '') === 'success') {
            return ['success' => true, 'message' => 'Tweet created'];
        }

        if ($code === 200 && is_array($data) && (stripos((string)($data['msg'] ?? ''), 'cookie') !== false)) {
            $login = $this->login(true);
            if ($login['success']) {
                return $this->send_post($post, $deal_data);
            }
        }

        if (get_option('dne_debug_mode', '0') === '1') {
            error_log('[DNE X create_tweet_v2] HTTP '.$code.' body='.substr($raw,0,300));
        }
        return ['success' => false, 'message' => 'Twitter API error: ' . (string)$raw];
    }

    public function login(bool $force = false)
    {
        if (!$force && !empty($this->session)) {
            return ['success' => true, 'message' => 'Already logged in'];
        }
        if (empty($this->api_key) || empty($this->user_name) || empty($this->email)
            || empty($this->password) || empty($this->totp_secret) || empty($this->proxy)) {
            return ['success' => false, 'message' => 'Missing login credentials for twitterapi.io'];
        }

        $endpoint = 'https://api.twitterapi.io/twitter/user_login_v2';
        $resp = wp_remote_post($endpoint, [
            'timeout' => 60,
            'headers' => [
                'x-api-key'    => $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'user_name'   => $this->user_name,
                'email'       => $this->email,
                'password'    => $this->password,
                'proxy'       => $this->proxy,
                'totp_secret' => $this->totp_secret,
            ]),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code === 200 && is_array($data)) {
            $cookie = $data['login_cookies'] ?? $data['login_cookie'] ?? $data['session'] ?? '';
            if (!empty($cookie)) {
                $this->session = (string)$cookie;
                update_option('dne_x_login_cookies', $this->session, false);
                update_option('dne_x_session', $this->session, false);
                return ['success' => true, 'message' => 'Login successful'];
            }
        }

        if (get_option('dne_debug_mode', '0') === '1') {
            error_log('[DNE X user_login_v2] HTTP '.$code.' body='.substr($raw,0,300));
        }
        if (stripos($raw, 'cloudflare') !== false) {
            return ['success' => false, 'message' => 'Cloudflare blocked the login route for this proxy'];
        }
        return ['success' => false, 'message' => (string)$raw];
    }

    private function render_template(string $tpl, array $vars): string
    {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        if (strpos($out, '{discount}') === false && !empty($vars['discount'])) {
            $out = intval($vars['discount']) . "% OFF " . $out;
        }
        // Price placeholders
        $out = str_replace('{_discount_price}', (string)($vars['price'] ?? ''), $out);
        $out = str_replace('{_original_price}', (string)($vars['old_price'] ?? ''), $out);
        $out = str_replace('{price}', (string)($vars['price'] ?? ''), $out);
        $out = str_replace('{old_price}', (string)($vars['old_price'] ?? ''), $out);
        $out = str_replace('{currency}', (string)($vars['currency'] ?? 'SEK'), $out);
        if (mb_strlen($out) > 280) {
            $out = mb_substr($out, 0, 277) . '...';
        }
        return $out;
    }
}
