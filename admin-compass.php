<?php
/**
 * Plugin Name: Admin Compass
 * Plugin URI: https://wordpress.org/plugins/admin-compass/
 * Description: Global search for WP-Admin. The fastest way to navigate your backend.
 * Version: 1.2.0
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
    require_once plugin_dir_path(__FILE__) . 'admin-compass-demo-setup.php';
}

define('ADMIN_COMPASS_VERSION', '1.1.1');

class admin_compass {
    public $db;
    public $db_file;
    public $db_name;

    public function __construct() {
        $this->init_db_name();
        $this->db_file = $this->get_db_path();
        $this->init_db();

        add_action('admin_bar_menu', array($this, 'add_search_icon'), 999);
        add_action('admin_footer', array($this, 'add_search_modal'));
        add_action('wp_footer', array($this, 'add_search_modal'));
        add_action('admin_init', array($this, 'check_db_security'));

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

    private function init_db_name() {
        $this->db_name = get_option('admin_compass_db_name');
        if (!$this->db_name) {
            $this->db_name = $this->generate_db_name();
            update_option('admin_compass_db_name', $this->db_name);
        }
    }

    private function generate_db_name() {
        $random_string = bin2hex(random_bytes(16)); // 32 character random string
        return 'admin_compass_' . $random_string . '.db';
    }

    private function get_db_path() {
        // Store the database one level above the WordPress root
        return WP_CONTENT_DIR . '/' . $this->db_name;
    }

    private function remove_db_file() {
        if ($this->db) {
            $this->db->close();
            $this->db = null;
        }

        if (file_exists($this->db_file)) {
            unlink($this->db_file);
        }

        if (file_exists($this->db_file . '-shm')) {
            unlink($this->db_file . '-shm');
        }
        if (file_exists($this->db_file . '-wal')) {
            unlink($this->db_file . '-wal');
        }

        // Remove the database name from options
        delete_option('admin_compass_db_name');
    }

    public function update() {
        $current_version = get_option('admin_compass_version', '0.0.0');

        if ($current_version !== '0.0.0' && version_compare($current_version, ADMIN_COMPASS_VERSION, '<')) {
            // Remove the existing database file
            $this->remove_db_file();

            // Recreate the database and rebuild the index
            $this->init_db();
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
        $old_db_file = WP_CONTENT_DIR . '/admin-compass.db';
        if (file_exists($old_db_file)) {
            unlink($old_db_file);
        }

        // Ensure the database file has the correct permissions
        if (file_exists($this->db_file)) {
            chmod($this->db_file, 0600);
        }

        $this->create_tables();

        set_transient( 'admin_compass_reindex_admin_menu', true);
        $this->rebuild_index();

        update_option('admin_compass_version', ADMIN_COMPASS_VERSION);
    }

    public function deactivate() {
        wp_clear_scheduled_hook('admin_compass_rebuild_index');
        $this->remove_db_file();
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
                thumbnail_url TEXT,
                title TEXT,
                content TEXT,
                edit_url TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_title ON search_index(title);
            CREATE INDEX IF NOT EXISTS idx_content ON search_index(content);
        ");
    }

    public function rebuild_index() {
        $this->db->exec("DELETE FROM search_index WHERE item_type != 'settings'");

        // Index posts and pages
        $posts = get_posts(array(
            'post_type' => array('post', 'page', 'attachment'),
            'posts_per_page' => 300,
            'post_status' => null,
            'post_parent' => null,
        ));

        foreach ($posts as $post) {

            $content = $post->post_content;
            if ($post->post_type === 'attachment') {
                $content .= ' ' . $post->post_title . ' ' . $post->post_name . ' ' . get_post_meta($post->ID, '_wp_attachment_image_alt', true);
            }

            $this->add_to_index($post->ID, $post->post_type, $post->post_title, $content, $this->get_edit_post_link($post, 'raw'), get_the_post_thumbnail_url($post));
        }
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

        if (get_transient( 'admin_compass_reindex_admin_menu') !== "1" || $this->db === false) {
            return;
        }

        delete_transient('admin_compass_reindex_admin_menu');

        $this->db->exec("DELETE FROM search_index WHERE item_type = 'settings'");
        foreach ($menu as $menu_item) {
            if (empty($menu_item[0])) continue;

            $menu_title = $this->clean_admin_menu_title(wp_strip_all_tags($menu_item[0]));
            $menu_url = $menu_item[2];

            // Index main menu item
            $this->add_to_index(0, 'settings', $menu_title, "Navigate to $menu_title admin page", admin_url($menu_url), null);

            // Index submenu items
            if (isset($submenu[$menu_url])) {
                foreach ($submenu[$menu_url] as $submenu_item) {
                    $submenu_title = $this->clean_admin_menu_title(wp_strip_all_tags($submenu_item[0]));
                    $submenu_url = $submenu_item[2];

                    // Check if it's a custom plugin page
                    if (strpos($submenu_url, 'php') === false) {
                        $submenu_url = $menu_url . '?page=' . $submenu_url;
                    }

                    $this->add_to_index(0, 'settings', "$menu_title - $submenu_title", "Navigate to $submenu_title under $menu_title", admin_url($submenu_url), null);
                }
            }
        }
    }

    private function add_to_index($item_id, $item_type, $title, $content, $edit_url, $thumbnail_url) {
        if ($this->db === false) {
            return;
        }

        $stmt = $this->db->prepare("INSERT INTO search_index (item_id, item_type, title, content, edit_url, thumbnail_url) VALUES (:item_id, :item_type, :title, :content, :edit_url, :thumbnail_url)");
        $stmt->bindValue(':item_id', $item_id, SQLITE3_INTEGER);
        $stmt->bindValue(':item_type', $item_type, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':edit_url', $edit_url, SQLITE3_TEXT);
        $stmt->bindValue(':thumbnail_url', $thumbnail_url, SQLITE3_TEXT);

        $stmt->execute();
    }

    public function update_index_on_save($post_id, $post, $update) {
        $this->remove_from_index($post_id);
        $this->add_to_index($post_id, $post->post_type, $post->post_title, $post->post_content, $this->get_edit_post_link($post, 'raw'), get_the_post_thumbnail_url($post));
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
            'title' => '<span class="ab-icon dashicons dashicons-search"></span>',
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
        <div id="admin-compass-overlay" class="admin-compass-hidden">
            <div id="admin-compass-modal" class="admin-compass-hidden">
                <div class="admin-compass-container">
                    <form autocomplete="off">
                        <input type="text" id="admin-compass-input" placeholder="Search with Admin Compass..." autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                    </form>
                    <div id="admin-compass-results"></div>
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
        $query = str_replace(' ', '%', $query);
        $limit = 10;

        $results = array();

        $search_query = $this->db->prepare("
            SELECT item_id, item_type, title, edit_url, thumbnail_url
            FROM search_index
            WHERE title LIKE :query OR content LIKE :query
            ORDER BY
                CASE
                    WHEN title LIKE :query THEN 1
                    WHEN content LIKE :query THEN 2
                END
            LIMIT 15
        ");

        if ($search_query === false) {
            wp_send_json_success([]);
        }

        $search_query->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
        $result = $search_query->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $results[] = array(
                'id' => $row['item_id'],
                'title' => $row['title'],
                'type' => $row['item_type'],
                'edit_url' => $row['edit_url'],
                'thumbnail_url' => $row['thumbnail_url'],
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

    public function check_db_security() {
        // Check file permissions
        if (file_exists($this->db_file)) {
            $perms = fileperms($this->db_file);
            if (($perms & 0777) !== 0600) {
                chmod($this->db_file, 0600);
                add_action('admin_notices', function() {
                    echo '<div class="warning"><p>Admin Compass: The database file permissions have been corrected to 0600.</p></div>';
                });
            }
        }

        $this->create_htaccess();

        $this->check_db_accessibility();
    }

     private function create_htaccess() {
        $htaccess_file = dirname($this->db_file) . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "<FilesMatch \"\\.(db)$\">
    Order allow,deny
    Deny from all
</FilesMatch>
";
            file_put_contents($htaccess_file, $htaccess_content);
        }
    }

    private function check_db_accessibility() {
        $db_url = str_replace(ABSPATH, get_site_url() . '/', $this->db_file);
        $response = wp_remote_head($db_url);

        if (!is_wp_error($response) && $response['response']['code'] !== 403) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>Warning: Your Admin Compass database file may be publicly accessible. Please check your server configuration.</p></div>';
            });
        }
    }
}

$admin_compass = new admin_compass();
$admin_compass->update();
