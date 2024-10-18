<?php
// File: admin-compass-load-test.php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Generates test content for Admin Compass load testing.
 */
class Admin_Compass_Load_Test {

    private $title_words = [
        'Innovative', 'Strategic', 'Dynamic', 'Sustainable', 'Efficient',
        'Creative', 'Productive', 'Optimized', 'Advanced', 'Integrated',
        'Customized', 'Responsive', 'Versatile', 'Streamlined', 'Adaptive',
        'Scalable', 'Robust', 'Intuitive', 'Seamless', 'Comprehensive'
    ];

    private $content_words = [
        'implement', 'utilize', 'integrate', 'streamline', 'optimize',
        'leverage', 'innovate', 'generate', 'cultivate', 'iterate',
        'synthesize', 'deploy', 'brand', 'grow', 'target',
        'revolutionize', 'transform', 'embrace', 'enable', 'orchestrate',
        'conceptualize', 'redefine', 'aggregate', 'architect', 'enhance',
        'incentivize', 'morph', 'empower', 'envisioneer', 'monetize',
        'harness', 'facilitate', 'seize', 'disintermediate', 'synergize',
        'strategize', 'deploy', 'engage', 'maximize', 'benchmark',
        'expedite', 'reintermediate', 'whiteboard', 'visualize', 'repurpose',
        'innovate', 'scale', 'unleash', 'drive', 'extend',
        'engineer', 'revolutionize', 'generate', 'exploit', 'transition',
        'e-enable', 'iterate', 'cultivate', 'matrix', 'productize',
        'redefine', 'recontextualize', 'transform', 'embrace', 'enable',
        'orchestrate', 'leverage', 'reinvent', 'aggregate', 'architect',
        'enhance', 'incentivize', 'morph', 'empower', 'envisioneer',
        'monetize', 'harness', 'facilitate', 'seize', 'disintermediate',
        'synergize', 'strategize', 'deploy', 'brand', 'grow',
        'target', 'syndicate', 'synthesize', 'deliver', 'mesh'
    ];

    /**
     * Generates a specified number of posts, pages, and products.
     *
     * ## OPTIONS
     *
     * <type>
     * : The type of content to generate (post, page, product, or all).
     *
     * <count>
     * : Number of items to generate.
     *
     * ## EXAMPLES
     *
     *     wp admin-compass generate post 100
     *     wp admin-compass generate page 50
     *     wp admin-compass generate product 200
     *     wp admin-compass generate all 100
     *
     * @when after_wp_load
     */
    public function generate( $args, $assoc_args ) {
        list( $type, $count ) = $args;
        $count = intval($count);

        switch ( $type ) {
            case 'post':
                $this->generate_posts( $count );
                break;
            case 'page':
                $this->generate_pages( $count );
                break;
            case 'product':
                $this->generate_products( $count );
                break;
            case 'all':
                $this->generate_posts( $count );
                $this->generate_pages( $count );
                $this->generate_products( $count );
                break;
            default:
                WP_CLI::error( "Invalid content type. Use 'post', 'page', 'product', or 'all'." );
        }
    }

    private function generate_posts( $count ) {
        for ( $i = 0; $i < $count; $i++ ) {
            $title = $this->generate_title('Post');
            $content = $this->generate_content();
            
            $post_id = wp_insert_post( array(
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => 'publish',
                'post_author'   => 1,
                'post_type'     => 'post',
            ) );

            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( "Failed to create post: $title" );
            } else {
                WP_CLI::success( "Created post: $title" );
            }
        }
    }

    private function generate_pages( $count ) {
        for ( $i = 0; $i < $count; $i++ ) {
            $title = $this->generate_title('Page');
            $content = $this->generate_content();
            
            $page_id = wp_insert_post( array(
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => 'publish',
                'post_author'   => 1,
                'post_type'     => 'page',
            ) );

            if ( is_wp_error( $page_id ) ) {
                WP_CLI::warning( "Failed to create page: $title" );
            } else {
                WP_CLI::success( "Created page: $title" );
            }
        }
    }

    private function generate_products( $count ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            WP_CLI::error( "WooCommerce is not active. Cannot generate products." );
            return;
        }

        for ( $i = 0; $i < $count; $i++ ) {
            $title = $this->generate_title('Product');
            $description = $this->generate_content();
            
            $product = new WC_Product_Simple();
            $product->set_name( $title );
            $product->set_description( $description );
            $product->set_short_description( substr($description, 0, 100) . '...' );
            $product->set_status( "publish" );
            $product->set_price( rand(10, 100) );
            $product->set_regular_price( rand(10, 100) );

            $product_id = $product->save();

            if ( ! $product_id ) {
                WP_CLI::warning( "Failed to create product: $title" );
            } else {
                WP_CLI::success( "Created product: $title" );
            }
        }
    }

    private function generate_title($prefix) {
        $word_count = rand(3, 5);
        $words = array_rand($this->title_words, $word_count);
        if (!is_array($words)) {
            $words = [$words];
        }
        $title_words = array_map(function($index) {
            return $this->title_words[$index];
        }, $words);
        return $prefix . ': ' . implode(' ', $title_words) . ' ' . uniqid();
    }

    private function generate_content() {
        $paragraph_count = rand(3, 7);
        $content = '';
        for ($i = 0; $i < $paragraph_count; $i++) {
            $sentence_count = rand(3, 8);
            $paragraph = '';
            for ($j = 0; $j < $sentence_count; $j++) {
                $word_count = rand(8, 15);
                $words = array_rand($this->content_words, $word_count);
                if (!is_array($words)) {
                    $words = [$words];
                }
                $sentence_words = array_map(function($index) {
                    return $this->content_words[$index];
                }, $words);
                $paragraph .= ucfirst(implode(' ', $sentence_words)) . '. ';
            }
            $content .= $paragraph . "\n\n";
        }
        return rtrim($content);
    }
}

WP_CLI::add_command( 'admin-compass', 'Admin_Compass_Load_Test' );