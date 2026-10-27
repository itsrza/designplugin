<?php
/**
 * Miran — Shop module
 *
 * Loaded by the unified Miran plugin. Internal functions use the
 * miran_suite_ prefix so this module cannot collide with the former
 * standalone plugins during migration.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ==========================================================================
 * Assets — separate, cacheable files, loaded only where the shop is shown
 * ========================================================================== */

function miran_suite_shop_needs_assets() {
    if (function_exists('is_shop') && (is_shop() || is_product_category() || is_product_tag())) {
        return true;
    }

    $object_id = get_queried_object_id();
    if ($object_id) {
        $post = get_post($object_id);
        if ($post && (has_shortcode($post->post_content, 'miran_shop') || has_shortcode($post->post_content, 'serviya_shop'))) {
            return true;
        }
        // Shortcode placed through Elementor (stored in post meta, not content).
        $elementor_data = get_post_meta($object_id, '_elementor_data', true);
        if (is_string($elementor_data) && (false !== strpos($elementor_data, 'miran_shop') || false !== strpos($elementor_data, 'serviya_shop'))) {
            return true;
        }
    }

    return (bool) apply_filters('miran_shop_needs_assets', false);
}

function miran_suite_shop_current_url() {
    $object_id = get_queried_object_id();
    if ($object_id && is_singular()) {
        $permalink = get_permalink($object_id);
        if ($permalink) {
            return $permalink;
        }
    }
    if (function_exists('wc_get_page_permalink')) {
        $shop = wc_get_page_permalink('shop');
        if ($shop) {
            return $shop;
        }
    }
    return home_url('/');
}

function miran_suite_shop_enqueue_assets() {
    $plugin_url  = MIRAN_URL;
    $plugin_path = MIRAN_PATH;

    // Minified builds in production; readable sources with SCRIPT_DEBUG.
    $use_min  = !(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);
    $css_rel  = 'assets/css/miran-shop' . ($use_min && file_exists($plugin_path . 'assets/css/miran-shop.min.css') ? '.min.css' : '.css');
    $js_rel   = 'assets/js/miran-shop' . ($use_min && file_exists($plugin_path . 'assets/js/miran-shop.min.js') ? '.min.js' : '.js');
    $css_file = $plugin_path . $css_rel;
    $js_file  = $plugin_path . $js_rel;

    $css_version = file_exists($css_file) ? (string) filemtime($css_file) : MIRAN_VERSION;
    $js_version  = file_exists($js_file) ? (string) filemtime($js_file) : MIRAN_VERSION;

    if (!wp_style_is('miran-shop', 'enqueued')) {
        wp_enqueue_style(
            'miran-shop',
            $plugin_url . $css_rel,
            array(),
            $css_version
        );
    }

    if (!wp_script_is('miran-shop', 'enqueued')) {
        if (version_compare(get_bloginfo('version'), '6.3', '>=')) {
            wp_enqueue_script(
                'miran-shop',
                $plugin_url . $js_rel,
                array(),
                $js_version,
                array('in_footer' => true, 'strategy' => 'defer')
            );
        } else {
            wp_enqueue_script(
                'miran-shop',
                $plugin_url . $js_rel,
                array(),
                $js_version,
                true
            );
        }

        $currency_symbol = function_exists('get_woocommerce_currency_symbol')
            ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8')
            : 'تومان';

        wp_localize_script('miran-shop', 'MiranShopData', array(
            'restBase'       => esc_url_raw(rest_url('wc/store/v1/')),
            'shopUrl'        => miran_suite_shop_current_url(),
            'homeUrl'        => home_url('/'),
            'siteName'       => get_bloginfo('name'),
            'currencySymbol' => $currency_symbol,
            'perPage'        => (int) apply_filters('miran_shop_per_page', 12),
        ));
    }
}

// Priority 100: print after the theme stylesheet (the previous inline version
// lived in the body, i.e. effectively last), so theme rules can't override it.
add_action('wp_enqueue_scripts', function () {
    if (miran_suite_shop_needs_assets()) {
        miran_suite_shop_enqueue_assets();
    }
}, 100);

/* ==========================================================================
 * SEO: filtered/sorted/searched variants are noindex,follow.
 * The clean /shop/ URL and single-category landings stay indexable.
 * ========================================================================== */

add_filter('wp_robots', function ($robots) {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only state.
    $get = isset($_GET) ? wp_unslash($_GET) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    // phpcs:enable

    $has_ss = false;
    foreach (array_keys((array) $get) as $key) {
        if (strpos((string) $key, 'ss_') === 0) {
            $has_ss = true;
            break;
        }
    }
    if (!$has_ss) {
        return $robots;
    }

    $categories = array();
    if (isset($get['ss_cat'])) {
        $categories = is_array($get['ss_cat'])
            ? array_filter(array_map('sanitize_title', $get['ss_cat']))
            : array_filter(array_map('sanitize_title', explode(',', (string) $get['ss_cat'])));
    }

    $has_attributes = false;
    foreach (array_keys((array) $get) as $key) {
        if (strpos((string) $key, 'ss_pa_') === 0 || $key === 'ss_attr') {
            $has_attributes = true;
            break;
        }
    }

    $extra = !empty($get['ss_q'])
        || !empty($get['ss_stock'])
        || !empty($get['ss_sale'])
        || (!empty($get['ss_sort']) && $get['ss_sort'] !== 'date')
        || (!empty($get['ss_page']) && (int) $get['ss_page'] > 1)
        || $has_attributes
        || count($categories) !== 1;

    if ($extra) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
        unset($robots['index']);
    }

    return $robots;
});

/* ==========================================================================
 * State — accepted URL params (shared between PHP, the no-JS form and JS)
 *   ss_q, ss_cat (slug,slug | ss_cat[]), ss_sort, ss_stock, ss_sale,
 *   ss_page, ss_{pa_taxonomy} (slug,slug | ss_attr[pa_x][])
 * ========================================================================== */

function miran_suite_shop_sanitize_list($value) {
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = explode(',', (string) $value);
    }
    $items = array_map('sanitize_title', $items);
    return array_values(array_filter($items));
}

function miran_suite_shop_attribute_taxonomies() {
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return array();
    }
    $taxonomies = array();
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
        if (taxonomy_exists($taxonomy)) {
            $taxonomies[$taxonomy] = $attribute;
        }
    }
    return $taxonomies;
}

function miran_suite_shop_parse_state() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter state.
    $get = isset($_GET) ? wp_unslash($_GET) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    // phpcs:enable

    $sorts = array('date', 'popularity', 'price', 'price-desc');
    $sort  = isset($get['ss_sort']) && in_array($get['ss_sort'], $sorts, true) ? $get['ss_sort'] : 'date';

    $attributes = array();
    foreach (array_keys(miran_suite_shop_attribute_taxonomies()) as $taxonomy) {
        $terms = array();
        if (isset($get['ss_' . $taxonomy])) {
            $terms = miran_suite_shop_sanitize_list($get['ss_' . $taxonomy]);
        } elseif (isset($get['ss_attr']) && is_array($get['ss_attr']) && isset($get['ss_attr'][$taxonomy])) {
            $terms = miran_suite_shop_sanitize_list($get['ss_attr'][$taxonomy]);
        }
        if ($terms) {
            $attributes[$taxonomy] = $terms;
        }
    }

    return array(
        'page'       => isset($get['ss_page']) ? max(1, absint($get['ss_page'])) : 1,
        'search'     => isset($get['ss_q']) ? sanitize_text_field($get['ss_q']) : '',
        'sort'       => $sort,
        'in_stock'   => isset($get['ss_stock']) && (string) $get['ss_stock'] === '1',
        'on_sale'    => isset($get['ss_sale']) && (string) $get['ss_sale'] === '1',
        'categories' => isset($get['ss_cat']) ? miran_suite_shop_sanitize_list($get['ss_cat']) : array(),
        'attributes' => $attributes,
    );
}

function miran_suite_shop_build_url($state, $base_url, $page = 0) {
    $args = array();
    if ($state['search'] !== '') {
        $args['ss_q'] = $state['search'];
    }
    if (!empty($state['categories'])) {
        $args['ss_cat'] = implode(',', $state['categories']);
    }
    foreach ($state['attributes'] as $taxonomy => $terms) {
        if ($terms) {
            $args['ss_' . $taxonomy] = implode(',', $terms);
        }
    }
    if ($state['in_stock']) {
        $args['ss_stock'] = '1';
    }
    if ($state['on_sale']) {
        $args['ss_sale'] = '1';
    }
    if ($state['sort'] !== 'date') {
        $args['ss_sort'] = $state['sort'];
    }
    if ($page > 1) {
        $args['ss_page'] = (string) $page;
    }
    return $args ? add_query_arg($args, $base_url) : $base_url;
}

function miran_suite_shop_active_filter_count($state) {
    $count = count($state['categories'])
        + ($state['in_stock'] ? 1 : 0)
        + ($state['on_sale'] ? 1 : 0)
        + ($state['search'] !== '' ? 1 : 0);
    foreach ($state['attributes'] as $terms) {
        $count += count($terms);
    }
    return $count;
}

function miran_suite_shop_fa_number($value) {
    $formatted = number_format((float) $value, 0, '.', ',');
    return strtr($formatted, array(
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ',' => '٬',
    ));
}

/* ==========================================================================
 * Product query (server-side, mirrors the Store API filtering used by JS)
 * ========================================================================== */

function miran_suite_shop_query_products($state, $per_page) {
    $args = array(
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => $per_page,
        'paged'               => $state['page'],
        'ignore_sticky_posts' => true,
        'no_found_rows'       => false,
    );

    if ($state['search'] !== '') {
        $args['s'] = $state['search'];
    }

    // Hide products excluded from the catalog, like a normal shop archive.
    $tax_query = array('relation' => 'AND');
    $hidden_term = get_term_by('slug', 'exclude-from-catalog', 'product_visibility');
    if ($hidden_term) {
        $tax_query[] = array(
            'taxonomy' => 'product_visibility',
            'field'    => 'term_id',
            'terms'    => array($hidden_term->term_id),
            'operator' => 'NOT IN',
        );
    }

    if (!empty($state['categories'])) {
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $state['categories'],
            'operator' => 'IN',
        );
    }

    foreach ($state['attributes'] as $taxonomy => $terms) {
        $tax_query[] = array(
            'taxonomy' => $taxonomy,
            'field'    => 'slug',
            'terms'    => $terms,
            'operator' => 'IN',
        );
    }

    if (count($tax_query) > 1) {
        $args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
    }

    $meta_query = array('relation' => 'AND');

    if ($state['in_stock']) {
        $meta_query['ss_stock_clause'] = array(
            'key'   => '_stock_status',
            'value' => 'instock',
        );
    }

    if ($state['on_sale']) {
        $sale_ids = function_exists('wc_get_product_ids_on_sale') ? wc_get_product_ids_on_sale() : array();
        $args['post__in'] = $sale_ids ? array_map('absint', $sale_ids) : array(0);
    }

    switch ($state['sort']) {
        case 'price':
            $meta_query['ss_price_clause'] = array('key' => '_price', 'compare' => 'EXISTS', 'type' => 'NUMERIC');
            $args['orderby'] = array('ss_price_clause' => 'ASC', 'date' => 'DESC');
            break;
        case 'price-desc':
            $meta_query['ss_price_clause'] = array('key' => '_price', 'compare' => 'EXISTS', 'type' => 'NUMERIC');
            $args['orderby'] = array('ss_price_clause' => 'DESC', 'date' => 'DESC');
            break;
        case 'popularity':
            $meta_query['ss_sales_clause'] = array('key' => 'total_sales', 'compare' => 'EXISTS', 'type' => 'NUMERIC');
            $args['orderby'] = array('ss_sales_clause' => 'DESC', 'date' => 'DESC');
            break;
        default:
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
            break;
    }

    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
    }

    $query = new WP_Query($args);

    $products = array();
    foreach ($query->posts as $post) {
        $product = wc_get_product($post->ID);
        if ($product) {
            $products[] = $product;
        }
    }

    return array(
        'products'    => $products,
        'total'       => (int) $query->found_posts,
        'total_pages' => max(1, (int) $query->max_num_pages),
    );
}

/* ==========================================================================
 * Card rendering — byte-for-byte the same classes/markup as the JS cards
 * ========================================================================== */

function miran_suite_shop_product_dimensions($product) {
    $parts = array_filter(
        array($product->get_length(), $product->get_width(), $product->get_height()),
        function ($value) {
            return $value !== '' && $value !== null && (string) $value !== '0';
        }
    );
    if ($parts) {
        return implode(' × ', $parts);
    }

    foreach ($product->get_attributes() as $attribute) {
        $is_dimensions = ($attribute->get_name() === 'ابعاد')
            || (method_exists($attribute, 'get_taxonomy') && $attribute->get_taxonomy() === 'pa_dimensions');
        if (!$is_dimensions) {
            continue;
        }
        if ($attribute->is_taxonomy()) {
            $names = wc_get_product_terms($product->get_id(), $attribute->get_taxonomy(), array('fields' => 'names'));
            if ($names) {
                return (string) $names[0];
            }
        } else {
            $options = $attribute->get_options();
            if ($options) {
                return (string) $options[0];
            }
        }
    }
    return '';
}

function miran_suite_shop_price_html($product) {
    $currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
    $currency_html = '<span class="ss-price-currency">' . esc_html($currency) . '</span>';

    if ($product->is_type('variable')) {
        $min = (float) $product->get_variation_price('min');
        $max = (float) $product->get_variation_price('max');
        if ($min > 0 && $max > 0 && $min !== $max) {
            return '<span class="ss-price-new">'
                . miran_suite_shop_fa_number($min) . ' – ' . miran_suite_shop_fa_number($max)
                . '</span>' . $currency_html;
        }
    }

    $price   = (float) $product->get_price();
    $regular = (float) $product->get_regular_price();

    if ($price <= 0) {
        return '<span class="ss-price-new">استعلام قیمت</span>';
    }

    if ($regular > $price) {
        return '<span class="ss-price-old">' . miran_suite_shop_fa_number($regular) . '</span>'
            . '<span class="ss-price-new">' . miran_suite_shop_fa_number($price) . '</span>'
            . $currency_html;
    }

    return '<span class="ss-price-new">' . miran_suite_shop_fa_number($price) . '</span>' . $currency_html;
}

function miran_suite_shop_render_card($product, $index) {
    $product_id = $product->get_id();
    $title      = $product->get_name() !== '' ? $product->get_name() : 'بدون نام';
    $link       = get_permalink($product_id);
    $in_stock   = $product->is_in_stock();

    // Badges.
    $badges = '';
    $price   = (float) $product->get_price();
    $regular = (float) $product->get_regular_price();
    if ($in_stock && $regular > $price && $price > 0) {
        $discount = min(99, (int) round((($regular - $price) / $regular) * 100));
        if ($discount > 0) {
            $badges .= '<span class="ss-badge-discount">' . miran_suite_shop_fa_number($discount) . '٪-</span>';
        }
    }
    $dimensions = miran_suite_shop_product_dimensions($product);
    if ($dimensions !== '') {
        $badges .= '<span class="ss-badge-dim">ابعاد <span>' . esc_html($dimensions) . '</span></span>';
    }

    // Image — wp_get_attachment_image emits srcset/sizes/width/height for free.
    $image_html = '';
    $image_id   = $product->get_image_id();
    if ($image_id) {
        $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        $img_attrs = array(
            'alt'      => $alt !== '' ? $alt : $title,
            'loading'  => $index < 2 ? 'eager' : 'lazy',
            'sizes'    => '(max-width: 768px) 45vw, (max-width: 1100px) 22vw, 240px',
            'decoding' => 'async',
        );
        if ($index === 0) {
            $img_attrs['fetchpriority'] = 'high';
        }
        $image_html = wp_get_attachment_image($image_id, 'woocommerce_thumbnail', false, $img_attrs);
    }
    if ($image_html === '') {
        $image_html = '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#F6F8F3">'
            . '<svg width="24" height="24" viewBox="0 0 24 24" stroke="#9B846C" fill="none" aria-hidden="true">'
            . '<rect x="3" y="3" width="18" height="18" rx="2"></rect>'
            . '<circle cx="8.5" cy="8.5" r="1.5"></circle>'
            . '<polyline points="21 15 16 10 5 21"></polyline>'
            . '</svg></div>';
    }

    // First category label.
    $category_name = 'بدون دسته';
    $term_ids = $product->get_category_ids();
    if ($term_ids) {
        $term = get_term($term_ids[0], 'product_cat');
        if ($term && !is_wp_error($term)) {
            $category_name = $term->name;
        }
    }

    // Button.
    if (!$in_stock) {
        $button_html = '<button type="button" class="ss-card-btn is-unavailable" disabled>'
            . '<span class="ss-btn-label">ناموجود</span></button>';
    } elseif ($product->is_type('simple') && $product->is_purchasable()) {
        $button_html = '<button type="button" class="ss-card-btn" data-product-id="' . esc_attr($product_id) . '"'
            . ' aria-label="' . esc_attr('افزودن ' . $title . ' به سبد خرید') . '">'
            . '<span class="ss-btn-label">افزودن به سبد خرید</span>'
            . '<svg class="ss-btn-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle>'
            . '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>'
            . '</svg></button>';
    } else {
        $label = $product->is_type('variable') ? 'انتخاب گزینه‌ها' : 'مشاهده محصول';
        $button_html = '<a href="' . esc_url($link) . '" class="ss-card-btn ss-card-link-btn">'
            . '<span class="ss-btn-label">' . esc_html($label) . '</span></a>';
    }

    return '<article class="ss-card' . ($in_stock ? '' : ' is-out-of-stock') . '">'
        . '<a href="' . esc_url($link) . '" class="ss-card-img" aria-label="' . esc_attr('مشاهده ' . $title) . '" tabindex="-1">'
        . $badges . $image_html
        . '</a>'
        . '<div class="ss-card-content">'
        . '<a href="' . esc_url($link) . '" class="ss-card-title-link">'
        . '<h3 class="ss-card-title">' . esc_html($title) . '</h3></a>'
        . '<div class="ss-card-cat">' . esc_html($category_name) . '</div>'
        . '<div class="ss-card-price-box">' . miran_suite_shop_price_html($product) . '</div>'
        . $button_html
        . '</div></article>';
}

/* ==========================================================================
 * Shortcode
 * ========================================================================== */

function miran_suite_shop_render($atts) {
    if (!class_exists('WooCommerce')) {
        return current_user_can('manage_options')
            ? '<p>افزونه میران: ووکامرس فعال نیست.</p>'
            : '';
    }

    miran_suite_shop_enqueue_assets();

    $atts = shortcode_atts(array(
        'per_page' => (int) apply_filters('miran_shop_per_page', 12),
        'title'    => 'فروشگاه',
    ), $atts, 'miran_shop');

    $per_page  = min(48, max(1, absint($atts['per_page'])));
    $shop_title = sanitize_text_field($atts['title']);
    $state     = miran_suite_shop_parse_state();
    $result    = miran_suite_shop_query_products($state, $per_page);
    $shop_url  = miran_suite_shop_current_url();
    $home_url  = home_url('/');
    $uid       = wp_unique_id('ss-');
    $active_count = miran_suite_shop_active_filter_count($state);

    // Categories (all + top level). hide_empty stays off on purpose: for
    // hierarchical taxonomies WordPress counts only directly assigned
    // products, so a parent whose products live in sub-categories would be
    // dropped (and its children orphaned). Instead we keep every term that
    // has products itself OR has a descendant with products.
    $all_categories = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ));
    if (is_wp_error($all_categories)) {
        $all_categories = array();
    }
    if ($all_categories) {
        $term_by_id = array();
        foreach ($all_categories as $term) {
            $term_by_id[(int) $term->term_id] = $term;
        }
        $keep_ids = array();
        foreach ($all_categories as $term) {
            $term_count = isset($term->count) ? (int) $term->count : 0;
            if ($term_count > 0) {
                $keep_ids[(int) $term->term_id] = true;
                $parent_id = (int) $term->parent;
                while ($parent_id > 0 && isset($term_by_id[$parent_id]) && !isset($keep_ids[$parent_id])) {
                    $keep_ids[$parent_id] = true;
                    $parent_id = (int) $term_by_id[$parent_id]->parent;
                }
            }
        }
        $all_categories = array_values(array_filter($all_categories, function ($term) use ($keep_ids) {
            return isset($keep_ids[(int) $term->term_id]);
        }));
    }
    $top_categories = array_filter($all_categories, function ($term) {
        return (int) $term->parent === 0;
    });

    // Flat category dataset (with parents + images) shared by the PHP-rendered
    // top strip and the JS (JSON below) so both render the drill-down identically.
    $term_slug_by_id = array();
    foreach ($all_categories as $term) {
        $term_slug_by_id[(int) $term->term_id] = $term->slug;
    }
    $categories_data  = array(); // list for JSON
    $category_by_slug = array(); // slug => data + term object (PHP only)
    $children_by_parent_slug = array();
    foreach ($all_categories as $term) {
        $parent_slug = isset($term_slug_by_id[(int) $term->parent]) ? $term_slug_by_id[(int) $term->parent] : '';
        $thumbnail_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
        $image_url = $thumbnail_id && function_exists('wp_get_attachment_image_url')
            ? (string) wp_get_attachment_image_url($thumbnail_id, 'thumbnail')
            : '';
        $row = array(
            'slug'   => $term->slug,
            'id'     => (int) $term->term_id,
            'name'   => $term->name,
            'parent' => $parent_slug,
            'image'  => $image_url,
        );
        $categories_data[] = $row;
        $row['term'] = $term;
        $row['thumbnail_id'] = $thumbnail_id;
        $category_by_slug[$term->slug] = $row;
        $children_by_parent_slug[$parent_slug][] = $row;
    }

    // Top strip drill-down: top level first; selecting a category shows its
    // sub-categories here (plus "back" and "all of <parent>").
    $strip_level_parent = null; // data row of the parent whose children are shown
    // Default view (no / several categories selected): ALL categories in
    // hierarchical order — exactly like the original widget's flat strip.
    // Selecting one category then drills down to its sub-categories.
    $strip_items = array();
    $flatten_categories = function ($parent_slug) use (&$flatten_categories, $children_by_parent_slug, &$strip_items) {
        foreach (isset($children_by_parent_slug[$parent_slug]) ? $children_by_parent_slug[$parent_slug] : array() as $row) {
            $strip_items[] = $row;
            $flatten_categories($row['slug']);
        }
    };
    $flatten_categories('');
    if (count($state['categories']) === 1 && isset($category_by_slug[$state['categories'][0]])) {
        $selected_row = $category_by_slug[$state['categories'][0]];
        if (!empty($children_by_parent_slug[$selected_row['slug']])) {
            $strip_level_parent = $selected_row;
            $strip_items = $children_by_parent_slug[$selected_row['slug']];
        } elseif ($selected_row['parent'] !== '' && isset($category_by_slug[$selected_row['parent']])) {
            $strip_level_parent = $category_by_slug[$selected_row['parent']];
            $strip_items = $children_by_parent_slug[$selected_row['parent']];
        }
    }
    $strip_back_target = $strip_level_parent ? (string) $strip_level_parent['parent'] : null;

    // Attributes + terms (server-side: no N+1 API calls, deterministic order).
    $attribute_groups = array();
    foreach (miran_suite_shop_attribute_taxonomies() as $taxonomy => $attribute) {
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        if (!is_wp_error($terms) && $terms) {
            $attribute_groups[] = array(
                'taxonomy' => $taxonomy,
                'label'    => $attribute->attribute_label,
                'terms'    => $terms,
            );
        }
    }

    // Heading / breadcrumb title.
    $heading = $shop_title;
    if (count($state['categories']) === 1) {
        if (isset($category_by_slug[$state['categories'][0]])) {
            $heading = $category_by_slug[$state['categories'][0]]['name'];
        } else {
            $term = get_term_by('slug', $state['categories'][0], 'product_cat');
            if ($term && !is_wp_error($term)) {
                $heading = $term->name;
            }
        }
    } elseif (count($state['categories']) > 1) {
        $heading = 'چند دسته‌بندی';
    } elseif ($state['search'] !== '') {
        $heading = 'نتایج جستجو';
    }
    $is_filtered_heading = ($heading !== $shop_title);

    // Ancestor chain for the breadcrumb when exactly one category is selected.
    $breadcrumb_ancestors = array();
    if (count($state['categories']) === 1 && isset($category_by_slug[$state['categories'][0]])) {
        $parent_slug = (string) $category_by_slug[$state['categories'][0]]['parent'];
        $guard = 0;
        while ($parent_slug !== '' && isset($category_by_slug[$parent_slug]) && $guard < 10) {
            $breadcrumb_ancestors[] = $category_by_slug[$parent_slug];
            $parent_slug = (string) $category_by_slug[$parent_slug]['parent'];
            $guard++;
        }
        $breadcrumb_ancestors = array_reverse($breadcrumb_ancestors);
    }

    // Sidebar category checkboxes (hierarchical, children after their parent).
    // Terms whose parent is missing from the list are treated as top level
    // so they can never silently disappear from the sidebar.
    $children_map = array();
    foreach ($all_categories as $term) {
        $parent_id = (int) $term->parent;
        if ($parent_id > 0 && !isset($term_slug_by_id[$parent_id])) {
            $parent_id = 0;
        }
        $children_map[$parent_id][] = $term;
    }
    $render_category_rows = function ($parent_id, $depth) use (&$render_category_rows, $children_map, $state) {
        $html = '';
        if (empty($children_map[$parent_id])) {
            return $html;
        }
        foreach ($children_map[$parent_id] as $term) {
            $checked = in_array($term->slug, $state['categories'], true) ? ' checked' : '';
            $html .= '<label class="ss-checkbox-row' . ($depth > 0 ? ' ss-child' : '') . '">'
                . '<input type="checkbox" class="ss-cat-check" name="ss_cat[]" value="' . esc_attr($term->slug) . '"'
                . ' data-cat-id="' . esc_attr($term->term_id) . '" data-type="category"' . $checked . '>'
                . '<span>' . esc_html($term->name) . '</span></label>';
            $html .= $render_category_rows((int) $term->term_id, $depth + 1);
        }
        return $html;
    };

    $sort_options = array(
        'date'       => 'جدیدترین',
        'popularity' => 'پرفروش‌ترین',
        'price'      => 'ارزان‌ترین',
        'price-desc' => 'گران‌ترین',
    );

    $fallback_icon = '<div class="ss-cat-icon-fallback">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<rect x="3" y="3" width="18" height="18" rx="2"></rect>'
        . '<circle cx="8.5" cy="8.5" r="1.5"></circle>'
        . '<polyline points="21 15 16 10 5 21"></polyline>'
        . '</svg></div>';

    $back_icon = '<div class="ss-cat-icon-fallback">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path>'
        . '</svg></div>';

    // One top-strip card. $label/$extra_attrs let callers build the
    // "همهٔ دسته مادر" variant with the exact same markup.
    $render_strip_item = function ($row, $label = '', $extra_attrs = '') use ($state, $shop_url, $fallback_icon) {
        $is_active = in_array($row['slug'], $state['categories'], true);
        $cat_state = $state;
        $cat_state['categories'] = array($row['slug']);
        $image_html = '';
        if (!empty($row['thumbnail_id'])) {
            $image_html = wp_get_attachment_image($row['thumbnail_id'], 'thumbnail', false, array(
                'class'    => 'ss-cat-img',
                'alt'      => $row['name'],
                'loading'  => 'lazy',
                'decoding' => 'async',
                'sizes'    => '45px',
            ));
        }
        return '<a class="ss-cat-item' . ($is_active ? ' active' : '') . '"'
            . ' href="' . esc_url(miran_suite_shop_build_url($cat_state, $shop_url)) . '"'
            . ' data-cat-slug="' . esc_attr($row['slug']) . '"'
            . ' data-cat-id="' . esc_attr($row['id']) . '"'
            . ($is_active ? ' aria-current="true"' : '')
            . $extra_attrs . '>'
            . ($image_html !== '' ? $image_html : $fallback_icon)
            . '<span class="ss-cat-title">' . esc_html($label !== '' ? $label : $row['name']) . '</span>'
            . '</a>';
    };

    ob_start();
    ?>
    <?php
    // Lets the JS detect when a full-page cache served HTML rendered for a
    // different filter state than the current URL (it then refetches once).
    $ssr_state = array(
        'page'       => $state['page'],
        'search'     => $state['search'],
        'sort'       => $state['sort'],
        'inStock'    => $state['in_stock'],
        'onSale'     => $state['on_sale'],
        'categories' => array_values($state['categories']),
        'attributes' => (object) $state['attributes'],
    );
    ?>
    <div class="miran-shop-widget" dir="rtl"
        data-current-page="<?php echo esc_attr($state['page']); ?>"
        data-total-pages="<?php echo esc_attr($result['total_pages']); ?>"
        data-per-page="<?php echo esc_attr($per_page); ?>"
        data-ssr-state="<?php echo esc_attr(wp_json_encode($ssr_state, JSON_UNESCAPED_UNICODE)); ?>">
        <div class="ss-container">
            <div class="ss-header-bar">
                <nav class="ss-breadcrumbs" aria-label="مسیر صفحه">
                    <ol>
                        <li>
                            <a href="<?php echo esc_url($home_url); ?>">خانه</a>
                            <span class="ss-sep" aria-hidden="true">/</span>
                        </li>
                        <?php if ($is_filtered_heading) : ?>
                            <li>
                                <a href="<?php echo esc_url($shop_url); ?>" data-ss-shop-link><?php echo esc_html($shop_title); ?></a>
                                <span class="ss-sep" aria-hidden="true">/</span>
                            </li>
                        <?php endif; ?>
                        <?php foreach ($breadcrumb_ancestors as $ancestor) :
                            $ancestor_state = $state;
                            $ancestor_state['categories'] = array($ancestor['slug']);
                            ?>
                            <li>
                                <a href="<?php echo esc_url(miran_suite_shop_build_url($ancestor_state, $shop_url)); ?>" data-ss-cat-link="<?php echo esc_attr($ancestor['slug']); ?>"><?php echo esc_html($ancestor['name']); ?></a>
                                <span class="ss-sep" aria-hidden="true">/</span>
                            </li>
                        <?php endforeach; ?>
                        <li><h1 class="ss-current" aria-current="page"><?php echo esc_html($heading); ?></h1></li>
                    </ol>
                </nav>
                <div class="ss-product-count">
                    <span class="ss-total-count"><?php echo miran_suite_shop_fa_number($result['total']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- digits only. ?></span> کالا
                </div>
            </div>

            <?php if ($strip_items || $strip_level_parent) : ?>
                <nav aria-label="دسته‌بندی‌های اصلی">
                    <div class="ss-top-categories">
                        <?php
                        if ($strip_level_parent) {
                            // Drilled into a category: back to the previous level…
                            $back_state = $state;
                            $back_state['categories'] = $strip_back_target !== '' ? array($strip_back_target) : array();
                            ?>
                            <a class="ss-cat-item ss-cat-back"
                                href="<?php echo esc_url(miran_suite_shop_build_url($back_state, $shop_url)); ?>"
                                data-ss-cat-back data-target-slug="<?php echo esc_attr((string) $strip_back_target); ?>">
                                <?php echo $back_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                                <span class="ss-cat-title">بازگشت</span>
                            </a>
                            <?php
                            // …"all of <parent>" (the parent itself)…
                            echo $render_strip_item( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
                                $strip_level_parent,
                                'همهٔ ' . $strip_level_parent['name'],
                                ' data-ss-all-parent="1"'
                            );
                        }
                        // …and the categories of the current level.
                        foreach ($strip_items as $row) {
                            echo $render_strip_item($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
                        }
                        ?>
                    </div>
                </nav>
                <script type="text/json" class="ss-cats-data"><?php echo wp_json_encode($categories_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload, tags hex-escaped. ?></script>
            <?php endif; ?>

            <form class="ss-layout ss-filters-form" method="get" action="<?php echo esc_url($shop_url); ?>">
                <button type="submit" class="ss-sr-only" tabindex="-1" aria-hidden="true">جستجو</button>

                <aside class="ss-sidebar" id="<?php echo esc_attr($uid); ?>-sidebar">
                    <div class="ss-drawer-head">
                        <span>جستجو و فیلترها</span>
                        <button type="button" class="ss-drawer-close" aria-label="بستن فیلترها">×</button>
                    </div>

                    <div class="ss-widget ss-search-widget">
                        <h2 class="ss-widget-title">جستجو در محصولات</h2>
                        <div class="ss-search-box">
                            <label class="ss-sr-only" for="<?php echo esc_attr($uid); ?>-search">جستجو در محصولات</label>
                            <input type="text" inputmode="search" enterkeyhint="search" class="ss-search-input" id="<?php echo esc_attr($uid); ?>-search"
                                name="ss_q" value="<?php echo esc_attr($state['search']); ?>"
                                placeholder="جستجو در محصولات..." autocomplete="off">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M11.5 21C16.7467 21 21 16.7467 21 11.5C21 6.25329 16.7467 2 11.5 2C6.25329 2 2 6.25329 2 11.5C2 16.7467 6.25329 21 11.5 21Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M22 22L20 20" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                    </div>

                    <div class="ss-widget ss-filters-widget">
                        <div class="ss-widget-head">
                            <h2 class="ss-widget-title">فیلترها</h2>
                            <button type="button" class="ss-clear-all"<?php echo ($active_count === 0 && $state['sort'] === 'date') ? ' hidden' : ''; ?>>حذف همه</button>
                        </div>

                        <div class="ss-toggles">
                            <label class="ss-toggle">
                                <span class="ss-toggle-label">فقط کالاهای موجود</span>
                                <span class="ss-toggle-switch">
                                    <input type="checkbox" class="ss-filter-stock" name="ss_stock" value="1"<?php checked($state['in_stock']); ?>>
                                    <span class="ss-slider"></span>
                                </span>
                            </label>
                            <label class="ss-toggle">
                                <span class="ss-toggle-label">فروش ویژه</span>
                                <span class="ss-toggle-switch">
                                    <input type="checkbox" class="ss-filter-sale" name="ss_sale" value="1"<?php checked($state['on_sale']); ?>>
                                    <span class="ss-slider"></span>
                                </span>
                            </label>
                        </div>

                        <div class="ss-dynamic-filters">
                            <?php if ($all_categories) :
                                $cat_open = true;
                                ?>
                                <div class="ss-accordion<?php echo $cat_open ? ' active' : ''; ?>" data-filter="category">
                                    <button type="button" class="ss-acc-header" id="<?php echo esc_attr($uid); ?>-acc-cat-btn"
                                        aria-expanded="<?php echo $cat_open ? 'true' : 'false'; ?>"
                                        aria-controls="<?php echo esc_attr($uid); ?>-acc-cat">
                                        <span>دسته‌بندی‌ها</span>
                                        <span class="ss-acc-meta">
                                            <span class="ss-acc-badge"><?php echo miran_suite_shop_fa_number(count($all_categories)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- digits only. ?></span>
                                            <span class="ss-acc-icon" aria-hidden="true">▼</span>
                                        </span>
                                    </button>
                                    <div class="ss-acc-content" id="<?php echo esc_attr($uid); ?>-acc-cat"
                                        role="region" aria-labelledby="<?php echo esc_attr($uid); ?>-acc-cat-btn">
                                        <?php echo $render_category_rows(0, 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building. ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($attribute_groups as $group) :
                                $selected_terms = isset($state['attributes'][$group['taxonomy']]) ? $state['attributes'][$group['taxonomy']] : array();
                                $is_open = !empty($selected_terms);
                                $acc_id = $uid . '-acc-' . sanitize_html_class($group['taxonomy']);
                                ?>
                                <div class="ss-accordion<?php echo $is_open ? ' active' : ''; ?>" data-filter="attribute">
                                    <button type="button" class="ss-acc-header" id="<?php echo esc_attr($acc_id); ?>-btn"
                                        aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>"
                                        aria-controls="<?php echo esc_attr($acc_id); ?>">
                                        <span><?php echo esc_html($group['label']); ?></span>
                                        <span class="ss-acc-meta">
                                            <span class="ss-acc-badge"><?php echo miran_suite_shop_fa_number(count($group['terms'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- digits only. ?></span>
                                            <span class="ss-acc-icon" aria-hidden="true">▼</span>
                                        </span>
                                    </button>
                                    <div class="ss-acc-content" id="<?php echo esc_attr($acc_id); ?>"
                                        role="region" aria-labelledby="<?php echo esc_attr($acc_id); ?>-btn">
                                        <?php foreach ($group['terms'] as $term) : ?>
                                            <label class="ss-checkbox-row">
                                                <input type="checkbox" class="ss-attr-check"
                                                    name="ss_attr[<?php echo esc_attr($group['taxonomy']); ?>][]"
                                                    value="<?php echo esc_attr($term->slug); ?>"
                                                    data-type="attribute"
                                                    data-taxonomy="<?php echo esc_attr($group['taxonomy']); ?>"
                                                    <?php checked(in_array($term->slug, $selected_terms, true)); ?>>
                                                <span><?php echo esc_html($term->name); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="ss-drawer-apply">مشاهده نتایج</button>
                </aside>

                <div class="ss-main">
                    <h2 class="ss-sr-only">محصولات</h2>
                    <div class="ss-top-bar">
                        <div class="ss-sort-wrapper">
                            <span class="ss-sort-label">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path d="M3 7H21M6 12H18M10 17H14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                مرتب‌سازی:
                            </span>
                            <div class="ss-sort-options">
                                <?php foreach ($sort_options as $value => $label) : ?>
                                    <button type="submit" name="ss_sort" value="<?php echo esc_attr($value); ?>"
                                        data-sort="<?php echo esc_attr($value); ?>"
                                        class="<?php echo $state['sort'] === $value ? 'active' : ''; ?>"
                                        aria-pressed="<?php echo $state['sort'] === $value ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="button" class="ss-mobile-filter-btn"
                            aria-expanded="false" aria-controls="<?php echo esc_attr($uid); ?>-sidebar">
                            فیلترها
                            <span class="ss-filter-count"<?php echo $active_count === 0 ? ' hidden' : ''; ?>><?php echo miran_suite_shop_fa_number($active_count); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- digits only. ?></span>
                        </button>
                    </div>

                    <div class="ss-grid">
                        <?php
                        if ($result['products']) {
                            foreach ($result['products'] as $index => $product) {
                                echo miran_suite_shop_render_card($product, $index); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
                            }
                        } else {
                            ?>
                            <div class="ss-error-msg" style="color:var(--srv-gray);border-color:var(--srv-border)">
                                <div>هیچ محصولی با این فیلترها یافت نشد.</div>
                                <a class="ss-inline-btn" href="<?php echo esc_url($shop_url); ?>">حذف همه فیلترها</a>
                            </div>
                            <?php
                        }
                        ?>
                    </div>

                    <div class="ss-pagination">
                        <button type="button" class="ss-load-more" hidden>مشاهده محصولات بیشتر</button>
                        <?php if ($result['total_pages'] > 1) :
                            $page_base = str_replace(
                                '999999999',
                                '%#%',
                                miran_suite_shop_build_url($state, $shop_url, 999999999)
                            );
                            $pagination_html = paginate_links(array(
                                'base'      => $page_base,
                                'format'    => '',
                                'total'     => $result['total_pages'],
                                'current'   => $state['page'],
                                'prev_text' => 'قبلی',
                                'next_text' => 'بعدی',
                                'type'      => 'plain',
                            ));
                            ?>
                            <?php // Visible UI is "Load more" only; crawlable numbered
                            // pagination stays available for no-JS visitors and in the
                            // raw HTML for crawlers, so removing it visually costs no SEO. ?>
                            <noscript>
                                <nav class="ss-pagination-nav" aria-label="صفحه‌بندی محصولات">
                                    <?php echo wp_kses_post($pagination_html); ?>
                                </nav>
                            </noscript>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <div class="ss-drawer-overlay"></div>
        <div class="ss-live-region ss-sr-only" role="status" aria-live="polite"></div>

        <?php
        // ItemList structured data for the products visible on this page.
        if ($result['products']) {
            $items = array();
            $offset = ($state['page'] - 1) * $per_page;
            foreach ($result['products'] as $index => $product) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $offset + $index + 1,
                    'name'     => $product->get_name(),
                    'url'      => get_permalink($product->get_id()),
                );
            }
            $schema = array(
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => $heading,
                'numberOfItems'   => $result['total'],
                'itemListElement' => $items,
            );
            echo '<script type="application/ld+json">'
                . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>';
        }
        ?>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('miran_shop', 'miran_suite_shop_render');
// Legacy alias: pages built before the rename keep working untouched.
add_shortcode('serviya_shop', 'miran_suite_shop_render');
