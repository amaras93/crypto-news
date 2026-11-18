const article = document.querySelector('[data-reading-article]');
const progress = document.querySelector('[data-reading-progress]');
const backToTop = document.querySelector('[data-back-top]');

if (article && progress) {
    progress.hidden = false;
    let pending = false;

    const updateProgress = () => {
        const rectangle = article.getBoundingClientRect();
        const readingEnd = article.querySelector('[data-reading-end]')?.getBoundingClientRect().bottom ?? rectangle.bottom;
        const start = rectangle.top + window.scrollY - 24;
        const end = Math.max(start, readingEnd + window.scrollY - window.innerHeight + 24);
        progress.value = end === start
            ? (window.scrollY >= start ? 100 : 0)
            : Math.max(0, Math.min(100, ((window.scrollY - start) / (end - start)) * 100));
        if (backToTop) {
            backToTop.hidden = window.scrollY < start + 350;
        }
        pending = false;
    };

    const scheduleUpdate = () => {
        if (!pending) {
            pending = true;
            window.requestAnimationFrame(updateProgress);
        }
    };

    updateProgress();
    window.addEventListener('scroll', scheduleUpdate, { passive: true });
    window.addEventListener('resize', scheduleUpdate);
    window.addEventListener('load', scheduleUpdate, { once: true });

    if ('ResizeObserver' in window) {
        new ResizeObserver(scheduleUpdate).observe(article);
    }
}

const copyButton = document.querySelector('[data-copy-url]');
const copyStatus = document.querySelector('[data-copy-status]');
const articleUrl = document.querySelector('#article-url');

if (copyButton && copyStatus && articleUrl) {
    copyButton.hidden = false;
    copyButton.addEventListener('click', async () => {
        try {
            if (!navigator.clipboard?.writeText) {
                throw new Error('Clipboard unavailable');
            }
            await navigator.clipboard.writeText(copyButton.dataset.copyUrl);
            copyStatus.textContent = copyButton.dataset.copySuccess;
        } catch {
            articleUrl.focus();
            articleUrl.select();
            copyStatus.textContent = copyButton.dataset.copyError;
        }
    });
}
