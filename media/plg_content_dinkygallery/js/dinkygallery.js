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

/**
 * Initialises every carousel on the page.
 */
function init() {
    document.querySelectorAll('.dg[data-dg]').forEach(initCarousel);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
