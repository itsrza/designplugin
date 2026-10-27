<?php
/**
 * Serviya suite — single-post (article) module.
 *
 * Renders a complete, Serviya-styled single blog article:
 * breadcrumb, category chips, H1 title, author/date/reading-time/comments
 * meta, featured image, the native post content, tags, share row, author
 * box, previous/next links, the native comments template, a left sidebar
 * (table of contents, recent posts, categories, tags, consultation CTA)
 * and an "other articles" section.
 *
 * Everything editorial stays native WordPress: the_content filters,
 * comments_template(), categories/tags and permalinks are untouched, so
 * SEO plugins, comment plugins and shortcodes inside the article keep
 * working exactly as before.
 *
 * Usage: [serviya_post] inside a blank single-post template (Elementor
 * Theme Builder → Single Post, or a child-theme single.php), or
 * [serviya_post id="123"] anywhere.
 *
 * @package Serviya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ assets */

function serviya_suite_post_needs_assets(): bool {
	// is_singular('post') on purpose: plain is_single() is also true on
	// WooCommerce product pages and loaded the article assets there.
	$need = function_exists( 'is_singular' ) && is_singular( 'post' );
	if ( ! $need ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 ) {
			$content = (string) get_post_field( 'post_content', $post_id )
				. (string) get_post_meta( $post_id, '_elementor_data', true );
			$need    = false !== strpos( $content, 'serviya_post' );
		}
	}
	/** Filter: force-load (or skip) the article assets on the current page. */
	return (bool) apply_filters( 'serviya_post_needs_assets', $need );
}

function serviya_suite_post_enqueue_assets(): void {
	$base    = SERVIYA_PATH;
	$url     = SERVIYA_URL;
	$use_min = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	$css_rel = 'assets/css/serviya-post' . ( $use_min && file_exists( $base . 'assets/css/serviya-post.min.css' ) ? '.min' : '' ) . '.css';
	$js_rel  = 'assets/js/serviya-post' . ( $use_min && file_exists( $base . 'assets/js/serviya-post.min.js' ) ? '.min' : '' ) . '.js';

	if ( file_exists( $base . $css_rel ) ) {
		wp_enqueue_style(
			'serviya-post',
			$url . $css_rel,
			array(),
			(string) filemtime( $base . $css_rel )
		);
	}
	if ( file_exists( $base . $js_rel ) ) {
		$script_args = true;
		if ( version_compare( (string) get_bloginfo( 'version' ), '6.3', '>=' ) ) {
			$script_args = array(
				'in_footer' => true,
				'strategy'  => 'defer',
			);
		}
		wp_enqueue_script(
			'serviya-post',
			$url . $js_rel,
			array(),
			(string) filemtime( $base . $js_rel ),
			$script_args
		);
	}
}

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( serviya_suite_post_needs_assets() ) {
			serviya_suite_post_enqueue_assets();
		}
	},
	100
);

/* ----------------------------------------------------------------- helpers */

/** Persian digits, no thousand grouping (dates, phone numbers). */
function serviya_suite_post_fa_digits( $value ): string {
	$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	return str_replace( $en, $fa, (string) $value );
}

/** Persian digits with thousand grouping (counts). */
function serviya_suite_post_fa_number( $value ): string {
	return serviya_suite_post_fa_digits( number_format( (float) $value, 0, '.', ',' ) );
}

/** Plain text of any HTML/shortcode content. */
function serviya_suite_post_plain_text( string $html ): string {
	if ( function_exists( 'strip_shortcodes' ) ) {
		$html = strip_shortcodes( $html );
	}
	$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
	return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
}

/** Unicode-safe truncation (a byte substr() could split Persian letters). */
function serviya_suite_post_trunc( string $text, int $length ): string {
	$text   = serviya_suite_post_plain_text( $text );
	$length = max( 1, $length );
	$count  = preg_match_all( '/./us', $text );
	if ( false !== $count ) {
		if ( $count > $length && preg_match( '/^.{0,' . $length . '}/us', $text, $match ) ) {
			return rtrim( $match[0] ) . '…';
		}
		return $text;
	}
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
		return rtrim( mb_substr( $text, 0, $length ) ) . '…';
	}
	return $text;
}

/** Estimated reading time in minutes (≈220 words per minute). */
function serviya_suite_post_read_minutes( WP_Post $post ): int {
	$words = preg_match_all( '/\S+/u', serviya_suite_post_plain_text( (string) $post->post_content ) );
	return max( 1, (int) round( ( (int) $words ) / 220 ) );
}

function serviya_suite_post_capture( callable $callback ): string {
	$level = ob_get_level();
	ob_start();
	try {
		$callback();
		return (string) ob_get_clean();
	} catch ( Throwable $error ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		throw $error;
	}
}

/** Resolve the post this shortcode should render. */
function serviya_suite_post_resolve_id( int $requested ): int {
	if ( $requested > 0 ) {
		return $requested;
	}
	$queried = (int) get_queried_object_id();
	if ( $queried > 0 && 'post' === get_post_type( $queried ) ) {
		return $queried;
	}
	global $post;
	if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
		return (int) $post->ID;
	}
	return 0;
}

/** Blog index URL: explicit attribute → posts page → /blog/. */
function serviya_suite_post_blog_url( string $custom ): string {
	if ( '' !== trim( $custom ) ) {
		return trim( $custom );
	}
	$posts_page = (int) get_option( 'page_for_posts' );
	if ( $posts_page > 0 ) {
		$link = get_permalink( $posts_page );
		if ( is_string( $link ) && '' !== $link ) {
			return $link;
		}
	}
	return home_url( '/blog/' );
}

/** Link to the Serviya blog index filtered by one category. */
function serviya_suite_post_category_url( WP_Term $term, string $blog_url ): string {
	return function_exists( 'add_query_arg' )
		? (string) add_query_arg( 'sb_cat', $term->slug, $blog_url )
		: $blog_url . '?sb_cat=' . rawurlencode( $term->slug );
}

/** @return WP_Term[] */
function serviya_suite_post_categories( WP_Post $post ): array {
	$terms = get_the_category( $post->ID );
	return is_array( $terms ) ? $terms : array();
}

/** Small inline stroke icons (no icon-font dependency). */
function serviya_suite_post_icon( string $name, int $size = 15 ): string {
	$paths = array(
		'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
		'clock'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'comment'  => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
		'refresh'  => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
		'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		'doc'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
		'tag'      => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
		'send'     => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
		'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
}

/* ---------------------------------------------------------------- template */

function serviya_suite_post_breadcrumb( WP_Post $post, string $blog_url ): string {
	$items = array(
		array( 'label' => 'خانه', 'url' => home_url( '/' ) ),
		array( 'label' => 'مجله میران', 'url' => $blog_url ),
	);
	$categories = serviya_suite_post_categories( $post );
	if ( ! empty( $categories ) && $categories[0] instanceof WP_Term ) {
		$items[] = array(
			'label' => $categories[0]->name,
			'url'   => serviya_suite_post_category_url( $categories[0], $blog_url ),
		);
	}
	$items[] = array( 'label' => serviya_suite_post_trunc( get_the_title( $post ), 42 ), 'url' => '' );

	$html = '<nav class="svp-breadcrumb" aria-label="مسیر صفحه"><ol>';
	$last = count( $items ) - 1;
	foreach ( $items as $index => $item ) {
		$html .= '<li>';
		if ( '' !== $item['url'] && $index !== $last ) {
			$html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		} else {
			$html .= '<span' . ( $index === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $item['label'] ) . '</span>';
		}
		$html .= '</li>';
	}
	$html .= '</ol></nav>';
	return $html;
}

/** @return WP_Post[] */
function serviya_suite_post_query( array $args ): array {
	$posts = get_posts( array_merge(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		),
		$args
	) );
	return is_array( $posts ) ? $posts : array();
}

/** Card used in the "other articles" section. */
function serviya_suite_post_card( WP_Post $post, string $blog_url ): string {
	$link       = (string) get_permalink( $post );
	$title      = get_the_title( $post );
	$categories = serviya_suite_post_categories( $post );
	$category   = ! empty( $categories ) && $categories[0] instanceof WP_Term ? $categories[0] : null;

	$thumb_id = (int) get_post_thumbnail_id( $post );
	$image    = $thumb_id > 0
		? wp_get_attachment_image( $thumb_id, 'medium_large', false, array( 'loading' => 'lazy', 'alt' => $title ) )
		: '';
	if ( '' === (string) $image ) {
		$image = '<span class="svp-noimg">' . serviya_suite_post_icon( 'doc', 40 ) . '</span>';
	}

	return '<article class="svp-card">'
		. '<a class="svp-card-img" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . $image . '</a>'
		. '<div class="svp-card-body">'
		. '<div class="svp-card-meta">'
		. ( $category ? '<a class="svp-cat" href="' . esc_url( serviya_suite_post_category_url( $category, $blog_url ) ) . '">' . esc_html( $category->name ) . '</a>' : '' )
		. '<span>' . serviya_suite_post_icon( 'calendar', 13 ) . esc_html( serviya_suite_post_fa_digits( get_the_date( 'j F Y', $post ) ) ) . '</span>'
		. '<span>' . serviya_suite_post_icon( 'clock', 13 ) . esc_html( serviya_suite_post_fa_number( serviya_suite_post_read_minutes( $post ) ) ) . ' دقیقه مطالعه</span>'
		. '</div>'
		. '<h3><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h3>'
		. '<p>' . esc_html( serviya_suite_post_trunc( get_the_excerpt( $post ), 110 ) ) . '</p>'
		. '<a class="svp-card-read" href="' . esc_url( $link ) . '">مطالعه مقاله</a>'
		. '</div></article>';
}

/** Left sidebar: TOC, recent posts, categories, tags and a consult CTA. */
function serviya_suite_post_sidebar( WP_Post $post, string $blog_url, int $recent_count, bool $with_toc ): string {
	$html = '';

	if ( $with_toc ) {
		// Filled and unhidden by JS only when the article has >= 2 headings.
		$html .= '<div class="svp-widget svp-toc" data-svp-toc hidden>'
			. '<h3 class="svp-widget-title">فهرست مقاله</h3>'
			. '<nav aria-label="فهرست مقاله"><ol data-svp-toc-list></ol></nav></div>';
	}

	$recent = serviya_suite_post_query( array(
		'posts_per_page' => max( 1, $recent_count ),
		'post__not_in'   => array( (int) $post->ID ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	if ( ! empty( $recent ) ) {
		$html .= '<div class="svp-widget"><h3 class="svp-widget-title">نوشته‌های اخیر</h3><ul class="svp-recent">';
		foreach ( $recent as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}
			$thumb_id = (int) get_post_thumbnail_id( $item );
			$thumb    = $thumb_id > 0
				? wp_get_attachment_image( $thumb_id, 'thumbnail', false, array( 'loading' => 'lazy', 'alt' => '' ) )
				: '<span class="svp-noimg svp-noimg--sm">' . serviya_suite_post_icon( 'doc', 22 ) . '</span>';
			$html .= '<li><a href="' . esc_url( (string) get_permalink( $item ) ) . '">'
				. '<span class="svp-recent-thumb">' . $thumb . '</span>'
				. '<span class="svp-recent-text"><strong>' . esc_html( get_the_title( $item ) ) . '</strong>'
				. '<small>' . esc_html( serviya_suite_post_fa_digits( get_the_date( 'j F Y', $item ) ) ) . '</small></span>'
				. '</a></li>';
		}
		$html .= '</ul></div>';
	}

	$categories = get_categories( array(
		'taxonomy'   => 'category',
		'hide_empty' => true,
		'orderby'    => 'count',
		'order'      => 'DESC',
		'number'     => 12,
	) );
	if ( is_array( $categories ) && ! empty( $categories ) ) {
		$current_ids = array_map( static function ( $term ): int {
			return $term instanceof WP_Term ? (int) $term->term_id : 0;
		}, serviya_suite_post_categories( $post ) );
		$html .= '<div class="svp-widget"><h3 class="svp-widget-title">دسته‌بندی‌ها</h3><ul class="svp-cats">';
		foreach ( $categories as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$html .= '<li' . ( in_array( (int) $term->term_id, $current_ids, true ) ? ' class="is-current"' : '' ) . '>'
				. '<a href="' . esc_url( serviya_suite_post_category_url( $term, $blog_url ) ) . '">'
				. '<span>' . esc_html( $term->name ) . '</span>'
				. '<span class="svp-cat-count">' . esc_html( serviya_suite_post_fa_number( (int) $term->count ) ) . '</span>'
				. '</a></li>';
		}
		$html .= '</ul></div>';
	}

	$tags = get_tags( array( 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 15 ) );
	if ( is_array( $tags ) && ! empty( $tags ) ) {
		$html .= '<div class="svp-widget"><h3 class="svp-widget-title">برچسب‌ها</h3><div class="svp-tagcloud">';
		foreach ( $tags as $tag ) {
			if ( ! $tag instanceof WP_Term ) {
				continue;
			}
			$link = get_tag_link( $tag );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$html .= '<a href="' . esc_url( (string) $link ) . '">' . esc_html( $tag->name ) . '</a>';
		}
		$html .= '</div></div>';
	}

	return '<aside class="svp-sidebar">' . $html . '</aside>';
}

function serviya_suite_post_share_row( WP_Post $post ): string {
	$url   = (string) get_permalink( $post );
	$title = get_the_title( $post );
	$items = array(
		array(
			'label' => 'تلگرام',
			'href'  => 'https://t.me/share/url?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ),
			'icon'  => 'send',
		),
		array(
			'label' => 'واتس‌اپ',
			'href'  => 'https://wa.me/?text=' . rawurlencode( $title . ' ' . $url ),
			'icon'  => 'chat',
		),
		array(
			'label' => 'لینکدین',
			'href'  => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url ),
			'icon'  => 'link',
		),
	);

	$html = '<div class="svp-share"><span class="svp-share-label">اشتراک‌گذاری:</span>';
	foreach ( $items as $item ) {
		$html .= '<a class="svp-share-btn" href="' . esc_url( $item['href'] ) . '" target="_blank" rel="noopener" aria-label="' . esc_attr( 'اشتراک‌گذاری در ' . $item['label'] ) . '">'
			. serviya_suite_post_icon( $item['icon'], 16 ) . '<span>' . esc_html( $item['label'] ) . '</span></a>';
	}
	$html .= '<button type="button" class="svp-share-btn" data-svp-copy="' . esc_attr( $url ) . '">'
		. serviya_suite_post_icon( 'link', 16 ) . '<span data-svp-copy-label>کپی لینک</span></button>';
	$html .= '</div>';
	return $html;
}

function serviya_suite_post_author_box( WP_Post $post ): string {
	$author_id = (int) $post->post_author;
	if ( $author_id <= 0 ) {
		return '';
	}
	$name = (string) get_the_author_meta( 'display_name', $author_id );
	$bio  = trim( (string) get_the_author_meta( 'description', $author_id ) );
	if ( '' === $name ) {
		return '';
	}
	$avatar = function_exists( 'get_avatar' ) ? (string) get_avatar( $author_id, 96, '', $name, array( 'loading' => 'lazy' ) ) : '';
	$link   = (string) get_author_posts_url( $author_id );

	return '<div class="svp-author">'
		. ( '' !== $avatar ? '<span class="svp-author-avatar">' . $avatar . '</span>' : '' )
		. '<div class="svp-author-body"><span class="svp-author-role">نویسنده مقاله</span>'
		. '<strong>' . ( '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) ) . '</strong>'
		. ( '' !== $bio ? '<p>' . esc_html( $bio ) . '</p>' : '' )
		. '</div></div>';
}

function serviya_suite_post_prev_next(): string {
	$prev = get_previous_post();
	$next = get_next_post();
	if ( ! $prev && ! $next ) {
		return '';
	}
	$card = static function ( $target, string $label, string $class ): string {
		if ( ! $target instanceof WP_Post ) {
			return '<span class="svp-nav-spacer" aria-hidden="true"></span>';
		}
		return '<a class="svp-postnav-card ' . $class . '" href="' . esc_url( (string) get_permalink( $target ) ) . '">'
			. '<small>' . esc_html( $label ) . '</small><strong>' . esc_html( get_the_title( $target ) ) . '</strong></a>';
	};
	return '<nav class="svp-postnav" aria-label="مقاله‌های قبلی و بعدی">'
		. $card( $prev, 'مقاله قبلی', 'is-prev' )
		. $card( $next, 'مقاله بعدی', 'is-next' )
		. '</nav>';
}

/** @return WP_Post[] Related by category first, topped up with recent posts. */
function serviya_suite_post_related( WP_Post $post, int $count ): array {
	if ( $count <= 0 ) {
		return array();
	}
	$categories = serviya_suite_post_categories( $post );
	$cat_ids    = array();
	foreach ( $categories as $term ) {
		if ( $term instanceof WP_Term ) {
			$cat_ids[] = (int) $term->term_id;
		}
	}

	$related = array();
	if ( ! empty( $cat_ids ) ) {
		$related = serviya_suite_post_query( array(
			'posts_per_page' => $count,
			'post__not_in'   => array( (int) $post->ID ),
			'category__in'   => $cat_ids,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
	}
	if ( count( $related ) < $count ) {
		$exclude = array( (int) $post->ID );
		foreach ( $related as $item ) {
			if ( $item instanceof WP_Post ) {
				$exclude[] = (int) $item->ID;
			}
		}
		$more = serviya_suite_post_query( array(
			'posts_per_page' => $count - count( $related ),
			'post__not_in'   => $exclude,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$related = array_merge( $related, $more );
	}
	return $related;
}

function serviya_suite_post_render( $atts ): string {
	static $rendering = array();

	if ( ! function_exists( 'get_post' ) ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'id'         => 0,
			'sidebar'    => '1',
			'toc'        => '1',
			'share'      => '1',
			'author_box' => '1',
			'nav'        => '1',
			'related'    => 4,
			'recent'     => 5,
			'blog_url'   => '',
		),
		is_array( $atts ) ? $atts : array(),
		'serviya_post'
	);

	$post_id = serviya_suite_post_resolve_id( absint( $atts['id'] ) );
	if ( $post_id <= 0 || isset( $rendering[ $post_id ] ) ) {
		return '';
	}
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
		return '';
	}
	if ( 'publish' !== get_post_status( $post ) && ! current_user_can( 'edit_post', $post_id ) ) {
		return '';
	}
	if ( post_password_required( $post ) ) {
		return get_the_password_form( $post );
	}

	$rendering[ $post_id ] = true;
	$previous_post         = $GLOBALS['post'] ?? null;
	$GLOBALS['post']       = $post;
	setup_postdata( $post );

	try {
		serviya_suite_post_enqueue_assets();

		$blog_url     = serviya_suite_post_blog_url( (string) $atts['blog_url'] );
		$categories   = serviya_suite_post_categories( $post );
		$with_sidebar = '1' === (string) $atts['sidebar'];
		$minutes      = serviya_suite_post_read_minutes( $post );
		$comments_no  = (int) get_comments_number( $post );

		/* ---- header meta ---- */
		$author_id   = (int) $post->post_author;
		$author_name = $author_id > 0 ? (string) get_the_author_meta( 'display_name', $author_id ) : '';
		$author_link = $author_id > 0 ? (string) get_author_posts_url( $author_id ) : '';
		$avatar      = ( $author_id > 0 && function_exists( 'get_avatar' ) )
			? (string) get_avatar( $author_id, 44, '', $author_name, array( 'loading' => 'lazy' ) )
			: '';

		$meta = '<div class="svp-meta">';
		if ( '' !== $author_name ) {
			$meta .= '<span class="svp-meta-author">'
				. ( '' !== $avatar ? '<span class="svp-meta-avatar">' . $avatar . '</span>' : '' )
				. ( '' !== $author_link ? '<a href="' . esc_url( $author_link ) . '">' . esc_html( $author_name ) . '</a>' : esc_html( $author_name ) )
				. '</span>';
		}
		$meta .= '<span class="svp-meta-item">' . serviya_suite_post_icon( 'calendar' )
			. '<time datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( serviya_suite_post_fa_digits( get_the_date( 'j F Y', $post ) ) ) . '</time></span>';
		$published_ts = (int) get_the_time( 'U', $post );
		$modified_ts  = (int) get_the_modified_time( 'U', $post );
		if ( $modified_ts > $published_ts + 86400 ) {
			$meta .= '<span class="svp-meta-item">' . serviya_suite_post_icon( 'refresh' ) . 'به‌روزرسانی: '
				. '<time datetime="' . esc_attr( get_the_modified_date( 'c', $post ) ) . '">' . esc_html( serviya_suite_post_fa_digits( get_the_modified_date( 'j F Y', $post ) ) ) . '</time></span>';
		}
		$meta .= '<span class="svp-meta-item">' . serviya_suite_post_icon( 'clock' )
			. esc_html( serviya_suite_post_fa_number( $minutes ) ) . ' دقیقه مطالعه</span>';
		$meta .= '<span class="svp-meta-item">' . serviya_suite_post_icon( 'comment' )
			. esc_html( serviya_suite_post_fa_number( $comments_no ) ) . ' دیدگاه</span>';
		$meta .= '</div>';

		$chips = '';
		foreach ( $categories as $term ) {
			if ( $term instanceof WP_Term ) {
				$chips .= '<a class="svp-chip" href="' . esc_url( serviya_suite_post_category_url( $term, $blog_url ) ) . '">' . esc_html( $term->name ) . '</a>';
			}
		}
		if ( '' !== $chips ) {
			$chips = '<div class="svp-chips">' . $chips . '</div>';
		}

		$lead = '';
		if ( has_excerpt( $post ) ) {
			$lead = '<p class="svp-lead">' . esc_html( serviya_suite_post_plain_text( get_the_excerpt( $post ) ) ) . '</p>';
		}

		/* ---- featured image + body ---- */
		$figure   = '';
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id > 0 ) {
			$img = wp_get_attachment_image( $thumb_id, 'large', false, array( 'fetchpriority' => 'high', 'alt' => get_the_title( $post ) ) );
			if ( '' !== (string) $img ) {
				$caption = function_exists( 'wp_get_attachment_caption' ) ? trim( (string) wp_get_attachment_caption( $thumb_id ) ) : '';
				$figure  = '<figure class="svp-featured">' . $img
					. ( '' !== $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '' )
					. '</figure>';
			}
		}

		$content = (string) apply_filters( 'the_content', (string) $post->post_content );
		if ( function_exists( 'wp_link_pages' ) ) {
			$pages = wp_link_pages( array(
				'echo'             => 0,
				'before'           => '<nav class="svp-pages" aria-label="صفحه‌های مقاله">',
				'after'            => '</nav>',
				'pagelink'         => '%',
				'next_or_number'   => 'number',
				'separator'        => '',
				'link_before'      => '',
				'link_after'       => '',
				'nextpagelink'     => '',
				'previouspagelink' => '',
			) );
			if ( is_string( $pages ) && '' !== trim( $pages ) ) {
				$content .= $pages;
			}
		}

		/* ---- tags ---- */
		$tags_html = '';
		$post_tags = get_the_tags( $post->ID );
		if ( is_array( $post_tags ) && ! empty( $post_tags ) ) {
			$tags_html = '<div class="svp-tags"><span class="svp-tags-label">' . serviya_suite_post_icon( 'tag', 14 ) . 'برچسب‌ها:</span>';
			foreach ( $post_tags as $tag ) {
				if ( ! $tag instanceof WP_Term ) {
					continue;
				}
				$link = get_tag_link( $tag );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$tags_html .= '<a href="' . esc_url( (string) $link ) . '">' . esc_html( $tag->name ) . '</a>';
			}
			$tags_html .= '</div>';
		}

		/* ---- comments (native template) ---- */
		$comments_html = '';
		if ( comments_open( $post ) || $comments_no > 0 ) {
			$raw = serviya_suite_post_capture( static function (): void {
				comments_template();
			} );
			if ( '' !== trim( $raw ) ) {
				$comments_html = '<section class="svp-comments">' . $raw . '</section>';
			}
		}

		/* ---- assemble ---- */
		$article = '<article class="svp-article" id="post-' . esc_attr( (string) $post_id ) . '">'
			. $chips
			. '<h1 class="svp-title">' . esc_html( get_the_title( $post ) ) . '</h1>'
			. $lead
			. $meta
			. $figure
			. '<div class="svp-content">' . $content . '</div>'
			. $tags_html
			. ( '1' === (string) $atts['share'] ? serviya_suite_post_share_row( $post ) : '' )
			. ( '1' === (string) $atts['author_box'] ? serviya_suite_post_author_box( $post ) : '' )
			. ( '1' === (string) $atts['nav'] ? serviya_suite_post_prev_next() : '' )
			. $comments_html
			. '</article>';

		$sidebar = $with_sidebar
			? serviya_suite_post_sidebar(
				$post,
				$blog_url,
				max( 1, absint( $atts['recent'] ) ),
				'1' === (string) $atts['toc']
			)
			: '';

		$related_html = '';
		$related      = serviya_suite_post_related( $post, max( 0, min( 8, absint( $atts['related'] ) ) ) );
		if ( ! empty( $related ) ) {
			$cards = '';
			foreach ( $related as $item ) {
				if ( $item instanceof WP_Post ) {
					$cards .= serviya_suite_post_card( $item, $blog_url );
				}
			}
			if ( '' !== $cards ) {
				$related_html = '<section class="svp-related">'
					. '<div class="svp-related-head"><h2>مقالات دیگر</h2>'
					. '<a class="svp-related-all" href="' . esc_url( $blog_url ) . '">همه مقالات مجله میران</a></div>'
					. '<div class="svp-related-grid">' . $cards . '</div></section>';
			}
		}

		$html = '<section class="serviya-post" dir="rtl" data-serviya-post>'
			. '<div class="svp-progress" data-svp-progress aria-hidden="true"></div>'
			. '<div class="svp-container">'
			. serviya_suite_post_breadcrumb( $post, $blog_url )
			. '<div class="svp-layout' . ( $with_sidebar ? '' : ' svp-layout--wide' ) . '">'
			. $article
			. $sidebar
			. '</div>'
			. $related_html
			. '</div></section>';
	} finally {
		unset( $rendering[ $post_id ] );
		if ( $previous_post instanceof WP_Post ) {
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );
		} else {
			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		}
	}

	return $html ?? '';
}

add_shortcode( 'serviya_post', 'serviya_suite_post_render' );
