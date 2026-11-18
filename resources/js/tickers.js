document.querySelectorAll('[data-market-ticker]').forEach((ticker) => {
    const viewport = ticker.querySelector('.market-ticker-viewport');
    const track = ticker.querySelector('.market-ticker-track');
    const items = ticker.querySelector('[data-ticker-copy="original"]');
    if (!viewport || !track || !items) return;

    const motion = window.matchMedia('(min-width: 801px) and (hover: hover) and (prefers-reduced-motion: no-preference)');
    const measure = () => {
        const width = items.getBoundingClientRect().width;
        const moving = motion.matches && items.children.length > 1 && width > 0 && viewport.clientWidth > 0;
        const repeats = moving ? Math.max(1, Math.ceil(viewport.clientWidth / width)) : 1;
        const copies = Array.from(track.querySelectorAll('[data-ticker-copy="clone"]'));
        const requiredClones = repeats * 2 - 1;
        while (copies.length < requiredClones) {
            const copy = items.cloneNode(true);
            copy.dataset.tickerCopy = 'clone';
            copy.setAttribute('aria-hidden', 'true');
            copy.setAttribute('inert', '');
            track.append(copy);
            copies.push(copy);
        }
        while (copies.length > requiredClones) copies.pop().remove();
        const cycleWidth = width * repeats;
        ticker.style.setProperty('--ticker-shift', '-' + cycleWidth + 'px');
        ticker.style.setProperty('--ticker-duration', Math.max(40, cycleWidth / 28) + 's');
        if (ticker.dataset.moving !== String(moving)) {
            viewport.scrollLeft = 0;
            ticker.dataset.moving = String(moving);
        }
    };

    viewport.addEventListener('touchstart', () => {
        ticker.dataset.interacting = 'true';
    }, { passive: true });
    viewport.addEventListener('touchend', (event) => {
        ticker.dataset.interacting = String(event.touches.length > 0);
    }, { passive: true });
    viewport.addEventListener('touchcancel', () => {
        ticker.dataset.interacting = 'false';
    }, { passive: true });
    viewport.addEventListener('blur', () => {
        if (ticker.dataset.moving === 'true') viewport.scrollLeft = 0;
    });
    measure();
    motion.addEventListener('change', measure);
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(measure);
        observer.observe(viewport);
        observer.observe(items);
    } else {
        window.addEventListener('resize', measure);
    }
});

const showLogoFallback = (image) => {
    if (image.getAttribute('src') !== image.dataset.fallback) image.src = image.dataset.fallback;
};
document.addEventListener('error', ({ target }) => {
    if (target.matches?.('img[data-market-logo]')) showLogoFallback(target);
}, true);
document.querySelectorAll('img[data-market-logo]').forEach((image) => {
    if (image.complete && image.naturalWidth === 0) showLogoFallback(image);
});
