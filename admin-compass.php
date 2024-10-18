<?php
/**
 * Plugin Name: Admin Compass
 * Plugin URI: https://wordpress.com/plugins/admin-compass
 * Description: Global search for WP-Admin. The fastest way to navigate your backend.
 * Version: 1.0.0
 * Author: Tag Concierge
 * Author URI: https://tagconcierge.com
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: admin-compass
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once plugin_dir_path( __FILE__ ) . 'admin-compass-load-test.php';
}

class admin_compass {
    public $db;
    public $db_file;

    public function __construct() {
        $this->db_file = WP_CONTENT_DIR . '/admin-compass.db';
        $this->init_db();

        add_action('admin_bar_menu', array($this, 'add_search_icon'), 999);
        add_action('admin_footer', array($this, 'add_search_modal'));
        add_action('wp_footer', array($this, 'add_search_modal'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('save_post', array($this, 'update_index_on_save'), 10, 3);
        add_action('delete_post', array($this, 'remove_from_index'));
        add_action('wp_ajax_admin_compass_search', array($this, 'admin_compass_ajax_handler'));
        add_action('wp_ajax_nopriv_admin_compass_search', array($this, 'admin_compass_ajax_handler'));
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

    public function activate() {
        $this->create_tables();
        set_transient( 'admin_compass_reindex_admin_menu', true);
        $this->rebuild_index();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('admin_compass_rebuild_index');
    }

    private function init_db() {
        if (!class_exists('SQLite3')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Admin Compass requires SQLite3 PHP extension. Please install it and try again.</p></div>';
            });
            return;
        }
        $this->db = new SQLite3($this->db_file);
        $this->db->exec('PRAGMA journal_mode = WAL;');
    }

    private function create_tables() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS search_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id INTEGER,
                item_type TEXT,
                title TEXT,
                content TEXT,
                edit_url TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_title ON search_index(title);
            CREATE INDEX IF NOT EXISTS idx_content ON search_index(content);
        ");
    }

    public function rebuild_index() {
        $this->db->exec("DELETE FROM search_index WHERE item_type != 'admin_page'");

        // Index posts and pages
        $posts = get_posts(array('post_type' => array('post', 'page'), 'posts_per_page' => 300));

        foreach ($posts as $post) {
            $this->add_to_index($post->ID, $post->post_type, $post->post_title, $post->post_content, $this->get_edit_post_link($post, 'raw'));
        }
    }

    public function admin_menu() {
        global $menu, $submenu;

        if (get_transient( 'admin_compass_reindex_admin_menu') !== "1") {
            return;
        }

        delete_transient('admin_compass_reindex_admin_menu');

        $this->db->exec("DELETE FROM search_index WHERE item_type = 'admin_page'");
        foreach ($menu as $menu_item) {
            if (empty($menu_item[0])) continue;

            $menu_title = wp_strip_all_tags($menu_item[0]);
            $menu_url = $menu_item[2];

            // Index main menu item
            $this->add_to_index(0, 'admin_page', $menu_title, "Navigate to $menu_title admin page", admin_url($menu_url));

            // Index submenu items
            if (isset($submenu[$menu_url])) {
                foreach ($submenu[$menu_url] as $submenu_item) {
                    $submenu_title = wp_strip_all_tags($submenu_item[0]);
                    $submenu_url = $submenu_item[2];

                    // Check if it's a custom plugin page
                    if (strpos($submenu_url, 'php') === false) {
                        $submenu_url = $menu_url . '?page=' . $submenu_url;
                    }

                    $this->add_to_index(0, 'admin_page', "$menu_title - $submenu_title", "Navigate to $submenu_title under $menu_title", admin_url($submenu_url));
                }
            }
        }
    }

    private function add_to_index($item_id, $item_type, $title, $content, $edit_url) {
        $stmt = $this->db->prepare("INSERT INTO search_index (item_id, item_type, title, content, edit_url) VALUES (:item_id, :item_type, :title, :content, :edit_url)");
        $stmt->bindValue(':item_id', $item_id, SQLITE3_INTEGER);
        $stmt->bindValue(':item_type', $item_type, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':edit_url', $edit_url, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function update_index_on_save($post_id, $post, $update) {
        $this->remove_from_index($post_id);
        $this->add_to_index($post_id, $post->post_type, $post->post_title, $post->post_content, $this->get_edit_post_link($post, 'raw'));
    }

    public function remove_from_index($item_id) {
        $stmt = $this->db->prepare("DELETE FROM search_index WHERE item_id = :item_id");
        $stmt->bindValue(':item_id', $item_id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function add_search_icon($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'admin-compass',
            'title' => '<span class="ab-icon dashicons dashicons-admin-site"></span>',
            'href'  => '#',
            'meta'  => array(
                'title' => 'Admin Compass Search (Ctrl + Shift + F)',
                'class' => 'admin-compass-icon'
            ),
        ));
    }

    public function add_search_modal() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div id="admin-compass-modal" style="display:none;">
            <div class="admin-compass-container">
                <form autocomplete="off">
                    <input type="text" id="admin-compass-input" placeholder="Search with Admin Compass..." autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                </form>
                <div id="admin-compass-results"></div>
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
        wp_enqueue_script('admin-compass', plugins_url('admin-compass.js', __FILE__), array('jquery'), '3.4', true);
        wp_enqueue_style('admin-compass', plugins_url('admin-compass.css', __FILE__), array(), '3.4');

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
        $query = str_replace(' ', '%', $query);
        $limit = 10;

        $results = array();

        $search_query = $this->db->prepare("
            SELECT item_id, item_type, title, edit_url
            FROM search_index
            WHERE title LIKE :query OR content LIKE :query
            ORDER BY
                CASE
                    WHEN title LIKE :query THEN 1
                    WHEN content LIKE :query THEN 2
                END
            LIMIT 15
        ");
        $search_query->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
        $result = $search_query->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $results[] = array(
                'id' => $row['item_id'],
                'title' => $row['title'],
                'type' => $row['item_type'],
                'edit_url' => $row['edit_url'],
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
}

$admin_compass = new admin_compass();



