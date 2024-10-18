<?php
// File: admin-compass-demo-setup.php

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manages demo content for Admin Compass plugin testing.
 */
class Admin_Compass_Demo_Setup {

    /**
     * Cleans existing content and sets up a demo environment for Admin Compass.
     *
     * ## OPTIONS
     *
     * [--posts=<number>]
     * : Number of demo posts to create.
     * ---
     * default: 10
     * ---
     *
     * [--pages=<number>]
     * : Number of demo pages to create.
     * ---
     * default: 5
     * ---
     *
     * ## EXAMPLES
     *
     *     wp admin-compass setup_demo
     *     wp admin-compass setup_demo --posts=15 --pages=7
     *
     * @when after_wp_load
     */
    public function setup_demo($args, $assoc_args) {
        $post_count = $assoc_args['posts'] ?? 10;
        $page_count = $assoc_args['pages'] ?? 5;

        WP_CLI::log('Starting demo environment setup for Admin Compass...');

        // Clean existing content
        $this->clean_existing_content();

        // Create demo posts
        $this->create_demo_posts($post_count);

        // Create demo pages
        $this->create_demo_pages($page_count);

        // Update options for demo
        $this->update_options();

        WP_CLI::success('Demo environment setup complete!');
    }

    private function clean_existing_content() {
        WP_CLI::log('Cleaning existing content...');

        // Remove all posts
        $posts = get_posts(['numberposts' => -1]);
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }

        // Remove all pages
        $pages = get_pages(['number' => -1]);
        foreach ($pages as $page) {
            wp_delete_post($page->ID, true);
        }

        WP_CLI::log('Existing content removed.');
    }

    private function create_demo_posts($count) {
        WP_CLI::log("Creating $count demo posts...");

        $post_titles = [
            'Getting Started with WordPress Plugin Development',
            'Understanding WordPress Hooks and Filters',
            'Best Practices for Secure Plugin Development',
            'Creating Custom Post Types in WordPress',
            'Integrating with the WordPress REST API',
            'Optimizing Your Plugin for Performance',
            'Internationalization and Localization in WordPress Plugins',
            'Debugging Techniques for WordPress Plugins',
            'Creating Settings Pages for Your Plugin',
            'Leveraging WordPress Transients for Better Performance',
            'Unit Testing Your WordPress Plugin',
            'Creating Custom Widgets in WordPress',
            'Using Composer in WordPress Plugin Development',
            'Integrating JavaScript and CSS in WordPress Plugins',
            'Creating Custom Gutenberg Blocks',
        ];

        for ($i = 0; $i < $count; $i++) {
            $title = $post_titles[$i % count($post_titles)];
            $content = "This is a demo post about $title. It demonstrates the search functionality of the Admin Compass plugin.";

            $post_id = wp_insert_post([
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => 'publish',
                'post_author'   => 1,
                'post_type'     => 'post',
            ]);

            if (is_wp_error($post_id)) {
                WP_CLI::warning("Failed to create post: $title");
            } else {
                WP_CLI::log("Created post: $title");
            }
        }
    }

    private function create_demo_pages($count) {
        WP_CLI::log("Creating $count demo pages...");

        $page_titles = [
            'About Our Plugin Development Blog',
            'WordPress Plugin Development Resources',
            'Contact Us for Custom Plugin Development',
            'Our Plugin Development Process',
            'Frequently Asked Questions about Plugin Development',
            'WordPress Plugin Development Services',
            'Plugin Development Case Studies',
        ];

        for ($i = 0; $i < $count; $i++) {
            $title = $page_titles[$i % count($page_titles)];
            $content = "This is a demo page about $title. It showcases the Admin Compass plugin's ability to search pages.";

            $page_id = wp_insert_post([
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => 'publish',
                'post_author'   => 1,
                'post_type'     => 'page',
            ]);

            if (is_wp_error($page_id)) {
                WP_CLI::warning("Failed to create page: $title");
            } else {
                WP_CLI::log("Created page: $title");
            }
        }
    }

    private function update_options() {
        WP_CLI::log('Updating WordPress options...');

        update_option('blogname', 'WordPress Plugin Development Blog');
        update_option('blogdescription', 'Insights and tutorials on creating powerful WordPress plugins');
        update_option('admin_email', 'admin@example.com');

        WP_CLI::log('WordPress options updated.');
    }
}

WP_CLI::add_command('admin-compass', 'Admin_Compass_Demo_Setup');