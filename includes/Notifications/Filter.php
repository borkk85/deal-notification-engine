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
        if (empty($filter_categories) || !is_array($filter_categories)) {
            return true; // No filter set
        }

        // User must match at least one category
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

        // Count active filters
        $filter_count = 0;

        if (!empty($preferences['discount_filter'])) {
            $filter_count++;
        }

        if (!empty($preferences['category_filter']) && is_array($preferences['category_filter'])) {
            $filter_count += count($preferences['category_filter']);
        }

        if (!empty($preferences['store_filter']) && is_array($preferences['store_filter'])) {
            $filter_count += count($preferences['store_filter']);
        }

        // Check against tier limits
        $tier_limits = [
            1 => ['total' => 1, 'categories' => 1, 'stores' => 1],
            2 => ['total' => 7, 'categories' => 3, 'stores' => 3],  // Updated
            3 => ['total' => 999, 'categories' => 999, 'stores' => 999]  // Effectively unlimited
        ];

        $limits = $tier_limits[$tier_level];

        // Validate total filters
        if ($filter_count > $limits['total']) {
            return false;
        }

        // Validate category count
        if (!empty($preferences['category_filter']) && is_array($preferences['category_filter'])) {
            if (count($preferences['category_filter']) > $limits['categories']) {
                return false;
            }
        }

        // Validate store count
        if (!empty($preferences['store_filter']) && is_array($preferences['store_filter'])) {
            if (count($preferences['store_filter']) > $limits['stores']) {
                return false;
            }
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
        $methods = get_user_meta($user_id, 'notification_delivery_methods', true);

        // Default to email if no methods set
        if (empty($methods) || !is_array($methods)) {
            return ['email'];
        }

        return $methods;
    }
}
