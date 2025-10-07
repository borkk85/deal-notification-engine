<?php

namespace DNE\Notifications;

/**
 * Filter users based on notification preferences and deal criteria
 */
class Filter
{

    /**
     * Find users whose preferences match the deal
     * 
     * @param array $deal_data Deal information
     * @return array User IDs that match
     */
    public function find_matching_users($deal_data)
    {
        $matched_users = [];

        // Get all users with deal tier roles who have notifications enabled
        $users = $this->get_eligible_users();

        foreach ($users as $user) {
            if ($this->user_matches_deal($user->ID, $deal_data)) {
                $matched_users[] = $user->ID;
            }
        }

        return $matched_users;
    }

    /**
     * Get all users eligible for notifications
     * 
     * @return array WP_User objects
     */
    private function get_eligible_users()
    {
        return get_users([
            'role__in' => [
                'um_deal-tier-1',
                'um_deal-tier_1',
                'um_deal-tier-2',
                'um_deal-tier_2',
                'um_deal-tier-3',
                'um_deal-tier_3'
            ],
            'meta_query' => [
                [
                    'key' => 'notifications_enabled',
                    'value' => '1',
                    'compare' => '='
                ]
            ]
        ]);
    }

    /**
     * Check if user's preferences match the deal
     * 
     * @param int $user_id User ID
     * @param array $deal_data Deal information
     * @return bool True if matches
     */
    public function user_matches_deal($user_id, $deal_data)
    {
        // Get user preferences
        $preferences = $this->get_user_preferences($user_id);

        // Check discount filter
        if (!$this->matches_discount_filter($preferences['discount_filter'], $deal_data['discount'])) {
            return false;
        }

        // Check category filter
        if (!$this->matches_category_filter($preferences['category_filter'], $deal_data['categories'])) {
            return false;
        }

        // Check store filter
        if (!$this->matches_store_filter($preferences['store_filter'], $deal_data['stores'])) {
            return false;
        }

        // Validate tier limits
        if (!$this->validate_tier_limits($user_id, $preferences)) {
            return false;
        }

        return true;
    }

    /**
     * Get user notification preferences
     * 
     * @param int $user_id User ID
     * @return array User preferences
     */
    private function get_user_preferences($user_id)
    {
        return [
            'discount_filter' => get_user_meta($user_id, 'user_discount_filter', true),
            'category_filter' => get_user_meta($user_id, 'user_category_filter', true),
            'store_filter' => get_user_meta($user_id, 'user_store_filter', true),
            'delivery_methods' => get_user_meta($user_id, 'notification_delivery_methods', true)
        ];
    }

    /**
     * Check if deal meets discount requirements
     * 
     * @param string $filter_value User's minimum discount
     * @param int $deal_discount Deal's discount percentage
     * @return bool
     */
    private function matches_discount_filter($filter_value, $deal_discount)
    {
        if (empty($filter_value)) {
            return true; // No filter set
        }

        return $deal_discount >= intval($filter_value);
    }

    /**
     * Check if deal has matching categories
     * 
     * @param array $filter_categories User's selected categories
     * @param array $deal_categories Deal's categories
     * @return bool
     */
    private function matches_category_filter($filter_categories, $deal_categories)
    {
        // Normalize inputs
        $filter_categories = is_array($filter_categories) ? $filter_categories : [];
        $deal_categories   = is_array($deal_categories) ? $deal_categories : [];

        // If user did not set any category filter, do not block
        if (empty($filter_categories)) {
            return true;
        }

        // Strict mode: if user filtered by categories but the deal has none, do not match
        if (empty($deal_categories)) {
            return false;
        }

        // Otherwise, require at least one overlap
        return !empty(array_intersect($filter_categories, $deal_categories));
    }

    /**
     * Check if deal has matching stores
     * 
     * @param array $filter_stores User's selected stores
     * @param array $deal_stores Deal's stores
     * @return bool
     */
    private function matches_store_filter($filter_stores, $deal_stores)
    {
        if (empty($filter_stores) || !is_array($filter_stores)) {
            return true; // No filter set
        }

        // User must match at least one store
        return !empty(array_intersect($filter_stores, $deal_stores));
    }

    /**
     * Validate user hasn't exceeded tier limits
     * 
     * @param int $user_id User ID
     * @param array $preferences User preferences
     * @return bool
     */
    private function validate_tier_limits($user_id, $preferences)
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Determine tier level
        $tier_level = $this->get_user_tier_level($user);
        if (!$tier_level) {
            return false; // No valid tier
        }

        $discount_selected = isset($preferences['discount_filter']) && $preferences['discount_filter'] !== '';

        $category_filter = [];
        if (!empty($preferences['category_filter']) && is_array($preferences['category_filter'])) {
            $category_filter = array_values(array_filter(array_map('intval', $preferences['category_filter'])));
        }

        $store_filter = [];
        if (!empty($preferences['store_filter']) && is_array($preferences['store_filter'])) {
            $store_filter = array_values(array_filter(array_map('intval', $preferences['store_filter'])));
        }

        $has_categories = !empty($category_filter);
        $has_stores = !empty($store_filter);

        switch ($tier_level) {
            case 1:
                if ($discount_selected && ($has_categories || $has_stores)) {
                    return false;
                }

                if ($has_categories && $has_stores) {
                    return false;
                }
                break;

            case 2:
                if ($has_categories && $has_stores) {
                    return false;
                }

                if (!$discount_selected && ($has_categories || $has_stores)) {
                    return false;
                }
                break;

            case 3:
            default:
                break;
        }

        return true;
    }

    /**
     * Get user's tier level
     * 
     * @param WP_User $user User object
     * @return int|false Tier level or false
     */
    private function get_user_tier_level($user)
    {
        foreach ($user->roles as $role) {
            if (strpos($role, 'um_deal') !== false && strpos($role, 'tier') !== false) {
                if (strpos($role, 'tier-1') !== false || strpos($role, 'tier_1') !== false) {
                    return 1;
                }
                if (strpos($role, 'tier-2') !== false || strpos($role, 'tier_2') !== false) {
                    return 2;
                }
                if (strpos($role, 'tier-3') !== false || strpos($role, 'tier_3') !== false) {
                    return 3;
                }
            }
        }

        return false;
    }

    /**
     * Get user's delivery methods
     * 
     * @param int $user_id User ID
     * @return array Delivery methods
     */
    public function get_user_delivery_methods($user_id)
    {
        // Read meta and normalize to array
        $methods = get_user_meta($user_id, 'notification_delivery_methods', true);
        if (!is_array($methods)) {
            $methods = [];
        }

        // Allow only known channels
        $methods = array_values(array_intersect(['email', 'webpush', 'telegram'], $methods));

        // Drop Telegram if not verified / no chat id
        if (in_array('telegram', $methods, true)) {
            $verified = get_user_meta($user_id, 'telegram_verified', true) === '1';
            $chat_id  = get_user_meta($user_id, 'telegram_chat_id', true);
            if (!$verified || empty($chat_id)) {
                $methods = array_values(array_diff($methods, ['telegram']));
            }
        }

        if (!in_array('telegram', $methods, true)) {
            dne_debug("methods for user {$user_id}: " . json_encode($methods)); // shows dropped telegram too
        }

        // IMPORTANT: do NOT default to ['email'] when empty.
        return $methods;
    }

    public function user_allows_channel($user_id, string $channel): bool
    {
        if (get_user_meta($user_id, 'notifications_enabled', true) !== '1') {
            return false;
        }
        $methods = $this->get_user_delivery_methods($user_id);
        dne_debug("gating {$channel} for user {$user_id}: enabled=".(get_user_meta($user_id,'notifications_enabled',true)==='1'?'1':'0').", methods=".json_encode($this->get_user_delivery_methods($user_id)));
        if (!in_array($channel, $methods, true)) {
            return false;
        }

        // Enforce tier allow-list at send time (defense-in-depth)
        $user = get_userdata($user_id);
        $tier = 1;
        if ($user && is_array($user->roles)) {
            foreach ($user->roles as $r) {
                if (strpos($r, 'um_deal') !== false && strpos($r, 'tier') !== false) {
                    if (strpos($r, 'tier-3') !== false || strpos($r, 'tier_3') !== false) { $tier = 3; break; }
                    if (strpos($r, 'tier-2') !== false || strpos($r, 'tier_2') !== false) { $tier = 2; break; }
                    $tier = 1;
                }
            }
        }
        $allowed_by_tier = [
            1 => ['webpush'],
            2 => ['webpush', 'telegram'],
            3 => ['email', 'webpush', 'telegram'],
        ];
        if (!in_array($channel, $allowed_by_tier[$tier], true)) {
            return false;
        }
        return true;
    }
}
