/* ═══════════════════════════════════════════════════════════════
   CPDO Land Portal — Client-side utilities
   ═══════════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    /* ── Session timeout ── */
    var timeout;
    var TIMEOUT_MS = 15 * 60 * 1000;

    function resetTimer() {
        window.clearTimeout(timeout);
        timeout = window.setTimeout(function () {
            var marker = '/public/';
            var path   = window.location.pathname;
            var base   = path.includes(marker)
                ? path.slice(0, path.indexOf(marker) + marker.length)
                : '/';
            window.location.href = base + 'logout.php?reason=timeout';
        }, TIMEOUT_MS);
    }

    ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (ev) {
        window.addEventListener(ev, resetTimer, { passive: true });
    });
    resetTimer();

    /* ── DLP: block right-click on sensitive elements ── */
    document.addEventListener('contextmenu', function (e) {
        if (e.target.closest('[data-sensitive]')) e.preventDefault();
    });

    /* ── DLP: block Ctrl+S, Ctrl+P, PrintScreen ── */
    document.addEventListener('keydown', function (e) {
        var k = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && ['s', 'p'].includes(k)) e.preventDefault();
        if (k === 'printscreen') navigator.clipboard && navigator.clipboard.writeText('');
    });

    /* ── Carousel navigation ── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-carousel]');
        if (!btn) return;
        var el  = document.getElementById(btn.dataset.carousel);
        var dir = btn.classList.contains('carousel-nav--next') ? 1 : -1;
        if (el) el.scrollBy({ left: dir * 320, behavior: 'smooth' });
    });

    /* ══════════════════════════════════════════════════════════
       SHARED PREVIEW MODAL
       Usage:
         window.CPDO.preview.open(url, type, title, meta)
         window.CPDO.preview.close()
       ══════════════════════════════════════════════════════════ */

    var backdrop, modal, titleEl, metaEl, bodyEl;

    function ensureModal() {
        if (backdrop) return;

        backdrop = document.createElement('div');
        backdrop.className = 'preview-modal-backdrop is-hidden';
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-label', 'Document Preview');

        backdrop.innerHTML = [
            '<div class="preview-modal">',
            '  <div class="preview-modal-header">',
            '    <div>',
            '      <div class="preview-modal-title" id="pm-title">Document Preview</div>',
            '      <div class="preview-modal-meta" id="pm-meta"></div>',
            '    </div>',
            '    <button class="preview-modal-close" id="pm-close" aria-label="Close preview" type="button">&#x2715;</button>',
            '  </div>',
            '  <div class="preview-modal-body" id="pm-body">',
            '    <div class="preview-modal-loading" id="pm-loading">Loading…</div>',
            '  </div>',
            '  <div class="preview-modal-footer">',
            '    <a class="btn btn-outline-secondary btn-sm" id="pm-open-tab" target="_blank" rel="noopener">Open in new tab</a>',
            '    <button class="btn btn-outline-secondary btn-sm" id="pm-close2" type="button">Close</button>',
            '  </div>',
            '</div>',
        ].join('');

        document.body.appendChild(backdrop);

        modal   = backdrop.querySelector('.preview-modal');
        titleEl = backdrop.querySelector('#pm-title');
        metaEl  = backdrop.querySelector('#pm-meta');
        bodyEl  = backdrop.querySelector('#pm-body');

        backdrop.querySelector('#pm-close').addEventListener('click', closePreview);
        backdrop.querySelector('#pm-close2').addEventListener('click', closePreview);
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closePreview();
        });
    }

    function openPreview(url, type, title, meta) {
        ensureModal();

        titleEl.textContent = title || 'Document Preview';
        metaEl.textContent  = meta  || '';

        var openTab = backdrop.querySelector('#pm-open-tab');
        if (openTab) openTab.href = url;

        // Clear body, show loading
        bodyEl.innerHTML = '<div class="preview-modal-loading" id="pm-loading">Loading…</div>';

        var ext = (type || '').toLowerCase().replace('.', '');
        var isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext);

        if (isImage) {
            var img = document.createElement('img');
            img.alt = title || 'Preview';
            img.onload  = function () { bodyEl.innerHTML = ''; bodyEl.appendChild(img); };
            img.onerror = function () { bodyEl.innerHTML = '<div class="preview-modal-loading">Could not load image.</div>'; };
            img.src = url;
        } else {
            var iframe = document.createElement('iframe');
            iframe.title = title || 'Preview';
            iframe.onload = function () {
                var loading = bodyEl.querySelector('#pm-loading');
                if (loading) loading.remove();
            };
            iframe.src = url;
            bodyEl.innerHTML = '';
            bodyEl.appendChild(iframe);
        }

        backdrop.classList.remove('is-hidden');
        document.body.style.overflow = 'hidden';
    }

    function closePreview() {
        if (!backdrop) return;
        backdrop.classList.add('is-hidden');
        if (bodyEl) bodyEl.innerHTML = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePreview();
    });

    /* ── Wire up any [data-preview-btn] buttons already in the DOM ── */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-preview-btn]');
        if (!btn) return;
        e.preventDefault();
        var url   = btn.dataset.previewUrl;
        var type  = btn.dataset.previewType || '';
        var title = btn.dataset.previewTitle || btn.closest('[data-upload-row]') && btn.closest('[data-upload-row]').querySelector('strong') && btn.closest('[data-upload-row]').querySelector('strong').textContent || 'Document';
        var meta  = btn.dataset.previewMeta || '';
        if (url) openPreview(url, type, title, meta);
    });

    /* ── Expose globally for inline scripts ── */
    window.CPDO = window.CPDO || {};
    window.CPDO.preview = { open: openPreview, close: closePreview };

    /* ── Eval select color sync ── */
    function syncEvalSelect(sel) {
        sel.classList.remove('status-pending', 'status-passed', 'status-failed');
        var v = sel.value;
        if (v === 'PASSED') sel.classList.add('status-passed');
        else if (v === 'FAILED') sel.classList.add('status-failed');
        else sel.classList.add('status-pending');
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('eval-select')) syncEvalSelect(e.target);
    });

    // Init on load
    document.querySelectorAll('.eval-select').forEach(syncEvalSelect);

}());

/* ══════════════════════════════════════════════════════════════
   GENERAL-PURPOSE MODAL SYSTEM
   ──────────────────────────────────────────────────────────────
   Open:   <button data-modal-target="my-modal-id">
   Close:  <button data-modal-close>  OR  click backdrop  OR  Esc
   API:    window.CPDO.modal.open(id)  /  window.CPDO.modal.close(id)
   ══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        // Move focus to first focusable element inside
        var focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) setTimeout(function () { focusable.focus(); }, 50);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        // Only restore scroll if no other modal is open and the preview modal is also closed
        var anyOpen = document.querySelector('.modal-backdrop.is-open');
        var previewOpen = document.querySelector('.preview-modal-backdrop:not(.is-hidden)');
        if (!anyOpen && !previewOpen) {
            document.body.style.overflow = '';
        }
    }

    // 1. Open via [data-modal-target]
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-modal-target]');
        if (!trigger) return;
        e.preventDefault();
        var targetId = trigger.getAttribute('data-modal-target');
        var target   = document.getElementById(targetId);
        openModal(target);
    });

    // 2. Close via [data-modal-close]
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-modal-close]');
        if (!btn) return;
        e.preventDefault();
        var modal = btn.closest('.modal-backdrop');
        closeModal(modal);
    });

    // 3. Close by clicking the dark backdrop itself
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('modal-backdrop') && e.target.classList.contains('is-open')) {
            closeModal(e.target);
        }
    });

    // 4. Close via Escape key (general modals only — preview modal has its own handler)
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var openModal = document.querySelector('.modal-backdrop.is-open');
        if (openModal) closeModal(openModal);
    });

    // Expose API
    window.CPDO = window.CPDO || {};
    window.CPDO.modal = {
        open:  function (id) { openModal(document.getElementById(id)); },
        close: function (id) { closeModal(document.getElementById(id)); },
    };
});
