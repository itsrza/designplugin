/*
 * Miran Blog — progressive enhancement.
 * Page 1 (posts + category chips) is server-rendered by PHP. This script adds
 * AJAX filtering/search/load-more on top, with URL sync, request cancellation,
 * a small in-memory cache and next-page prefetching.
 * Data comes from the plugin's own slim endpoint (miran/v1/posts), which
 * returns only the fields a card needs — no full post content, no _embed blob.
 */
(function () {
    'use strict';

    var CFG = window.MiranBlogData || {};
    var REST_BASE = String(CFG.restBase || '/wp-json/miran/v1/').replace(/\/?$/, '/');
    var PAGE_URL = CFG.pageUrl || window.location.pathname;
    var DEFAULT_PER_PAGE = parseInt(CFG.perPage, 10) || 9;
    var RESULTS_TTL = 60000;

    var TXT = Object.assign({
        featured: 'مقاله ویژه',
        readMore: 'ادامه مطلب',
        readArticle: 'مطالعه مقاله',
        read: 'مطالعه',
        minutes: 'دقیقه مطالعه',
        emptyTitle: 'مقاله‌ای یافت نشد',
        emptyText: 'با فیلتر یا جستجوی دیگری امتحان کنید.',
        resetFilters: 'بازنشانی فیلترها',
        errorTitle: 'بارگذاری مقالات با خطا مواجه شد',
        errorText: 'لطفاً اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.',
        loadMoreError: 'دریافت مقالات بیشتر انجام نشد.',
        retry: 'تلاش مجدد',
        resultsAnnounce: 'مقاله نمایش داده شد.'
    }, CFG.i18n || {});

    function escapeHTML(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;', '<': '&lt;', '>': '&gt;',
                '"': '&quot;', "'": '&#039;'
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

    function trunc(text, length) {
        var value = String(text || '').replace(/\s+/g, ' ').trim();
        return value.length > length ? value.slice(0, length).trim() + '…' : value;
    }

    function icon(name, size) {
        return '<svg width="' + size + '" height="' + size + '" aria-hidden="true"><use href="#bi-' + name + '"/></svg>';
    }

    function initMiranBlog(root) {
        if (!root || root.dataset.sbInitialized === '1') return;
        root.dataset.sbInitialized = '1';

        var DOM = {
            form: root.querySelector('.b-form'),
            searchInput: root.querySelector('.b-search-input'),
            filters: root.querySelector('.b-filters'),
            featured: root.querySelector('.b-featured-slot'),
            grid: root.querySelector('.b-grid'),
            moreWrap: root.querySelector('.b-more-wrap'),
            moreBtn: root.querySelector('.b-more'),
            hero: root.querySelector('.b-hero'),
            glow: root.querySelector('.b-hero-glow'),
            liveRegion: root.querySelector('.b-live-region')
        };
        if (!DOM.grid || !DOM.form) return;

        /* ------------------------------- State ------------------------------- */

        function parseStateFromURL() {
            var params = new URLSearchParams(window.location.search);
            return {
                page: Math.max(1, parseInt(params.get('sb_page'), 10) || 1),
                perPage: parseInt(root.dataset.perPage, 10) || DEFAULT_PER_PAGE,
                cat: (params.get('sb_cat') || '').trim(),
                search: (params.get('sb_q') || '').trim()
            };
        }

        var state = parseStateFromURL();
        state.page = Math.max(1, parseInt(root.dataset.currentPage, 10) || state.page);
        state.totalPages = Math.max(1, parseInt(root.dataset.totalPages, 10) || 1);
        state.hasMore = state.page < state.totalPages;
        state.isLoading = false;
        state.requestId = 0;

        var abortController = null;
        var prefetchController = null;
        var resultsCache = new Map();

        /* Path + query identify the blog state; the fragment never does.
         * Remembering this lets popstate ignore fragment-only navigation
         * such as a theme's "#" back-to-top link or in-page anchors. */
        function relevantLocationKey() {
            return window.location.pathname + window.location.search;
        }
        var lastLocationKey = relevantLocationKey();

        /* -------------------------------- URLs -------------------------------- */

        function buildPageURL(page) {
            var url = new URL(PAGE_URL, window.location.origin);
            if (state.cat) url.searchParams.set('sb_cat', state.cat);
            if (state.search) url.searchParams.set('sb_q', state.search);
            if (page && page > 1) url.searchParams.set('sb_page', String(page));
            return url.pathname + url.search;
        }

        function updateURL(push) {
            var url = buildPageURL(1);
            if (url === relevantLocationKey()) {
                lastLocationKey = url;
                return;
            }
            if (push) window.history.pushState({ miranBlog: true }, '', url);
            else window.history.replaceState({ miranBlog: true }, '', url);
            lastLocationKey = relevantLocationKey();
        }

        function buildPostsURL(page) {
            var params = new URLSearchParams();
            params.set('page', String(page));
            params.set('per_page', String(state.perPage));
            if (state.cat) params.set('cat', state.cat);
            if (state.search) params.set('q', state.search);
            return REST_BASE + 'posts?' + params.toString();
        }

        /* ------------------------------- Rendering ------------------------------- */

        function announce(message) {
            if (!DOM.liveRegion) return;
            DOM.liveRegion.textContent = '';
            window.setTimeout(function () { DOM.liveRegion.textContent = message; }, 40);
        }

        function imageHTML(post, featured) {
            var img = post.image;
            if (!img || !img.src) {
                return '<div class="b-noimg">' + icon('doc', featured ? 46 : 40) + '</div>';
            }
            var srcset = img.srcset ? ' srcset="' + escapeHTML(img.srcset) + '"' : '';
            var sizes = featured
                ? ' sizes="(max-width: 768px) 100vw, 560px"'
                : ' sizes="(max-width: 768px) 100vw, (max-width: 1100px) 50vw, 380px"';
            var loading = featured ? 'eager' : 'lazy';
            var priority = featured ? ' fetchpriority="high"' : '';
            var dims = (img.width && img.height)
                ? ' width="' + Number(img.width) + '" height="' + Number(img.height) + '"'
                : '';
            return '<img src="' + escapeHTML(safeURL(img.src)) + '"' + srcset + sizes +
                ' alt="' + escapeHTML(img.alt || post.title) + '"' + dims +
                ' loading="' + loading + '" decoding="async"' + priority + '>';
        }

        function metaHTML(post, withCategory) {
            var html = '';
            if (withCategory && post.category) {
                html += '<span class="b-cat">' + escapeHTML(post.category.name) + '</span>';
            }
            html += '<span><svg width="13" height="13" style="vertical-align:-2px;color:#96632E" aria-hidden="true"><use href="#bi-cal"/></svg> ' +
                escapeHTML(post.date_fa) + '</span>';
            html += '<span><svg width="13" height="13" style="vertical-align:-2px;color:#96632E" aria-hidden="true"><use href="#bi-clock"/></svg> ' +
                toFaNumber(post.read_minutes || 1) + ' ' + escapeHTML(TXT.minutes) + '</span>';
            return html;
        }

        function featuredHTML(post) {
            var link = safeURL(post.link) || '#';
            return '' +
                '<article class="b-feat b-r">' +
                    '<div class="b-feat-img">' +
                        '<span class="b-feat-badge">' + icon('star', 12) + escapeHTML(TXT.featured) + '</span>' +
                        imageHTML(post, true) +
                    '</div>' +
                    '<div class="b-feat-body">' +
                        '<div class="b-feat-meta">' + metaHTML(post, true) + '</div>' +
                        '<h2><a href="' + escapeHTML(link) + '">' + escapeHTML(post.title) + '</a></h2>' +
                        '<p>' + escapeHTML(trunc(post.excerpt, 180)) + '</p>' +
                        '<a class="b-feat-link" href="' + escapeHTML(link) + '">' + escapeHTML(TXT.readMore) + ' ' + icon('arrow', 16) + '</a>' +
                    '</div>' +
                '</article>';
        }

        function cardHTML(post, i) {
            var link = safeURL(post.link) || '#';
            var catBadge = post.category
                ? '<span class="b-card-cat">' + escapeHTML(post.category.name) + '</span>'
                : '';
            return '' +
                '<article class="b-card b-r" style="transition-delay:' + (i * 60) + 'ms">' +
                    '<a href="' + escapeHTML(link) + '" class="b-card-img" aria-label="' + escapeHTML(post.title) + '">' +
                        catBadge + imageHTML(post, false) +
                    '</a>' +
                    '<div class="b-card-body">' +
                        '<div class="b-card-meta">' + metaHTML(post, false) + '</div>' +
                        '<h3><a href="' + escapeHTML(link) + '">' + escapeHTML(post.title) + '</a></h3>' +
                        '<p>' + escapeHTML(trunc(post.excerpt, 110)) + '</p>' +
                        '<div class="b-card-foot">' +
                            '<a class="b-card-read" href="' + escapeHTML(link) + '">' + escapeHTML(TXT.readArticle) + '</a>' +
                            '<a class="b-card-ar" href="' + escapeHTML(link) + '" aria-label="' + escapeHTML(TXT.read) + '">' + icon('arrow', 14) + '</a>' +
                        '</div>' +
                    '</div>' +
                '</article>';
        }

        function emptyHTML() {
            return '' +
                '<div class="b-empty" style="grid-column:1/-1">' +
                    '<div class="b-empty-ic">' + icon('doc', 30) + '</div>' +
                    '<h3>' + escapeHTML(TXT.emptyTitle) + '</h3>' +
                    '<p>' + escapeHTML(TXT.emptyText) + '</p>' +
                    '<button type="button" data-sb-reset>' + escapeHTML(TXT.resetFilters) + '</button>' +
                '</div>';
        }

        function errorHTML() {
            return '' +
                '<div class="b-empty" style="grid-column:1/-1" role="alert">' +
                    '<div class="b-empty-ic">' + icon('doc', 30) + '</div>' +
                    '<h3>' + escapeHTML(TXT.errorTitle) + '</h3>' +
                    '<p>' + escapeHTML(TXT.errorText) + '</p>' +
                    '<button type="button" data-sb-retry>' + escapeHTML(TXT.retry) + '</button>' +
                '</div>';
        }

        function skeletonsHTML(count) {
            var one = '<div class="b-skel" aria-hidden="true"><div class="b-skel-img"></div>' +
                '<div class="b-skel-body"><div class="b-skel-line sm"></div>' +
                '<div class="b-skel-line lg"></div><div class="b-skel-line"></div>' +
                '<div class="b-skel-line" style="width:70%"></div></div></div>';
            return one.repeat(count);
        }

        function revealNew(container) {
            window.requestAnimationFrame(function () {
                container.querySelectorAll('.b-r:not(.b-in)').forEach(function (el) {
                    el.classList.add('b-in');
                });
            });
        }

        function shouldShowFeatured() {
            return state.page === 1 && !state.cat && !state.search;
        }

        function renderResult(result, append) {
            var posts = result.posts;

            if (!append) {
                var gridPosts = posts;
                if (shouldShowFeatured() && posts.length) {
                    DOM.featured.innerHTML = featuredHTML(posts[0]);
                    gridPosts = posts.slice(1);
                } else {
                    DOM.featured.innerHTML = '';
                }
                DOM.grid.innerHTML = gridPosts.length ? gridPosts.map(cardHTML).join('') : emptyHTML();
                revealNew(DOM.featured);
            } else {
                removeSkeletons();
                DOM.grid.insertAdjacentHTML('beforeend', posts.map(cardHTML).join(''));
            }
            revealNew(DOM.grid);
        }

        function removeSkeletons() {
            DOM.grid.querySelectorAll('.b-skel').forEach(function (el) { el.remove(); });
        }

        function updateMoreVisibility() {
            if (DOM.moreWrap) DOM.moreWrap.hidden = !state.hasMore;
            if (DOM.moreBtn) DOM.moreBtn.disabled = state.isLoading;
        }

        function clearMoreError() {
            var box = root.querySelector('.b-more-error');
            if (box) box.remove();
        }

        function showMoreError() {
            clearMoreError();
            var box = document.createElement('div');
            box.className = 'b-more-error';
            box.setAttribute('role', 'alert');
            box.innerHTML = '<div>' + escapeHTML(TXT.loadMoreError) + '</div>' +
                '<button type="button" data-sb-retry-more>' + escapeHTML(TXT.retry) + '</button>';
            DOM.moreWrap.parentNode.insertBefore(box, DOM.moreWrap.nextSibling);
        }

        function syncFilterUI() {
            if (!DOM.filters) return;
            DOM.filters.querySelectorAll('[data-cat-slug]').forEach(function (chip) {
                var active = (chip.getAttribute('data-cat-slug') || '') === state.cat;
                chip.setAttribute('aria-pressed', active ? 'true' : 'false');
                if (active) chip.setAttribute('aria-current', 'true');
                else chip.removeAttribute('aria-current');
            });
            if (DOM.searchInput && DOM.searchInput.value !== state.search) {
                DOM.searchInput.value = state.search;
            }
        }

        /* ------------------------------- Data ------------------------------- */

        function cacheGet(url) {
            var hit = resultsCache.get(url);
            if (hit && (Date.now() - hit.ts) < RESULTS_TTL) return hit.result;
            if (hit) resultsCache.delete(url);
            return null;
        }

        function fetchPostsData(url, signal) {
            return fetch(url, {
                signal: signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                var total = parseInt(response.headers.get('X-WP-Total'), 10);
                var totalPages = parseInt(response.headers.get('X-WP-TotalPages'), 10) || 1;
                return response.json().then(function (posts) {
                    if (!Array.isArray(posts)) throw new Error('Invalid response');
                    var result = {
                        posts: posts,
                        total: isNaN(total) ? posts.length : total,
                        totalPages: totalPages
                    };
                    resultsCache.set(url, { ts: Date.now(), result: result });
                    return result;
                });
            });
        }

        function fetchPosts(reset, pageOverride) {
            if (abortController) abortController.abort();
            if (reset && prefetchController) prefetchController.abort();

            var requestId = ++state.requestId;
            var targetPage = reset ? (pageOverride || 1) : state.page + 1;
            var requestURL = buildPostsURL(targetPage);

            function applyResult(result) {
                state.page = targetPage;
                state.totalPages = result.totalPages;
                state.hasMore = state.page < state.totalPages;
                renderResult(result, !reset);
                announce(toFaNumber(result.total) + ' ' + TXT.resultsAnnounce);
                maybePrefetchSoon();
            }

            function finishLoading() {
                if (requestId !== state.requestId) return;
                state.isLoading = false;
                DOM.grid.removeAttribute('aria-busy');
                if (DOM.moreBtn) DOM.moreBtn.classList.remove('loading');
                updateMoreVisibility();
            }

            var cached = cacheGet(requestURL);
            if (cached) {
                abortController = new AbortController();
                state.isLoading = true;
                clearMoreError();
                applyResult(cached);
                finishLoading();
                return;
            }

            abortController = new AbortController();
            state.isLoading = true;
            DOM.grid.setAttribute('aria-busy', 'true');
            clearMoreError();

            if (reset) {
                DOM.featured.innerHTML = '';
                DOM.grid.innerHTML = skeletonsHTML(6);
                if (DOM.moreWrap) DOM.moreWrap.hidden = true;
            } else {
                DOM.grid.insertAdjacentHTML('beforeend', skeletonsHTML(3));
                if (DOM.moreBtn) DOM.moreBtn.classList.add('loading');
            }

            fetchPostsData(requestURL, abortController.signal)
                .then(function (result) {
                    if (requestId !== state.requestId) return;
                    applyResult(result);
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') return;
                    if (requestId !== state.requestId) return;
                    removeSkeletons();
                    if (reset) {
                        DOM.featured.innerHTML = '';
                        DOM.grid.innerHTML = errorHTML();
                    } else {
                        showMoreError();
                    }
                    announce(TXT.errorTitle);
                })
                .finally(finishLoading);
        }

        function prefetchNextPage() {
            if (!state.hasMore || state.isLoading) return;
            var conn = navigator.connection;
            if (conn && (conn.saveData || /2g/.test(conn.effectiveType || ''))) return;
            var url = buildPostsURL(state.page + 1);
            if (cacheGet(url)) return;
            if (prefetchController) prefetchController.abort();
            prefetchController = new AbortController();
            fetchPostsData(url, prefetchController.signal).catch(function () {});
        }

        function maybePrefetchSoon() {
            if (!state.hasMore || !DOM.moreWrap) return;
            var run = function () {
                if (!state.hasMore || DOM.moreWrap.hidden) return;
                var rect = DOM.moreWrap.getBoundingClientRect();
                if (rect.top < window.innerHeight + 600) prefetchNextPage();
            };
            if (window.requestIdleCallback) window.requestIdleCallback(run, { timeout: 1200 });
            else window.setTimeout(run, 250);
        }

        /* ------------------------------- Flow ------------------------------- */

        function applyFilters(push) {
            updateURL(push !== false);
            syncFilterUI();
            fetchPosts(true);
        }

        function resetFilters() {
            state.cat = '';
            state.search = '';
            applyFilters(true);
        }

        function setupGlow() {
            if (!DOM.hero || !DOM.glow) return;
            if (!window.matchMedia) return;
            if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            var rafId = null;
            var lastEvent = null;
            DOM.hero.addEventListener('mousemove', function (event) {
                lastEvent = event;
                if (rafId !== null) return;
                rafId = window.requestAnimationFrame(function () {
                    rafId = null;
                    if (!lastEvent) return;
                    var rect = DOM.hero.getBoundingClientRect();
                    DOM.glow.style.left = (lastEvent.clientX - rect.left) + 'px';
                    DOM.glow.style.top = (lastEvent.clientY - rect.top) + 'px';
                });
            }, { passive: true });
        }

        function setupEventListeners() {
            DOM.form.addEventListener('submit', function (event) {
                event.preventDefault();
                state.search = DOM.searchInput ? DOM.searchInput.value.trim() : '';
                applyFilters(true);
            });

            if (DOM.filters) {
                DOM.filters.addEventListener('click', function (event) {
                    var chip = event.target.closest('[data-cat-slug]');
                    if (!chip) return;
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                    event.preventDefault();
                    var slug = chip.getAttribute('data-cat-slug') || '';
                    if (slug === state.cat) return;
                    state.cat = slug;
                    applyFilters(true);
                });
            }

            var searchTimer = null;
            if (DOM.searchInput) {
                DOM.searchInput.addEventListener('input', function (event) {
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(function () {
                        state.search = event.target.value.trim();
                        updateURL(false);
                        fetchPosts(true);
                    }, 420);
                });
            }

            if (DOM.moreBtn) {
                DOM.moreBtn.addEventListener('click', function () {
                    if (state.isLoading || !state.hasMore) return;
                    fetchPosts(false);
                });
            }

            root.addEventListener('click', function (event) {
                if (event.target.closest('[data-sb-retry]')) {
                    fetchPosts(true);
                } else if (event.target.closest('[data-sb-retry-more]')) {
                    if (!state.isLoading && state.hasMore) fetchPosts(false);
                } else if (event.target.closest('[data-sb-reset]')) {
                    resetFilters();
                }
            });

            window.addEventListener('popstate', function () {
                var currentLocationKey = relevantLocationKey();
                if (currentLocationKey === lastLocationKey) return;
                lastLocationKey = currentLocationKey;

                var fresh = parseStateFromURL();
                var page = fresh.page;
                Object.assign(state, fresh);
                syncFilterUI();
                fetchPosts(true, page);
            });

            if (DOM.moreWrap && 'IntersectionObserver' in window) {
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) prefetchNextPage();
                    });
                }, { rootMargin: '600px 0px' });
                observer.observe(DOM.moreWrap);
            }
        }

        /* ------------------------------- Boot ------------------------------- */

        function sameStateAsSSR() {
            if (!root.dataset.ssrState) return true;
            try {
                var ssr = JSON.parse(root.dataset.ssrState);
            } catch (error) {
                return true;
            }
            return (ssr.cat || '') === state.cat &&
                (ssr.search || '') === state.search &&
                Number(ssr.page || 1) === state.page;
        }

        /* Posts are server-rendered: no initial fetch (the original version
         * waited for the categories request before even asking for posts). */
        syncFilterUI();
        updateMoreVisibility();
        setupEventListeners();
        setupGlow();
        if (!sameStateAsSSR()) {
            fetchPosts(true, state.page);
        } else {
            maybePrefetchSoon();
        }
    }

    function boot() {
        document.querySelectorAll('.miran-blog').forEach(initMiranBlog);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
