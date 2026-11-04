(() => {
    'use strict';
    if (window.KNSAnnouncementGallery) return;
    window.KNSAnnouncementGallery = true;
    let dialog, image, heading, counter, original, previous, next, error;
    let images = [];
    let current = 0;
    let title = '';
    let trigger = null;
    let previousOverflow = '';
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text) node.textContent = text;
        return node;
    };
    const button = (label, text) => {
        const node = element('button', '', text);
        node.type = 'button';
        node.setAttribute('aria-label', label);
        return node;
    };
    const show = index => {
        if (index < 0 || index >= images.length) return;
        current = index;
        error.textContent = '';
        image.src = images[current];
        image.alt = 'Image ' + (current + 1) + ' of ' + images.length + ' for ' + title;
        counter.textContent = (current + 1) + ' / ' + images.length;
        original.href = images[current];
        previous.disabled = current === 0;
        next.disabled = current === images.length - 1;
    };
    function createViewer() {
        if (dialog) return;
        dialog = element('dialog', 'kns-gallery-viewer');
        dialog.setAttribute('aria-label', 'Post images');
        const top = element('div', 'kns-gallery-heading');
        heading = element('h2', 'kns-gallery-title');
        const close = button('Close image viewer', '\u00d7');
        close.addEventListener('click', () => dialog.close());
        top.append(heading, close);
        const stage = element('div', 'kns-gallery-stage');
        previous = button('Previous image', '\u2039');
        next = button('Next image', '\u203a');
        previous.dataset.galleryPrevious = '';
        next.dataset.galleryNext = '';
        previous.addEventListener('click', () => show(current - 1));
        next.addEventListener('click', () => show(current + 1));
        const figure = element('figure', '');
        image = element('img', 'kns-gallery-image');
        image.addEventListener('error', () => {
            if (dialog.open) error.textContent = 'Unable to load this image. Use Open original to view it.';
        });
        figure.append(image);
        stage.append(previous, figure, next);
        const footer = element('div', 'kns-gallery-footer');
        counter = element('span', 'kns-gallery-counter');
        counter.setAttribute('role', 'status');
        counter.setAttribute('aria-live', 'polite');
        original = element('a', 'kns-gallery-original', 'Open original');
        original.target = '_blank';
        original.rel = 'noopener noreferrer';
        footer.append(counter, original);
        error = element('p', 'kns-gallery-error');
        error.setAttribute('role', 'status');
        dialog.append(top, stage, footer, error);
        dialog.addEventListener('keydown', event => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                show(current + (event.key === 'ArrowLeft' ? -1 : 1));
            }
        });
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            image.removeAttribute('src');
            images = [];
            document.body.style.overflow = previousOverflow;
            if (trigger?.isConnected) trigger.focus({preventScroll: true});
            trigger = null;
        });
        document.body.append(dialog);
    }
    // Delegation also handles overview fragments inserted during navigation.
    document.addEventListener('click', event => {
        const anchor = event.target.closest('a[data-gallery-index]');
        if (!anchor || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const gallery = anchor.closest('[data-gallery-images]');
        if (!gallery) return;
        let items;
        try {
            items = JSON.parse(gallery.dataset.galleryImages);
            if (!Array.isArray(items) || !items.length || !items.every(value => typeof value === 'string'
                && ['http:', 'https:'].includes(new URL(value, location.origin).protocol))) return;
        } catch (_) { return; }
        event.preventDefault();
        createViewer();
        images = items;
        title = gallery.dataset.galleryTitle || 'Post images';
        heading.textContent = title;
        heading.title = title;
        trigger = anchor;
        previousOverflow = document.body.style.overflow;
        show(Math.min(Number(anchor.dataset.galleryIndex) || 0, images.length - 1));
        document.body.style.overflow = 'hidden';
        dialog.showModal();
    });
})();
