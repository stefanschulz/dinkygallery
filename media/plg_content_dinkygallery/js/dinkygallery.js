/*!
 * plg_content_dinkygallery — carousel arrows + lightbox (ES module)
 * Copyright (C) 2026 The Loom / Stefan Schulz
 * GNU General Public License version 3 or later; see LICENSE.txt
 *
 * Progressive enhancement for the server-rendered .dg markup: reveals the
 * carousel arrows when the strip overflows and steps it one card at a time, and
 * builds a single reusable lightbox for the card links. Class / data-* names are
 * the contract shared with dinkygallery.css and Render.php — keep them stable.
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

    // The "i / N" pill (server-rendered on the first card, absent for a one-image
    // gallery) rides on the first visible card and shows that card's position.
    const badge = dg.querySelector('.dg-count');
    const cardCount = track.querySelectorAll('.dg-card').length;

    const syncBadge = () => {
        if (!badge) {
            return;
        }

        const step = stepSize();
        let index = step > 0 ? Math.round(track.scrollLeft / step) : 0;
        index = Math.max(0, Math.min(cardCount - 1, index));

        const link = track.querySelectorAll('.dg-card')[index].querySelector('.dg-card__link');

        if (badge.parentElement !== link) {
            link.appendChild(badge);
        }

        badge.textContent = (index + 1) + ' / ' + cardCount;
    };

    let programmatic = false;
    let target = track.scrollLeft;
    let settleTimer = 0;

    const sync = () => {
        syncArrows();
        syncBadge();
    };

    const settle = () => {
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(() => {
            programmatic = false;
            target = track.scrollLeft;
            sync();
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

    // Fires after any scroll settles (user or programmatic) — cheap safety net for
    // the arrow state and the position pill.
    track.addEventListener('scrollend', sync, { passive: true });

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
                sync();
            });
        }

        settle();
    }, { passive: true });

    window.addEventListener('resize', () => {
        target = track.scrollLeft;
        sync();
    }, { passive: true });

    // Fonts / images can change the track width after first paint.
    if (document.readyState !== 'complete') {
        window.addEventListener('load', sync, { once: true });
    }

    sync();
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

/** @type {{el:HTMLElement, frame:HTMLElement, stage:HTMLElement, img:HTMLImageElement, backdrop:HTMLElement, prev:HTMLButtonElement, mid:HTMLButtonElement, next:HTMLButtonElement, close:HTMLButtonElement, count:HTMLElement, status:HTMLElement}|null} */
let LB = null;

const lbState = {
    links: /** @type {HTMLAnchorElement[]} */ ([]),
    index: 0,
    loop: true,
    opener: /** @type {HTMLElement|null} */ (null),
    spinTimer: 0,
    sliding: false,
    pendingDir: 0,
    fitImage: false,
};

/**
 * In "image" frame-shape mode, retargets `--dg-lb-aspect` to a loaded image's own
 * ratio so the frame morphs to hug it.
 *
 * @param {HTMLImageElement} img
 */
function applyImageAspect(img) {
    if (lbState.fitImage && img.naturalWidth && img.naturalHeight) {
        LB.el.style.setProperty('--dg-lb-aspect', img.naturalWidth + ' / ' + img.naturalHeight);
    }
}

/**
 * The alt text for a card's image, or "".
 *
 * @param {HTMLElement} link  A .dg-card__link.
 * @returns {string}
 */
function altOf(link) {
    const im = link.querySelector('.dg-card__img');

    return im ? (im.getAttribute('alt') || '') : '';
}

/**
 * The full-size image URL for a card link: `data-full` (the size-capped copy),
 * falling back to the link's own href.
 *
 * @param {HTMLElement} link  A .dg-card__link.
 * @returns {string}
 */
function fullOf(link) {
    return link.dataset.full || link.getAttribute('href');
}

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
        + '<div class="dg-lb__stage"><img class="dg-lb__img" alt=""></div>'
        + '<button type="button" class="dg-lb__zone dg-lb__zone--prev"></button>'
        + '<button type="button" class="dg-lb__zone dg-lb__zone--mid" hidden></button>'
        + '<button type="button" class="dg-lb__zone dg-lb__zone--next"></button>'
        + '<button type="button" class="dg-lb__close" data-dg-close>×</button>'
        + '<div class="dg-lb__count" aria-hidden="true" hidden></div>'
        + '</div>'
        + '<div class="dg-lb__status" role="status" aria-live="polite"></div>';

    document.body.appendChild(el);

    const refs = {
        el,
        frame: el.querySelector('.dg-lb__frame'),
        stage: el.querySelector('.dg-lb__stage'),
        img: el.querySelector('.dg-lb__img'),
        backdrop: el.querySelector('.dg-lb__backdrop'),
        prev: el.querySelector('.dg-lb__zone--prev'),
        mid: el.querySelector('.dg-lb__zone--mid'),
        next: el.querySelector('.dg-lb__zone--next'),
        close: el.querySelector('.dg-lb__close'),
        count: el.querySelector('.dg-lb__count'),
        status: el.querySelector('.dg-lb__status'),
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

    // Frame shape: "viewport" (X vw × X vh), a fixed ratio, or "image" (per image).
    const aspect = (dg.dataset.lbAspect || 'viewport').trim();

    lbState.fitImage = aspect === 'image';
    LB.el.classList.toggle('dg-lb--fitted', aspect !== 'viewport');
    LB.el.classList.toggle('dg-lb--fit-image', lbState.fitImage);

    if (aspect === 'viewport') {
        LB.el.style.removeProperty('--dg-lb-aspect');
    } else if (!lbState.fitImage) {
        LB.el.style.setProperty('--dg-lb-aspect', aspect);
    }

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
 * Swaps the visible image to index i in place: preloads it (the previous one stays
 * visible until it is ready, spinner after 150 ms), then applies the state. Used on
 * open and whenever motion is reduced.
 *
 * @param {number} i  Target index.
 */
function showLightboxImage(i) {
    const link = lbState.links[i];
    const url = fullOf(link);
    const alt = altOf(link);

    window.clearTimeout(lbState.spinTimer);
    lbState.spinTimer = window.setTimeout(() => LB.el.classList.add('dg-lb--loading'), 150);

    const pre = new Image();
    pre.onload = pre.onerror = () => {
        window.clearTimeout(lbState.spinTimer);
        LB.el.classList.remove('dg-lb--loading');
        LB.img.src = url;
        LB.img.alt = alt;
        applyImageAspect(pre);
    };
    pre.src = url;

    applyImageState(i);
}

/**
 * Non-visual bookkeeping for landing on index i: current index, neighbour preload,
 * zone disabled state, and the position pill + live region.
 *
 * @param {number} i  Target index.
 */
function applyImageState(i) {
    const n = lbState.links.length;

    lbState.index = i;

    [i + 1, i - 1].forEach((j) => {
        const k = lbState.loop ? (j + n) % n : j;

        if (k >= 0 && k < n) {
            new Image().src = fullOf(lbState.links[k]);
        }
    });

    const atEnds = !lbState.loop && n > 1;

    LB.prev.disabled = atEnds && i <= 0;
    LB.next.disabled = atEnds && i >= n - 1;
    LB.prev.setAttribute('aria-disabled', String(LB.prev.disabled));
    LB.next.setAttribute('aria-disabled', String(LB.next.disabled));

    LB.count.hidden = n <= 1;
    LB.count.textContent = (i + 1) + ' / ' + n;
    LB.status.textContent = t('PLG_CONTENT_DINKYGALLERY_ARIA_POSITION', 'Image %1$s of %2$s')
        .replace('%1$s', String(i + 1))
        .replace('%2$s', String(n));
}

/**
 * Moves by one image, wrapping when the gallery loops. A click during a slide is
 * remembered (opposite clicks cancel) and applied when the slide ends.
 *
 * @param {number} dir  -1 or +1.
 */
function navLightbox(dir) {
    const n = lbState.links.length;

    if (n <= 1) {
        return;
    }

    if (lbState.sliding) {
        lbState.pendingDir = (lbState.pendingDir || 0) + dir;

        return;
    }

    let i = lbState.index + dir;

    if (lbState.loop) {
        i = (i + n) % n;
    } else if (i < 0 || i >= n) {
        return;
    }

    if (REDUCED_MOTION) {
        showLightboxImage(i);
    } else {
        slideToImage(i, dir);
    }
}

/**
 * Runs a nav that was queued while a slide was in flight (coalesced to one step).
 */
function flushPendingNav() {
    const dir = Math.sign(lbState.pendingDir || 0);

    lbState.pendingDir = 0;

    if (dir && !LB.el.hidden) {
        navLightbox(dir);
    }
}

/**
 * Slides image i in from the side given by `dir` and the current one out the other
 * way: a second `.dg-lb__img` is appended, its start transform committed, then both
 * animate. Falls back to an in-place state apply if closed mid-slide.
 *
 * @param {number} i    Target index.
 * @param {number} dir  -1 (enter from the left) or +1 (enter from the right).
 */
function slideToImage(i, dir) {
    const link = lbState.links[i];
    const url = fullOf(link);
    const outgoing = LB.img;
    const shift = LB.stage.clientWidth || LB.frame.clientWidth || 1000;

    const incoming = new Image();
    incoming.className = 'dg-lb__img';
    incoming.alt = altOf(link);
    incoming.style.transform = 'translateX(' + (dir * shift) + 'px)';

    lbState.sliding = true;
    window.clearTimeout(lbState.spinTimer);
    lbState.spinTimer = window.setTimeout(() => LB.el.classList.add('dg-lb--loading'), 150);

    let started = false;
    const begin = () => {
        if (started) {
            return;
        }

        started = true;
        window.clearTimeout(lbState.spinTimer);
        LB.el.classList.remove('dg-lb--loading');

        if (LB.el.hidden) {
            lbState.sliding = false;

            return;
        }

        LB.stage.appendChild(incoming);
        LB.img = incoming;
        applyImageAspect(incoming); // in "image" mode: morph the frame while sliding

        // Forced reflow commits the start transform; then transition to rest. No
        // requestAnimationFrame — it is paused while the tab is not painting.
        void incoming.offsetWidth;

        LB.stage.classList.add('dg-lb__stage--sliding');
        outgoing.style.transform = 'translateX(' + (-dir * shift) + 'px)';
        incoming.style.transform = 'translateX(0)';

        let done = false;
        const finish = () => {
            if (done) {
                return;
            }

            done = true;
            window.clearTimeout(fallback);
            incoming.removeEventListener('transitionend', finish);
            outgoing.remove();
            LB.stage.classList.remove('dg-lb__stage--sliding');
            incoming.style.transform = '';
            lbState.sliding = false;

            if (!LB.el.hidden) {
                applyImageState(i);
                flushPendingNav();
            }
        };

        const fallback = window.setTimeout(finish, 450);
        incoming.addEventListener('transitionend', finish);
    };

    incoming.onload = incoming.onerror = begin;
    incoming.src = url;

    if (incoming.complete) {
        begin();
    }
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
        unlockScroll();
        setBackgroundInert(false);

        // Collapse any in-flight slide back to a single, reset image.
        lbState.sliding = false;
        lbState.pendingDir = 0;
        LB.stage.classList.remove('dg-lb__stage--sliding');
        LB.stage.querySelectorAll('.dg-lb__img').forEach((im) => {
            if (im !== LB.img) {
                im.remove();
            }
        });
        LB.img.style.transform = '';
        LB.img.removeAttribute('src');

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

    // Warm the full-size image the moment a card is pointed at or focused, so the
    // lightbox has it ready. Each URL is fetched at most once.
    const warmed = new Set();
    const warm = (e) => {
        const link = e.target.closest ? e.target.closest('.dg-card__link') : null;
        const url = link && fullOf(link);

        if (url && !warmed.has(url)) {
            warmed.add(url);
            new Image().src = url;
        }
    };

    document.addEventListener('pointerover', warm, { passive: true });
    document.addEventListener('focusin', warm);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
