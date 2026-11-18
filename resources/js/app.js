import './article';
import './tickers';

const header = document.querySelector('[data-site-header]');
if (header) {
    const updateHeader = () => header.classList.toggle('is-scrolled', window.scrollY > 24);
    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
}
const searchToggle = document.querySelector('[data-search-toggle]');
const searchPanel = document.querySelector('[data-header-search]');
if (searchToggle && searchPanel) {
    searchToggle.addEventListener('click', () => {
        const open = searchPanel.hidden;
        searchPanel.hidden = !open;
        searchToggle.setAttribute('aria-expanded', String(open));
        if (open) searchPanel.querySelector('input')?.focus();
    });
}
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const activeMenu = event.target.closest?.('details[data-dismissable][open]') ?? document.querySelector('details[data-dismissable][open]');
    if (activeMenu) {
        activeMenu.open = false;
        activeMenu.querySelector('summary')?.focus();
    }
    if (searchPanel && !searchPanel.hidden) {
        searchPanel.hidden = true;
        searchToggle?.setAttribute('aria-expanded', 'false');
        searchToggle?.focus();
    }
});
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-dismissable][open]').forEach((details) => {
        if (!details.contains(event.target)) details.open = false;
    });
});
document.querySelectorAll('img[data-cover]').forEach((image) => {
    const showFallback = () => {
        const fallback = image.dataset.fallback;
        if (fallback && image.getAttribute('src') !== fallback) image.src = fallback;
    };
    image.addEventListener('error', showFallback, { once: true });
    if (image.complete && image.naturalWidth === 0) showFallback();
});
