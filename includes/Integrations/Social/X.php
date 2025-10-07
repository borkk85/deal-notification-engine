<?php

namespace DNE\Integrations\Social;

/**
 * X (Twitter) broadcaster via RapidAPI (twitter-api47) using authToken cookies.
 * Legacy twitterapi.io login logic is retained (commented in code) for future use if needed.
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
        return (!empty($this->api_key) && !empty($this->session));
    }

    public function send_post($post, array $deal_data = [])
    {
        if (!$this->is_configured()) {
            return ['success' => false, 'message' => 'X/Twitter not configured'];
        }

        if (empty($this->session)) {
            return ['success' => false, 'message' => 'Auth token (login_cookie) is missing. Paste it in the settings to enable X posting.'];
        }

        $title    = get_the_title($post);
        $url      = get_permalink($post);
        $discount = isset($deal_data['discount']) ? (int)$deal_data['discount'] : 0;
        $price    = $deal_data['price'] ?? get_post_meta($post->ID, '_discount_price', true);
        $old      = $deal_data['old_price'] ?? get_post_meta($post->ID, '_original_price', true);
        $currency = $deal_data['currency'] ?? (has_filter('dne_social_currency') ? apply_filters('dne_social_currency', 'SEK', $post->ID) : 'SEK');
        $text     = $this->render_template($this->template, [
            'title'     => $title,
            'url'       => $url,
            'discount'  => $discount,
            'price'     => $price,
            'old_price' => $old,
            'currency'  => $currency,
            'site'      => get_bloginfo('name'),
        ]);

        $endpoint = 'https://twitter-api47.p.rapidapi.com/v3/interaction/create-post';
        $body = [
            'authToken' => $this->session,
            'text'      => $text,
        ];

        if (!empty($this->proxy)) {
            $body['proxy'] = $this->proxy;
        }

        $resp = wp_remote_post($endpoint, [
            'timeout' => 45,
            'headers' => [
                'x-rapidapi-key'  => $this->api_key,
                'x-rapidapi-host' => 'twitter-api47.p.rapidapi.com',
                'Content-Type'    => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => $resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        $status = is_array($data) ? ($data['status'] ?? ($data['success'] ?? null)) : null;
        $nested_success = false;
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $inner = $data['data'];
            if (array_key_exists('success', $inner)) {
                $val = $inner['success'];
                if (is_bool($val)) {
                    $nested_success = $val;
                } elseif (is_string($val)) {
                    $nested_success = in_array(strtolower($val), ['true', '1', 'yes'], true);
                } else {
                    $nested_success = (bool)$val;
                }
            } elseif (isset($inner['id']) && $inner['id'] !== '') {
                $nested_success = true;
            }
        }

        if ($code >= 200 && $code < 300 && (
            $status === true ||
            (is_string($status) && strtolower($status) === 'success') ||
            isset($data['tweet_url']) ||
            $nested_success
        )) {
            return ['success' => true, 'message' => 'Posted to X'];
        }

        if (get_option('dne_debug_mode', '0') === '1') {
            error_log('[DNE X create_post rapidapi] HTTP '.$code.' body='.substr($raw, 0, 300));
        }

        $error_message = 'Twitter API error: ' . ($raw ?: 'unknown error');
        if (is_array($data)) {
            if (isset($data['error'])) {
                $error_message = 'Twitter API error: ' . (string)$data['error'];
            } elseif (isset($data['message'])) {
                $error_message = 'Twitter API error: ' . (string)$data['message'];
            }
        }

        return ['success' => false, 'message' => $error_message];
    }


    public function login(bool $force = false)
    {
        // twitterapi.io login flow temporarily disabled. Keep using the Auth Token field.
        return [
            'success' => false,
            'message' => 'twitterapi.io login is temporarily disabled. Paste the login_cookie/authToken directly in the settings.'
        ];
    }

    /*
    Legacy twitterapi.io login reference (for future re-enable):

    POST https://api.twitterapi.io/twitter/user_login_v2
    Headers: { "x-api-key": "YOUR_KEY", "Content-Type": "application/json" }
    Body:
    {
        "user_name": "username",
        "email": "email",
        "password": "password",
        "proxy": "user:pass@host:port",
        "totp_secret": "BASE32"
    }

    Response:
    {
        "status": "success",
        "login_cookies": "cookie string"
    }

    Create tweet v2:
    POST https://api.twitterapi.io/twitter/create_tweet_v2
    Body:
    {
        "login_cookies": "cookie string",
        "tweet_text": "hello",
        "proxy": "user:pass@host:port"
    }
    */

    private function render_template(string $tpl, array $vars): string
    {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        if (strpos($out, '{discount}') === false && !empty($vars['discount'])) {
            $out = intval($vars['discount']) . "% OFF\n" . $out;
        }
        // Price placeholders
        $out = str_replace('{_discount_price}', (string)($vars['price'] ?? ''), $out);
        $out = str_replace('{_original_price}', (string)($vars['old_price'] ?? ''), $out);
        $out = str_replace('{price}', (string)($vars['price'] ?? ''), $out);
        $out = str_replace('{old_price}', (string)($vars['old_price'] ?? ''), $out);
        $out = str_replace('{currency}', (string)($vars['currency'] ?? 'SEK'), $out);

        $out = Template_Normalizer::to_plain_text($out);

        if (mb_strlen($out) > 280) {
            $out = mb_substr($out, 0, 277) . '...';
        }
        return $out;
    }
}
