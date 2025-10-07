<?php

namespace DNE\Admin\Tables;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Notification_Log_Table extends \WP_List_Table
{
    private $available_methods = [];
    private $current_method = '';

    public function __construct()
    {
        parent::__construct([
            'singular' => 'dne_log_entry',
            'plural'   => 'dne_log_entries',
            'ajax'     => false,
        ]);

        $this->available_methods = $this->fetch_available_methods();
        $this->current_method = isset($_REQUEST['method']) ? sanitize_text_field(wp_unslash($_REQUEST['method'])) : '';
    }

    public function get_columns()
    {
        return [
            'user'    => __('User', 'deal-notification-engine'),
            'post'    => __('Post', 'deal-notification-engine'),
            'method'  => __('Method', 'deal-notification-engine'),
            'status'  => __('Status', 'deal-notification-engine'),
            'details' => __('Details', 'deal-notification-engine'),
            'time'    => __('Time', 'deal-notification-engine'),
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'time'   => ['time', true],
            'status' => ['status', false],
            'method' => ['method', false],
        ];
    }

    public function prepare_items()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_log';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable, 'user'];

        $per_page = $this->get_items_per_page('dne_log_per_page', 20);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'time';
        $order = isset($_REQUEST['order']) && strtolower($_REQUEST['order']) === 'asc' ? 'ASC' : 'DESC';

        $order_map = [
            'time'   => 'created_at',
            'status' => 'status',
            'method' => "COALESCE(NULLIF(delivery_method, ''), NULLIF(action, ''))",
        ];
        $orderby_sql = $order_map[$orderby] ?? 'created_at';

        $where = '1=1';

        if (!empty($_REQUEST['s'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_REQUEST['s']))) . '%';
            $where .= $wpdb->prepare(' AND (details LIKE %s OR status LIKE %s OR delivery_method LIKE %s OR action LIKE %s)', $search, $search, $search, $search);
        }

        if ($this->current_method) {
            $where .= $wpdb->prepare(' AND (delivery_method = %s OR action = %s)', $this->current_method, $this->current_method);
        }

        $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby_sql} {$order} LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $rows = $wpdb->get_results($query, ARRAY_A);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->format_row($row);
        }

        $this->items = $items;
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($total_items / $per_page) : 1,
        ]);
    }

    private function format_row(array $row)
    {
        $user = get_userdata((int) $row['user_id']);
        $post = $row['post_id'] ? get_post((int) $row['post_id']) : null;

        $method = $row['delivery_method'];
        if (!$method && !empty($row['action'])) {
            $method = $row['action'];
        }

        $details = '';
        if (!empty($row['details'])) {
            $decoded = json_decode($row['details'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (isset($decoded['error'])) {
                    $details = (string) $decoded['error'];
                } else {
                    $details = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            } else {
                $details = (string) $row['details'];
            }
        }

        if (mb_strlen($details) > 160) {
            $details = mb_substr($details, 0, 157) . '…';
        }

        return [
            'id'         => (int) $row['id'],
            'user_id'    => $user ? $user->ID : (int) $row['user_id'],
            'user_name'  => $user ? $user->display_name : sprintf(__('User #%d', 'deal-notification-engine'), (int) $row['user_id']),
            'post_id'    => $post ? $post->ID : (int) $row['post_id'],
            'post_title' => $post ? $post->post_title : ($row['post_id'] ? sprintf(__('Post #%d', 'deal-notification-engine'), (int) $row['post_id']) : __('(None)', 'deal-notification-engine')),
            'method'     => $method ?: __('(System)', 'deal-notification-engine'),
            'status'     => $row['status'],
            'details'    => $details,
            'created_at' => $row['created_at'],
        ];
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'details':
                return esc_html($item['details']);
            default:
                return isset($item[$column_name]) ? esc_html($item[$column_name]) : '&ndash;';
        }
    }

    public function column_user($item)
    {
        $link = get_edit_user_link($item['user_id']);
        $label = esc_html($item['user_name']);
        if ($link) {
            return "<a href=\"" . esc_url($link) . "\">" . $label . "</a>";
        }
        return $label;
    }

    public function column_post($item)
    {
        if (!$item['post_id']) {
            return esc_html($item['post_title']);
        }
        $link = get_edit_post_link($item['post_id']);
        $title = esc_html($item['post_title']);
        return $link ? "<a href=\"" . esc_url($link) . "\">" . $title . "</a>" : $title;
    }

    public function column_method($item)
    {
        return esc_html($item['method']);
    }

    public function column_status($item)
    {
        return esc_html($item['status']);
    }

    public function column_time($item)
    {
        $format = get_option('date_format') . ' ' . get_option('time_format');
        return esc_html(mysql2date($format, $item['created_at'], false));
    }

    public function get_available_methods()
    {
        return $this->available_methods;
    }

    public function current_method()
    {
        return $this->current_method;
    }

    private function fetch_available_methods()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'dne_notification_log';

        $methods = $wpdb->get_col("SELECT DISTINCT delivery_method FROM {$table} WHERE delivery_method IS NOT NULL AND delivery_method <> ''");
        $actions = $wpdb->get_col("SELECT DISTINCT action FROM {$table} WHERE action IS NOT NULL AND action <> ''");

        $combined = array_unique(array_filter(array_merge((array) $methods, (array) $actions)));
        sort($combined);

        return array_map('sanitize_text_field', $combined);
    }
}

