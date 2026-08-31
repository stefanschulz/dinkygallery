/*!
 * plg_content_dinkygallery — carousel arrows + lightbox (ES module)
 * Copyright (C) 2026 The Loom / Stefan Schulz
 * GNU General Public License version 3 or later; see LICENSE.txt
 *
 * Slice 3: the scroll-snap carousel (reveal arrows, step by one card, wrap or
 * disable at the ends). Slice 4 adds the lightbox. See .doc/WORKPLAN.md §8.
 */

const REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Wires up one .dg carousel: reveals the arrows when the track overflows, scrolls
 * it by a card per click, and keeps the arrows disabled at the ends unless the
 * gallery loops.
 *
 * The intended scroll position is tracked in `target` rather than read back from
 * `track.scrollLeft` on every click — during a smooth scroll `scrollLeft` lags,
 * so rapid clicks would otherwise collapse onto one step.
 *
 * @param {HTMLElement} dg  The .dg container.
 */
function initCarousel(dg) {
    const track = dg.querySelector('.dg-track');
    const prev = dg.querySelector('.dg-arrow--prev');
    const next = dg.querySelector('.dg-arrow--next');

    if (!track || !prev || !next) {
        return;
    }

    const loop = dg.dataset.loop === '1';

    const maxScroll = () => Math.max(0, track.scrollWidth - track.clientWidth);
    const overflows = () => maxScroll() > 1;

    const stepSize = () => {
        const card = track.querySelector('.dg-card');
        const styles = getComputedStyle(track);
        const gap = parseFloat(styles.columnGap || styles.gap) || 0;

        return card ? card.getBoundingClientRect().width + gap : track.clientWidth;
    };

    let programmatic = false;
    let target = track.scrollLeft;
    let settleTimer = 0;

    const settle = () => {
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(() => {
            programmatic = false;
            target = track.scrollLeft;
            syncArrows();
        }, 140);
    };

    const go = (dir) => {
        // Idle (no animation of ours running): re-anchor to the real position so
        // `target` cannot drift. Mid-animation, keep accumulating so a burst of
        // clicks steps that many cards instead of collapsing onto one.
        if (!programmatic) {
            target = track.scrollLeft;
        }

        const max = maxScroll();
        let dest = target + dir * stepSize();

        if (loop && dir > 0 && target >= max - 1) {
            dest = 0;
        } else if (loop && dir < 0 && target <= 1) {
            dest = max;
        } else {
            dest = Math.max(0, Math.min(max, dest));
        }

        target = dest;
        programmatic = true;
        track.scrollTo({ left: dest, behavior: REDUCED_MOTION ? 'auto' : 'smooth' });
        settle();
    };

    const syncArrows = () => {
        const show = overflows();

        prev.hidden = !show;
        next.hidden = !show;

        if (!show || loop) {
            prev.disabled = false;
            next.disabled = false;

            return;
        }

        prev.disabled = track.scrollLeft <= 1;
        next.disabled = track.scrollLeft >= maxScroll() - 1;
    };

    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));

    let rafPending = false;
    track.addEventListener('scroll', () => {
        // A scroll we did not start (touch, wheel, scrollbar) re-anchors target.
        if (!programmatic) {
            target = track.scrollLeft;
        }

        if (!rafPending) {
            rafPending = true;
            requestAnimationFrame(() => {
                rafPending = false;
                syncArrows();
            });
        }

        settle();
    }, { passive: true });

    window.addEventListener('resize', () => {
        target = track.scrollLeft;
        syncArrows();
    }, { passive: true });

    // Fonts / images can change the track width after first paint.
    if (document.readyState !== 'complete') {
        window.addEventListener('load', syncArrows, { once: true });
    }

    syncArrows();
}

/* ------------------------------------------------------------------ lightbox */

/**
 * Translated string with an English fallback for when Joomla.Text is unavailable.
 *
 * @param {string} key       The language key.
 * @param {string} fallback  English default.
 * @returns {string}
 */
function t(key, fallback) {
    return (window.Joomla && Joomla.Text && Joomla.Text._) ? Joomla.Text._(key, fallback) : fallback;
}

/** @type {{el:HTMLElement, frame:HTMLElement, img:HTMLImageElement, backdrop:HTMLElement, prev:HTMLButtonElement, mid:HTMLButtonElement, next:HTMLButtonElement, close:HTMLButtonElement}|null} */
let LB = null;

const lbState = {
    links: /** @type {HTMLAnchorElement[]} */ ([]),
    index: 0,
    loop: true,
    opener: /** @type {HTMLElement|null} */ (null),
    spinTimer: 0,
};

/**
 * Builds the single lightbox element, appends it to <body>, wires its events.
 *
 * @returns {typeof LB}
 */
function buildLightbox() {
    const el = document.createElement('div');

    el.className = 'dg-lb';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', t('PLG_CONTENT_DINKYGALLERY_ARIA_DIALOG', 'Image viewer'));
    el.hidden = true;
    el.innerHTML =
        '<div class="dg-lb__backdrop" data-dg-close></div>'
        + '<div class="dg-lb__frame">'
        + '<img class="dg-lb__img" alt="">'
        + '<button type="button" class="dg-lb__zone dg-lb__zone--prev"></button>'
        + '<button type="button" class="dg-lb__zone dg-lb__zone--mid" hidden></button>'
        + '<button type="button" class="dg-lb__zone dg-lb__zone--next"></button>'
        + '<button type="button" class="dg-lb__close" data-dg-close>×</button>'
        + '</div>';

    document.body.appendChild(el);

    const refs = {
        el,
        frame: el.querySelector('.dg-lb__frame'),
        img: el.querySelector('.dg-lb__img'),
        backdrop: el.querySelector('.dg-lb__backdrop'),
        prev: el.querySelector('.dg-lb__zone--prev'),
        mid: el.querySelector('.dg-lb__zone--mid'),
        next: el.querySelector('.dg-lb__zone--next'),
        close: el.querySelector('.dg-lb__close'),
    };

    refs.prev.setAttribute('aria-label', t('PLG_CONTENT_DINKYGALLERY_ARIA_PREV', 'Previous image'));
    refs.next.setAttribute('aria-label', t('PLG_CONTENT_DINKYGALLERY_ARIA_NEXT', 'Next image'));
    refs.mid.setAttribute('aria-label', t('PLG_CONTENT_DINKYGALLERY_ARIA_CLOSE', 'Close'));
    refs.close.setAttribute('aria-label', t('PLG_CONTENT_DINKYGALLERY_ARIA_CLOSE', 'Close'));

    refs.prev.addEventListener('click', () => navLightbox(-1));
    refs.next.addEventListener('click', () => navLightbox(1));
    refs.mid.addEventListener('click', closeLightbox);
    refs.close.addEventListener('click', closeLightbox);
    refs.backdrop.addEventListener('click', closeLightbox);
    el.addEventListener('keydown', onLightboxKeydown);

    return refs;
}

/**
 * Opens the lightbox for a gallery at a given index.
 *
 * @param {HTMLElement} dg          The .dg the click came from.
 * @param {number}      startIndex  Index of the clicked image.
 * @param {HTMLElement} opener      The link to restore focus to on close.
 */
function openLightbox(dg, startIndex, opener) {
    LB = LB || buildLightbox();

    lbState.links = Array.from(dg.querySelectorAll('.dg-card__link'));
    lbState.loop = dg.dataset.loop === '1';
    lbState.opener = opener;

    const size = parseInt(dg.dataset.size, 10);
    const backdrop = parseInt(dg.dataset.backdrop, 10);

    LB.el.style.setProperty('--dg-size', Number.isFinite(size) ? size : 100);
    LB.el.style.setProperty('--dg-backdrop', (Number.isFinite(backdrop) ? backdrop : 60) / 100);
    LB.el.style.setProperty('--dg-lb-rgb', dg.dataset.lbColor || '0, 0, 0');
    LB.el.style.setProperty('--dg-lb-padding', dg.dataset.lbPad || '10px');

    const single = lbState.links.length <= 1;

    LB.prev.hidden = single;
    LB.next.hidden = single;
    LB.mid.hidden = dg.dataset.middle !== 'close';

    lockScroll();
    setBackgroundInert(true);
    LB.el.hidden = false;
    void LB.el.offsetWidth; // reflow so the opacity transition runs
    LB.el.classList.add('dg-lb--open');

    showLightboxImage(startIndex);
    LB.close.focus();
}

/**
 * Marks every body child except the lightbox `inert` while it is open, so assistive
 * tech and Tab cannot reach the page behind it. The manual focus trap stays as a
 * fallback for browsers without `inert`.
 *
 * @param {boolean} on
 */
function setBackgroundInert(on) {
    for (const child of document.body.children) {
        if (child === LB.el) {
            continue;
        }

        if (on) {
            if (child.inert) {
                continue;
            }

            child.inert = true;
            child.dataset.dgInert = '1';
        } else if (child.dataset.dgInert) {
            child.inert = false;
            delete child.dataset.dgInert;
        }
    }
}

/**
 * Shows image i: preloads it (keeping the previous one visible until it is ready),
 * updates alt, preloads the neighbours, and refreshes the zone disabled state.
 *
 * @param {number} i  Target index.
 */
function showLightboxImage(i) {
    const n = lbState.links.length;

    lbState.index = i;

    const link = lbState.links[i];
    const url = link.dataset.full || link.getAttribute('href');
    const alt = link.querySelector('.dg-card__img') ? link.querySelector('.dg-card__img').getAttribute('alt') || '' : '';

    window.clearTimeout(lbState.spinTimer);
    lbState.spinTimer = window.setTimeout(() => LB.el.classList.add('dg-lb--loading'), 150);

    const pre = new Image();
    pre.onload = pre.onerror = () => {
        window.clearTimeout(lbState.spinTimer);
        LB.el.classList.remove('dg-lb--loading');
        LB.img.src = url;
        LB.img.alt = alt;
    };
    pre.src = url;

    [i + 1, i - 1].forEach((j) => {
        const k = lbState.loop ? (j + n) % n : j;

        if (k >= 0 && k < n) {
            const l = lbState.links[k];
            new Image().src = l.dataset.full || l.getAttribute('href');
        }
    });

    const atEnds = !lbState.loop && n > 1;

    LB.prev.disabled = atEnds && i <= 0;
    LB.next.disabled = atEnds && i >= n - 1;
    LB.prev.setAttribute('aria-disabled', String(LB.prev.disabled));
    LB.next.setAttribute('aria-disabled', String(LB.next.disabled));
}

/**
 * Moves by one image, wrapping when the gallery loops.
 *
 * @param {number} dir  -1 or +1.
 */
function navLightbox(dir) {
    const n = lbState.links.length;

    if (n <= 1) {
        return;
    }

    let i = lbState.index + dir;

    if (lbState.loop) {
        i = (i + n) % n;
    } else if (i < 0 || i >= n) {
        return;
    }

    showLightboxImage(i);
}

/**
 * Closes the lightbox, unlocks scroll and returns focus to the opening card.
 */
function closeLightbox() {
    if (!LB || LB.el.hidden) {
        return;
    }

    window.clearTimeout(lbState.spinTimer);
    LB.el.classList.remove('dg-lb--open', 'dg-lb--loading');

    const finish = () => {
        LB.el.hidden = true;
        LB.img.removeAttribute('src');
        unlockScroll();
        setBackgroundInert(false);

        if (lbState.opener) {
            lbState.opener.focus();
        }
    };

    if (REDUCED_MOTION) {
        finish();

        return;
    }

    let done = false;
    const onEnd = () => {
        if (done) {
            return;
        }

        done = true;
        LB.el.removeEventListener('transitionend', onEnd);
        finish();
    };

    LB.el.addEventListener('transitionend', onEnd);
    window.setTimeout(onEnd, 200);
}

/**
 * Keyboard handling while the lightbox is open: arrows navigate, Esc closes, Tab
 * is trapped inside the dialog.
 *
 * @param {KeyboardEvent} e
 */
function onLightboxKeydown(e) {
    switch (e.key) {
        case 'Escape':
            e.preventDefault();
            closeLightbox();
            break;
        case 'ArrowLeft':
            e.preventDefault();
            navLightbox(-1);
            break;
        case 'ArrowRight':
            e.preventDefault();
            navLightbox(1);
            break;
        case 'Tab':
            trapFocus(e);
            break;
        default:
    }
}

/**
 * Keeps Tab focus cycling within the lightbox controls.
 *
 * @param {KeyboardEvent} e
 */
function trapFocus(e) {
    const focusable = [LB.prev, LB.mid, LB.next, LB.close].filter((b) => !b.hidden && !b.disabled);

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (e.shiftKey && (active === first || !LB.el.contains(active))) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && (active === last || !LB.el.contains(active))) {
        e.preventDefault();
        first.focus();
    }
}

let scrollLockPad = '';

/** Locks <html> scroll and compensates for the scrollbar width. */
function lockScroll() {
    const bar = window.innerWidth - document.documentElement.clientWidth;

    scrollLockPad = document.documentElement.style.paddingRight;
    document.documentElement.style.overflow = 'hidden';

    if (bar > 0) {
        document.documentElement.style.paddingRight = bar + 'px';
    }
}

/** Restores <html> scroll. */
function unlockScroll() {
    document.documentElement.style.overflow = '';
    document.documentElement.style.paddingRight = scrollLockPad;
}

/* ---------------------------------------------------------------------- init */

/**
 * Initialises every carousel and the shared card-click -> lightbox handler.
 */
function init() {
    document.querySelectorAll('.dg[data-dg]').forEach(initCarousel);

    document.addEventListener('click', (e) => {
        const link = e.target.closest ? e.target.closest('.dg-card__link') : null;

        if (!link) {
            return;
        }

        const dg = link.closest('.dg[data-dg]');

        if (!dg) {
            return;
        }

        e.preventDefault();

        const links = Array.from(dg.querySelectorAll('.dg-card__link'));

        openLightbox(dg, Math.max(0, links.indexOf(link)), link);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
