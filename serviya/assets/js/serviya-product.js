/**
 * Serviya — single product enhancements.
 *
 * Everything commerce-critical (gallery slider, variations/swatches,
 * add-to-cart, compare, wishlist, countdown, FBT) is handled by
 * WooCommerce/WoodMart. This file only adds:
 *  1. a mobile sticky buy bar that scrolls back to the native buy form
 *     (removed automatically when WoodMart's own sticky bar exists),
 *  2. sticky price syncing for variable products,
 *  3. Persian digits in the summary/sticky prices plus variation-aware
 *     SKU, dimensions, and stock-pill updates,
 *  4. the plugin-owned fallback sale countdown, and
 *  5. a thumbnail fallback for the plugin's own fallback gallery, and
 *     for a canonical gallery that no slider initialized.
 */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

  function faDigits(value) {
    return String(value).replace(/[0-9\u0660-\u0669]/g, function (digit) {
      var code = digit.charCodeAt(0);
      var index = code >= 0x660 ? code - 0x660 : code - 48;
      return FA_DIGITS[index];
    });
  }

  function persianizeText(node) {
    if (!node || !document.createTreeWalker) return;
    var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
    var textNode;
    while ((textNode = walker.nextNode())) {
      var converted = faDigits(textNode.nodeValue);
      if (converted !== textNode.nodeValue) {
        textNode.nodeValue = converted;
      }
    }
  }

  function persianizePricesIn(scope) {
    if (!scope) return;
    if (scope.matches && scope.matches('.price, [data-sp-sticky-price]')) {
      persianizeText(scope);
    }
    scope.querySelectorAll('.price, [data-sp-sticky-price]').forEach(persianizeText);
  }

  function persianizeExternalStickyPrices() {
    document.querySelectorAll(
      '.wd-sticky-btn .price, .woodmart-sticky-btn .price, .wd-sticky-add-to-cart .price'
    ).forEach(persianizeText);
  }

  function initPersianPrices(root) {
    persianizePricesIn(root);
    persianizeExternalStickyPrices();

    window.addEventListener('load', function () {
      persianizePricesIn(root);
      persianizeExternalStickyPrices();
    }, { once: true });

    if (!window.MutationObserver) return;

    var pending = false;
    function schedule() {
      if (pending) return;
      pending = true;
      window.requestAnimationFrame(function () {
        pending = false;
        persianizePricesIn(root);
        persianizeExternalStickyPrices();
      });
    }

    var rootObserver = new MutationObserver(schedule);
    rootObserver.observe(root, { childList: true, characterData: true, subtree: true });

    // WoodMart's sticky bar is printed outside the Serviya wrapper and can
    // also be updated after a variation is selected.
    document.querySelectorAll('.wd-sticky-btn, .woodmart-sticky-btn, .wd-sticky-add-to-cart')
      .forEach(function (sticky) {
        if (root.contains(sticky)) return;
        var stickyObserver = new MutationObserver(schedule);
        stickyObserver.observe(sticky, { childList: true, characterData: true, subtree: true });
      });
  }

  function hasExternalSticky(root) {
    var candidates = document.querySelectorAll('.wd-sticky-btn, .woodmart-sticky-btn, .wd-sticky-add-to-cart');
    for (var i = 0; i < candidates.length; i++) {
      if (!root.contains(candidates[i])) return true;
    }
    return false;
  }

  function initSticky(root) {
    var sticky = root.querySelector('[data-sp-sticky]');
    if (!sticky) return;

    var summary = root.querySelector('.sp-summary');
    var target = summary ? summary.querySelector('form.cart') : null;
    if (!target && summary) {
      target = summary.querySelector('.single_add_to_cart_button, .add_to_cart_button');
    }
    if (!target) {
      sticky.remove();
      return;
    }

    if (hasExternalSticky(root)) {
      sticky.remove();
      return;
    }
    window.addEventListener('load', function () {
      if (sticky.isConnected && hasExternalSticky(root)) sticky.remove();
    }, { once: true });

    sticky.hidden = false;

    /* ---- price sync (variable products) ---- */
    var priceBox = sticky.querySelector('[data-sp-sticky-price]');
    var mainPrice = summary.querySelector('.summary-inner > .price, :scope > .price');

    function setPrice(html) {
      if (priceBox && html && priceBox.innerHTML !== html) {
        priceBox.innerHTML = html;
        persianizeText(priceBox);
      }
    }

    function refreshPrice() {
      var variationPrice = summary.querySelector('.woocommerce-variation-price .price');
      if (variationPrice && variationPrice.innerHTML.trim() !== '') {
        setPrice(variationPrice.innerHTML);
      } else if (mainPrice) {
        setPrice(mainPrice.innerHTML);
      }
    }

    refreshPrice();

    if (window.MutationObserver && summary) {
      var pending = false;
      var observer = new MutationObserver(function () {
        if (pending) return;
        pending = true;
        window.requestAnimationFrame(function () {
          pending = false;
          refreshPrice();
        });
      });
      observer.observe(summary, { childList: true, characterData: true, subtree: true });
    }

    // WooCommerce variation events give the exact price HTML.
    if (window.jQuery && target.tagName === 'FORM') {
      var $form = window.jQuery(target);
      $form.on('found_variation', function (_event, variation) {
        if (variation && variation.price_html) setPrice(variation.price_html);
      });
      $form.on('hide_variation reset_data', function () {
        window.setTimeout(refreshPrice, 0);
      });
    }

    /* ---- show after the buy form leaves the viewport (upwards) ---- */
    function updateVisibility() {
      var rect = target.getBoundingClientRect();
      var passed = rect.bottom < 0;
      sticky.classList.toggle('is-visible', passed);
    }

    // A scroll listener is needed in addition to IntersectionObserver:
    // when a fast scroll or anchor jump moves the form from below the
    // viewport to above it without ever intersecting, IO reports no state
    // change and would leave the bar in its previous state.
    var visibilityTicking = false;
    function scheduleVisibilityUpdate() {
      if (visibilityTicking) return;
      visibilityTicking = true;
      window.requestAnimationFrame(function () {
        visibilityTicking = false;
        updateVisibility();
      });
    }

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function () { updateVisibility(); }, { threshold: 0 });
      io.observe(target);
    }
    window.addEventListener('scroll', scheduleVisibilityUpdate, { passive: true });
    window.addEventListener('resize', scheduleVisibilityUpdate);
    updateVisibility();

    /* ---- CTA scrolls back to the native buy form ---- */
    var cta = sticky.querySelector('[data-sp-sticky-cta]');
    if (cta) {
      cta.addEventListener('click', function () {
        var rect = target.getBoundingClientRect();
        var top = rect.top + window.scrollY - 90;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: Math.max(0, top), behavior: reduceMotion ? 'auto' : 'smooth' });

        window.setTimeout(function () {
          var focusable = target.tagName === 'FORM'
            ? target.querySelector('select[name^="attribute_"], .single_add_to_cart_button, input.qty')
            : target;
          if (focusable && typeof focusable.focus === 'function') {
            focusable.focus({ preventScroll: true });
          }
        }, reduceMotion ? 0 : 450);
      });
    }
  }

  function initVariationMeta(root) {
    var form = root.querySelector('form.variations_form');
    if (!form || !window.jQuery) return;

    var pill = root.querySelector('[data-sp-stock-pill]');
    var pillText = pill ? pill.querySelector('[data-sp-stock-text]') : null;
    var skuValue = root.querySelector('[data-sp-sku-value]');
    var dimensions = root.querySelector('[data-sp-dimensions]');
    var dimensionsValue = dimensions ? dimensions.querySelector('[data-sp-dimensions-value]') : null;

    var original = {
      pillText: pillText ? pillText.textContent : '',
      pillClass: pill ? pill.className : '',
      sku: skuValue ? skuValue.textContent : '',
      dimensions: dimensionsValue ? dimensionsValue.textContent : ''
    };

    function setPill(text, state) {
      if (!pill || !pillText) return;
      pillText.textContent = faDigits(text);
      pill.classList.remove('is-in', 'is-low', 'is-backorder', 'is-out');
      pill.classList.add(state);
    }

    function updateStock(variation) {
      if (!pill || !pillText || !variation) return;

      var availability = document.createElement('div');
      availability.innerHTML = variation.availability_html || '';
      var stockNode = availability.querySelector('.stock');
      var statusClass = stockNode ? stockNode.className : '';

      if ((stockNode && stockNode.classList.contains('out-of-stock')) || variation.is_in_stock === false) {
        if (variation.backorders_allowed || (stockNode && stockNode.classList.contains('available-on-backorder'))) {
          setPill('قابل سفارش (پیش‌خرید)', 'is-backorder');
        } else {
          setPill('ناموجود', 'is-out');
        }
        return;
      }
      if (stockNode && stockNode.classList.contains('available-on-backorder')) {
        setPill('قابل سفارش (پیش‌خرید)', 'is-backorder');
        return;
      }

      var quantity = null;
      if (variation.max_qty !== '' && variation.max_qty !== null && typeof variation.max_qty !== 'undefined') {
        var parsed = Number(variation.max_qty);
        if (Number.isFinite(parsed)) quantity = parsed;
      }
      if (quantity === null && stockNode) {
        var match = stockNode.textContent.replace(/[\u0660-\u0669]/g, function (digit) {
          return String(digit.charCodeAt(0) - 0x660);
        }).replace(/[\u06f0-\u06f9]/g, function (digit) {
          return String(digit.charCodeAt(0) - 0x6f0);
        }).match(/\d+/);
        if (match) quantity = Number(match[0]);
      }

      var format = pill.getAttribute('data-stock-format') || '';
      var threshold = Number(pill.getAttribute('data-low-threshold') || 0);
      if (quantity !== null && format !== 'no_amount' && threshold > 0 && quantity <= threshold) {
        setPill('تنها ' + quantity + ' عدد در انبار باقی مانده', 'is-low');
      } else if (quantity !== null && (format === '' || format === 'always')) {
        setPill(quantity + ' عدد موجود در انبار', 'is-in');
      } else {
        setPill('موجود در انبار', 'is-in');
      }
      void statusClass;
    }

    function updateDimensions(variation) {
      if (!dimensions || !dimensionsValue) return;
      var parts = [];
      if (variation && variation.dimensions) {
        ['length', 'width', 'height'].forEach(function (key) {
          var value = variation.dimensions[key];
          if (value !== '' && value !== null && typeof value !== 'undefined' && String(value) !== '0') {
            parts.push(String(value));
          }
        });
      }
      dimensionsValue.textContent = parts.length ? parts.join(' × ') : original.dimensions;
      dimensions.hidden = dimensionsValue.textContent === '';
    }

    var $form = window.jQuery(form);
    $form.on('found_variation', function (_event, variation) {
      if (!variation) return;
      updateStock(variation);
      updateDimensions(variation);
      if (skuValue) {
        skuValue.textContent = variation.sku ? String(variation.sku) : original.sku;
      }
    });

    $form.on('hide_variation reset_data', function () {
      if (pill && pillText) {
        pillText.textContent = original.pillText;
        pill.className = original.pillClass;
      }
      if (skuValue) skuValue.textContent = original.sku;
      if (dimensions && dimensionsValue) {
        dimensionsValue.textContent = original.dimensions;
        dimensions.hidden = original.dimensions === '';
      }
    });
  }

  function initCountdowns(root) {
    root.querySelectorAll('[data-sp-countdown]').forEach(function (countdown) {
      var end = Number(countdown.getAttribute('data-end') || 0) * 1000;
      if (!end) return;

      var fields = {
        days: countdown.querySelector('[data-countdown-unit="days"]'),
        hours: countdown.querySelector('[data-countdown-unit="hours"]'),
        minutes: countdown.querySelector('[data-countdown-unit="minutes"]'),
        seconds: countdown.querySelector('[data-countdown-unit="seconds"]')
      };

      var timer = null;
      function update() {
        var remaining = Math.max(0, Math.floor((end - Date.now()) / 1000));
        if (remaining <= 0) {
          if (timer) window.clearInterval(timer);
          countdown.remove();
          return;
        }
        var values = {
          days: Math.floor(remaining / 86400),
          hours: Math.floor((remaining % 86400) / 3600),
          minutes: Math.floor((remaining % 3600) / 60),
          seconds: remaining % 60
        };
        Object.keys(values).forEach(function (key) {
          if (!fields[key]) return;
          var text = key === 'days'
            ? String(values[key])
            : String(values[key]).padStart(2, '0');
          fields[key].textContent = faDigits(text);
        });
      }

      update();
      timer = window.setInterval(update, 1000);
    });
  }

  function initFallbackGallery(root) {
    var galleries = root.querySelectorAll('[data-sp-gallery]');
    galleries.forEach(function (gallery) {
      var main = gallery.querySelector('.sp-fallback-main');
      if (!main) return;
      var thumbs = gallery.querySelectorAll('[data-sp-thumb]');
      thumbs.forEach(function (thumb) {
        thumb.addEventListener('click', function () {
          var full = thumb.getAttribute('data-full');
          if (!full) return;
          main.removeAttribute('srcset');
          main.src = full;
          thumbs.forEach(function (item) { item.classList.remove('is-active'); });
          thumb.classList.add('is-active');
        });
      });
    });
  }

  /**
   * If neither WooCommerce (Flexslider) nor the theme (Swiper/Slick)
   * initialized the canonical gallery, turn its thumbnails into a plain
   * image switcher instead of showing every slide stacked.
   */
  function initUninitializedGallery(root) {
    var gallery = root.querySelector('.sp-gallery .woocommerce-product-gallery');
    if (!gallery || gallery.hasAttribute('data-sp-gallery')) return;

    window.setTimeout(function () {
      if (gallery.querySelector('.flex-viewport, .swiper-initialized, .slick-initialized')) return;
      if (gallery.classList.contains('swiper-initialized')) return;

      var slides = gallery.querySelectorAll('.woocommerce-product-gallery__image');
      var thumbs = gallery.querySelectorAll('.flex-control-thumbs li');
      if (slides.length < 2 || thumbs.length !== slides.length) return;

      function activate(index) {
        slides.forEach(function (slide, i) {
          slide.style.display = i === index ? '' : 'none';
        });
        thumbs.forEach(function (thumb, i) {
          thumb.classList.toggle('sp-thumb-active', i === index);
        });
      }

      thumbs.forEach(function (thumb, index) {
        thumb.addEventListener('click', function () { activate(index); });
      });
      activate(0);
    }, 650);
  }

  onReady(function () {
    document.querySelectorAll('[data-serviya-product]').forEach(function (root) {
      initPersianPrices(root);
      initSticky(root);
      initVariationMeta(root);
      initCountdowns(root);
      initFallbackGallery(root);
      initUninitializedGallery(root);
    });
  });
})();
