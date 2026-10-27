<?php
/**
 * Serviya suite — single-product module.
 *
 * Renders a complete, Serviya-styled single-product view through the
 * canonical WooCommerce hooks. Gallery, title, rating, price, short
 * description, the add-to-cart/variation form, meta, tabs (description /
 * additional information / reviews), upsells and related products are all
 * produced by WooCommerce and the active theme (WoodMart), so native
 * features keep working untouched: product types (simple, variable —
 * including WoodMart swatches and variation galleries — grouped,
 * external), sale schedule + WoodMart countdown (with a plugin fallback),
 * stock management + stock-progress bar, sold counter, compare, wishlist,
 * buy-now, dynamic discounts, Frequently Bought Together, role-based pricing
 * plugins, size guides, estimated delivery, waitlist, custom tabs, etc.
 *
 * Usage: [serviya_product] inside a blank single-product template
 * (WoodMart/Elementor Theme Builder), or [serviya_product id="123"]
 * anywhere.
 *
 * @package Serviya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ assets */

function serviya_suite_product_needs_assets(): bool {
	$need = function_exists( 'is_product' ) && is_product();
	if ( ! $need ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 ) {
			$content = (string) get_post_field( 'post_content', $post_id )
				. (string) get_post_meta( $post_id, '_elementor_data', true );
			$need    = false !== strpos( $content, 'serviya_product' );
		}
	}
	/** Filter: force-load (or skip) the product assets on the current page. */
	return (bool) apply_filters( 'serviya_product_needs_assets', $need );
}

function serviya_suite_product_enqueue_assets(): void {
	$base    = SERVIYA_PATH;
	$url     = SERVIYA_URL;
	$use_min = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
	$css_rel = 'assets/css/serviya-product' . ( $use_min && file_exists( $base . 'assets/css/serviya-product.min.css' ) ? '.min' : '' ) . '.css';
	$js_rel  = 'assets/js/serviya-product' . ( $use_min && file_exists( $base . 'assets/js/serviya-product.min.js' ) ? '.min' : '' ) . '.js';

	if ( file_exists( $base . $css_rel ) ) {
		$deps = array();
		foreach ( array( 'woocommerce-general', 'woocommerce-layout' ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				$deps[] = $handle;
			}
		}
		wp_enqueue_style(
			'serviya-product',
			$url . $css_rel,
			$deps,
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
			'serviya-product',
			$url . $js_rel,
			array(),
			(string) filemtime( $base . $js_rel ),
			$script_args
		);
	}

	// Make sure WooCommerce's own single-product scripts are present even
	// when the shortcode runs on a non-product page (id="…" usage).
	foreach ( array( 'wc-single-product', 'flexslider', 'photoswipe', 'photoswipe-ui-default', 'zoom' ) as $handle ) {
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_enqueue_script( $handle );
		}
	}
}

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( serviya_suite_product_needs_assets() ) {
			serviya_suite_product_enqueue_assets();
		}
	},
	100
);

/* ----------------------------------------------------------------- helpers */

function serviya_suite_product_fa_number( $value ): string {
	if ( is_numeric( $value ) && function_exists( 'serviya_suite_shop_fa_number' ) ) {
		return serviya_suite_shop_fa_number( $value );
	}
	return serviya_suite_product_fa_digits( $value );
}

/** Persian digits without thousand grouping (phone numbers, codes). */
function serviya_suite_product_fa_digits( $value ): string {
	$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
	return str_replace( $en, $fa, (string) $value );
}

function serviya_suite_product_capture( callable $callback ): string {
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

/** Resolve the product this shortcode should render. */
function serviya_suite_product_resolve_id( int $requested ): int {
	if ( $requested > 0 ) {
		return $requested;
	}
	$queried = (int) get_queried_object_id();
	if ( $queried > 0 && 'product' === get_post_type( $queried ) ) {
		return $queried;
	}
	global $product, $post;
	if ( $product instanceof WC_Product ) {
		return (int) $product->get_id();
	}
	if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
		return (int) $post->ID;
	}
	return 0;
}

/** Deepest assigned product category (most specific breadcrumb trail). */
function serviya_suite_product_primary_category( WC_Product $product ): ?WP_Term {
	$best  = null;
	$depth = -1;
	foreach ( $product->get_category_ids() as $term_id ) {
		$term = get_term( (int) $term_id, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		$ancestors = get_ancestors( $term->term_id, 'product_cat' );
		$count     = is_array( $ancestors ) ? count( $ancestors ) : 0;
		if ( $count > $depth ) {
			$best  = $term;
			$depth = $count;
		}
	}
	return $best;
}

/** Brand label from the brand taxonomy, or a single-value brand attribute. */
function serviya_suite_product_brand_label( WC_Product $product ): string {
	foreach ( array( 'product_brand', 'product-brands' ) as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		$names = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
		if ( is_array( $names ) && ! empty( $names ) ) {
			return (string) reset( $names );
		}
	}
	// Fall back to attributes named brand/برند — only when they carry one
	// value (otherwise they describe variations, not "the brand").
	foreach ( $product->get_attributes() as $attribute ) {
		if ( ! $attribute instanceof WC_Product_Attribute ) {
			continue;
		}
		$name = $attribute->get_name();
		$is_brand = false !== stripos( $name, 'brand' );
		if ( ! $is_brand && $attribute->is_taxonomy() && taxonomy_exists( $name ) ) {
			$taxonomy = get_taxonomy( $name );
			$labels   = array( (string) $taxonomy->label, (string) ( $taxonomy->labels->singular_name ?? '' ) );
			$is_brand = in_array( 'برند', $labels, true ) || in_array( 'brand', array_map( 'strtolower', $labels ), true );
		}
		if ( ! $is_brand ) {
			continue;
		}
		$options = $attribute->is_taxonomy()
			? wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) )
			: $attribute->get_options();
		if ( is_array( $options ) && 1 === count( $options ) ) {
			return (string) reset( $options );
		}
	}
	return '';
}

/** WooCommerce stock-display format: '', 'low_qty', or 'no_amount'. */
function serviya_suite_product_stock_format(): string {
	return function_exists( 'get_option' )
		? (string) get_option( 'woocommerce_stock_format', '' )
		: '';
}

/** @return array{text:string,class:string} Small stock pill for the eyebrow row. */
function serviya_suite_product_stock_pill( WC_Product $product ): array {
	if ( ! $product->is_in_stock() ) {
		return array( 'text' => 'ناموجود', 'class' => 'is-out' );
	}
	$quantity = $product->get_stock_quantity();
	if ( $product->managing_stock() && null !== $quantity && $quantity <= 0 && $product->backorders_allowed() ) {
		return array( 'text' => 'قابل سفارش (پیش‌خرید)', 'class' => 'is-backorder' );
	}
	if ( $product->managing_stock() && null !== $quantity ) {
		$format   = serviya_suite_product_stock_format();
		$show_qty = 'no_amount' !== $format;
		$low      = (int) $product->get_low_stock_amount();
		if ( $show_qty && $low > 0 && $quantity <= $low ) {
			return array(
				'text'  => sprintf( 'تنها %s عدد در انبار باقی مانده', serviya_suite_product_fa_number( $quantity ) ),
				'class' => 'is-low',
			);
		}
		// WooCommerce uses an empty value for "always show quantity"; some
		// extensions expose the same choice as "always".
		if ( $show_qty && ( '' === $format || 'always' === $format ) ) {
			return array(
				'text'  => sprintf( '%s عدد موجود در انبار', serviya_suite_product_fa_number( $quantity ) ),
				'class' => 'is-in',
			);
		}
	}
	return array( 'text' => 'موجود در انبار', 'class' => 'is-in' );
}

/** Whole-number sale percentage for simple (and priced) products, else 0. */
function serviya_suite_product_sale_percent( WC_Product $product ): int {
	if ( ! $product->is_on_sale() ) {
		return 0;
	}
	$regular = 0.0;
	$sale    = 0.0;
	if ( $product->is_type( 'variable' ) ) {
		$regular = (float) $product->get_variation_regular_price( 'min' );
		$sale    = (float) $product->get_variation_sale_price( 'min' );
	} else {
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
	}
	if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) {
		return 0;
	}
	return (int) min( 99, round( ( 1 - $sale / $regular ) * 100 ) );
}

/** Dimension text in the same form as the shop card label (without unit). */
function serviya_suite_product_dimensions_label( WC_Product $product ): string {
	if ( function_exists( 'serviya_suite_shop_product_dimensions' ) ) {
		return (string) serviya_suite_shop_product_dimensions( $product );
	}

	$parts = array();
	foreach ( array( 'get_length', 'get_width', 'get_height' ) as $method ) {
		if ( ! method_exists( $product, $method ) ) {
			continue;
		}
		$value = $product->$method();
		if ( '' !== (string) $value && null !== $value && '0' !== (string) $value ) {
			$parts[] = (string) $value;
		}
	}
	if ( ! empty( $parts ) ) {
		return implode( ' × ', $parts );
	}

	if ( ! method_exists( $product, 'get_attributes' ) ) {
		return '';
	}
	foreach ( $product->get_attributes() as $attribute ) {
		if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
			continue;
		}
		$is_dimensions = 'ابعاد' === $attribute->get_name()
			|| ( method_exists( $attribute, 'get_taxonomy' ) && 'pa_dimensions' === $attribute->get_taxonomy() );
		if ( ! $is_dimensions ) {
			continue;
		}
		if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
			$names = function_exists( 'wc_get_product_terms' )
				? wc_get_product_terms( $product->get_id(), $attribute->get_taxonomy(), array( 'fields' => 'names' ) )
				: array();
			if ( is_array( $names ) && ! empty( $names ) ) {
				return (string) reset( $names );
			}
		} elseif ( method_exists( $attribute, 'get_options' ) ) {
			$options = $attribute->get_options();
			if ( is_array( $options ) && ! empty( $options ) ) {
				return (string) reset( $options );
			}
		}
	}
	return '';
}

/** Next future sale end (Unix timestamp), including variable products. */
function serviya_suite_product_sale_end_timestamp( WC_Product $product ): int {
	$now        = time();
	$candidates = array();
	$collect    = static function ( $item ) use ( &$candidates, $now ): void {
		if ( ! $item instanceof WC_Product
			|| ! method_exists( $item, 'get_date_on_sale_to' )
			|| ( method_exists( $item, 'is_on_sale' ) && ! $item->is_on_sale() )
		) {
			return;
		}
		$date = $item->get_date_on_sale_to();
		$time = 0;
		if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
			$time = (int) $date->getTimestamp();
		} elseif ( is_numeric( $date ) ) {
			$time = (int) $date;
		} elseif ( is_string( $date ) && '' !== $date ) {
			$parsed = strtotime( $date );
			$time   = false === $parsed ? 0 : (int) $parsed;
		}
		if ( $time > $now ) {
			$candidates[] = $time;
		}
	};

	if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
		foreach ( (array) $product->get_children() as $child_id ) {
			$collect( function_exists( 'wc_get_product' ) ? wc_get_product( (int) $child_id ) : null );
		}
	}
	if ( empty( $candidates ) ) {
		$collect( $product );
	}
	return empty( $candidates ) ? 0 : min( $candidates );
}

/** Plugin-owned sale countdown, used only when the theme printed none. */
function serviya_suite_product_countdown_html( WC_Product $product ): string {
	$end = serviya_suite_product_sale_end_timestamp( $product );
	if ( $end <= time() ) {
		return '';
	}

	$seconds = max( 0, $end - time() );
	// RTL display order: seconds first (rightmost), then minutes, hours, days.
	$units   = array(
		'seconds' => array( 'value' => $seconds % 60, 'label' => 'ثانیه' ),
		'minutes' => array( 'value' => intdiv( $seconds % 3600, 60 ), 'label' => 'دقیقه' ),
		'hours'   => array( 'value' => intdiv( $seconds % 86400, 3600 ), 'label' => 'ساعت' ),
		'days'    => array( 'value' => intdiv( $seconds, 86400 ), 'label' => 'روز' ),
	);

	$html = '<div class="sp-countdown" data-sp-countdown data-end="' . esc_attr( (string) $end ) . '" role="timer" aria-label="زمان باقی‌مانده تا پایان فروش ویژه">'
		. '<span class="sp-countdown-label">تا پایان فروش ویژه</span><div class="sp-countdown-units">';
	foreach ( $units as $key => $unit ) {
		$value = 'days' === $key
			? serviya_suite_product_fa_number( $unit['value'] )
			: serviya_suite_product_fa_digits( str_pad( (string) $unit['value'], 2, '0', STR_PAD_LEFT ) );
		$html .= '<span class="sp-countdown-unit"><strong data-countdown-unit="' . esc_attr( $key ) . '">'
			. esc_html( $value ) . '</strong><small>' . esc_html( $unit['label'] ) . '</small></span>';
	}
	$html .= '</div></div>';
	return $html;
}

/* ---------------------------------------------------------------- template */

function serviya_suite_product_breadcrumb( WC_Product $product ): string {
	$items = array(
		array( 'label' => 'خانه', 'url' => home_url( '/' ) ),
	);
	$shop_url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
	if ( '' !== $shop_url ) {
		$items[] = array( 'label' => 'فروشگاه', 'url' => $shop_url );
	}
	$primary = serviya_suite_product_primary_category( $product );
	if ( $primary instanceof WP_Term ) {
		$ancestors = get_ancestors( $primary->term_id, 'product_cat' );
		$trail     = is_array( $ancestors ) ? array_reverse( $ancestors ) : array();
		$trail[] = $primary->term_id;
		foreach ( $trail as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			$items[] = array(
				'label' => $term->name,
				'url'   => is_wp_error( $link ) ? '' : (string) $link,
			);
		}
	}

	$html = '<nav class="sp-breadcrumb" aria-label="مسیر صفحه"><ol>';
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

function serviya_suite_product_adjacent_nav( string $shop_url ): string {
	$prev = get_previous_post( true, '', 'product_cat' );
	$next = get_next_post( true, '', 'product_cat' );
	if ( ! $prev && ! $next && '' === $shop_url ) {
		return '';
	}
	$card = static function ( $post, string $label, string $class ): string {
		if ( ! $post instanceof WP_Post ) {
			return '<span class="sp-nav-spacer" aria-hidden="true"></span>';
		}
		$item  = wc_get_product( $post );
		$thumb = get_the_post_thumbnail( $post, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) );
		$price = $item instanceof WC_Product ? (string) $item->get_price_html() : '';
		return '<a class="sp-nav-card ' . $class . '" href="' . esc_url( get_permalink( $post ) ) . '">'
			. ( '' !== $thumb ? '<span class="sp-nav-thumb">' . $thumb . '</span>' : '' )
			. '<span class="sp-nav-text"><small>' . esc_html( $label ) . '</small>'
			. '<strong>' . esc_html( get_the_title( $post ) ) . '</strong>'
			. ( '' !== $price ? '<span class="sp-nav-price">' . $price . '</span>' : '' )
			. '</span></a>';
	};

	$html  = '<div class="sp-product-nav">';
	$html .= $card( $prev, 'محصول قبلی', 'is-prev' );
	if ( '' !== $shop_url ) {
		$html .= '<a class="sp-nav-shop" href="' . esc_url( $shop_url ) . '" aria-label="بازگشت به فروشگاه">'
			. '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>'
			. '<span>فروشگاه</span></a>';
	}
	$html .= $card( $next, 'محصول بعدی', 'is-next' );
	$html .= '</div>';
	return $html;
}

/** Minimal gallery used only when the theme/WooCommerce printed none. */
function serviya_suite_product_fallback_gallery( WC_Product $product ): string {
	$ids = array_values( array_filter( array_unique( array_merge(
		array( (int) $product->get_image_id() ),
		array_map( 'intval', $product->get_gallery_image_ids() )
	) ) ) );
	if ( empty( $ids ) ) {
		$placeholder = function_exists( 'wc_placeholder_img_src' ) ? (string) wc_placeholder_img_src( 'woocommerce_single' ) : '';
		return '' === $placeholder ? '' : '<div class="sp-fallback-gallery"><img src="' . esc_url( $placeholder ) . '" alt="' . esc_attr( $product->get_name() ) . '"></div>';
	}
	$main = wp_get_attachment_image(
		$ids[0],
		'woocommerce_single',
		false,
		array( 'class' => 'sp-fallback-main', 'fetchpriority' => 'high', 'alt' => $product->get_name() )
	);
	$html = '<div class="sp-fallback-gallery" data-sp-gallery><figure class="sp-fallback-figure">' . $main . '</figure>';
	if ( count( $ids ) > 1 ) {
		$html .= '<div class="sp-fallback-thumbs" role="list">';
		foreach ( $ids as $index => $id ) {
			$full = wp_get_attachment_image_src( $id, 'woocommerce_single' );
			$html .= '<button type="button" class="sp-fallback-thumb' . ( 0 === $index ? ' is-active' : '' ) . '"'
				. ' data-sp-thumb data-full="' . esc_url( $full ? (string) $full[0] : '' ) . '"'
				. ' aria-label="' . esc_attr( sprintf( 'تصویر %s محصول', serviya_suite_product_fa_number( $index + 1 ) ) ) . '">'
				. wp_get_attachment_image( $id, 'woocommerce_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) )
				. '</button>';
		}
		$html .= '</div>';
	}
	$html .= '</div>';
	return $html;
}

function serviya_suite_product_consult_box( WC_Product $product, string $phone, string $whatsapp, string $telegram ): string {
	if ( '' === $phone && '' === $whatsapp && '' === $telegram ) {
		return '';
	}
	$links    = '';
	$icon_svg = static function ( string $path ): string {
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
	};
	if ( '' !== $phone ) {
		$tel    = preg_replace( '/[^\d+]/', '', $phone );
		$links .= '<a class="sp-consult-btn" href="tel:' . esc_attr( (string) $tel ) . '">'
			. $icon_svg( '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>' )
			. '<span dir="ltr">' . esc_html( serviya_suite_product_fa_digits( $phone ) ) . '</span></a>';
	}
	if ( '' !== $whatsapp ) {
		$number = preg_replace( '/\D/', '', $whatsapp );
		$text   = rawurlencode( 'سلام، درباره محصول «' . $product->get_name() . '» سؤال دارم: ' . get_permalink( $product->get_id() ) );
		$links .= '<a class="sp-consult-btn is-whatsapp" href="https://wa.me/' . esc_attr( (string) $number ) . '?text=' . $text . '" target="_blank" rel="noopener">'
			. $icon_svg( '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>' )
			. '<span>واتس‌اپ</span></a>';
	}
	if ( '' !== $telegram ) {
		$url    = 0 === strpos( $telegram, 'http' ) ? $telegram : 'https://t.me/' . ltrim( $telegram, '@' );
		$links .= '<a class="sp-consult-btn is-telegram" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
			. $icon_svg( '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>' )
			. '<span>تلگرام</span></a>';
	}

	return '<aside class="sp-consult">'
		. '<div class="sp-consult-head">'
		. '<span class="sp-consult-icon"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 14v-3a9 9 0 0 1 18 0v3"/><path d="M21 17a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/><path d="M3 17a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg></span>'
		. '<div><strong>مشاوره قبل از خرید</strong>'
		. '<p>برای انتخاب بهتر یا ثبت سفارش اختصاصی، تیم میران پاسخ‌گوی شماست.</p></div>'
		. '</div>'
		. '<div class="sp-consult-links">' . $links . '</div>'
		. '</aside>';
}

/**
 * Restructure the first summary price into the Serviya price card:
 * amounts stacked in .sp-price-stack (WooCommerce already prints the
 * old <del> price first, so it lands on top) plus a left-aligned
 * «قیمت فروش»/«قیمت فروش ویژه» label. Price ranges (multiple bare
 * amounts, e.g. unselected variable products) are left untouched.
 */
function serviya_suite_product_price_card( string $summary_html ): string {
	if ( false !== strpos( $summary_html, 'sp-price-stack' ) ) {
		return $summary_html;
	}
	$done = 0;
	$out  = preg_replace_callback(
		'#<(p|span|div) class="price">(.*?)</\1>#s',
		static function ( array $match ): string {
			$inner   = $match[2];
			$has_del = false !== stripos( $inner, '<del' );
			$has_ins = false !== stripos( $inner, '<ins' );
			if ( ! $has_del && ! $has_ins && substr_count( $inner, 'woocommerce-Price-amount' ) > 1 ) {
				return $match[0]; // A price range: keep the inline layout.
			}
			$label = $has_del ? 'قیمت فروش ویژه' : 'قیمت فروش';
			return '<' . $match[1] . ' class="price sp-price-card">'
				. '<span class="sp-price-stack">' . $inner . '</span>'
				. '<span class="sp-price-label">' . $label . '</span>'
				. '</' . $match[1] . '>';
		},
		$summary_html,
		1,
		$done
	);
	return is_string( $out ) ? $out : $summary_html;
}

function serviya_suite_product_render( $atts ): string {
	static $rendering = array();

	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
		return current_user_can( 'edit_posts' )
			? '<p style="direction:rtl">افزونه ووکامرس فعال نیست؛ شورت‌کد [serviya_product] به ووکامرس نیاز دارد.</p>'
			: '';
	}

	$atts = shortcode_atts(
		array(
			'id'       => 0,
			'sticky'   => '1',
			'consult'  => '1',
			'phone'    => '09382420272',
			'whatsapp' => '989382420272',
			'telegram' => '',
		),
		is_array( $atts ) ? $atts : array(),
		'serviya_product'
	);

	$product_id = serviya_suite_product_resolve_id( absint( $atts['id'] ) );
	if ( $product_id <= 0 || isset( $rendering[ $product_id ] ) ) {
		return '';
	}
	$product = wc_get_product( $product_id );
	$post    = get_post( $product_id );
	if ( ! $product instanceof WC_Product || ! $post instanceof WP_Post ) {
		return '';
	}
	if ( 'publish' !== get_post_status( $post ) && ! current_user_can( 'edit_post', $product_id ) ) {
		return '';
	}
	if ( post_password_required( $post ) ) {
		return get_the_password_form( $post );
	}

	$rendering[ $product_id ] = true;
	$previous_post            = $GLOBALS['post'] ?? null;
	$previous_product         = $GLOBALS['product'] ?? null;
	$GLOBALS['post']          = $post;
	$GLOBALS['product']       = $product;
	setup_postdata( $post );

	try {
		serviya_suite_product_enqueue_assets();
		if ( $product->is_type( 'variable' ) && wp_script_is( 'wc-add-to-cart-variation', 'registered' ) ) {
			wp_enqueue_script( 'wc-add-to-cart-variation' );
		}

		/* Canonical WooCommerce output — theme (WoodMart) + plugin hooks
		 * all run here; the plugin only supplies fallbacks for pieces a
		 * custom layout may have detached from the standard hooks. */
		$before_html = serviya_suite_product_capture( static function (): void {
			do_action( 'woocommerce_before_single_product' );
		} );

		$gallery_html = serviya_suite_product_capture( static function (): void {
			do_action( 'woocommerce_before_single_product_summary' );
		} );
		if ( false === strpos( $gallery_html, 'woocommerce-product-gallery' ) && false === stripos( $gallery_html, '<img' ) ) {
			$direct = function_exists( 'woocommerce_show_product_images' )
				? serviya_suite_product_capture( 'woocommerce_show_product_images' )
				: '';
			$gallery_html .= false !== stripos( $direct, '<img' )
				? $direct
				: serviya_suite_product_fallback_gallery( $product );
		}
		if ( $product->is_on_sale()
			&& false === strpos( $gallery_html, 'onsale' )
			&& false === strpos( $gallery_html, 'product-label' )
		) {
			$percent       = serviya_suite_product_sale_percent( $product );
			$gallery_html  = '<span class="sp-sale-badge">'
				. esc_html( $percent > 0 ? serviya_suite_product_fa_number( (string) $percent ) . '٪ تخفیف' : 'فروش ویژه' )
				. '</span>' . $gallery_html;
		}
		$dimensions = serviya_suite_product_dimensions_label( $product );
		if ( '' !== $dimensions ) {
			$gallery_html = '<span class="sp-dim-badge" data-sp-dimensions>ابعاد <span data-sp-dimensions-value>'
				. esc_html( $dimensions ) . '</span></span>' . $gallery_html;
		}

		$summary_html = serviya_suite_product_capture( static function (): void {
			do_action( 'woocommerce_single_product_summary' );
		} );
		$summary_prefix = '';
		$summary_suffix = '';
		if ( false === stripos( $summary_html, '<h1' ) ) {
			$summary_prefix .= function_exists( 'woocommerce_template_single_title' )
				? serviya_suite_product_capture( 'woocommerce_template_single_title' )
				: '<h1 class="product_title entry-title">' . esc_html( $product->get_name() ) . '</h1>';
		}
		if ( false === strpos( $summary_html, 'woocommerce-product-rating' )
			&& function_exists( 'woocommerce_template_single_rating' ) ) {
			$rating = serviya_suite_product_capture( 'woocommerce_template_single_rating' );
			if ( '' !== trim( $rating ) ) {
				$summary_prefix .= $rating;
			}
		}
		if ( false === strpos( $summary_html, 'woocommerce-Price-amount' )
			&& false === strpos( $summary_html, 'class="price"' )
			&& function_exists( 'woocommerce_template_single_price' ) ) {
			$summary_prefix .= serviya_suite_product_capture( 'woocommerce_template_single_price' );
		}
		if ( false === strpos( $summary_html, 'woocommerce-product-details__short-description' )
			&& function_exists( 'woocommerce_template_single_excerpt' ) ) {
			$summary_suffix .= serviya_suite_product_capture( 'woocommerce_template_single_excerpt' );
		}
		if ( false === strpos( $summary_html, 'single_add_to_cart_button' )
			&& false === strpos( $summary_html, 'form class="cart"' )
			&& function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
			$summary_suffix .= serviya_suite_product_capture( 'woocommerce_template_single_add_to_cart' );
		}
		// The default bottom product meta is intentionally not used here:
		// category/tag output is hidden by the design, while SKU is moved
		// to a small framed label directly below the product title.
		$summary_html = $summary_prefix . $summary_html . $summary_suffix;
		$summary_html = serviya_suite_product_price_card( $summary_html );

		$sku = method_exists( $product, 'get_sku' ) ? trim( (string) $product->get_sku() ) : '';
		if ( '' !== $sku ) {
			$sku_html   = '<div class="sp-sku-row"><span class="sp-sku-chip" data-sp-sku>شناسه محصول: '
				. '<span class="sp-sku-value" data-sp-sku-value dir="ltr">' . esc_html( $sku ) . '</span></span></div>';
			$sku_done   = 0;
			$with_sku   = preg_replace_callback(
				'#</h1>#i',
				static function ( array $match ) use ( $sku_html ): string {
					return $match[0] . $sku_html;
				},
				$summary_html,
				1,
				$sku_done
			);
			$summary_html = is_string( $with_sku ) ? $with_sku : $summary_html;
			if ( 0 === $sku_done ) {
				$summary_html = $sku_html . $summary_html;
			}
		}

		$has_countdown = false !== stripos( $summary_html, 'countdown' )
			|| false !== strpos( $summary_html, 'wd-timer' )
			|| false !== strpos( $summary_html, 'woodmart-timer' );
		if ( ! $has_countdown ) {
			$countdown_html = serviya_suite_product_countdown_html( $product );
			if ( '' !== $countdown_html ) {
				$countdown_done = 0;
				$with_countdown = preg_replace_callback(
					'#(<p\b[^>]*class=(?:"[^"]*\bprice\b[^"]*"|\'[^\']*\bprice\b[^\']*\')[^>]*>.*?</p>)#is',
					static function ( array $match ) use ( $countdown_html ): string {
						return $match[1] . $countdown_html;
					},
					$summary_html,
					1,
					$countdown_done
				);
				$summary_html = is_string( $with_countdown ) ? $with_countdown : $summary_html;
				if ( 0 === $countdown_done ) {
					$form_pos = stripos( $summary_html, '<form' );
					$summary_html = false === $form_pos
						? $summary_html . $countdown_html
						: substr( $summary_html, 0, $form_pos ) . $countdown_html . substr( $summary_html, $form_pos );
				}
			}
		}

		$after_html = serviya_suite_product_capture( static function (): void {
			do_action( 'woocommerce_after_single_product_summary' );
		} );
		if ( false === strpos( $after_html, 'woocommerce-tabs' )
			&& function_exists( 'woocommerce_output_product_data_tabs' ) ) {
			$after_html = serviya_suite_product_capture( 'woocommerce_output_product_data_tabs' ) . $after_html;
		}
		if ( false === strpos( $after_html, 'up-sells' )
			&& ! empty( $product->get_upsell_ids() )
			&& function_exists( 'woocommerce_upsell_display' ) ) {
			$after_html .= serviya_suite_product_capture( 'woocommerce_upsell_display' );
		}
		if ( false === strpos( $after_html, 'related products' )
			&& function_exists( 'woocommerce_output_related_products' ) ) {
			$after_html .= serviya_suite_product_capture( 'woocommerce_output_related_products' );
		}

		$after_product_html = serviya_suite_product_capture( static function (): void {
			do_action( 'woocommerce_after_single_product' );
		} );

		$has_breadcrumb = false !== strpos( $summary_html, 'woocommerce-breadcrumb' )
			|| false !== strpos( $summary_html, 'wd-breadcrumbs' )
			|| false !== strpos( $before_html, 'woocommerce-breadcrumb' );
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		$category = serviya_suite_product_primary_category( $product );
		$brand    = serviya_suite_product_brand_label( $product );
		$stock    = serviya_suite_product_stock_pill( $product );
		$show_sticky  = '1' === (string) $atts['sticky'] && $product->is_purchasable() && $product->is_in_stock();
		$show_consult = '1' === (string) $atts['consult'];

		$eyebrow = '<div class="sp-eyebrow">';
		if ( '' !== $brand ) {
			$eyebrow .= '<span class="sp-chip sp-chip--brand">' . esc_html( 'برند: ' . $brand ) . '</span>';
		}
		if ( $category instanceof WP_Term ) {
			$link = get_term_link( $category );
			$eyebrow .= is_wp_error( $link )
				? '<span class="sp-chip">' . esc_html( $category->name ) . '</span>'
				: '<a class="sp-chip" href="' . esc_url( $link ) . '">' . esc_html( $category->name ) . '</a>';
		}
		$eyebrow .= '<span class="sp-chip sp-chip--stock ' . esc_attr( $stock['class'] ) . '" data-sp-stock-pill'
			. ' data-stock-format="' . esc_attr( serviya_suite_product_stock_format() ) . '"'
			. ' data-low-threshold="' . esc_attr( (string) ( method_exists( $product, 'get_low_stock_amount' ) ? (int) $product->get_low_stock_amount() : 0 ) ) . '">'
			. '<span class="sp-stock-dot" aria-hidden="true"></span>'
			. '<span data-sp-stock-text>' . esc_html( $stock['text'] ) . '</span></span>';
		$eyebrow .= '</div>';

		$consult_html = $show_consult
			? serviya_suite_product_consult_box( $product, trim( (string) $atts['phone'] ), trim( (string) $atts['whatsapp'] ), trim( (string) $atts['telegram'] ) )
			: '';

		$product_classes = function_exists( 'wc_product_class' )
			? serviya_suite_product_capture( static function () use ( $product ): void {
				wc_product_class( 'serviya-product__article', $product );
			} )
			: 'class="serviya-product__article product"';

		$sticky_html = '';
		if ( $show_sticky ) {
			$thumb = get_the_post_thumbnail( $post, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) );
			$sticky_html = '<div class="sp-sticky" data-sp-sticky hidden>'
				. '<div class="sp-sticky-inner">'
				. ( '' !== $thumb ? '<span class="sp-sticky-thumb">' . $thumb . '</span>' : '' )
				. '<span class="sp-sticky-info"><strong>' . esc_html( $product->get_name() ) . '</strong>'
				. '<span class="sp-sticky-price" data-sp-sticky-price>' . $product->get_price_html() . '</span></span>'
				. '<button type="button" class="sp-sticky-btn" data-sp-sticky-cta>خرید محصول</button>'
				. '</div></div>';
		}

		$html = $before_html
			. '<section class="serviya-product" dir="rtl" data-serviya-product>'
			. '<div class="sp-container">'
			. '<div class="sp-toolbar">'
			. ( $has_breadcrumb ? '' : serviya_suite_product_breadcrumb( $product ) )
			. '</div>'
			. '<article id="product-' . esc_attr( (string) $product_id ) . '" ' . $product_classes . '>'
			. '<div class="sp-main product-image-summary"><div class="sp-main-inner product-image-summary-inner">'
			. '<div class="sp-gallery product-images">' . $gallery_html . '</div>'
			. '<div class="sp-summary summary entry-summary"><div class="summary-inner">'
			. $eyebrow
			. $summary_html
			. $consult_html
			. '</div></div>'
			. '</div></div>'
			. ( '' !== trim( $after_html ) ? '<div class="sp-after">' . $after_html . '</div>' : '' )
			. '</article>'
			. '</div>'
			. $sticky_html
			. '</section>'
			. $after_product_html;
	} finally {
		unset( $rendering[ $product_id ] );
		if ( $previous_post instanceof WP_Post ) {
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );
		} else {
			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		}
		if ( $previous_product instanceof WC_Product ) {
			$GLOBALS['product'] = $previous_product;
		} else {
			unset( $GLOBALS['product'] );
		}
	}

	return $html ?? '';
}

add_shortcode( 'serviya_product', 'serviya_suite_product_render' );
