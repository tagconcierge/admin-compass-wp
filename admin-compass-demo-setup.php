<?php
// File: admin-compass-demo-setup.php

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manages demo content for Admin Compass plugin testing.
 */
class Admin_Compass_Demo_Setup {

    private function get_unsplash_image($query = 'nature', $width = 800, $height = 600) {
        $url = "https://source.unsplash.com/random/{$width}x{$height}/?{$query}";
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }
        return wp_remote_retrieve_header($response, 'location');
    }

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
        $media_count = $assoc_args['media'] ?? 20;

        WP_CLI::log('Starting demo environment setup for Admin Compass...');

        // Clean existing content
        $this->clean_existing_content();

        // Create demo posts
        $this->create_demo_posts($post_count);

        // Create demo pages
        $this->create_demo_pages($page_count);

        // Create demo media
        $this->create_demo_media($media_count);

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

            // Fetch an image from Unsplash
            $image_url = $this->get_unsplash_image('technology', 800, 600);

            if ($image_url) {
                // Download the image and add it to the media library
                $upload = media_sideload_image($image_url, 0, $title, 'id');
                if (!is_wp_error($upload)) {
                    $content .= "\n\n" . wp_get_attachment_image($upload, 'large');
                }
            }

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
                if ($upload && !is_wp_error($upload)) {
                    set_post_thumbnail($post_id, $upload);
                }
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

            // Fetch an image from Unsplash
            $image_url = $this->get_unsplash_image('office', 1200, 800);

            if ($image_url) {
                // Download the image and add it to the media library
                $upload = media_sideload_image($image_url, 0, $title, 'id');
                if (!is_wp_error($upload)) {
                    $content .= "\n\n" . wp_get_attachment_image($upload, 'large');
                }
            }

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
                if ($upload && !is_wp_error($upload)) {
                    set_post_thumbnail($page_id, $upload);
                }
                WP_CLI::log("Created page: $title");
            }
        }
    }

    private function create_demo_media($count) {
        WP_CLI::log("Creating $count demo media items...");

        $queries = ['technology', 'nature', 'business', 'food', 'travel'];

        for ($i = 0; $i < $count; $i++) {
            $query = $queries[$i % count($queries)];
            $title = ucfirst($query) . " Image " . ($i + 1);

            $image_url = $this->get_unsplash_image($query, 1200, 800);

            if ($image_url) {
                $upload = media_sideload_image($image_url, 0, $title, 'id');
                if (!is_wp_error($upload)) {
                    $attachment_url = wp_get_attachment_url($upload);
                    WP_CLI::log("Created media item: $title ($attachment_url)");
                } else {
                    WP_CLI::warning("Failed to create media item: $title");
                }
            } else {
                WP_CLI::warning("Failed to fetch image for: $title");
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