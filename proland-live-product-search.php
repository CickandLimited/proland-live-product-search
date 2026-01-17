<?php
/**
 * Plugin Name: ProLand Live Product Search
 * Description: Front-end live (typeahead) search for WooCommerce products by name + description. Use shortcode [proland_live_product_search].
 * Version: 1.0.0
 * Author: Kris Rabai Veritium Support Services
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class ProLand_Live_Product_Search {
    const SHORTCODE = 'proland_live_product_search';
    const NONCE_ACTION = 'plps_search_nonce';

    public static function init(): void {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render_shortcode']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        // AJAX for logged-in + guests
        add_action('wp_ajax_plps_search_products', [__CLASS__, 'ajax_search_products']);
        add_action('wp_ajax_nopriv_plps_search_products', [__CLASS__, 'ajax_search_products']);
    }

    public static function register_assets(): void {
        $url = plugin_dir_url(__FILE__);

        wp_register_script(
            'plps-js',
            $url . 'assets/plps.js',
            [],
            '1.0.0',
            true
        );

        wp_register_style(
            'plps-css',
            $url . 'assets/plps.css',
            [],
            '1.0.0'
        );
    }

    public static function render_shortcode($atts): string {
        // Enqueue only when shortcode is used
        wp_enqueue_script('plps-js');
        wp_enqueue_style('plps-css');

        $atts = shortcode_atts([
            'placeholder' => 'Search courses…',
            'limit'       => 8,
            'min_chars'   => 2,
        ], (array)$atts, self::SHORTCODE);

        $limit = max(1, min(20, (int)$atts['limit']));
        $min_chars = max(1, min(10, (int)$atts['min_chars']));

        wp_localize_script('plps-js', 'PLPS', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce(self::NONCE_ACTION),
            'limit'     => $limit,
            'minChars'  => $min_chars,
        ]);

        $placeholder = esc_attr($atts['placeholder']);
        $uid = 'plps-' . wp_generate_uuid4();

        ob_start(); ?>
        <div class="plps" data-plps id="<?php echo esc_attr($uid); ?>">
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
        return (string)ob_get_clean();
    }

    public static function ajax_search_products(): void {
        // Basic hardening
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Invalid request.'], 403);
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce not active.'], 400);
        }

        $term  = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 8;

        $term = trim($term);
        $limit = max(1, min(20, $limit));

        if (mb_strlen($term) < 1) {
            wp_send_json_success(['items' => []]);
        }

        // Search products by title + content (long description) + excerpt (short description)
        // WP's 's' search covers title/content/excerpt by default.
        $q = new WP_Query([
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            's'                      => $term,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'orderby'                => 'relevance',
            'fields'                 => 'ids',
            'meta_query'             => [
                // Hide out-of-stock if your catalogue does (optional). Comment out if not desired.
                // ['key' => '_stock_status', 'value' => 'outofstock', 'compare' => '!='],
            ],
        ]);

        $items = [];
        foreach ($q->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            // Respect catalogue visibility
            if (!$product->is_visible()) continue;

            $title = get_the_title($product_id);

            // Combine excerpt + content for a meaningful snippet
            $short = (string) get_post_field('post_excerpt', $product_id);
            $long  = (string) get_post_field('post_content', $product_id);
            $raw   = $short !== '' ? $short : $long;

            $snippet = wp_strip_all_tags($raw);
            $snippet = preg_replace('/\s+/', ' ', $snippet);
            $snippet = trim($snippet);
            $snippet = mb_substr($snippet, 0, 140);
            if ($snippet !== '' && mb_strlen($snippet) === 140) $snippet .= '…';

            $items[] = [
                'id'       => $product_id,
                'title'    => $title,
                'snippet'  => $snippet,
                'url'      => get_permalink($product_id),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }
}

ProLand_Live_Product_Search::init();
