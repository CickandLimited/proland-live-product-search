<?php
/**
 * Plugin Name: ProLand Live Product Search
 * Description: Front-end live (typeahead) search for WooCommerce products by name + description. Use shortcode [proland_live_product_search].
 * Version: 2.0.0
 * Author: Kris Rabai Veritium Support Services
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class ProLand_Live_Product_Search {
    const NONCE_ACTION  = 'plps_search_nonce';

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_block']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_frontend_assets']);

        add_action('wp_ajax_plps_search_products', [__CLASS__, 'ajax_search_products']);
        add_action('wp_ajax_nopriv_plps_search_products', [__CLASS__, 'ajax_search_products']);
    }

    public static function register_frontend_assets(): void {
        $url = plugin_dir_url(__FILE__);

        wp_register_script(
            'plps-frontend',
            $url . 'assets/plps.js',
            [],
            '1.0.2',
            true
        );

        wp_register_style(
            'plps-frontend',
            $url . 'assets/plps.css',
            [],
            '1.0.0'
        );
    }

    public static function register_block(): void {
        $block_dir = __DIR__ . '/blocks/live-product-search';

        // Registers editor scripts/styles from block.json (build output)
        register_block_type($block_dir, [
            'render_callback' => [__CLASS__, 'render_block'],
        ]);
    }

    public static function render_block($attributes, $content, $block): string {
        // Enqueue frontend assets only when block is actually rendered
        wp_enqueue_script('plps-frontend');
        wp_enqueue_style('plps-frontend');

        $placeholder = isset($attributes['placeholder']) ? (string)$attributes['placeholder'] : 'Search courses…';
        $limit       = isset($attributes['limit']) ? (int)$attributes['limit'] : 8;
        $min_chars   = isset($attributes['minChars']) ? (int)$attributes['minChars'] : 2;

        $limit     = max(1, min(20, $limit));
        $min_chars = max(1, min(10, $min_chars));

        $uid = 'plps-' . wp_generate_uuid4();

        $data = [
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce(self::NONCE_ACTION),
            'limit'     => (string)$limit,
            'min_chars' => (string)$min_chars,
        ];

        $placeholder = esc_attr($placeholder);

        ob_start(); ?>
        <div
            class="plps"
            data-plps
            data-plps-ajax-url="<?php echo esc_attr($data['ajax_url']); ?>"
            data-plps-nonce="<?php echo esc_attr($data['nonce']); ?>"
            data-plps-limit="<?php echo esc_attr($data['limit']); ?>"
            data-plps-min-chars="<?php echo esc_attr($data['min_chars']); ?>"
            id="<?php echo esc_attr($uid); ?>"
        >
            <label class="plps__label" for="<?php echo esc_attr($uid); ?>-input">Product search</label>

            <div class="plps__inputWrap">
                <input
                    id="<?php echo esc_attr($uid); ?>-input"
                    class="plps__input"
                    type="search"
                    autocomplete="off"
                    placeholder="<?php echo $placeholder; ?>"
                    aria-autocomplete="list"
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($uid); ?>-results"
                />
                <div class="plps__spinner" aria-hidden="true"></div>
            </div>

            <div
                id="<?php echo esc_attr($uid); ?>-results"
                class="plps__results"
                role="listbox"
                aria-label="Search results"
                hidden
            ></div>

            <div class="plps__status" role="status" aria-live="polite"></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function ajax_search_products(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Invalid request.'], 403);
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce not active.'], 400);
        }

        $term  = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 8;

        $term  = trim($term);
        $limit = max(1, min(20, $limit));

        if ($term === '') {
            wp_send_json_success(['items' => []]);
        }

        $q = new WP_Query([
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            's'                   => $term,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'orderby'             => 'relevance',
            'fields'              => 'ids',
        ]);

        $items = [];
        foreach ($q->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            if (!$product->is_visible()) continue;

            $title = get_the_title($product_id);

            $short = (string) get_post_field('post_excerpt', $product_id);
            $long  = (string) get_post_field('post_content', $product_id);
            $raw   = $short !== '' ? $short : $long;

            $snippet = wp_strip_all_tags($raw);
            $snippet = preg_replace('/\s+/', ' ', $snippet);
            $snippet = trim($snippet);
            $snippet = mb_substr($snippet, 0, 140);
            if ($snippet !== '' && mb_strlen($snippet) === 140) $snippet .= '…';

            $items[] = [
                'id'      => $product_id,
                'title'   => $title,
                'snippet' => $snippet,
                'url'     => get_permalink($product_id),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }
}

ProLand_Live_Product_Search::init();