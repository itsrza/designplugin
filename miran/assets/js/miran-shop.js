/*
 * Miran Shop v2 — progressive enhancement layer.
 *
 * The first page of products, categories and attribute filters is rendered
 * server-side by PHP (SEO + speed + works without JS). This script only:
 *   - AJAX-ifies filtering / sorting / searching / "load more"
 *   - keeps the URL in sync (shareable links, back button, refresh-safe)
 *   - cancels stale requests with AbortController (no more dead-lock)
 *   - handles add-to-cart via the WooCommerce Store API
 *   - drives the mobile filter drawer
 */
(function () {
    'use strict';

    var CFG = window.MiranShopData || {};
    var REST_BASE = String(CFG.restBase || '/wp-json/wc/store/v1/').replace(/\/?$/, '/');
    var SHOP_URL = CFG.shopUrl || window.location.pathname;
    var HOME_URL = CFG.homeUrl || '/';
    var CURRENCY = CFG.currencySymbol || 'تومان';
    var PER_PAGE = parseInt(CFG.perPage, 10) || 12;
    var SORTS = ['date', 'popularity', 'price', 'price-desc'];

    var TXT = Object.assign({
        home: 'خانه',
        shop: 'فروشگاه',
        multiCategory: 'چند دسته‌بندی',
        searchResults: 'نتایج جستجو',
        noProducts: 'هیچ محصولی با این فیلترها یافت نشد.',
        clearFilters: 'حذف همه فیلترها',
        loadError: 'دریافت محصولات با خطا روبه‌رو شد. لطفاً دوباره تلاش کنید.',
        loadMoreError: 'دریافت محصولات بیشتر انجام نشد.',
        retry: 'تلاش دوباره',
        unnamed: 'بدون نام',
        noCategory: 'بدون دسته',
        priceOnRequest: 'استعلام قیمت',
        outOfStock: 'ناموجود',
        addToCart: 'افزودن به سبد خرید',
        adding: 'دارم اضافه میکنم',
        added: 'توی سبد خریدته!',
        chooseOptions: 'انتخاب گزینه‌ها',
        viewProduct: 'مشاهده محصول',
        dimensions: 'ابعاد',
        resultsAnnounce: 'محصول نمایش داده شد.',
        prevPage: 'قبلی',
        nextPage: 'بعدی',
        back: 'بازگشت',
        allOf: 'همهٔ '
    }, CFG.i18n || {});

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    function escapeHTML(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function safeURL(value) {
        try {
            var url = new URL(value, window.location.origin);
            if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
        } catch (error) {}
        return '';
    }

    function toFaNumber(value) {
        return Number(value).toLocaleString('fa-IR');
    }

    function splitList(value) {
        if (Array.isArray(value)) return value.map(String).filter(Boolean);
        return String(value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    /* ------------------------------------------------------------------ */
    /* Per-instance init (multiple widgets per page are supported)         */
    /* ------------------------------------------------------------------ */

    function initMiranShop(root) {
        if (!root || root.dataset.ssInitialized === '1') return;
        root.dataset.ssInitialized = '1';

        var DOM = {
            form: root.querySelector('.ss-filters-form'),
            searchInput: root.querySelector('.ss-search-input'),
            sortButtons: Array.prototype.slice.call(root.querySelectorAll('.ss-sort-options button[data-sort]')),
            filterInStock: root.querySelector('.ss-filter-stock'),
            filterOnSale: root.querySelector('.ss-filter-sale'),
            dynamicFiltersBox: root.querySelector('.ss-dynamic-filters'),
            topCategoriesBox: root.querySelector('.ss-top-categories'),
            productsGrid: root.querySelector('.ss-grid'),
            loadMoreBtn: root.querySelector('.ss-load-more'),
            totalCount: root.querySelector('.ss-total-count'),
            breadcrumbs: root.querySelector('.ss-breadcrumbs'),
            liveRegion: root.querySelector('.ss-live-region'),
            clearAllBtn: root.querySelector('.ss-clear-all'),
            paginationNav: root.querySelector('.ss-pagination-nav'),
            mobileFilterBtn: root.querySelector('.ss-mobile-filter-btn'),
            filterCountBadge: root.querySelector('.ss-filter-count'),
            drawerClose: root.querySelector('.ss-drawer-close'),
            drawerApply: root.querySelector('.ss-drawer-apply'),
            drawerOverlay: root.querySelector('.ss-drawer-overlay'),
            sidebar: root.querySelector('.ss-sidebar')
        };

        if (!DOM.productsGrid || !DOM.form) return;

        /* Slug <-> ID / name maps, built from the server-rendered filters. */
        var catSlugToId = {};
        var catSlugToName = {};
        root.querySelectorAll('.ss-cat-check[data-cat-id]').forEach(function (input) {
            catSlugToId[String(input.value)] = String(input.dataset.catId);
            var label = input.closest('label');
            catSlugToName[String(input.value)] = label ? label.textContent.trim() : String(input.value);
        });
        if (DOM.topCategoriesBox) {
            DOM.topCategoriesBox.querySelectorAll('[data-cat-slug]').forEach(function (link) {
                if (link.dataset.catId) catSlugToId[link.dataset.catSlug] = String(link.dataset.catId);
                var title = link.querySelector('.ss-cat-title');
                if (title) catSlugToName[link.dataset.catSlug] = title.textContent.trim();
            });
        }

        var knownTaxonomies = [];
        root.querySelectorAll('.ss-attr-check[data-taxonomy]').forEach(function (input) {
            if (knownTaxonomies.indexOf(input.dataset.taxonomy) === -1) {
                knownTaxonomies.push(input.dataset.taxonomy);
            }
        });

        /* Full category tree (slug / id / name / parent / image), printed by
         * PHP as JSON; powers the top strip drill-down + breadcrumb chain. */
        var catsData = [];
        var catsDataEl = root.querySelector('.ss-cats-data');
        if (catsDataEl) {
            try {
                var parsedCats = JSON.parse(catsDataEl.textContent);
                if (Array.isArray(parsedCats)) catsData = parsedCats;
            } catch (error) {}
        }
        if (!catsData.length) {
            /* Fallback (e.g. cached pages from an older version): flat list. */
            root.querySelectorAll('.ss-cat-check[data-cat-id]').forEach(function (input) {
                var label = input.closest('label');
                catsData.push({
                    slug: String(input.value),
                    id: Number(input.dataset.catId),
                    name: label ? label.textContent.trim() : String(input.value),
                    parent: '',
                    image: ''
                });
            });
        }
        var catsBySlug = {};
        var catChildren = {};
        catsData.forEach(function (row) {
            row.slug = String(row.slug || '');
            row.parent = String(row.parent || '');
            catsBySlug[row.slug] = row;
            catSlugToId[row.slug] = String(row.id);
            catSlugToName[row.slug] = row.name;
            if (!catChildren[row.parent]) catChildren[row.parent] = [];
            catChildren[row.parent].push(row);
        });

        /* ------------------------------ State ------------------------------ */

        function parseStateFromURL() {
            var params = new URLSearchParams(window.location.search);
            var state = {
                page: Math.max(1, parseInt(params.get('ss_page'), 10) || 1),
                perPage: PER_PAGE,
                search: (params.get('ss_q') || '').trim(),
                sort: SORTS.indexOf(params.get('ss_sort')) !== -1 ? params.get('ss_sort') : 'date',
                inStock: params.get('ss_stock') === '1',
                onSale: params.get('ss_sale') === '1',
                selectedCategories: splitList(params.get('ss_cat')),
                selectedAttributes: {}
            };

            /* Also accept the array-style params produced by a no-JS form submit. */
            if (!state.selectedCategories.length && params.getAll('ss_cat[]').length) {
                state.selectedCategories = params.getAll('ss_cat[]');
            }

            knownTaxonomies.forEach(function (taxonomy) {
                var terms = splitList(params.get('ss_' + taxonomy));
                if (!terms.length) terms = params.getAll('ss_attr[' + taxonomy + '][]');
                if (terms.length) state.selectedAttributes[taxonomy] = terms;
            });

            return state;
        }

        var state = parseStateFromURL();
        state.perPage = parseInt(root.dataset.perPage, 10) || PER_PAGE;
        state.page = Math.max(1, parseInt(root.dataset.currentPage, 10) || state.page);
        state.totalPages = Math.max(1, parseInt(root.dataset.totalPages, 10) || 1);
        state.hasMore = state.page < state.totalPages;
        state.isLoading = false;
        state.requestId = 0;
        state.cartNonce = '';
        state.cartNoncePromise = null;

        var abortController = null;

        /* v2.1: short-lived in-memory cache for filter results + prefetch. */
        var RESULTS_TTL = 60000;
        var resultsCache = new Map();
        var prefetchController = null;

        /* Path + query identify the shop state; the fragment never does.
         * Remembering this lets popstate ignore fragment-only navigation
         * such as a theme's "#" back-to-top link or in-page anchors. */
        function relevantLocationKey() {
            return window.location.pathname + window.location.search;
        }
        var lastLocationKey = relevantLocationKey();

        /* --------------------------- URL building --------------------------- */

        function buildPageURL(page, categoriesOverride) {
            var url = new URL(SHOP_URL, window.location.origin);
            var categories = categoriesOverride !== undefined ? categoriesOverride : state.selectedCategories;
            if (state.search) url.searchParams.set('ss_q', state.search);
            if (categories.length) url.searchParams.set('ss_cat', categories.join(','));
            Object.keys(state.selectedAttributes).forEach(function (taxonomy) {
                var terms = state.selectedAttributes[taxonomy];
                if (terms && terms.length) url.searchParams.set('ss_' + taxonomy, terms.join(','));
            });
            if (state.inStock) url.searchParams.set('ss_stock', '1');
            if (state.onSale) url.searchParams.set('ss_sale', '1');
            if (state.sort !== 'date') url.searchParams.set('ss_sort', state.sort);
            if (page && page > 1) url.searchParams.set('ss_page', String(page));
            return url.pathname + url.search;
        }

        function updateURL(push) {
            var url = buildPageURL(1);
            if (url === relevantLocationKey()) {
                lastLocationKey = url;
                return;
            }
            if (push) {
                window.history.pushState({ miranShop: true }, '', url);
            } else {
                window.history.replaceState({ miranShop: true }, '', url);
            }
            lastLocationKey = relevantLocationKey();
        }

        /* ------------------------------ UI sync ------------------------------ */

        function activeFilterCount() {
            var count = state.selectedCategories.length + (state.inStock ? 1 : 0) +
                (state.onSale ? 1 : 0) + (state.search ? 1 : 0);
            Object.keys(state.selectedAttributes).forEach(function (taxonomy) {
                count += state.selectedAttributes[taxonomy].length;
            });
            return count;
        }

        function announce(message) {
            if (!DOM.liveRegion) return;
            DOM.liveRegion.textContent = '';
            window.setTimeout(function () { DOM.liveRegion.textContent = message; }, 40);
        }

        function currentHeadingText() {
            if (state.selectedCategories.length === 1) {
                return catSlugToName[state.selectedCategories[0]] || TXT.shop;
            }
            if (state.selectedCategories.length > 1) return TXT.multiCategory;
            if (state.search) return TXT.searchResults;
            return TXT.shop;
        }

        function updateBreadcrumbs() {
            if (!DOM.breadcrumbs) return;
            var heading = currentHeadingText();
            var isFilteredLanding = heading !== TXT.shop;
            var shopItem = isFilteredLanding
                ? '<li><a href="' + escapeHTML(SHOP_URL) + '" data-ss-shop-link>' + escapeHTML(TXT.shop) + '</a><span class="ss-sep" aria-hidden="true">/</span></li>'
                : '';

            /* Ancestor categories between "shop" and the current category. */
            var ancestorItems = '';
            if (state.selectedCategories.length === 1 && catsBySlug[state.selectedCategories[0]]) {
                var chain = [];
                var cursor = catsBySlug[state.selectedCategories[0]];
                var guard = 0;
                while (cursor && cursor.parent && catsBySlug[cursor.parent] && guard < 10) {
                    chain.unshift(catsBySlug[cursor.parent]);
                    cursor = catsBySlug[cursor.parent];
                    guard++;
                }
                ancestorItems = chain.map(function (row) {
                    return '<li><a href="' + escapeHTML(buildPageURL(1, [row.slug])) + '" data-ss-cat-link="' + escapeHTML(row.slug) + '">' +
                        escapeHTML(row.name) + '</a><span class="ss-sep" aria-hidden="true">/</span></li>';
                }).join('');
            }

            DOM.breadcrumbs.innerHTML =
                '<ol>' +
                    '<li><a href="' + escapeHTML(HOME_URL) + '">' + escapeHTML(TXT.home) + '</a><span class="ss-sep" aria-hidden="true">/</span></li>' +
                    shopItem +
                    ancestorItems +
                    '<li><h1 class="ss-current" aria-current="page">' + escapeHTML(heading) + '</h1></li>' +
                '</ol>';

            document.title = heading + ' – ' + (CFG.siteName || document.title.split('–').pop().trim());
        }

        var CAT_FALLBACK_ICON =
            '<div class="ss-cat-icon-fallback">' +
                '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                    '<rect x="3" y="3" width="18" height="18" rx="2"></rect>' +
                    '<circle cx="8.5" cy="8.5" r="1.5"></circle>' +
                    '<polyline points="21 15 16 10 5 21"></polyline>' +
                '</svg>' +
            '</div>';

        var CAT_BACK_ICON =
            '<div class="ss-cat-icon-fallback">' +
                '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                    '<path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path>' +
                '</svg>' +
            '</div>';

        function stripItemHTML(row, label, extraAttrs) {
            var active = state.selectedCategories.indexOf(row.slug) !== -1;
            var imageURL = row.image ? safeURL(row.image) : '';
            var image = imageURL
                ? '<img class="ss-cat-img" src="' + escapeHTML(imageURL) + '" alt="' + escapeHTML(row.name) + '" loading="lazy" decoding="async" sizes="45px">'
                : CAT_FALLBACK_ICON;
            return '' +
                '<a class="ss-cat-item' + (active ? ' active' : '') + '"' +
                    ' href="' + escapeHTML(buildPageURL(1, [row.slug])) + '"' +
                    ' data-cat-slug="' + escapeHTML(row.slug) + '"' +
                    ' data-cat-id="' + escapeHTML(row.id) + '"' +
                    (active ? ' aria-current="true"' : '') +
                    (extraAttrs || '') + '>' +
                    image +
                    '<span class="ss-cat-title">' + escapeHTML(label || row.name) + '</span>' +
                '</a>';
        }

        /*
         * Top strip drill-down:
         *  - nothing / several selected  -> top-level categories
         *  - one selected with children  -> [back] [all of X] [children…]
         *  - one selected leaf child     -> [back] [all of parent] [siblings…]
         */
        function renderTopStrip() {
            if (!DOM.topCategoriesBox || !catsData.length) return;

            var levelParent = null;
            /* Default view: ALL categories in hierarchical order, like the
             * original widget. Selecting one drills down (below). */
            var items = [];
            (function flatten(parentSlug) {
                (catChildren[parentSlug] || []).forEach(function (row) {
                    items.push(row);
                    flatten(row.slug);
                });
            })('');

            if (state.selectedCategories.length === 1) {
                var selected = catsBySlug[state.selectedCategories[0]];
                if (selected) {
                    if ((catChildren[selected.slug] || []).length) {
                        levelParent = selected;
                        items = catChildren[selected.slug];
                    } else if (selected.parent && catsBySlug[selected.parent]) {
                        levelParent = catsBySlug[selected.parent];
                        items = catChildren[selected.parent] || [];
                    }
                }
            }

            var html = '';

            if (levelParent) {
                var backTarget = levelParent.parent || '';
                html += '' +
                    '<a class="ss-cat-item ss-cat-back"' +
                        ' href="' + escapeHTML(buildPageURL(1, backTarget ? [backTarget] : [])) + '"' +
                        ' data-ss-cat-back data-target-slug="' + escapeHTML(backTarget) + '">' +
                        CAT_BACK_ICON +
                        '<span class="ss-cat-title">' + escapeHTML(TXT.back) + '</span>' +
                    '</a>';
                html += stripItemHTML(levelParent, TXT.allOf + levelParent.name, ' data-ss-all-parent="1"');
            }

            html += items.map(function (row) { return stripItemHTML(row); }).join('');
            DOM.topCategoriesBox.innerHTML = html;
        }

        function syncCategoryUI() {
            renderTopStrip();
            root.querySelectorAll('.ss-cat-check').forEach(function (input) {
                input.checked = state.selectedCategories.indexOf(String(input.value)) !== -1;
            });
        }

        function syncControlsFromState() {
            if (DOM.searchInput) DOM.searchInput.value = state.search;
            if (DOM.filterInStock) DOM.filterInStock.checked = state.inStock;
            if (DOM.filterOnSale) DOM.filterOnSale.checked = state.onSale;

            DOM.sortButtons.forEach(function (button) {
                var active = button.dataset.sort === state.sort;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            root.querySelectorAll('.ss-attr-check').forEach(function (input) {
                var terms = state.selectedAttributes[input.dataset.taxonomy] || [];
                input.checked = terms.indexOf(String(input.value)) !== -1;
            });

            syncCategoryUI();
            updateFilterChrome();
            updateBreadcrumbs();
        }

        function updateFilterChrome() {
            var count = activeFilterCount();
            if (DOM.clearAllBtn) {
                DOM.clearAllBtn.hidden = count === 0 && state.sort === 'date';
            }
            if (DOM.filterCountBadge) {
                DOM.filterCountBadge.hidden = count === 0;
                DOM.filterCountBadge.textContent = toFaNumber(count);
            }
        }

        function renderPagination() {
            if (!DOM.paginationNav) return;
            var total = state.totalPages;
            var current = state.page;
            if (total <= 1) {
                DOM.paginationNav.innerHTML = '';
                DOM.paginationNav.hidden = true;
                return;
            }
            DOM.paginationNav.hidden = false;

            var pages = [];
            [1, total, current - 1, current, current + 1].forEach(function (p) {
                if (p >= 1 && p <= total && pages.indexOf(p) === -1) pages.push(p);
            });
            pages.sort(function (a, b) { return a - b; });

            var html = '';
            if (current > 1) {
                html += '<a class="page-numbers prev" href="' + escapeHTML(buildPageURL(current - 1)) + '">' + escapeHTML(TXT.prevPage) + '</a>';
            }
            var previous = 0;
            pages.forEach(function (p) {
                if (previous && p - previous > 1) {
                    html += '<span class="page-numbers dots" aria-hidden="true">…</span>';
                }
                if (p === current) {
                    html += '<span class="page-numbers current" aria-current="page">' + toFaNumber(p) + '</span>';
                } else {
                    html += '<a class="page-numbers" href="' + escapeHTML(buildPageURL(p)) + '">' + toFaNumber(p) + '</a>';
                }
                previous = p;
            });
            if (current < total) {
                html += '<a class="page-numbers next" href="' + escapeHTML(buildPageURL(current + 1)) + '">' + escapeHTML(TXT.nextPage) + '</a>';
            }
            DOM.paginationNav.innerHTML = html;
        }

        /* --------------------------- Products fetch --------------------------- */

        function getSkeletonsHTML(count) {
            var html = '';
            for (var i = 0; i < count; i++) {
                html +=
                    '<div class="ss-skel-card" aria-hidden="true">' +
                        '<div class="ss-skeleton ss-skel-img-box"></div>' +
                        '<div class="ss-skeleton ss-skel-text-1"></div>' +
                        '<div class="ss-skeleton ss-skel-text-2"></div>' +
                        '<div class="ss-skeleton ss-skel-text-3"></div>' +
                        '<div class="ss-skeleton ss-skel-btn-box"></div>' +
                    '</div>';
            }
            return html;
        }

        function buildProductsURL(page) {
            var params = new URLSearchParams();
            params.set('page', String(page));
            params.set('per_page', String(state.perPage));
            params.set('_fields', 'id,name,permalink,type,is_in_stock,is_purchasable,images,prices,categories,dimensions,attributes,add_to_cart');
            if (state.search) params.set('search', state.search);

            if (state.sort === 'price') {
                params.set('orderby', 'price');
                params.set('order', 'asc');
            } else if (state.sort === 'price-desc') {
                params.set('orderby', 'price');
                params.set('order', 'desc');
            } else if (state.sort === 'popularity') {
                params.set('orderby', 'popularity');
            } else {
                params.set('orderby', 'date');
                params.set('order', 'desc');
            }

            if (state.inStock) params.set('stock_status', 'instock');
            if (state.onSale) params.set('on_sale', 'true');

            var categoryIds = state.selectedCategories.map(function (slug) {
                return catSlugToId[slug];
            }).filter(Boolean);
            if (categoryIds.length) params.set('category', categoryIds.join(','));

            var index = 0;
            Object.keys(state.selectedAttributes).forEach(function (taxonomy) {
                var terms = state.selectedAttributes[taxonomy];
                if (terms && terms.length) {
                    params.set('attributes[' + index + '][attribute]', taxonomy);
                    params.set('attributes[' + index + '][term]', terms.join(','));
                    index++;
                }
            });

            return REST_BASE + 'products?' + params.toString();
        }

        function removeSkeletons() {
            DOM.productsGrid.querySelectorAll('.ss-skel-card').forEach(function (item) { item.remove(); });
        }

        function clearLoadMoreError() {
            var box = root.querySelector('.ss-loadmore-error');
            if (box) box.remove();
        }

        function showLoadMoreError() {
            clearLoadMoreError();
            var wrapper = document.createElement('div');
            wrapper.className = 'ss-loadmore-error';
            wrapper.setAttribute('role', 'alert');
            wrapper.innerHTML =
                '<div>' + escapeHTML(TXT.loadMoreError) + '</div>' +
                '<button type="button" class="ss-inline-btn" data-ss-retry-more>' + escapeHTML(TXT.retry) + '</button>';
            DOM.loadMoreBtn.parentNode.appendChild(wrapper);
        }

        function updateLoadMoreVisibility() {
            if (!DOM.loadMoreBtn) return;
            DOM.loadMoreBtn.hidden = !state.hasMore;
            DOM.loadMoreBtn.disabled = state.isLoading;
        }

        function cacheGet(url) {
            var hit = resultsCache.get(url);
            if (hit && (Date.now() - hit.ts) < RESULTS_TTL) return hit.result;
            if (hit) resultsCache.delete(url);
            return null;
        }

        function fetchProductsData(url, signal) {
            return fetch(url, {
                signal: signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                var totalItems = response.headers.get('X-WP-Total');
                var totalPages = parseInt(response.headers.get('X-WP-TotalPages'), 10) || 1;
                return response.json().then(function (products) {
                    if (!Array.isArray(products)) throw new Error('Invalid response');
                    var result = { products: products, totalItems: totalItems, totalPages: totalPages };
                    resultsCache.set(url, { ts: Date.now(), result: result });
                    return result;
                });
            });
        }

        function fetchProducts(reset, pageOverride) {
            /* Cancel whatever is in flight — the previous version dead-locked here. */
            if (abortController) abortController.abort();
            if (reset && prefetchController) prefetchController.abort();

            var requestId = ++state.requestId;
            var targetPage = reset ? (pageOverride || 1) : state.page + 1;
            var requestURL = buildProductsURL(targetPage);

            function applyResult(result) {
                state.page = targetPage;
                state.totalPages = result.totalPages;
                state.hasMore = state.page < state.totalPages;

                if (DOM.totalCount) {
                    DOM.totalCount.textContent = result.totalItems === null
                        ? '-'
                        : toFaNumber(result.totalItems);
                }

                if (reset) {
                    DOM.productsGrid.innerHTML = '';
                } else {
                    removeSkeletons();
                }

                renderProducts(result.products);
                renderPagination();
                updateFilterChrome();
                updateBreadcrumbs();
                announce(toFaNumber(result.totalItems || 0) + ' ' + TXT.resultsAnnounce);
                maybePrefetchSoon();
            }

            function finishLoading() {
                if (requestId !== state.requestId) return;
                state.isLoading = false;
                DOM.productsGrid.removeAttribute('aria-busy');
                updateLoadMoreVisibility();
            }

            /* Instant path: identical filter state seen in the last minute. */
            var cached = cacheGet(requestURL);
            if (cached) {
                abortController = new AbortController();
                state.isLoading = true;
                clearLoadMoreError();
                applyResult(cached);
                finishLoading();
                return;
            }

            abortController = new AbortController();
            state.isLoading = true;
            DOM.productsGrid.setAttribute('aria-busy', 'true');
            clearLoadMoreError();

            if (reset) {
                DOM.productsGrid.innerHTML = getSkeletonsHTML(state.perPage);
                if (DOM.loadMoreBtn) DOM.loadMoreBtn.hidden = true;
            } else {
                DOM.productsGrid.insertAdjacentHTML('beforeend', getSkeletonsHTML(4));
                if (DOM.loadMoreBtn) DOM.loadMoreBtn.disabled = true;
            }

            fetchProductsData(requestURL, abortController.signal)
            .then(function (result) {
                if (requestId !== state.requestId) return;
                applyResult(result);
            }).catch(function (error) {
                if (error && error.name === 'AbortError') return;
                if (requestId !== state.requestId) return;

                removeSkeletons();

                if (reset) {
                    /* Full-grid error with an in-place retry (no page refresh needed). */
                    DOM.productsGrid.innerHTML =
                        '<div class="ss-error-msg" role="alert">' +
                            '<div>' + escapeHTML(TXT.loadError) + '</div>' +
                            '<button type="button" class="ss-inline-btn" data-ss-retry>' + escapeHTML(TXT.retry) + '</button>' +
                        '</div>';
                    if (DOM.totalCount) DOM.totalCount.textContent = '-';
                } else {
                    /* Keep the already-loaded products; just offer a retry. */
                    showLoadMoreError();
                }
                announce(TXT.loadError);
            }).finally(finishLoading);
        }

        /* Prefetch the next page before the user asks for it. */
        function prefetchNextPage() {
            if (!state.hasMore || state.isLoading) return;
            var conn = navigator.connection;
            if (conn && (conn.saveData || /2g/.test(conn.effectiveType || ''))) return;

            var url = buildProductsURL(state.page + 1);
            if (cacheGet(url)) return;

            if (prefetchController) prefetchController.abort();
            prefetchController = new AbortController();
            fetchProductsData(url, prefetchController.signal).catch(function () {});
        }

        function maybePrefetchSoon() {
            if (!state.hasMore || !DOM.loadMoreBtn) return;
            var run = function () {
                if (!state.hasMore || DOM.loadMoreBtn.hidden) return;
                var rect = DOM.loadMoreBtn.getBoundingClientRect();
                if (rect.top < window.innerHeight + 600) prefetchNextPage();
            };
            if (window.requestIdleCallback) window.requestIdleCallback(run, { timeout: 1200 });
            else window.setTimeout(run, 250);
        }

        function setupPrefetchObserver() {
            if (!DOM.loadMoreBtn || !('IntersectionObserver' in window)) return;
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) prefetchNextPage();
                });
            }, { rootMargin: '600px 0px' });
            observer.observe(DOM.loadMoreBtn);
        }

        /* --------------------------- Product cards --------------------------- */

        function minorToMajor(value, minorUnit) {
            var amount = Number(value);
            if (!isFinite(amount)) return 0;
            return amount / Math.pow(10, Number(minorUnit) || 0);
        }

        function formatPrice(value) {
            return toFaNumber(Math.round(value));
        }

        function renderProducts(products) {
            if (products.length === 0 && state.page === 1) {
                DOM.productsGrid.innerHTML =
                    '<div class="ss-error-msg" style="color:var(--srv-gray);border-color:var(--srv-border)">' +
                        '<div>' + escapeHTML(TXT.noProducts) + '</div>' +
                        '<button type="button" class="ss-inline-btn" data-ss-reset>' + escapeHTML(TXT.clearFilters) + '</button>' +
                    '</div>';
                return;
            }

            var html = products.map(function (product) {
                var image = (product.images && product.images.length) ? product.images[0] : null;
                var imageURL = image ? safeURL(image.thumbnail || image.src) : '';
                var title = escapeHTML(product.name || TXT.unnamed);
                var link = safeURL(product.permalink) || '#';
                var minorUnit = product.prices ? product.prices.currency_minor_unit : 0;

                var rawPrice = product.prices && product.prices.price
                    ? minorToMajor(product.prices.price, minorUnit) : 0;
                var regularPrice = product.prices && product.prices.regular_price
                    ? minorToMajor(product.prices.regular_price, minorUnit) : 0;

                var isOutOfStock = product.is_in_stock === false ||
                    (product.add_to_cart && product.add_to_cart.maximum === 0);
                var stockClass = isOutOfStock ? ' is-out-of-stock' : '';
                var productType = product.type || 'simple';
                var canAddToCart = productType === 'simple' && !isOutOfStock && product.is_purchasable !== false;

                var dimensions = '';
                if (product.dimensions) {
                    var parts = [product.dimensions.length, product.dimensions.width, product.dimensions.height]
                        .filter(function (value) { return value && value !== '0'; });
                    if (parts.length) dimensions = parts.join(' × ');
                }
                if (!dimensions && Array.isArray(product.attributes)) {
                    var attribute = product.attributes.find(function (item) {
                        return item.name === TXT.dimensions || item.taxonomy === 'pa_dimensions';
                    });
                    if (attribute && attribute.terms && attribute.terms.length) {
                        dimensions = attribute.terms[0].name;
                    }
                }

                var discountBadge = '';
                if (regularPrice > rawPrice && rawPrice > 0 && !isOutOfStock) {
                    var discount = Math.min(99, Math.round(((regularPrice - rawPrice) / regularPrice) * 100));
                    if (discount > 0) {
                        discountBadge = '<span class="ss-badge-discount">' + toFaNumber(discount) + '٪-</span>';
                    }
                }

                var dimensionBadge = dimensions
                    ? '<span class="ss-badge-dim">' + escapeHTML(TXT.dimensions) + ' <span>' + escapeHTML(dimensions) + '</span></span>'
                    : '';

                var categoryName = product.categories && product.categories.length
                    ? escapeHTML(product.categories[0].name)
                    : escapeHTML(TXT.noCategory);

                var priceHTML = '';
                var range = product.prices && product.prices.price_range;
                if (range && range.min_amount && range.max_amount && range.min_amount !== range.max_amount) {
                    priceHTML =
                        '<span class="ss-price-new">' + formatPrice(minorToMajor(range.min_amount, minorUnit)) + ' – ' +
                        formatPrice(minorToMajor(range.max_amount, minorUnit)) + '</span>' +
                        '<span class="ss-price-currency">' + escapeHTML(CURRENCY) + '</span>';
                } else if (rawPrice <= 0) {
                    priceHTML = '<span class="ss-price-new">' + escapeHTML(TXT.priceOnRequest) + '</span>';
                } else if (regularPrice > rawPrice) {
                    priceHTML =
                        '<span class="ss-price-old">' + formatPrice(regularPrice) + '</span>' +
                        '<span class="ss-price-new">' + formatPrice(rawPrice) + '</span>' +
                        '<span class="ss-price-currency">' + escapeHTML(CURRENCY) + '</span>';
                } else {
                    priceHTML =
                        '<span class="ss-price-new">' + formatPrice(rawPrice) + '</span>' +
                        '<span class="ss-price-currency">' + escapeHTML(CURRENCY) + '</span>';
                }

                var imageHTML;
                if (imageURL) {
                    var srcset = image && image.srcset ? ' srcset="' + escapeHTML(image.srcset) + '"' : '';
                    var sizes = ' sizes="(max-width: 768px) 45vw, (max-width: 1100px) 22vw, 240px"';
                    var alt = image && image.alt ? escapeHTML(image.alt) : title;
                    imageHTML = '<img src="' + escapeHTML(imageURL) + '"' + srcset + sizes + ' alt="' + alt + '" loading="lazy" decoding="async">';
                } else {
                    imageHTML =
                        '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#F6F8F3">' +
                            '<svg width="24" height="24" viewBox="0 0 24 24" stroke="#9B846C" fill="none" aria-hidden="true">' +
                                '<rect x="3" y="3" width="18" height="18" rx="2"></rect>' +
                                '<circle cx="8.5" cy="8.5" r="1.5"></circle>' +
                                '<polyline points="21 15 16 10 5 21"></polyline>' +
                            '</svg>' +
                        '</div>';
                }

                var buttonHTML;
                if (isOutOfStock) {
                    buttonHTML =
                        '<button type="button" class="ss-card-btn is-unavailable" disabled>' +
                            '<span class="ss-btn-label">' + escapeHTML(TXT.outOfStock) + '</span>' +
                        '</button>';
                } else if (canAddToCart) {
                    buttonHTML =
                        '<button type="button" class="ss-card-btn" data-product-id="' + Number(product.id) + '" aria-label="' + escapeHTML(TXT.addToCart) + ' ' + title + '">' +
                            '<span class="ss-btn-label">' + escapeHTML(TXT.addToCart) + '</span>' +
                            '<svg class="ss-btn-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                                '<circle cx="9" cy="21" r="1"></circle>' +
                                '<circle cx="20" cy="21" r="1"></circle>' +
                                '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>' +
                            '</svg>' +
                        '</button>';
                } else {
                    buttonHTML =
                        '<a href="' + escapeHTML(link) + '" class="ss-card-btn ss-card-link-btn">' +
                            '<span class="ss-btn-label">' + escapeHTML(productType === 'variable' ? TXT.chooseOptions : TXT.viewProduct) + '</span>' +
                        '</a>';
                }

                return '' +
                    '<article class="ss-card' + stockClass + '">' +
                        '<a href="' + escapeHTML(link) + '" class="ss-card-img" aria-label="' + escapeHTML(TXT.viewProduct) + ' ' + title + '" tabindex="-1">' +
                            discountBadge +
                            dimensionBadge +
                            imageHTML +
                        '</a>' +
                        '<div class="ss-card-content">' +
                            '<a href="' + escapeHTML(link) + '" class="ss-card-title-link">' +
                                '<h3 class="ss-card-title">' + title + '</h3>' +
                            '</a>' +
                            '<div class="ss-card-cat">' + categoryName + '</div>' +
                            '<div class="ss-card-price-box">' + priceHTML + '</div>' +
                            buttonHTML +
                        '</div>' +
                    '</article>';
            }).join('');

            DOM.productsGrid.insertAdjacentHTML('beforeend', html);
        }

        /* ------------------------------ Cart (Store API) ------------------------------ */

        function getCartNonce(forceRefresh) {
            if (!forceRefresh && state.cartNonce) return Promise.resolve(state.cartNonce);
            if (!forceRefresh && state.cartNoncePromise) return state.cartNoncePromise;

            state.cartNoncePromise = fetch(REST_BASE + 'cart', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Cart request failed');
                var nonce = response.headers.get('Nonce');
                if (!nonce) throw new Error('Missing cart nonce');
                state.cartNonce = nonce;
                return nonce;
            }).finally(function () {
                state.cartNoncePromise = null;
            });

            return state.cartNoncePromise;
        }

        function notifyWooCommerceCartUpdated(cartData, button) {
            var productId = Number(button.dataset.productId);
            try {
                document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', {
                    bubbles: true,
                    detail: { preserveCartData: true, productId: productId, quantity: 1 }
                }));
            } catch (error) {}
            try {
                if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
            } catch (error) {}
            try {
                document.body.dispatchEvent(new CustomEvent('miran:cart-updated', {
                    bubbles: true,
                    detail: { cart: cartData, productId: productId, quantity: 1 }
                }));
            } catch (error) {}
        }

        async function addProductToCart(productId, button) {
            if (!productId || button.disabled ||
                button.classList.contains('is-animating') ||
                button.classList.contains('is-added')) {
                return;
            }

            var label = button.querySelector('.ss-btn-label');
            var originalText = label ? label.textContent : TXT.addToCart;
            var originalAria = button.getAttribute('aria-label');

            button.disabled = true;
            button.classList.add('is-animating');
            button.setAttribute('aria-busy', 'true');
            if (label) label.textContent = TXT.adding;

            try {
                var response = null;
                var data = null;
                var attempt = 0;

                while (attempt < 2) {
                    var nonce = await getCartNonce(attempt > 0);

                    response = await fetch(REST_BASE + 'cart/add-item', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'Nonce': nonce
                        },
                        body: JSON.stringify({ id: Number(productId), quantity: 1 })
                    });

                    var newNonce = response.headers.get('Nonce');
                    if (newNonce) state.cartNonce = newNonce;

                    data = await response.json().catch(function () { return {}; });
                    if (response.ok) break;

                    if ((response.status === 401 || response.status === 403) && attempt === 0) {
                        state.cartNonce = '';
                        attempt++;
                        continue;
                    }
                    throw new Error((data && data.message) || 'Add to cart failed');
                }

                if (!response || !response.ok) throw new Error('Add to cart failed');

                button.classList.remove('is-animating');
                button.classList.add('is-added');
                button.removeAttribute('aria-busy');
                button.setAttribute('aria-label', TXT.added);
                if (label) label.textContent = TXT.added;

                notifyWooCommerceCartUpdated(data, button);
                announce(TXT.added);

                /* Re-enable after a short confirmation so another unit can be added. */
                window.setTimeout(function () {
                    if (!button.isConnected) return;
                    button.classList.remove('is-added');
                    button.disabled = false;
                    if (label) label.textContent = originalText;
                    if (originalAria) button.setAttribute('aria-label', originalAria);
                }, 2600);
            } catch (error) {
                button.classList.remove('is-animating', 'is-added');
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.setAttribute('title', (error && error.message) || TXT.retry);
                if (label) label.textContent = TXT.retry;

                window.setTimeout(function () {
                    if (!button.isConnected || button.classList.contains('is-added')) return;
                    if (label) label.textContent = originalText;
                    button.removeAttribute('title');
                }, 2500);

                if (window.console) console.error('[Miran Shop] Add to cart failed:', error);
            }
        }

        /* ------------------------------ Filter flow ------------------------------ */

        function applyFilters(push) {
            updateURL(push !== false);
            updateFilterChrome();
            syncCategoryUI();
            fetchProducts(true);
        }

        function resetAllFilters() {
            state.search = '';
            state.sort = 'date';
            state.inStock = false;
            state.onSale = false;
            state.selectedCategories = [];
            state.selectedAttributes = {};
            syncControlsFromState();
            applyFilters(true);
        }

        function readFiltersFromDOM() {
            state.selectedCategories = Array.prototype.slice
                .call(root.querySelectorAll('.ss-cat-check:checked'))
                .map(function (input) { return String(input.value); });

            state.selectedAttributes = {};
            root.querySelectorAll('.ss-attr-check:checked').forEach(function (input) {
                var taxonomy = input.dataset.taxonomy;
                if (!state.selectedAttributes[taxonomy]) state.selectedAttributes[taxonomy] = [];
                state.selectedAttributes[taxonomy].push(String(input.value));
            });
        }

        /* ------------------------------ Mobile drawer ------------------------------ */

        var previousOverflow = '';

        function openDrawer() {
            root.classList.add('ss-drawer-open');
            previousOverflow = document.documentElement.style.overflow;
            document.documentElement.style.overflow = 'hidden';
            if (DOM.mobileFilterBtn) DOM.mobileFilterBtn.setAttribute('aria-expanded', 'true');
            if (DOM.drawerClose) DOM.drawerClose.focus();
        }

        function closeDrawer(returnFocus) {
            if (!root.classList.contains('ss-drawer-open')) return;
            root.classList.remove('ss-drawer-open');
            document.documentElement.style.overflow = previousOverflow;
            if (DOM.mobileFilterBtn) {
                DOM.mobileFilterBtn.setAttribute('aria-expanded', 'false');
                if (returnFocus) DOM.mobileFilterBtn.focus();
            }
        }

        /* ------------------------------ Events ------------------------------ */

        function setupEventListeners() {
            /* The form never really submits while JS is alive. */
            DOM.form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (DOM.searchInput) state.search = DOM.searchInput.value.trim();
                readFiltersFromDOM();
                applyFilters(true);
            });

            var searchTimeout = null;
            if (DOM.searchInput) {
                DOM.searchInput.addEventListener('input', function (event) {
                    window.clearTimeout(searchTimeout);
                    searchTimeout = window.setTimeout(function () {
                        state.search = event.target.value.trim();
                        /* replaceState while typing: avoids flooding the history. */
                        updateURL(false);
                        updateFilterChrome();
                        fetchProducts(true);
                    }, 400);
                });
            }

            DOM.sortButtons.forEach(function (button) {
                button.setAttribute('aria-pressed', button.classList.contains('active') ? 'true' : 'false');
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    if (state.sort === button.dataset.sort) return;
                    state.sort = button.dataset.sort;
                    DOM.sortButtons.forEach(function (item) {
                        var active = item === button;
                        item.classList.toggle('active', active);
                        item.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                    applyFilters(true);
                });
            });

            if (DOM.filterInStock) {
                DOM.filterInStock.addEventListener('change', function (event) {
                    state.inStock = event.target.checked;
                    applyFilters(true);
                });
            }

            if (DOM.filterOnSale) {
                DOM.filterOnSale.addEventListener('change', function (event) {
                    state.onSale = event.target.checked;
                    applyFilters(true);
                });
            }

            if (DOM.dynamicFiltersBox) {
                /* Accordion toggling (real <button>s: keyboard support is native). */
                DOM.dynamicFiltersBox.addEventListener('click', function (event) {
                    var header = event.target.closest('button.ss-acc-header');
                    if (!header) return;
                    var accordion = header.closest('.ss-accordion');
                    if (!accordion) return;
                    var isOpen = accordion.classList.toggle('active');
                    header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });

                /* Checkbox filters via 'change' (reliable for mouse + keyboard). */
                DOM.dynamicFiltersBox.addEventListener('change', function (event) {
                    if (!event.target.matches('input[type="checkbox"]')) return;
                    readFiltersFromDOM();
                    applyFilters(true);
                });
            }

            if (DOM.topCategoriesBox) {
                DOM.topCategoriesBox.addEventListener('click', function (event) {
                    /* Let modified clicks open the real link in a new tab. */
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

                    var back = event.target.closest('[data-ss-cat-back]');
                    if (back) {
                        event.preventDefault();
                        var target = (back.getAttribute('data-target-slug') || '').trim();
                        state.selectedCategories = target ? [target] : [];
                        applyFilters(true);
                        return;
                    }

                    var link = event.target.closest('a[data-cat-slug]');
                    if (!link) return;
                    event.preventDefault();

                    var slug = link.dataset.catSlug;
                    if (link.hasAttribute('data-ss-all-parent')) {
                        /* "All of <parent>" always selects the parent (no toggle-off). */
                        state.selectedCategories = [slug];
                    } else {
                        state.selectedCategories =
                            (state.selectedCategories.length === 1 && state.selectedCategories[0] === slug)
                                ? []
                                : [slug];
                    }
                    applyFilters(true);
                });
            }

            if (DOM.breadcrumbs) {
                DOM.breadcrumbs.addEventListener('click', function (event) {
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

                    var catLink = event.target.closest('[data-ss-cat-link]');
                    if (catLink) {
                        event.preventDefault();
                        state.selectedCategories = [catLink.getAttribute('data-ss-cat-link')];
                        applyFilters(true);
                        return;
                    }

                    var link = event.target.closest('[data-ss-shop-link]');
                    if (!link) return;
                    event.preventDefault();
                    resetAllFilters();
                });
            }

            if (DOM.clearAllBtn) {
                DOM.clearAllBtn.addEventListener('click', function () {
                    resetAllFilters();
                });
            }

            /* Grid clicks: add-to-cart, retry and empty-state reset. */
            DOM.productsGrid.addEventListener('click', function (event) {
                if (event.target.closest('[data-ss-retry]')) {
                    fetchProducts(true);
                    return;
                }
                if (event.target.closest('[data-ss-reset]')) {
                    resetAllFilters();
                    return;
                }
                var button = event.target.closest('.ss-card-btn[data-product-id]');
                if (!button || !DOM.productsGrid.contains(button)) return;
                event.preventDefault();
                event.stopPropagation();
                addProductToCart(button.dataset.productId, button);
            });

            if (DOM.loadMoreBtn) {
                DOM.loadMoreBtn.parentNode.addEventListener('click', function (event) {
                    if (event.target.closest('[data-ss-retry-more]')) {
                        if (!state.isLoading && state.hasMore) fetchProducts(false);
                    }
                });

                DOM.loadMoreBtn.addEventListener('click', function () {
                    if (!state.hasMore || state.isLoading) return;
                    fetchProducts(false);
                });
            }

            /* Mobile drawer */
            if (DOM.mobileFilterBtn) {
                DOM.mobileFilterBtn.addEventListener('click', openDrawer);
            }
            if (DOM.drawerClose) {
                DOM.drawerClose.addEventListener('click', function () { closeDrawer(true); });
            }
            if (DOM.drawerApply) {
                DOM.drawerApply.addEventListener('click', function () { closeDrawer(true); });
            }
            if (DOM.drawerOverlay) {
                DOM.drawerOverlay.addEventListener('click', function () { closeDrawer(true); });
            }
            root.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeDrawer(true);
            });
            if (window.matchMedia) {
                var desktopQuery = window.matchMedia('(min-width: 769px)');
                var onDesktopChange = function (query) { if (query.matches) closeDrawer(false); };
                if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', onDesktopChange);
                else if (desktopQuery.addListener) desktopQuery.addListener(onDesktopChange);
            }

            /* Back / forward: re-read the URL, sync the UI, refetch — no push. */
            window.addEventListener('popstate', function () {
                var currentLocationKey = relevantLocationKey();
                if (currentLocationKey === lastLocationKey) return;
                lastLocationKey = currentLocationKey;

                var fresh = parseStateFromURL();
                var page = fresh.page;
                Object.assign(state, fresh);
                syncControlsFromState();
                fetchProducts(true, page);
            });
        }

        /* ------------------------------ Boot ------------------------------ */

        function sameList(a, b) {
            a = (a || []).map(String).sort();
            b = (b || []).map(String).sort();
            return a.length === b.length && a.every(function (value, i) { return value === b[i]; });
        }

        function ssrMatchesURLState() {
            if (!root.dataset.ssrState) return true;
            try {
                var ssr = JSON.parse(root.dataset.ssrState);
            } catch (error) {
                return true;
            }
            if ((ssr.search || '') !== state.search) return false;
            if ((ssr.sort || 'date') !== state.sort) return false;
            if (Boolean(ssr.inStock) !== state.inStock) return false;
            if (Boolean(ssr.onSale) !== state.onSale) return false;
            if (!sameList(ssr.categories, state.selectedCategories)) return false;

            var taxonomies = {};
            Object.keys(ssr.attributes || {}).forEach(function (t) { taxonomies[t] = true; });
            Object.keys(state.selectedAttributes).forEach(function (t) { taxonomies[t] = true; });
            return Object.keys(taxonomies).every(function (taxonomy) {
                return sameList((ssr.attributes || {})[taxonomy], state.selectedAttributes[taxonomy]);
            });
        }

        /* Products/filter UI are already server-rendered: no initial fetch —
         * unless a page cache served HTML rendered for different filters. */
        syncControlsFromState();
        updateLoadMoreVisibility();
        setupEventListeners();
        setupPrefetchObserver();
        maybePrefetchSoon();
        if (!ssrMatchesURLState()) {
            fetchProducts(true, state.page);
        }
    }

    function boot() {
        document.querySelectorAll('.miran-shop-widget').forEach(initMiranShop);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
