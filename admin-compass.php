<?php
/**
 * Plugin Name: Admin Compass
 * Plugin URI: https://wordpress.org/plugins/admin-compass/
 * Description: Global search for WP-Admin. The fastest way to navigate your backend.
 * Version: 1.3.1
 * Author: Tag Pilot
 * Author URI: https://tagpilot.io
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: admin-compass
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

define('ADMIN_COMPASS_VERSION', '1.3.1');

class admin_compass {
    public $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'admin_compass_search_index';

        add_action('admin_bar_menu', array($this, 'add_search_icon'), 999);
        add_action('admin_footer', array($this, 'add_search_modal'));
        add_action('wp_footer', array($this, 'add_search_modal'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('save_post', array($this, 'update_index_on_save'), 10, 3);
        add_action('delete_post', array($this, 'remove_from_index'));
        add_action('wp_ajax_admin_compass_search', array($this, 'admin_compass_ajax_handler'));
        add_action('wp_ajax_nopriv_admin_compass_search', array($this, 'admin_compass_ajax_handler'));
        add_action('wp_ajax_admin_compass_check_indexing', array($this, 'check_indexing_status'));
        add_filter('plugin_row_meta', array($this, 'add_plugin_meta_links'), 10, 4);
        add_action('wp_ajax_admin_compass_reindex', array($this, 'schedule_background_job'));
        add_action('admin_menu', array($this, 'admin_menu'));

        // Schedule index rebuild
        if (!wp_next_scheduled('admin_compass_rebuild_index')) {
            wp_schedule_event(time(), 'daily', 'admin_compass_rebuild_index');
        }
        add_action('admin_compass_rebuild_index', array($this, 'rebuild_index'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    private function drop_table() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }

    public function update() {
        $current_version = get_option('admin_compass_version', '0.0.0');

        if ($current_version !== '0.0.0' && version_compare($current_version, ADMIN_COMPASS_VERSION, '<')) {
            // Drop and recreate the table for updates
            $this->drop_table();
            $this->create_tables();
            $this->rebuild_index();
            set_transient( 'admin_compass_reindex_admin_menu', true);

            // Update the stored version number
            update_option('admin_compass_version', ADMIN_COMPASS_VERSION);

            // Add an admin notice about the update
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>Admin Compass has been updated to version ' . esc_html(ADMIN_COMPASS_VERSION) . '. The search index has been rebuilt.</p>';
                echo '</div>';
            });
        }
    }

    public function activate() {
        $this->create_tables();

        set_transient( 'admin_compass_reindex_admin_menu', true);
        $this->rebuild_index();

        update_option('admin_compass_version', ADMIN_COMPASS_VERSION);
    }

    public function deactivate() {
        wp_clear_scheduled_hook('admin_compass_rebuild_index');
        // Clear indexing state if deactivated during indexing
        delete_option('admin_compass_indexing_in_progress');
        delete_option('admin_compass_indexing_started');
    }


    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            item_id bigint(20) NOT NULL,
            item_type varchar(50) NOT NULL,
            thumbnail_url text,
            title text NOT NULL,
            content longtext,
            edit_url text NOT NULL,
            date_modified datetime DEFAULT NULL,
            date_created datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_title (title(191)),
            KEY idx_content (content(191)),
            KEY idx_item_type (item_type),
            KEY idx_combined (title(191), content(191)),
            KEY idx_date_modified (date_modified),
            KEY idx_date_created (date_created),
            UNIQUE KEY idx_item_unique (item_id, item_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function rebuild_index() {
        // Set indexing state
        update_option('admin_compass_indexing_in_progress', true);
        update_option('admin_compass_indexing_started', time());

        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name} WHERE item_type != %s", 'settings'));

        // Index posts and pages in batches
        $post_types = array('post', 'page', 'attachment', 'product');
        $batch_size = 100;
        $total_indexed = 0;

        foreach ($post_types as $post_type) {
            $offset = 0;

            do {
                $posts = get_posts(array(
                    'post_type' => $post_type,
                    'posts_per_page' => $batch_size,
                    'offset' => $offset,
                    'post_status' => array('publish', 'draft', 'private'),
                ));

                if (empty($posts)) {
                    break;
                }

                foreach ($posts as $post) {

            $content = $post->post_content;
            if ($post->post_type === 'attachment') {
                $content .= ' ' . $post->post_title . ' ' . $post->post_name . ' ' . get_post_meta($post->ID, '_wp_attachment_image_alt', true);
            }

            if ($post->post_type === 'product' && function_exists('wc_get_product')) {
                $product = wc_get_product($post->ID);
                if ($product) {
                    $content .= ' ' . $product->get_sku() . ' ' . $product->get_price();
                }
            }

                    $edit_url = $this->get_edit_post_link($post, 'raw');
                    if (!$edit_url) {
                        $edit_url = admin_url('post.php?post=' . $post->ID . '&action=edit');
                    }
                    $this->add_to_index($post->ID, $post->post_type, $post->post_title, $content, $edit_url, get_the_post_thumbnail_url($post), $post->post_modified, $post->post_date, true);
                    $total_indexed++;
                }

                $offset += $batch_size;

                // Prevent timeout
                if (connection_status() != CONNECTION_NORMAL) {
                    break 2;
                }

            } while (count($posts) == $batch_size);
        }

        // Index orders in batches
        if (class_exists('WC_Order_Query') && function_exists('wc_get_order')) {
            $batch_size = 50; // Smaller batch for orders as they're more complex
            $offset = 0;
            $processed_ids = array(); // Track processed IDs to avoid duplicates

            do {
                $query = new WC_Order_Query(array(
                    'limit' => $batch_size,
                    'offset' => $offset,
                    'return' => 'ids',
                    'type' => 'shop_order', // Exclude refunds from the query
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'paginate' => false
                ));
                $order_ids = $query->get_orders();

                if (empty($order_ids)) {
                    break;
                }

                foreach ($order_ids as $order_id) {
                    // Skip if already processed in this batch run
                    if (in_array($order_id, $processed_ids)) {
                        continue;
                    }
                    $processed_ids[] = $order_id;

                    $order = wc_get_order($order_id);
                    if ($order) {
                        // Skip refunds as they don't have billing/shipping methods
                        if ($order->get_type() === 'shop_order_refund') {
                            continue;
                        }

                    // Include comprehensive order data for search
                    $customer_data = array(
                        $order->get_billing_first_name(),
                        $order->get_billing_last_name(),
                        $order->get_billing_email(),
                        $order->get_billing_phone(),
                        $order->get_billing_company(),
                        $order->get_shipping_first_name(),
                        $order->get_shipping_last_name(),
                        $order->get_shipping_company()
                    );

                    $content = sprintf(
                        'Order #%s %s %s %s %s %s %s',
                        $order->get_order_number(),
                        $order->get_formatted_billing_full_name(),
                        $order->get_billing_email(),
                        $order->get_total(),
                        $order->get_status(),
                        $order->get_billing_phone(),
                        implode(' ', array_filter($customer_data))
                    );

                    $this->add_to_index($order_id, 'shop_order', 'Order #' . $order->get_order_number(), $content, $this->get_edit_order_link($order_id), null, $order->get_date_modified()->format('Y-m-d H:i:s'), $order->get_date_created()->format('Y-m-d H:i:s'), true);
                    }
                }

                $offset += $batch_size;

                // Prevent timeout
                if (connection_status() != CONNECTION_NORMAL) {
                    break;
                }

            } while (count($order_ids) == $batch_size);
        }

        // Clear indexing state
        delete_option('admin_compass_indexing_in_progress');
        delete_option('admin_compass_indexing_started');
    }

    public function clean_admin_menu_title($title) {
        $title = preg_replace('/[0-9]+/', '', $title);

        $comments = __( 'Comments' );

        if (strpos($title, $comments) === 0) {
            return $comments;
        }

        return $title;
    }

    public function admin_menu() {
        global $menu, $submenu;

        if (!get_transient( 'admin_compass_reindex_admin_menu')) {
            return;
        }

        delete_transient('admin_compass_reindex_admin_menu');

        global $wpdb;
        $wpdb->delete($this->table_name, array('item_type' => 'settings'));
        foreach ($menu as $menu_item) {
            if (empty($menu_item[0])) continue;

            $menu_title = $this->clean_admin_menu_title(wp_strip_all_tags($menu_item[0]));
            $menu_url = $menu_item[2];

            // Index main menu item
            $this->add_to_index(0, 'settings', $menu_title, "Navigate to $menu_title admin page", admin_url($menu_url), null, null, null, true);

            // Index submenu items
            if (isset($submenu[$menu_url])) {
                foreach ($submenu[$menu_url] as $submenu_item) {
                    $submenu_title = $this->clean_admin_menu_title(wp_strip_all_tags($submenu_item[0]));
                    $submenu_url = $submenu_item[2];

                    // Check if it's a custom plugin page
                    if (strpos($submenu_url, 'php') === false) {
                        $submenu_url = $menu_url . '?page=' . $submenu_url;
                    }

                    $this->add_to_index(0, 'settings', "$menu_title - $submenu_title", "Navigate to $submenu_title under $menu_title", admin_url($submenu_url), null, null, null, true);
                }
            }
        }
    }

    private function add_to_index($item_id, $item_type, $title, $content, $edit_url, $thumbnail_url, $date_modified = null, $date_created = null, $force_insert = false) {
        global $wpdb;

        if ($force_insert) {
            // Force insert during rebuild (we already deleted old entries)
            $wpdb->insert(
                $this->table_name,
                array(
                    'item_id' => $item_id,
                    'item_type' => $item_type,
                    'title' => $title,
                    'content' => $content,
                    'edit_url' => $edit_url,
                    'thumbnail_url' => $thumbnail_url,
                    'date_modified' => $date_modified,
                    'date_created' => $date_created
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        } else {
            // Use INSERT ... ON DUPLICATE KEY UPDATE for better performance
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$this->table_name}
                (item_id, item_type, title, content, edit_url, thumbnail_url, date_modified, date_created)
                VALUES (%d, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                content = VALUES(content),
                edit_url = VALUES(edit_url),
                thumbnail_url = VALUES(thumbnail_url),
                date_modified = VALUES(date_modified),
                date_created = VALUES(date_created)",
                $item_id,
                $item_type,
                $title,
                $content,
                $edit_url,
                $thumbnail_url,
                $date_modified,
                $date_created
            ));
        }
    }

    public function update_index_on_save($post_id, $post, $update) {
        $this->remove_from_index($post_id);
        $edit_url = $this->get_edit_post_link($post, 'raw');
        if (!$edit_url) {
            $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        }
        $this->add_to_index($post_id, $post->post_type, $post->post_title, $post->post_content, $edit_url, get_the_post_thumbnail_url($post), $post->post_modified, $post->post_date);
    }

    public function remove_from_index($item_id) {
        global $wpdb;
        $wpdb->delete($this->table_name, array('item_id' => $item_id), array('%d'));
    }

    public function add_search_icon($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'admin-compass',
            'title' => '<span class="ab-icon dashicons dashicons-search"></span>',
            'href'  => '#',
            'meta'  => array(
                'title' => 'Admin Compass Search (Ctrl+K or Cmd+K)',
                'class' => 'admin-compass-icon'
            ),
        ));
    }

    public function add_search_modal() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div id="admin-compass-overlay" class="admin-compass-hidden">
            <div id="admin-compass-modal" class="admin-compass-hidden">
                <div class="admin-compass-container">
                    <form autocomplete="off">
                        <input type="text" id="admin-compass-input" placeholder="Search with Admin Compass..." autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                    </form>
                    <div id="admin-compass-results"></div>
                    <div class="admin-compass-footer">
                        <div class="keyboard-shortcuts">
                            <span class="shortcut"><kbd>↑</kbd><kbd>↓</kbd> Navigate</span>
                            <span class="shortcut"><kbd>↵</kbd> Select</span>
                            <span class="shortcut"><kbd>Esc</kbd> Close</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_script('jquery');
        wp_enqueue_script('admin-compass', plugins_url('admin-compass.js', __FILE__), array('jquery'), ADMIN_COMPASS_VERSION, true);
        wp_enqueue_style('admin-compass', plugins_url('admin-compass.css', __FILE__), array(), ADMIN_COMPASS_VERSION);

        wp_localize_script('admin-compass', 'adminCompass', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('admin_compass_nonce'),
        ));
    }

    public function admin_compass_ajax_handler() {
        check_ajax_referer('admin_compass_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (empty($_POST['query'])) {
            return wp_send_json_success([]);
        }
        $query = sanitize_text_field(wp_unslash($_POST['query']));

        $results = array();

        global $wpdb;
        $search_term = '%' . $wpdb->esc_like($query) . '%';

        $results_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT item_id, item_type, title, edit_url, thumbnail_url, content, date_modified, date_created
                FROM {$this->table_name}
                WHERE title LIKE %s OR content LIKE %s
                ORDER BY
                    CASE
                        WHEN title LIKE %s THEN 1
                        ELSE 2
                    END,
                    COALESCE(date_modified, date_created) DESC,
                    title
                LIMIT 15",
                $search_term,
                $search_term,
                '%' . $wpdb->esc_like($query) . '%'
            ),
            ARRAY_A
        );

        foreach ($results_data as $row) {
            // Generate preview excerpt
            $preview = '';
            if (!empty($row['content'])) {
                $preview = wp_strip_all_tags($row['content']);
                $preview = substr($preview, 0, 120);
                if (strlen($row['content']) > 120) {
                    $preview .= '...';
                }
            }

            $results[] = array(
                'id' => $row['item_id'],
                'title' => $row['title'],
                'type' => $row['item_type'],
                'edit_url' => $row['edit_url'],
                'thumbnail_url' => $row['thumbnail_url'],
                'preview' => $preview,
            );
        }

        wp_send_json_success($results);
    }

    function add_plugin_meta_links($meta, $file, $data, $status) {
        $plugin_file = plugin_basename(__FILE__);
        if ($file == $plugin_file) {
            $new_meta = [
                '<a href="#" class="schedule-background-job">Rebuild index</a>',
            ];
            $meta = array_merge($meta, $new_meta);
        }
        return $meta;
    }

    function schedule_background_job() {
        check_ajax_referer('admin_compass_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        set_transient( 'admin_compass_reindex_admin_menu', true);

        wp_schedule_single_event(time(), 'admin_compass_rebuild_index');

        wp_send_json_success('Background job scheduled');
    }

    public function check_indexing_status() {
        check_ajax_referer('admin_compass_nonce', 'nonce');

        $is_indexing = get_option('admin_compass_indexing_in_progress', false);
        $indexing_started = get_option('admin_compass_indexing_started', 0);

        $response = array(
            'is_indexing' => $is_indexing,
            'started_at' => $indexing_started,
            'elapsed_time' => $is_indexing ? (time() - $indexing_started) : 0
        );

        wp_send_json_success($response);
    }

    function get_edit_post_link( $post = 0, $context = 'display' ) {
        $post = get_post( $post );

        if ( ! $post ) {
            return;
        }

        if ( 'revision' === $post->post_type ) {
            $action = '';
        } elseif ( 'display' === $context ) {
            $action = '&amp;action=edit';
        } else {
            $action = '&action=edit';
        }

        $post_type_object = get_post_type_object( $post->post_type );

        if ( ! $post_type_object ) {
            return;
        }

        $link = '';

        if ( 'wp_template' === $post->post_type || 'wp_template_part' === $post->post_type ) {
            $slug = urlencode( get_stylesheet() . '//' . $post->post_name );
            $link = admin_url( sprintf( $post_type_object->_edit_link, $post->post_type, $slug ) );
        } elseif ( 'wp_navigation' === $post->post_type ) {
            $link = admin_url( sprintf( $post_type_object->_edit_link, (string) $post->ID ) );
        } elseif ( $post_type_object->_edit_link ) {
            $link = admin_url( sprintf( $post_type_object->_edit_link . $action, $post->ID ) );
        }

        /**
         * Filters the post edit link.
         *
         * @since 2.3.0
         *
         * @param string $link    The edit link.
         * @param int    $post_id Post ID.
         * @param string $context The link context. If set to 'display' then ampersands
         *                        are encoded.
         */
        return apply_filters( 'get_edit_post_link', $link, $post->ID, $context );
    }

    function get_edit_order_link($order_id) {
        if (!function_exists('wc_get_order')) {
            return admin_url('edit.php?post_type=shop_order');
        }
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

}

$admin_compass = new admin_compass();
$admin_compass->update();
