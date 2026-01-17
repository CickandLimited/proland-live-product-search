<?php
/**
 * Plugin Name: ProLand Live Product Search
 * Description: Front-end live (typeahead) search for WooCommerce products by name + description. Use shortcode [proland_live_product_search].
 * Version: 2.1.0
 * Author: Kris Rabai Veritium Support Services
 * License: GPLv2 or later
 */



if (!defined('ABSPATH')) exit;

final class ProLand_Live_Product_Search {
    const NONCE_ACTION = 'plps_search_nonce';

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_block']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        add_action('wp_ajax_plps_search_products', [__CLASS__, 'ajax_search_products']);
        add_action('wp_ajax_nopriv_plps_search_products', [__CLASS__, 'ajax_search_products']);
    }

    public static function register_assets(): void {
        $url = plugin_dir_url(__FILE__);

        wp_register_script(
            'plps-frontend',
            $url . 'assets/plps.js',
            [],
            '2.2.0',
            true
        );

        wp_register_style(
            'plps-frontend',
            $url . 'assets/plps.css',
            [],
            '2.1.0'
        );
    }

    public static function register_block(): void {
        register_block_type(__DIR__ . '/blocks/live-product-search', [
            'render_callback' => [__CLASS__, 'render_block'],
        ]);
    }

    public static function render_block($attributes): string {
        wp_enqueue_script('plps-frontend');
        wp_enqueue_style('plps-frontend');

        $placeholder = $attributes['placeholder'] ?? 'Search products…';
        $limit       = max(1, min(20, (int)($attributes['limit'] ?? 8)));
        $min_chars   = max(1, min(10, (int)($attributes['minChars'] ?? 2)));

        $uid = 'plps-' . wp_generate_uuid4();

        ob_start(); ?>
        <div
            class="plps"
            data-plps
            data-plps-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
            data-plps-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>"
            data-plps-limit="<?php echo esc_attr($limit); ?>"
            data-plps-min-chars="<?php echo esc_attr($min_chars); ?>"
            id="<?php echo esc_attr($uid); ?>"
        >
            <label class="plps__label" for="<?php echo esc_attr($uid); ?>-input">Product search</label>

            <div class="plps__inputWrap">
                <input
                    id="<?php echo esc_attr($uid); ?>-input"
                    class="plps__input"
                    type="search"
                    autocomplete="off"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                />
                <div class="plps__spinner"></div>
            </div>

            <div class="plps__results" hidden></div>
            <div class="plps__status" role="status" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Relevance-ish score based on title vs search term.
     * Higher is "closer match".
     */
    private static function title_match_score(string $title, string $term): int {
        $t = mb_strtolower(trim($title));
        $q = mb_strtolower(trim($term));

        if ($q === '' || $t === '') return 0;

        // Exact match / prefix / contains bonuses
        if ($t === $q) return 10000;
        if (str_starts_with($t, $q)) return 9000;
        if (str_contains($t, $q)) return 8000;

        // Similarity fallback
        $percent = 0.0;
        similar_text($t, $q, $percent);

        // Scale to 0..7000 (keeps "contains" above similarity)
        return (int) round(min(7000, max(0, $percent * 70)));
    }

    public static function ajax_search_products(): void {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::NONCE_ACTION)
        ) {
            wp_send_json_error(['message' => 'Invalid request'], 403);
        }

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce not active'], 400);
        }

        $term  = trim(sanitize_text_field(wp_unslash($_POST['term'] ?? '')));
        $limit = max(1, min(20, (int)($_POST['limit'] ?? 8)));

        if ($term === '') {
            wp_send_json_success(['items' => []]);
        }

        // Pull more candidates than we display so we can sort properly.
        $candidate_limit = min(200, max(50, $limit * 8));

        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $candidate_limit,
            's'              => $term,
            'orderby'        => 'relevance',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $rows = [];

        foreach ($q->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $title = (string) get_the_title($product_id);

            $categories = wc_get_product_category_list($product_id, ', ');
            $categories = $categories ? wp_strip_all_tags($categories) : 'Uncategorised';

            $price = $product->get_price_html();
            if ($price === '') {
                $price = 'N/A';
            }

            $in_stock = $product->is_in_stock();

            $rows[] = [
                'title'        => $title,
                'title_lc'     => mb_strtolower($title),
                'score'        => self::title_match_score($title, $term),
                'in_stock'     => $in_stock ? 1 : 0,

                'url'          => get_permalink($product_id),
                'category'     => $categories,
                'price'        => $price,
                'availability' => $in_stock ? 'In stock' : 'Out of stock',
                'outOfStock'   => !$in_stock,
            ];
        }

        // Sort: in-stock first -> closest match -> alphabetical
        usort($rows, function($a, $b) {
            if ($a['in_stock'] !== $b['in_stock']) {
                return $b['in_stock'] <=> $a['in_stock']; // 1 before 0
            }

            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score']; // higher score first
            }

            return $a['title_lc'] <=> $b['title_lc']; // A-Z
        });

        // Return only what we need, and remove helper fields
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(function($r) {
            return [
                'title'        => $r['title'],
                'url'          => $r['url'],
                'category'     => $r['category'],
                'price'        => $r['price'],
                'availability' => $r['availability'],
                'outOfStock'   => $r['outOfStock'],
            ];
        }, $rows);

        wp_send_json_success(['items' => $items]);
    }
}

ProLand_Live_Product_Search::init();