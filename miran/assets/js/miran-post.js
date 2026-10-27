/**
 * Miran — single article enhancements.
 *
 * Article content, comments and permalinks are native WordPress. This
 * file only adds:
 *  1. a reading-progress bar for the article,
 *  2. an automatic table of contents from the article headings
 *     (with active-section highlighting), and
 *  3. the copy-link share button.
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

  function initProgress(root) {
    var bar = root.querySelector('[data-svp-progress]');
    var article = root.querySelector('.svp-article');
    if (!bar || !article) return;

    var ticking = false;
    function update() {
      ticking = false;
      var rect = article.getBoundingClientRect();
      var total = rect.height - window.innerHeight;
      var read = Math.min(Math.max(-rect.top, 0), Math.max(total, 0));
      var pct = total > 0 ? (read / total) * 100 : 0;
      bar.style.width = pct.toFixed(2) + '%';
    }
    function schedule() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(update);
    }
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    update();
  }

  function initToc(root) {
    var widget = root.querySelector('[data-svp-toc]');
    var list = root.querySelector('[data-svp-toc-list]');
    var content = root.querySelector('.svp-content');
    if (!widget || !list || !content) return;

    var headings = Array.prototype.slice.call(
      content.querySelectorAll('h2, h3')
    ).filter(function (h) { return h.textContent.trim() !== ''; });
    if (headings.length < 2) return;

    var links = [];
    headings.forEach(function (heading, index) {
      if (!heading.id) heading.id = 'svp-h-' + (index + 1);
      var li = document.createElement('li');
      if (heading.tagName === 'H3') li.className = 'is-sub';
      var a = document.createElement('a');
      a.href = '#' + heading.id;
      a.textContent = heading.textContent.trim();
      a.addEventListener('click', function (event) {
        event.preventDefault();
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        heading.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '#' + heading.id);
        }
      });
      li.appendChild(a);
      list.appendChild(li);
      links.push({ heading: heading, link: a });
    });
    widget.hidden = false;

    if ('IntersectionObserver' in window) {
      var current = null;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          if (current) current.classList.remove('is-active');
          var found = links.filter(function (item) { return item.heading === entry.target; })[0];
          if (found) {
            found.link.classList.add('is-active');
            current = found.link;
          }
        });
      }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });
      links.forEach(function (item) { io.observe(item.heading); });
    }
  }

  function initCopy(root) {
    root.querySelectorAll('[data-svp-copy]').forEach(function (button) {
      button.addEventListener('click', function () {
        var url = button.getAttribute('data-svp-copy') || window.location.href;
        var label = button.querySelector('[data-svp-copy-label]');
        function done() {
          if (!label) return;
          var original = label.textContent;
          label.textContent = 'کپی شد';
          window.setTimeout(function () { label.textContent = original; }, 2200);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(done, function () { fallback(); });
        } else {
          fallback();
        }
        function fallback() {
          var area = document.createElement('textarea');
          area.value = url;
          area.style.position = 'fixed';
          area.style.opacity = '0';
          document.body.appendChild(area);
          area.select();
          try { document.execCommand('copy'); done(); } catch (e) { /* no-op */ }
          area.remove();
        }
      });
    });
  }

  onReady(function () {
    document.querySelectorAll('[data-miran-post]').forEach(function (root) {
      initProgress(root);
      initToc(root);
      initCopy(root);
    });
  });
})();
