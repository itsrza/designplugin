<?php
/**
 * Miran — Blog module
 *
 * Loaded by the unified Miran plugin. Internal functions use the
 * miran_suite_ prefix so this module cannot collide with the former
 * standalone plugins during migration.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ==========================================================================
 * Assets — loaded only where the blog widget is shown, after the theme CSS
 * ========================================================================== */

function miran_suite_blog_needs_assets() {
    if (is_home()) {
        return true;
    }
    $object_id = get_queried_object_id();
    if ($object_id) {
        $post = get_post($object_id);
        if ($post && (has_shortcode($post->post_content, 'miran_blog') || has_shortcode($post->post_content, 'serviya_blog'))) {
            return true;
        }
        $elementor_data = get_post_meta($object_id, '_elementor_data', true);
        if (is_string($elementor_data) && (false !== strpos($elementor_data, 'miran_blog') || false !== strpos($elementor_data, 'serviya_blog'))) {
            return true;
        }
    }
    return (bool) apply_filters('miran_blog_needs_assets', false);
}

function miran_suite_blog_current_url() {
    if (is_home()) {
        $posts_page = (int) get_option('page_for_posts');
        if ($posts_page && get_permalink($posts_page)) {
            return get_permalink($posts_page);
        }
        return home_url('/');
    }
    $object_id = get_queried_object_id();
    if ($object_id && is_singular() && get_permalink($object_id)) {
        return get_permalink($object_id);
    }
    $archive = get_post_type_archive_link('post');
    return $archive ? $archive : home_url('/');
}

function miran_suite_blog_enqueue_assets() {
    $plugin_url  = MIRAN_URL;
    $plugin_path = MIRAN_PATH;

    $use_min  = !(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);
    $css_rel  = 'assets/css/miran-blog' . ($use_min && file_exists($plugin_path . 'assets/css/miran-blog.min.css') ? '.min.css' : '.css');
    $js_rel   = 'assets/js/miran-blog' . ($use_min && file_exists($plugin_path . 'assets/js/miran-blog.min.js') ? '.min.js' : '.js');
    $css_file = $plugin_path . $css_rel;
    $js_file  = $plugin_path . $js_rel;

    $css_version = file_exists($css_file) ? (string) filemtime($css_file) : MIRAN_VERSION;
    $js_version  = file_exists($js_file) ? (string) filemtime($js_file) : MIRAN_VERSION;

    if (!wp_style_is('miran-blog', 'enqueued')) {
        wp_enqueue_style('miran-blog', $plugin_url . $css_rel, array(), $css_version);
    }

    if (!wp_script_is('miran-blog', 'enqueued')) {
        if (version_compare(get_bloginfo('version'), '6.3', '>=')) {
            wp_enqueue_script('miran-blog', $plugin_url . $js_rel, array(), $js_version, array('in_footer' => true, 'strategy' => 'defer'));
        } else {
            wp_enqueue_script('miran-blog', $plugin_url . $js_rel, array(), $js_version, true);
        }

        wp_localize_script('miran-blog', 'MiranBlogData', array(
            'restBase' => esc_url_raw(rest_url('miran/v1/')),
            'pageUrl'  => miran_suite_blog_current_url(),
            'perPage'  => (int) apply_filters('miran_blog_per_page', 9),
        ));
    }
}

add_action('wp_enqueue_scripts', function () {
    if (miran_suite_blog_needs_assets()) {
        miran_suite_blog_enqueue_assets();
    }
}, 100);

/* Filtered/searched variants of the blog page: noindex, follow. */
add_filter('wp_robots', function ($robots) {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only state.
    $get = isset($_GET) ? wp_unslash($_GET) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    // phpcs:enable
    foreach (array_keys((array) $get) as $key) {
        if (strpos((string) $key, 'sb_') === 0) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
            unset($robots['index']);
            break;
        }
    }
    return $robots;
});

/* ==========================================================================
 * State + query (shared by SSR and the REST endpoint)
 * ========================================================================== */

/**
 * Sanitize one category slug without ever leaking a non-scalar value.
 *
 * WordPress calls a REST sanitize_callback as ($value, $request, $key).
 * Passing sanitize_title directly is unsafe: its second argument is the
 * fallback title, so an empty category would "sanitize" to the
 * WP_REST_Request object itself and a later (string) cast would fatal.
 */
function miran_suite_blog_sanitize_slug($value) {
    return is_scalar($value) ? sanitize_title((string) $value) : '';
}

function miran_suite_blog_parse_state($source = null) {
    if ($source === null) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter state.
        $source = isset($_GET) ? wp_unslash($_GET) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable
        $cat    = isset($source['sb_cat']) ? $source['sb_cat'] : '';
        $search = isset($source['sb_q']) ? $source['sb_q'] : '';
        $page   = isset($source['sb_page']) ? $source['sb_page'] : 1;
    } else {
        $cat    = isset($source['cat']) ? $source['cat'] : '';
        $search = isset($source['q']) ? $source['q'] : '';
        $page   = isset($source['page']) ? $source['page'] : 1;
    }

    // Query params can be arrays/objects in malformed requests; only scalar
    // filter values are meaningful here and safe to sanitize/cast.
    if (!is_scalar($cat)) {
        $cat = '';
    }
    if (!is_scalar($search)) {
        $search = '';
    }
    if (!is_scalar($page)) {
        $page = 1;
    }

    return array(
        'cat'    => miran_suite_blog_sanitize_slug($cat),
        'search' => sanitize_text_field((string) $search),
        'page'   => max(1, absint($page)),
    );
}

function miran_suite_blog_build_url($state, $base_url, $page = 0, $cat_override = null) {
    $args = array();
    $cat  = $cat_override !== null ? $cat_override : $state['cat'];
    if ($cat !== '') {
        $args['sb_cat'] = $cat;
    }
    if ($state['search'] !== '') {
        $args['sb_q'] = $state['search'];
    }
    if ($page > 1) {
        $args['sb_page'] = (string) $page;
    }
    return $args ? add_query_arg($args, $base_url) : $base_url;
}

function miran_suite_blog_query_posts($state, $per_page) {
    $args = array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'posts_per_page'      => $per_page,
        'paged'               => $state['page'],
        'ignore_sticky_posts' => true,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'no_found_rows'       => false,
    );
    if ($state['search'] !== '') {
        $args['s'] = $state['search'];
    }
    if ($state['cat'] !== '') {
        $args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            array(
                'taxonomy' => 'category',
                'field'    => 'slug',
                'terms'    => array($state['cat']),
            ),
        );
    }

    $query = new WP_Query($args);
    return array(
        'posts'       => $query->posts,
        'total'       => (int) $query->found_posts,
        'total_pages' => max(1, (int) $query->max_num_pages),
    );
}

/* ==========================================================================
 * Small helpers
 * ========================================================================== */

function miran_suite_blog_fa_digits($value) {
    return strtr((string) $value, array(
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ));
}

function miran_suite_blog_fa_number($value) {
    return miran_suite_blog_fa_digits(number_format((float) $value, 0, '.', ','));
}

function miran_suite_blog_trunc($text, $length) {
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
    $length = max(1, (int) $length);

    // Unicode-safe truncation that also works when mbstring is missing:
    // a byte-based substr() could split a Persian character in half and the
    // resulting invalid UTF-8 would be rendered as an empty string.
    $count = preg_match_all('/./us', $text);
    if ($count !== false) {
        if ($count > $length && preg_match('/^.{0,' . $length . '}/us', $text, $match)) {
            return rtrim($match[0]) . '…';
        }
        return $text;
    }

    if (function_exists('mb_strlen') && mb_strlen($text) > $length) {
        return rtrim(mb_substr($text, 0, $length)) . '…';
    }
    return $text;
}

function miran_suite_blog_read_minutes($post) {
    $words = preg_match_all('/\S+/u', wp_strip_all_tags((string) $post->post_content));
    return max(1, (int) round(((int) $words) / 220));
}

function miran_suite_blog_first_category($post) {
    $terms = get_the_category($post->ID);
    if ($terms && !is_wp_error($terms)) {
        return $terms[0];
    }
    return null;
}

function miran_suite_blog_icon($name, $size) {
    return '<svg width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true"><use href="#bi-' . esc_attr($name) . '"/></svg>';
}

/* Slim DTO for the REST endpoint — only what one card needs. */
function miran_suite_blog_post_dto($post) {
    $category = miran_suite_blog_first_category($post);
    $image    = null;
    $image_id = get_post_thumbnail_id($post->ID);
    if ($image_id) {
        $src_data = wp_get_attachment_image_src($image_id, 'medium_large');
        $alt      = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        if ($src_data) {
            $image = array(
                'src'    => $src_data[0],
                'srcset' => (string) wp_get_attachment_image_srcset($image_id, 'medium_large'),
                'alt'    => $alt !== '' ? $alt : get_the_title($post),
                'width'  => (int) $src_data[1],
                'height' => (int) $src_data[2],
            );
        }
    }

    return array(
        'id'           => (int) $post->ID,
        'title'        => get_the_title($post),
        'link'         => get_permalink($post),
        'date_fa'      => miran_suite_blog_fa_digits(get_the_date('j F Y', $post)),
        'excerpt'      => miran_suite_blog_trunc(get_the_excerpt($post), 220),
        'read_minutes' => miran_suite_blog_read_minutes($post),
        'category'     => $category ? array(
            'id'   => (int) $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
        ) : null,
        'image'        => $image,
    );
}

/* ==========================================================================
 * Slim REST endpoint: GET /wp-json/miran/v1/posts
 * ========================================================================== */

add_action('rest_api_init', function () {
    // Registered under both namespaces: cached JS from before the rename keeps
    // working, and the new build talks to miran/v1.
    foreach (array('miran/v1', 'serviya/v1') as $miran_rest_namespace) {
        register_rest_route($miran_rest_namespace, '/posts', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'miran_suite_blog_rest_posts',
            'args'                => array(
                'page'     => array('type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint'),
                'per_page' => array('type' => 'integer', 'default' => 9, 'sanitize_callback' => 'absint'),
                'cat'      => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'miran_suite_blog_sanitize_slug'),
                'q'        => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));
    }
});

function miran_suite_blog_rest_posts($request) {
    $state    = miran_suite_blog_parse_state(array(
        'page' => $request->get_param('page'),
        'cat'  => $request->get_param('cat'),
        'q'    => $request->get_param('q'),
    ));
    $per_page = min(24, max(1, absint($request->get_param('per_page'))));
    $result   = miran_suite_blog_query_posts($state, $per_page);

    $posts = array_map('miran_suite_blog_post_dto', $result['posts']);
    $response = rest_ensure_response($posts);
    $response->header('X-WP-Total', (string) $result['total']);
    $response->header('X-WP-TotalPages', (string) $result['total_pages']);
    return $response;
}

/* ==========================================================================
 * Card rendering — same markup/classes as the JS-rendered cards
 * ========================================================================== */

function miran_suite_blog_image_html($post, $featured) {
    $image_id = get_post_thumbnail_id($post->ID);
    if ($image_id) {
        $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        $attrs = array(
            'alt'      => $alt !== '' ? $alt : get_the_title($post),
            'loading'  => $featured ? 'eager' : 'lazy',
            'decoding' => 'async',
            'sizes'    => $featured
                ? '(max-width: 768px) 100vw, 560px'
                : '(max-width: 768px) 100vw, (max-width: 1100px) 50vw, 380px',
        );
        if ($featured) {
            $attrs['fetchpriority'] = 'high';
        }
        $html = wp_get_attachment_image($image_id, 'medium_large', false, $attrs);
        if ($html !== '') {
            return $html;
        }
    }
    return '<div class="b-noimg">' . miran_suite_blog_icon('doc', $featured ? 46 : 40) . '</div>';
}

function miran_suite_blog_meta_html($post, $with_category) {
    $html = '';
    $category = miran_suite_blog_first_category($post);
    if ($with_category && $category) {
        $html .= '<span class="b-cat">' . esc_html($category->name) . '</span>';
    }
    $html .= '<span><svg width="13" height="13" style="vertical-align:-2px;color:#96632E" aria-hidden="true"><use href="#bi-cal"/></svg> '
        . esc_html(miran_suite_blog_fa_digits(get_the_date('j F Y', $post))) . '</span>';
    $html .= '<span><svg width="13" height="13" style="vertical-align:-2px;color:#96632E" aria-hidden="true"><use href="#bi-clock"/></svg> '
        . miran_suite_blog_fa_number(miran_suite_blog_read_minutes($post)) . ' دقیقه مطالعه</span>';
    return $html;
}

function miran_suite_blog_render_featured($post) {
    $link  = get_permalink($post);
    $title = get_the_title($post);
    return '<article class="b-feat b-r b-in">'
        . '<div class="b-feat-img">'
        . '<span class="b-feat-badge">' . miran_suite_blog_icon('star', 12) . 'مقاله ویژه</span>'
        . miran_suite_blog_image_html($post, true)
        . '</div>'
        . '<div class="b-feat-body">'
        . '<div class="b-feat-meta">' . miran_suite_blog_meta_html($post, true) . '</div>'
        . '<h2><a href="' . esc_url($link) . '">' . esc_html($title) . '</a></h2>'
        . '<p>' . esc_html(miran_suite_blog_trunc(get_the_excerpt($post), 180)) . '</p>'
        . '<a class="b-feat-link" href="' . esc_url($link) . '">ادامه مطلب ' . miran_suite_blog_icon('arrow', 16) . '</a>'
        . '</div></article>';
}

function miran_suite_blog_render_card($post, $index = 0) {
    $link     = get_permalink($post);
    $title    = get_the_title($post);
    $category = miran_suite_blog_first_category($post);
    $badge    = $category ? '<span class="b-card-cat">' . esc_html($category->name) . '</span>' : '';

    return '<article class="b-card b-r b-in" style="transition-delay:' . (int) ($index * 60) . 'ms">'
        . '<a href="' . esc_url($link) . '" class="b-card-img" aria-label="' . esc_attr($title) . '">'
        . $badge . miran_suite_blog_image_html($post, false)
        . '</a>'
        . '<div class="b-card-body">'
        . '<div class="b-card-meta">' . miran_suite_blog_meta_html($post, false) . '</div>'
        . '<h3><a href="' . esc_url($link) . '">' . esc_html($title) . '</a></h3>'
        . '<p>' . esc_html(miran_suite_blog_trunc(get_the_excerpt($post), 110)) . '</p>'
        . '<div class="b-card-foot">'
        . '<a class="b-card-read" href="' . esc_url($link) . '">مطالعه مقاله</a>'
        . '<a class="b-card-ar" href="' . esc_url($link) . '" aria-label="مطالعه">' . miran_suite_blog_icon('arrow', 14) . '</a>'
        . '</div></div></article>';
}

function miran_suite_blog_svg_defs() {
    return '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>'
        . '<symbol id="bi-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol>'
        . '<symbol id="bi-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>'
        . '<symbol id="bi-cal" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></symbol>'
        . '<symbol id="bi-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></symbol>'
        . '<symbol id="bi-star" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18.5 5.5 22 7 14.5 2 9.5 9 9 12 2"/></symbol>'
        . '<symbol id="bi-doc" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol>'
        . '<symbol id="bi-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.58 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>'
        . '<symbol id="bi-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/></symbol>'
        . '</defs></svg>';
}

/* ==========================================================================
 * Shortcode
 * ========================================================================== */

function miran_suite_blog_render($atts) {
    miran_suite_blog_enqueue_assets();

    $shop_link = function_exists('wc_get_page_permalink') && wc_get_page_permalink('shop')
        ? wc_get_page_permalink('shop')
        : home_url('/shop/');

    $atts = shortcode_atts(array(
        'per_page'     => (int) apply_filters('miran_blog_per_page', 9),
        'phone'        => '09382420272',
        'products_url' => $shop_link,
    ), $atts, 'miran_blog');

    $per_page = min(24, max(1, absint($atts['per_page'])));
    $state    = miran_suite_blog_parse_state();
    $result   = miran_suite_blog_query_posts($state, $per_page);
    $page_url = miran_suite_blog_current_url();
    $uid      = wp_unique_id('sb-');

    $categories = get_categories(array(
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
        'number'     => 20,
    ));
    if (is_wp_error($categories)) {
        $categories = array();
    }

    $posts        = $result['posts'];
    $show_featured = $state['page'] === 1 && $state['cat'] === '' && $state['search'] === '' && !empty($posts);
    $grid_posts   = $show_featured ? array_slice($posts, 1) : $posts;

    $ssr_state = array(
        'cat'    => $state['cat'],
        'search' => $state['search'],
        'page'   => $state['page'],
    );

    ob_start();
    ?>
    <div class="miran-blog" dir="rtl" lang="fa"
        data-current-page="<?php echo esc_attr($state['page']); ?>"
        data-total-pages="<?php echo esc_attr($result['total_pages']); ?>"
        data-per-page="<?php echo esc_attr($per_page); ?>"
        data-ssr-state="<?php echo esc_attr(wp_json_encode($ssr_state, JSON_UNESCAPED_UNICODE)); ?>">

        <?php echo miran_suite_blog_svg_defs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG symbols. ?>

        <form class="b-form" method="get" action="<?php echo esc_url($page_url); ?>">
            <button type="submit" class="b-sr-only" tabindex="-1" aria-hidden="true">جستجو</button>

            <section class="b-hero">
                <div class="b-hero-glow" aria-hidden="true"></div>
                <div class="bw b-hero-in">
                    <span class="b-chip"><i></i>مجله میران</span>
                    <h1>مقالات و راهنمای <em>بسته‌بندی و چاپ اختصاصی</em></h1>
                    <p>آخرین مطالب، نکات کاربردی و راهنماهای تخصصی برای انتخاب بسته‌بندی مناسب کسب‌وکار شما.</p>
                    <div class="b-search">
                        <label class="b-sr-only" for="<?php echo esc_attr($uid); ?>-search">جستجو در مقالات</label>
                        <input type="text" inputmode="search" enterkeyhint="search" class="b-search-input"
                            id="<?php echo esc_attr($uid); ?>-search" name="sb_q"
                            value="<?php echo esc_attr($state['search']); ?>"
                            placeholder="جستجو در مقالات..." aria-label="جستجو در مقالات" autocomplete="off">
                        <svg width="20" height="20" aria-hidden="true"><use href="#bi-search"/></svg>
                    </div>
                </div>
            </section>

            <section class="b-main">
                <div class="bw">
                    <div class="b-filters" role="group" aria-label="فیلتر دسته‌بندی مقالات">
                        <a class="b-fchip" href="<?php echo esc_url(miran_suite_blog_build_url($state, $page_url, 0, '')); ?>"
                            data-cat-slug="" aria-pressed="<?php echo $state['cat'] === '' ? 'true' : 'false'; ?>">همه مقالات</a>
                        <?php foreach ($categories as $category) :
                            $is_active = $state['cat'] === $category->slug;
                            ?>
                            <a class="b-fchip" href="<?php echo esc_url(miran_suite_blog_build_url($state, $page_url, 0, $category->slug)); ?>"
                                data-cat-slug="<?php echo esc_attr($category->slug); ?>"
                                aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
                                <?php echo $is_active ? 'aria-current="true"' : ''; ?>><?php echo esc_html($category->name); ?> <small>(<?php echo miran_suite_blog_fa_number($category->count); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- digits only. ?>)</small></a>
                        <?php endforeach; ?>
                    </div>

                    <div class="b-featured-slot">
                        <?php
                        if ($show_featured) {
                            echo miran_suite_blog_render_featured($posts[0]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
                        }
                        ?>
                    </div>

                    <div class="b-grid">
                        <?php
                        if ($grid_posts) {
                            foreach ($grid_posts as $index => $post) {
                                echo miran_suite_blog_render_card($post, $index); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
                            }
                        } elseif (!$show_featured) {
                            ?>
                            <div class="b-empty" style="grid-column:1/-1">
                                <div class="b-empty-ic"><?php echo miran_suite_blog_icon('doc', 30); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></div>
                                <h3>مقاله‌ای یافت نشد</h3>
                                <p>با فیلتر یا جستجوی دیگری امتحان کنید.</p>
                                <a class="b-reset-btn" href="<?php echo esc_url($page_url); ?>">بازنشانی فیلترها</a>
                            </div>
                            <?php
                        }
                        ?>
                    </div>

                    <div class="b-more-wrap" hidden>
                        <button type="button" class="b-more">
                            <span class="b-sp" aria-hidden="true"></span>
                            <span class="b-txt">نمایش مقالات بیشتر</span>
                            <svg width="17" height="17" aria-hidden="true"><use href="#bi-arrow"/></svg>
                        </button>
                    </div>

                    <?php if ($result['total_pages'] > 1) :
                        $page_base = str_replace(
                            '999999999',
                            '%#%',
                            miran_suite_blog_build_url($state, $page_url, 999999999)
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
                        <noscript>
                            <nav class="b-pagination-nav" aria-label="صفحه‌بندی مقالات">
                                <?php echo wp_kses_post($pagination_html); ?>
                            </nav>
                        </noscript>
                    <?php endif; ?>
                </div>
            </section>
        </form>

        <section class="b-cta">
            <div class="bw b-cta-in">
                <h2>به دنبال بسته‌بندی مناسب <em>کسب‌وکار خود هستید؟</em></h2>
                <p>با مشاوران میران در تماس باشید؛ راهنمایی رایگان برای انتخاب جنس، سایز و چاپ اختصاصی.</p>
                <div class="b-cta-btns">
                    <a class="b-btn b-btn--g" href="tel:+98<?php echo esc_attr(ltrim(sanitize_text_field($atts['phone']), '0')); ?>"><svg width="16" height="16" aria-hidden="true"><use href="#bi-phone"/></svg>تماس با میران</a>
                    <a class="b-btn b-btn--w" href="<?php echo esc_url($atts['products_url']); ?>"><svg width="16" height="16" aria-hidden="true"><use href="#bi-box"/></svg>مشاهده محصولات</a>
                </div>
            </div>
        </section>

        <div class="b-live-region b-sr-only" role="status" aria-live="polite"></div>

        <?php
        if ($posts) {
            $items  = array();
            $offset = ($state['page'] - 1) * $per_page;
            foreach ($posts as $index => $post) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $offset + $index + 1,
                    'name'     => get_the_title($post),
                    'url'      => get_permalink($post),
                );
            }
            $schema = array(
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => 'مجله میران',
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

add_shortcode('miran_blog', 'miran_suite_blog_render');
// Legacy alias for pages built before the rename.
add_shortcode('serviya_blog', 'miran_suite_blog_render');
