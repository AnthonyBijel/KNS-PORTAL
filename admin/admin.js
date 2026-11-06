"use strict";

// Page configuration and shared state.
// Register each new admin page here, together with its setup functions.
const ADMIN_PAGES = {
    "overview.php": {
        navigation: ".overview-button",
        initializers: [initAnnouncements, initMediaPreview],
    },
    "results.php": {
        navigation: ".results-button",
        initializers: [initStudentUpload, initGradeUpload, initStudentList, container => window.KNSAcademicTerms?.init(container), container => window.KNSStudentListDownload?.init(container)],
    },
    "requests.html": {
        navigation: ".may-request-button",
        initializers: [initRequestDetails],
    },
    "profile.php": {
        navigation: ".student-information-button",
        initializers: [initOfficeAccounts, container => window.KNSStaffSettings?.init(container)],
    },
};

let latestPageRequest = 0;
let mediaPreviewURLs = [];
let dashboardInitialized = false;
let requestMessengerCleanup = null;

// Page loading and navigation.
// Keep loadPage global because the sidebar HTML calls it through onclick.
async function loadPage(page, context = {}) {
    const container = document.getElementById("main-content-contianer");
    const config = Object.hasOwn(ADMIN_PAGES, page) ? ADMIN_PAGES[page] : null;

    if (!container || !config || !document.querySelector(config.navigation)) {
        console.error("Cannot load admin page:", page);
        return;
    }

    const requestId = ++latestPageRequest;
    container.setAttribute("aria-busy", "true");

    try {
        const response = await fetch(page, { cache: "no-store" });
        if (response.redirected) {
            window.location.assign(response.url);
            return;
        }
        if (!response.ok) {
            throw new Error(`Failed to load ${page}: ${response.status}`);
        }

        const html = await response.text();

        // A slower, earlier request must not replace the most recent page.
        if (requestId !== latestPageRequest) return;

        clearMediaPreviewURLs();
        requestMessengerCleanup?.();
        requestMessengerCleanup = null;
        container.innerHTML = html;

        // Page elements only exist after their HTML has been inserted.
        config.initializers.forEach(initialize => initialize(container));
        updateNavigation(config.navigation);
        if (context.requestId) {
            const opened = await requestMessengerCleanup?.openRequest(Number(context.requestId), {
                messenger: context.openMessenger === true
            });
            if (requestId !== latestPageRequest) return false;
            if (!opened) throw new Error("Unable to open the request notification.");
        }
        return true;
    } catch (error) {
        if (requestId === latestPageRequest) {
            console.error("Error loading admin page:", error);
        }
        return false;
    } finally {
        if (requestId === latestPageRequest) {
            container.setAttribute("aria-busy", "false");
        }
    }
}

function updateNavigation(activeSelector) {
    Object.values(ADMIN_PAGES).forEach(config => {
        const button = document.querySelector(config.navigation);
        if (button) {
            button.classList.toggle("selected", config.navigation === activeSelector);
        }
    });
}

// Requests: bind popup controls after the page fragment has been inserted.
function initRequestDetails(container) {
    requestMessengerCleanup = window.KNSRequests.init(container, "admin");
}

// Overview: real post search, category filtering, expansion, and the publisher dialog.
function initAnnouncements(container) {
    const root = container.querySelector('.kns-overview');
    if (!root) return;
    const search = root.querySelector('[data-post-search]');
    const filter = root.querySelector('[data-post-filter]');
    const cards = [...root.querySelectorAll('[data-post-card]')];
    const status = root.querySelector('[data-feed-status]');
    const empty = root.querySelector('[data-post-empty]');
    const normalize = value => value.normalize('NFKC').toLocaleLowerCase().replace(/\s+/g, ' ').trim();
    const records = cards.map(card => ({card, text: normalize(card.dataset.searchText), expires: Date.parse(card.dataset.expiresAt) || Infinity}));
    const serverClock = Number(root.dataset.serverTime) || Date.now();
    const startedAt = performance.now();
    const serverNow = () => serverClock + performance.now() - startedAt;
    let appliedSearch = ''; // Typing stays a draft until Enter is pressed.
    const measureDescriptions = () => {
        cards.forEach(card => {
            if (card.hidden || !card.isConnected) return;
            const text = card.querySelector('.kns-description');
            const button = card.querySelector('.kns-see-more');
            button.hidden = !text.classList.contains('is-expanded') && text.scrollHeight <= text.clientHeight + 1;
        });
    };
    const applyFilters = () => {
        const words = appliedSearch.split(' ').filter(Boolean);
        let count = 0;
        let activeCount = 0;
        const now = serverNow();
        records.forEach(({card, text, expires}) => {
            const active = card.isConnected && expires > now;
            if (active) activeCount++;
            const visible = active && (!filter.value || card.dataset.postType === filter.value) && words.every(word => text.includes(word));
            card.hidden = !visible;
            if (visible) count++;
            else card.querySelectorAll('video').forEach(video => video.pause());
        });
        empty.hidden = count > 0;
        empty.querySelector('strong').textContent = activeCount ? 'No matching posts' : 'No announcements yet';
        empty.querySelector('p').textContent = activeCount ? 'Try a different search or post type.' : 'Create a post to keep your school community informed.';
        root.querySelector('[data-total-posts]').textContent = activeCount.toLocaleString('en-US');
        status.textContent = `${count} ${count === 1 ? 'post' : 'posts'}${count !== activeCount ? ` of ${activeCount}` : ''}`;
        requestAnimationFrame(measureDescriptions);
    };
    search.addEventListener('keydown', event => {
        if (event.key !== 'Enter' || event.isComposing) return;
        event.preventDefault();
        appliedSearch = normalize(search.value);
        applyFilters();
    });
    filter.addEventListener('change', applyFilters);
    root.querySelectorAll('.kns-see-more').forEach(button => {
        button.addEventListener('click', () => {
            const text = root.querySelector(`#${button.getAttribute('aria-controls')}`);
            const expanded = text.classList.toggle('is-expanded');
            button.textContent = expanded ? 'See less' : 'See more';
            button.setAttribute('aria-expanded', String(expanded));
        });
    });
    const observer = new ResizeObserver(() => {
        if (!root.isConnected) observer.disconnect();
        else measureDescriptions();
    });
    observer.observe(root);
    applyFilters();
    initAnnouncementDeletion(root, applyFilters);
    // Check the nearest deadline, using server time so a device clock cannot extend a post.
    const scheduleExpiration = () => {
        const now = serverNow();
        const next = records.reduce((nearest, record) => record.card.isConnected && record.expires > now ? Math.min(nearest, record.expires) : nearest, Infinity);
        if (!Number.isFinite(next)) return;
        setTimeout(() => {
            if (!root.isConnected) return;
            applyFilters();
            scheduleExpiration();
        }, Math.min(60000, Math.max(1, next - now + 50)));
    };
    scheduleExpiration();
    const dialog = root.querySelector('.kns-post-modal');
    const form = root.querySelector('[data-post-form]');
    const fieldset = form.querySelector('fieldset');
    const publish = form.querySelector('[type=submit]');
    const message = form.querySelector('[data-post-message]');
    const duration = form.elements.namedItem('duration');
    const expiresInput = form.elements.namedItem('expires_at');
    const customExpiration = form.querySelector('[data-custom-expiration]');
    const updateDuration = () => {
        const custom = duration.value === 'custom';
        customExpiration.hidden = !custom;
        expiresInput.disabled = !custom;
        expiresInput.required = custom;
        expiresInput.setCustomValidity('');
        // datetime-local has no timezone; show and validate Philippine wall-clock time.
        expiresInput.min = new Date(Date.now() + 8 * 60 * 60 * 1000 + 60000).toISOString().slice(0, 16);
    };
    duration.addEventListener('change', updateDuration);
    updateDuration();
    let publishing = false;
    let published = false;
    root.querySelector('[data-create-post]').addEventListener('click', () => {
        if (published) return;
        message.textContent = '';
        updateDuration();
        dialog.showModal();
    });
    root.querySelectorAll('[data-close-post]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => {
        clearMediaPreviewURLs();
        root.querySelector('#mediaPreview').replaceChildren();
        root.querySelector('#mediaInput').value = '';
        root.querySelector('[data-media-message]').textContent = '';
    });
    // Native <dialog> handles focus trapping, focus restoration, and Escape.
    form.addEventListener('input', event => event.target.setCustomValidity?.(''));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (publishing || published) return;
        for (const name of ['title', 'highlights', 'description']) {
            const input = form.elements.namedItem(name);
            input.setCustomValidity(input.value.trim() ? '' : 'Please complete this field.');
        }
        if (!form.reportValidity()) return;
        const body = new FormData(form);
        const selectedSearch = search.value;
        const selectedAppliedSearch = appliedSearch;
        const selectedType = filter.value;
        publishing = true;
        fieldset.disabled = true;
        publish.disabled = true;
        publish.textContent = 'Publishing...';
        form.setAttribute('aria-busy', 'true');
        message.textContent = '';
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body,
            });
            if (response.status === 401) {
                window.location.assign('../login.php');
                return;
            }
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to publish your post.');
            published = true;
            if (!root.isConnected) return;
            dialog.close();
            const refreshed = await loadPage('overview.php');
            if (!refreshed) {
                root.querySelector('[data-create-post]').disabled = true;
                const feedback = root.querySelector('[data-publish-status]');
                feedback.textContent = 'Your post was published. Reload the overview to see it.';
                feedback.hidden = false;
                return;
            }
            const updatedRoot = container.querySelector('.kns-overview');
            if (!updatedRoot) return;
            const updatedSearch = updatedRoot.querySelector('[data-post-search]');
            updatedSearch.value = selectedAppliedSearch;
            updatedRoot.querySelector('[data-post-filter]').value = selectedType;
            updatedSearch.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true}));
            updatedSearch.value = selectedSearch;
            const feedback = updatedRoot.querySelector('[data-publish-status]');
            feedback.textContent = 'Your post was published.';
            feedback.hidden = false;
        } catch (error) {
            message.textContent = error.message || 'Unable to publish your post. Please try again.';
            if (!dialog.open && !published && root.isConnected) dialog.showModal();
        } finally {
            publishing = false;
            fieldset.disabled = false;
            publish.disabled = published;
            publish.textContent = published ? 'Published' : 'Publish Post';
            form.removeAttribute('aria-busy');
        }
    });
}

// Overview: delete only after the administrator confirms the selected post.
function initAnnouncementDeletion(root, applyFilters) {
    const dialog = root.querySelector('.kns-delete-modal');
    const form = dialog.querySelector('[data-delete-form]');
    const title = dialog.querySelector('[data-delete-title]');
    const message = dialog.querySelector('[data-delete-message]');
    const confirm = form.querySelector('[type=submit]');
    const cancelButtons = dialog.querySelectorAll('[data-cancel-delete]');
    let targetCard = null;
    let deleting = false;
    root.querySelectorAll('[data-delete-post]').forEach(button => {
        button.addEventListener('click', () => {
            if (deleting) return;
            targetCard = button.closest('[data-post-card]');
            form.elements.announcement_id.value = targetCard.dataset.announcementId;
            title.textContent = targetCard.querySelector('h3').textContent.trim();
            message.textContent = '';
            dialog.showModal();
        });
    });
    cancelButtons.forEach(button => button.addEventListener('click', () => {
        if (!deleting) dialog.close();
    }));
    dialog.addEventListener('cancel', event => {
        if (deleting) event.preventDefault();
    });
    dialog.addEventListener('close', () => {
        targetCard = null;
        form.elements.announcement_id.value = '';
        message.textContent = '';
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (deleting || !targetCard) return;
        const card = targetCard;
        const body = new FormData(form);
        deleting = true;
        confirm.disabled = true;
        confirm.textContent = 'Deleting...';
        cancelButtons.forEach(button => { button.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        message.textContent = '';
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body,
            });
            if (response.status === 401) {
                window.location.assign('../login.php');
                return;
            }
            const data = await response.json();
            if ((!response.ok || !data.ok) && response.status !== 404) {
                throw new Error(data.message || 'Unable to delete the post. Please try again.');
            }
            if (!root.isConnected) return;
            card.querySelectorAll('video').forEach(video => video.pause());
            card.remove();
            applyFilters();
            const feedback = root.querySelector('[data-publish-status]');
            feedback.textContent = data.message;
            feedback.hidden = false;
            dialog.close();
            const next = root.querySelector('[data-post-card]:not([hidden]) [data-delete-post]') || root.querySelector('[data-create-post]');
            next.focus();
        } catch (error) {
            message.textContent = error.message || 'Unable to delete the post. Please try again.';
        } finally {
            deleting = false;
            confirm.disabled = false;
            confirm.textContent = 'Delete Post';
            cancelButtons.forEach(button => { button.disabled = false; });
            form.removeAttribute('aria-busy');
        }
    });
}

// Overview: multiple-image selection, removal, and temporary URL cleanup.
function clearMediaPreviewURLs() {
    mediaPreviewURLs.forEach(url => URL.revokeObjectURL(url));
    mediaPreviewURLs = [];
}

function initMediaPreview(container) {
    const input = container.querySelector('.kns-overview #mediaInput');
    const preview = container.querySelector('.kns-overview #mediaPreview');
    if (!input || !preview) return;
    const message = container.querySelector('[data-media-message]');
    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm'];
    let selectedFiles = [];
    const syncInput = () => {
        const transfer = new DataTransfer();
        selectedFiles.forEach(file => transfer.items.add(file));
        input.files = transfer.files;
    };
    const render = () => {
        clearMediaPreviewURLs();
        preview.replaceChildren();
        selectedFiles.forEach((file, index) => {
            const tile = document.createElement('figure');
            tile.className = 'kns-media-preview-item';
            const media = document.createElement(file.type.startsWith('image/') ? 'img' : 'video');
            if (media.tagName === 'IMG') media.alt = 'Selected image ' + (index + 1) + ': ' + file.name;
            else media.controls = true;
            const url = URL.createObjectURL(file);
            mediaPreviewURLs.push(url);
            media.src = url;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'kns-remove-media';
            remove.textContent = '\u00d7';
            remove.setAttribute('aria-label', 'Remove ' + file.name);
            remove.title = 'Remove ' + file.name;
            remove.addEventListener('click', () => {
                selectedFiles.splice(index, 1);
                syncInput();
                message.textContent = '';
                render();
                const buttons = preview.querySelectorAll('.kns-remove-media');
                (buttons[Math.min(index, buttons.length - 1)] || input).focus();
            });
            const caption = document.createElement('figcaption');
            caption.textContent = file.name;
            caption.title = file.name;
            tile.append(media, remove, caption);
            preview.appendChild(tile);
        });
    };
    input.addEventListener('change', () => {
        const files = [...selectedFiles];
        [...input.files].forEach(file => {
            if (!files.some(existing => existing.name === file.name && existing.size === file.size
                && existing.lastModified === file.lastModified && existing.type === file.type)) files.push(file);
        });
        let error = '';
        if (files.length > 10) error = 'Choose up to 10 images or one video.';
        else if (files.some(file => !allowed.includes(file.type) || !file.size)) {
            error = 'Choose nonempty JPG, PNG, GIF, WEBP images, or one MP4 or WEBM video.';
        } else if (files.reduce((total, file) => total + file.size, 0) > 20 * 1024 * 1024) {
            error = 'Attachments must total at most 20 MB.';
        } else if (files.length > 1 && files.some(file => file.type.startsWith('video/'))) {
            error = 'Choose multiple images or one video. Remove the current selection to switch.';
        }
        message.textContent = error;
        if (!error) selectedFiles = files;
        syncInput();
        if (!error) render();
    });
    input.closest('dialog').addEventListener('close', () => { selectedFiles = []; });
}

function initOfficeAccounts(container) {
    const launch = container.querySelector('[data-open-office-accounts]');
    const list = container.querySelector('#kns-office-accounts');
    const confirmation = container.querySelector('#kns-office-account-delete');
    const registration = container.querySelector('[data-open-office-registration]');
    const registrationModal = container.querySelector('#kns-office-registration');
    const createForm = container.querySelector('#kns-office-registration-form');
    const deleteForm = container.querySelector('#kns-office-deactivation-form');
    if (!launch || !list || !confirmation || !registration || !createForm || !deleteForm) return;
    const body = list.querySelector('tbody');
    const listMessage = list.querySelector('[data-office-accounts-message]');
    const createMessage = createForm.querySelector('[data-office-registration-message]');
    const deleteMessage = deleteForm.querySelector('[data-office-deactivation-message]');
    const endpoint = new URL(createForm.getAttribute('action'), document.baseURI).href;
    let records = new Map();
    let loadVersion = 0;
    let creating = false;
    let deactivating = false;

    const positionLaunch = () => {
        const anchor = registration.parentElement.getBoundingClientRect();
        launch.style.right = (anchor.right - registration.getBoundingClientRect().left + 12) + 'px';
    };
    const observer = new ResizeObserver(() => {
        if (!launch.isConnected) { observer.disconnect(); return; }
        positionLaunch();
    });
    observer.observe(registration);
    observer.observe(registration.parentElement);
    positionLaunch();

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const message = (node, text = '', error = false) => {
        node.textContent = text;
        node.hidden = !text;
        node.dataset.state = error ? 'error' : 'success';
        node.setAttribute('role', error ? 'alert' : 'status');
    };
    const request = async options => {
        const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store',
            headers: {Accept: 'application/json'}, ...options});
        let data;
        try { data = await response.json(); } catch { throw new Error('The server could not complete the account request. Please try again.'); }
        if (response.status === 401) window.location.assign('../login.php');
        if (!response.ok || !data.ok) {
            const error = new Error(data.message || 'Unable to complete the account request.');
            error.data = data;
            throw error;
        }
        return data;
    };
    const stateRow = (text, retry = false) => {
        const row = element('tr');
        const cell = element('td', 'kns-office-accounts-state', text);
        cell.colSpan = 5;
        if (retry) {
            const button = element('button', 'kns-office-accounts-retry', 'Try again');
            button.type = 'button';
            button.addEventListener('click', () => loadAccounts());
            cell.append(document.createTextNode(' '), button);
        }
        row.append(cell);
        body.replaceChildren(row);
    };
    const trashIcon = () => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5M14 11v5');
        svg.append(path);
        return svg;
    };
    const render = accounts => {
        records = new Map(accounts.map(account => [account.id, account]));
        if (!accounts.length) { stateRow('No office accounts yet.'); return; }
        const rows = accounts.map(account => {
            const row = element('tr');
            row.dataset.officeAccountId = account.id;
            const name = element('td', 'kns-office-accounts-name');
            name.append(element('span', 'kns-office-accounts-name-text', account.full_name),
                element('span', 'kns-office-accounts-detail', 'Phone: ' + (account.phone_number || '\u2014')));
            const contact = element('td');
            contact.append(element('span', 'kns-office-accounts-email', account.email || '\u2014'),
                element('span', 'kns-office-accounts-detail', 'Username: ' + account.username));
            const role = element('td', '', account.role);
            const status = element('td');
            status.append(element('span', 'kns-office-accounts-status' + (account.is_active ? '' : ' is-inactive'),
                account.is_active ? 'Active' : 'Inactive'));
            const action = element('td');
            const button = element('button', 'kns-office-accounts-row-delete');
            button.type = 'button';
            button.dataset.officeAccountDelete = account.id;
            button.setAttribute('aria-label', 'Deactivate ' + account.full_name);
            button.setAttribute('aria-haspopup', 'dialog');
            button.setAttribute('aria-controls', 'kns-office-account-delete');
            button.disabled = !account.is_active;
            button.append(trashIcon(), document.createTextNode('Delete'));
            action.append(button);
            row.append(name, contact, role, status, action);
            return row;
        });
        body.replaceChildren(...rows);
    };
    async function loadAccounts(success = '') {
        const version = ++loadVersion;
        list.querySelector('table').setAttribute('aria-busy', 'true');
        message(listMessage, success);
        stateRow('Loading office accounts...');
        try {
            const data = await request({method: 'GET'});
            if (version !== loadVersion || !container.isConnected) return;
            render(data.accounts);
        } catch (error) {
            if (version !== loadVersion || !container.isConnected) return;
            stateRow('Unable to load office accounts.', true);
            message(listMessage, error.message, true);
        } finally {
            if (version === loadVersion) list.querySelector('table').removeAttribute('aria-busy');
        }
    }
    const busy = (modal, form, value, closeSelector, submitLabel) => {
        form.setAttribute('aria-busy', String(value));
        [...form.elements].forEach(field => { field.disabled = value; });
        modal.querySelectorAll(closeSelector).forEach(button => { button.disabled = value; });
        form.querySelector('[type="submit"]').textContent = submitLabel;
    };
    const clearValidity = form => form.querySelectorAll('[aria-invalid]').forEach(field => field.removeAttribute('aria-invalid'));

    launch.addEventListener('click', () => { list.showModal(); loadAccounts(); });
    list.querySelector('[data-close-office-accounts]').addEventListener('click', () => list.close());
    registration.addEventListener('click', () => {
        message(createMessage);
        clearValidity(createForm);
        registrationModal.showModal();
    });
    list.querySelector('.kns-office-accounts-register').addEventListener('click', () => {
        list.close();
        registration.click();
    });
    registrationModal.querySelectorAll('[data-close-office-registration]').forEach(button => {
        button.addEventListener('click', () => { if (!creating) registrationModal.close(); });
    });
    registrationModal.addEventListener('cancel', event => { if (creating) event.preventDefault(); });
    registrationModal.addEventListener('close', () => {
        createForm.elements.password.value = '';
        createForm.elements.password_confirmation.value = '';
        createForm.elements.password_confirmation.setCustomValidity('');
    });
    createForm.addEventListener('input', event => {
        event.target.removeAttribute('aria-invalid');
        createForm.elements.password_confirmation.setCustomValidity('');
    });
    createForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (creating) return;
        createForm.elements.password_confirmation.setCustomValidity(
            createForm.elements.password.value === createForm.elements.password_confirmation.value ? '' : 'The passwords must match.');
        if (!createForm.reportValidity()) return;
        const payload = new FormData(createForm);
        creating = true;
        clearValidity(createForm);
        busy(registrationModal, createForm, true, '[data-close-office-registration]', 'Creating...');
        message(createMessage);
        let invalidField;
        try {
            const data = await request({method: 'POST', body: payload});
            if (!container.isConnected) return;
            createForm.reset();
            registrationModal.close();
            list.showModal();
            await loadAccounts(data.message);
        } catch (error) {
            message(createMessage, error.message, true);
            Object.keys(error.data?.errors || {}).forEach(name => {
                const field = createForm.elements.namedItem(name);
                if (field) { field.setAttribute('aria-invalid', 'true'); invalidField ||= field; }
            });
        } finally {
            creating = false;
            busy(registrationModal, createForm, false, '[data-close-office-registration]', 'Create Account');
            createForm.elements.password.value = '';
            createForm.elements.password_confirmation.value = '';
            invalidField?.focus();
        }
    });

    body.addEventListener('click', event => {
        const button = event.target.closest('[data-office-account-delete]');
        if (!button || button.disabled) return;
        const account = records.get(Number(button.dataset.officeAccountDelete));
        if (!account || !account.is_active) return;
        confirmation.querySelector('[data-selected-office-account-name]').textContent = account.full_name;
        confirmation.querySelector('[data-selected-office-account-role]').textContent = account.role;
        deleteForm.elements.account_id.value = account.id;
        deleteForm.elements.admin_password.value = '';
        message(deleteMessage);
        confirmation.showModal();
    });
    confirmation.querySelectorAll('[data-close-office-account-delete]').forEach(button => {
        button.addEventListener('click', () => { if (!deactivating) confirmation.close(); });
    });
    confirmation.addEventListener('cancel', event => { if (deactivating) event.preventDefault(); });
    confirmation.addEventListener('close', () => {
        deleteForm.elements.admin_password.value = '';
        deleteForm.elements.account_id.value = '';
        message(deleteMessage);
    });
    deleteForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (deactivating || !deleteForm.reportValidity()) return;
        const payload = new FormData(deleteForm);
        deactivating = true;
        busy(confirmation, deleteForm, true, '[data-close-office-account-delete]', 'Deactivating...');
        message(deleteMessage);
        try {
            const data = await request({method: 'POST', body: payload});
            if (!container.isConnected) return;
            confirmation.close();
            await loadAccounts(data.message);
        } catch (error) {
            message(deleteMessage, error.message, true);
        } finally {
            deactivating = false;
            busy(confirmation, deleteForm, false, '[data-close-office-account-delete]', 'Delete Account');
            deleteForm.elements.admin_password.value = '';
        }
    });
}

// Profile: save the signed-in admin's details and refresh the dashboard labels.
function initAdminDashboard() {
    if (dashboardInitialized) return;
    if (!document.getElementById("main-content-contianer")) return;

    dashboardInitialized = true;
    window.KNSNotifications.init({
        role: "admin",
        navigate: async notification => {
            if (notification.type === "announcement" || notification.type === "activity") {
                const opened = await loadPage(notification.type === "announcement" ? "overview.php" : "profile.php");
                if (!opened) throw new Error("Unable to open this notification.");
                return;
            }
            const opened = await loadPage("requests.html", {
                requestId: notification.request_id,
                openMessenger: notification.type === "message"
            });
            if (!opened) throw new Error("Unable to open this request notification. Please try again.");
        }
    });
    const view = new URLSearchParams(window.location.search).get("view");
    const requested = view === "profile" ? "profile.php" : view === "results" ? "results.php" : "overview.php";
    const page = document.querySelector(ADMIN_PAGES[requested].navigation) ? requested : "overview.php";
    return loadPage(page);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAdminDashboard, { once: true });
} else {
    initAdminDashboard();
}

// Match the grade card: its button opens the hidden student workbook picker.
function initStudentUpload(container) {
    const form = container.querySelector("#student-upload-form");
    const button = container.querySelector("[data-student-upload-picker]");
    if (!form || !button) return;
    const input = form.querySelector("[name='student_excel']");

    button.addEventListener("click", () => input.click());
    input.addEventListener("change", () => {
        if (input.files.length) form.requestSubmit();
    });
}

// Results is loaded as a fragment; attach its workbook picker after insertion.
function initGradeUpload(container) {
    const form = container.querySelector("#grade-upload-form");
    const button = container.querySelector("[data-grade-upload-picker]");
    if (!form || !button) return;
    const input = form.querySelector("[name='grades_excel']");
    const label = button.querySelector("[data-grade-upload-label]");
    const message = container.querySelector("#grade-upload-message");
    let uploading = false;

    button.addEventListener("click", () => {
        if (!uploading) input.click();
    });
    input.addEventListener("change", () => {
        if (!uploading && input.files.length) form.requestSubmit();
    });
    form.addEventListener("submit", async event => {
        event.preventDefault();
        if (uploading) return;
        const file = input.files[0];
        if (!file) return;
        if (!file.name.toLowerCase().endsWith(".xlsx") || file.size === 0 || file.size > 10 * 1024 * 1024) {
            message.textContent = "Choose a nonempty .xlsx workbook at most 10 MB in size.";
            message.dataset.state = "error";
            input.value = "";
            return;
        }
        const body = new FormData(form);
        uploading = true;
        button.disabled = true;
        input.disabled = true;
        button.setAttribute("aria-busy", "true");
        label.textContent = "Uploading...";
        message.textContent = "Uploading and validating the workbook...";
        message.dataset.state = "pending";
        try {
            const response = await fetch(form.action, {
                method: "POST",
                credentials: "same-origin",
                headers: { Accept: "application/json" },
                body,
            });
            if (response.status === 401 || response.redirected) {
                window.location.assign(response.redirected ? response.url : "../login.php");
                return;
            }
            const data = await response.json().catch(() => null);
            if (!data) {
                throw new Error("Unable to confirm the upload result. Check the server log and try again.");
            }
            if (!response.ok || !data.ok) {
                throw new Error(data.message || "Unable to import the workbook.");
            }
            message.textContent = data.message;
            message.dataset.state = "success";
        } catch (error) {
            message.textContent = error instanceof TypeError
                ? "Unable to confirm the upload result. Check your connection and try again."
                : (error.message || "Unable to confirm the upload result. Please try again.");
            message.dataset.state = "error";
        } finally {
            input.value = "";
            uploading = false;
            button.disabled = false;
            input.disabled = false;
            button.removeAttribute("aria-busy");
            label.textContent = "Upload Grades";
        }
    });
}

// Load database search results and pages together, reusing the server-rendered rows.
function initStudentList(container) {
    const input = container.querySelector("#student-search");
    const table = container.querySelector(".results-table");
    const body = container.querySelector("#student-results-body");
    const total = container.querySelector(".studentlist-total p");
    const entries = container.querySelector("#student-entries");
    const pagination = container.querySelector(".pagination");
    const filterPanel = container.querySelector(".student-list-filters");
    const filterFields = [...container.querySelectorAll("[data-student-filter]")];
    const applyButton = filterPanel?.querySelector(".filter-apply");
    const resetButton = filterPanel?.querySelector(".filter-reset");
    if (!input || !table || !body || !total || !entries || !pagination || !applyButton || !resetButton) return;
    let appliedFilters = Object.fromEntries(filterFields.map(field => [field.name, field.value]));

    let timer;
    let controller;
    let requestVersion = 0;

    function showMessage(text) {
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.colSpan = 8;
        cell.textContent = text;
        row.appendChild(cell);
        body.replaceChildren(row);
    }

    async function loadStudents(version, page) {
        if (version !== requestVersion || !input.isConnected) return;
        controller = new AbortController();
        const url = new URL("results.php", window.location.href);
        url.searchParams.set("q", input.value.trim());
        url.searchParams.set("page", String(page));
        url.searchParams.set("per_page", entries.value);
        for (const [name, value] of Object.entries(appliedFilters)) {
            if (value !== "") url.searchParams.set(name, value);
        }
        table.setAttribute("aria-busy", "true");
        showMessage("Loading students...");

        try {
            const response = await fetch(url, {
                credentials: "same-origin",
                cache: "no-store",
                signal: controller.signal,
            });
            if (version !== requestVersion || !input.isConnected) return;
            if (response.redirected || response.status === 401) {
                window.location.assign(response.redirected ? response.url : "../login.php");
                return;
            }
            if (!response.ok) throw new Error("Unable to load students.");

            const html = await response.text();
            if (version !== requestVersion || !input.isConnected) return;
            const result = new DOMParser().parseFromString(html, "text/html");
            const nextBody = result.querySelector("#student-results-body");
            const nextTotal = result.querySelector(".studentlist-total p");
            const nextEntries = result.querySelector("#student-entries");
            const nextPagination = result.querySelector(".pagination");
            if (!nextBody || !nextTotal || !nextEntries || !nextPagination) {
                throw new Error("Invalid student list response.");
            }

            body.replaceChildren(...nextBody.childNodes);
            total.textContent = nextTotal.textContent;
            entries.value = nextEntries.value;
            pagination.replaceChildren(...nextPagination.childNodes);
            pagination.dataset.currentPage = nextPagination.dataset.currentPage;
            pagination.dataset.totalPages = nextPagination.dataset.totalPages;
            const selectAll = table.querySelector("thead input[type='checkbox']");
            if (selectAll) selectAll.checked = false;
        } catch (error) {
            if (error.name === "AbortError" || version !== requestVersion || !input.isConnected) return;
            showMessage("Unable to load students. Please check your connection and try again.");
            total.textContent = "\u2014";
        } finally {
            if (version === requestVersion && input.isConnected) {
                table.setAttribute("aria-busy", "false");
            }
        }
    }

    function scheduleLoad(page = 1, immediate = false) {
        clearTimeout(timer);
        controller?.abort();
        const version = ++requestVersion;
        if (immediate) {
            loadStudents(version, page);
        } else {
            timer = setTimeout(() => loadStudents(version, page), 300);
        }
    }

    input.addEventListener("input", () => scheduleLoad());
    input.addEventListener("keydown", event => {
        if (event.key === "Enter" && !event.isComposing) {
            event.preventDefault();
            scheduleLoad(1, true);
        }
    });
    // Dropdown edits take effect when Apply Filter is pressed.
    applyButton.addEventListener("click", () => {
        appliedFilters = Object.fromEntries(filterFields.map(field => [field.name, field.value]));
        scheduleLoad(1, true);
    });
    resetButton.addEventListener("click", () => {
        filterFields.forEach(field => { field.value = ""; });
        appliedFilters = Object.fromEntries(filterFields.map(field => [field.name, ""]));
        input.value = "";
        scheduleLoad(1, true);
    });
    entries.addEventListener("change", () => scheduleLoad(1, true));
    pagination.addEventListener("click", event => {
        const button = event.target.closest("button[data-page]");
        if (!button || !pagination.contains(button) || button.disabled) return;
        const page = Number(button.dataset.page);
        if (Number.isInteger(page) && page > 0) scheduleLoad(page, true);
    });
}
