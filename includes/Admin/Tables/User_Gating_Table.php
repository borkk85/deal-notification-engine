<?php

namespace DNE\Admin\Tables;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class User_Gating_Table extends \WP_List_Table
{
    private $allowed_map = [
        1 => ['webpush'],
        2 => ['webpush', 'telegram'],
        3 => ['email', 'webpush', 'telegram'],
    ];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'dne_user',
            'plural'   => 'dne_users',
            'ajax'     => false,
        ]);
    }

    public function get_columns()
    {
        return [
            'user'            => __('User', 'deal-notification-engine'),
            'tier'            => __('Tier', 'deal-notification-engine'),
            'saved_methods'   => __('Saved Methods', 'deal-notification-engine'),
            'allowed_methods' => __('Allowed by Tier', 'deal-notification-engine'),
            'effective'       => __('Effective', 'deal-notification-engine'),
            'min_percent'     => __('Min %', 'deal-notification-engine'),
            'categories'      => __('Categories', 'deal-notification-engine'),
            'stores'          => __('Stores', 'deal-notification-engine'),
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'tier'        => ['tier', false],
            'min_percent' => ['min_percent', false],
        ];
    }

    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable, 'user'];

        $per_page = $this->get_items_per_page('dne_gating_per_page', 20);
        $current_page = $this->get_pagenum();

        $args = [
            'number'      => $per_page,
            'offset'      => ($current_page - 1) * $per_page,
            'meta_key'    => 'notifications_enabled',
            'meta_value'  => '1',
            'count_total' => true,
            'fields'      => 'all',
            'orderby'     => 'display_name',
            'order'       => 'ASC',
        ];

        if (!empty($_REQUEST['s'])) {
            $search = wp_unslash($_REQUEST['s']);
            $args['search'] = '*' . sanitize_text_field($search) . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $user_query = new \WP_User_Query($args);
        $users = $user_query->get_results();

        $items = [];
        foreach ($users as $user) {
            $items[] = $this->format_user($user);
        }

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : '';
        $order   = isset($_REQUEST['order']) && strtolower($_REQUEST['order']) === 'asc' ? 'asc' : 'desc';

        if ($orderby === 'tier' || $orderby === 'min_percent') {
            usort($items, function ($a, $b) use ($orderby, $order) {
                $value_a = $a[$orderby];
                $value_b = $b[$orderby];
                if ($value_a == $value_b) {
                    return 0;
                }
                $result = ($value_a < $value_b) ? -1 : 1;
                return $order === 'asc' ? $result : -$result;
            });
        }

        $total_items = isset($user_query->total_users) ? (int) $user_query->total_users : count($items);

        $this->items = $items;
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($total_items / $per_page) : 1,
        ]);
    }

    private function format_user(\WP_User $user)
    {
        $saved_methods = get_user_meta($user->ID, 'notification_delivery_methods', true);
        if (!is_array($saved_methods)) {
            $saved_methods = [];
        }

        $tier = $this->detect_tier($user);
        $allowed = $this->allowed_map[$tier] ?? ['webpush'];
        $effective = array_values(array_intersect($saved_methods, $allowed));

        $min_percent = (int) get_user_meta($user->ID, 'user_discount_filter', true);

        $cat_names = $this->resolve_terms(get_user_meta($user->ID, 'user_category_filter', true), 'product_categories');
        $store_names = $this->resolve_terms(get_user_meta($user->ID, 'user_store_filter', true), 'store_type');

        return [
            'user_id'         => $user->ID,
            'display_name'    => $user->display_name,
            'user_email'      => $user->user_email,
            'tier'            => $tier,
            'saved_methods'   => $saved_methods,
            'allowed_methods' => $allowed,
            'effective'       => $effective,
            'min_percent'     => $min_percent,
            'categories'      => $cat_names,
            'stores'          => $store_names,
        ];
    }

    private function detect_tier(\WP_User $user)
    {
        $tier = 1;
        if (is_array($user->roles)) {
            foreach ($user->roles as $role) {
                if (strpos($role, 'tier-3') !== false || strpos($role, 'tier_3') !== false) {
                    return 3;
                }
                if (strpos($role, 'tier-2') !== false || strpos($role, 'tier_2') !== false) {
                    $tier = 2;
                }
                if (strpos($role, 'tier-1') !== false || strpos($role, 'tier_1') !== false) {
                    $tier = max($tier, 1);
                }
            }
        }
        return $tier;
    }

    private function resolve_terms($ids, $taxonomy)
    {
        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'include'    => array_map('intval', $ids),
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return wp_list_pluck($terms, 'name');
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'saved_methods':
            case 'allowed_methods':
            case 'effective':
                return $this->format_list($item[$column_name]);
            case 'categories':
            case 'stores':
                return $this->format_list($item[$column_name]);
            case 'tier':
            case 'min_percent':
                return $item[$column_name] ? esc_html($item[$column_name]) : '&ndash;';
            default:
                return '&ndash;';
        }
    }

    public function column_user($item)
    {
        $profile_url = get_edit_user_link($item['user_id']);
        $name = esc_html($item['display_name']);
        $id = (int) $item['user_id'];
        $email = $item['user_email'] ? esc_html($item['user_email']) : '';

        $out = '<strong>';
        if ($profile_url) {
            $out .= "<a href=\"" . esc_url($profile_url) . "\">" . $name . "</a>";
        } else {
            $out .= $name;
        }
        $out .= ' (#' . $id . ')</strong>';

        if ($email) {
            $out .= "<br><span class=\"description\">" . $email . "</span>";
        }

        return $out;
    }

    private function format_list($items)
    {
        if (empty($items)) {
            return '&ndash;';
        }
        $clean = array_map('sanitize_text_field', $items);
        return esc_html(implode(', ', $clean));
    }
}

