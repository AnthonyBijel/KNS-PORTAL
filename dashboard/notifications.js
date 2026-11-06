/* Shared admin/student notification panel. Each role uses its own authenticated feed and read receipts. */
(() => {
    "use strict";
    const glyph = {
        bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        announcement: '<path d="m3 10 12-5v14L3 14z"/><path d="M15 8h4v8h-4M6 15l1 6h4l-2-5"/>',
        message: '<path d="M4 4h16v12H9l-5 4z"/>',
        request: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 10h8M8 14h8M8 18h5"/>',
        Approved: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        Rejected: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
        Processing: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
    };
    const svg = name => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (glyph[name] || glyph.bell) + '</svg>';
    function node(tag, className, text) {
        const result = document.createElement(tag);
        if (className) result.className = className;
        if (text !== undefined) result.textContent = text;
        return result;
    }
    const dayFormat = new Intl.DateTimeFormat("en-CA", {timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit"});
    const dateFormat = new Intl.DateTimeFormat("en-PH", {timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short"});
    function time(value) {
        const when = new Date(value);
        const difference = Math.max(0, Math.floor((Date.now() - when.getTime()) / 1000));
        if (difference < 60) return "Just now";
        if (difference < 3600) { const count = Math.floor(difference / 60); return count + (count === 1 ? " minute ago" : " minutes ago"); }
        if (difference < 86400) { const count = Math.floor(difference / 3600); return count + (count === 1 ? " hour ago" : " hours ago"); }
        return dateFormat.format(when);
    }
    function group(value) {
        const today = dayFormat.format(new Date());
        const yesterday = dayFormat.format(new Date(Date.now() - 86400000));
        const actual = dayFormat.format(new Date(value));
        return actual === today ? "Today" : actual === yesterday ? "Yesterday" : "Earlier";
    }

    function init({navigate, role = "student"}) {
        const admin = role === "admin";
        const prefix = admin ? "admin" : "student";
        const endpoint = new URL("../php/" + prefix + "/notifications.php", window.location.href);
        const bell = document.getElementById(prefix + "NotificationsBell");
        if (!bell) return;
        const badge = document.getElementById(prefix + "NotificationCount");
        const panel = node("section", "kns-notifications");
        panel.id = prefix + "NotificationPanel";
        panel.hidden = true;
        panel.setAttribute("role", "dialog");
        panel.setAttribute("aria-labelledby", prefix + "NotificationTitle");
        // Static design markup; all records below are inserted as text.
        panel.innerHTML = '<header class="kn-titlebar"><span class="kn-title-icon">' + svg("bell") + '</span><h2 id="studentNotificationTitle">Notifications</h2><span class="kn-new" hidden></span><button type="button" class="kn-close" aria-label="Close notifications">&times;</button></header>' +
            '<div class="kn-toolbar"><div class="kn-tabs" role="tablist" aria-label="Notification filter"><button type="button" role="tab" class="kn-tab is-active" data-filter="all" aria-selected="true">All</button><button type="button" role="tab" class="kn-tab" data-filter="unread" aria-selected="false">Unread <span class="kn-unread-label">(0)</span></button></div><button type="button" class="kn-read-all">Mark all as read</button></div>' +
            '<div class="kn-scroll" tabindex="0" aria-label="Notifications"><div class="kn-list"></div></div><p class="kn-feedback" role="status" aria-live="polite"></p><footer class="kn-footer"><button type="button" class="kn-history">View all notifications <span aria-hidden="true">›</span></button></footer>';
        panel.querySelector("h2").id = prefix + "NotificationTitle";
        document.body.append(panel);
        const list = panel.querySelector(".kn-list");
        const scroller = panel.querySelector(".kn-scroll");
        const feedback = panel.querySelector(".kn-feedback");
        const newBadge = panel.querySelector(".kn-new");
        const readAll = panel.querySelector(".kn-read-all");
        const history = panel.querySelector(".kn-history");
        const tabs = Array.from(panel.querySelectorAll(".kn-tab"));
        let rows = [], unread = 0, total = 0, csrf = "", filter = "all";
        let cursor = null, expanded = false, loadedOlder = false, ready = false, posting = false, opening = false;
        let timer = null, controller = null, sequence = 0, loading = false, terminal = false, failed = false;
        let mutationQueue = Promise.resolve();
        const acknowledged = new Map(), pendingAcknowledgements = new Map();
        const viewedRequests = new Set(), pendingRequestViews = new Set(), waitingRequestViews = new Set();

        async function api(params = {}, body = null, signal) {
            const url = new URL(endpoint);
            Object.entries(params).forEach(([key, value]) => { if (value != null && value !== "") url.searchParams.set(key, value); });
            const response = await fetch(url, {method: body ? "POST" : "GET", body, signal,
                credentials: "same-origin", cache: "no-store", headers: {Accept: "application/json"}});
            let data;
            try { data = await response.json(); } catch (error) {
                if (error.name === "AbortError") throw error;
                throw new Error("Unable to load notifications. Please try again.");
            }
            if (!response.ok || !data.ok) {
                const error = new Error(data.message || "Unable to load notifications.");
                error.status = response.status;
                throw error;
            }
            return data;
        }
        function position() {
            if (panel.hidden) return;
            const rect = bell.getBoundingClientRect();
            const width = Math.min(420, window.innerWidth - 24);
            const left = Math.max(12, Math.min(rect.right + 24 - width, window.innerWidth - width - 12));
            const top = Math.max(12, rect.bottom + 12);
            panel.style.left = left + "px";
            panel.style.top = top + "px";
            panel.style.width = width + "px";
            panel.style.maxHeight = Math.max(140, window.innerHeight - top - 12) + "px";
            panel.style.setProperty("--kn-pointer", Math.max(22, Math.min(width - 22, rect.left + rect.width / 2 - left)) + "px");
        }
        function updateControls() {
            badge.hidden = unread === 0;
            badge.textContent = unread > 99 ? "99+" : String(unread);
            bell.setAttribute("aria-label", unread ? "Notifications, " + unread + " unread" : "Notifications");
            newBadge.hidden = unread === 0;
            newBadge.textContent = unread + " new";
            panel.querySelector(".kn-unread-label").textContent = "(" + unread + ")";
            readAll.disabled = !ready || posting || unread === 0;
            tabs.forEach(tab => {
                const active = tab.dataset.filter === filter;
                tab.classList.toggle("is-active", active);
                tab.setAttribute("aria-selected", String(active));
                tab.tabIndex = active ? 0 : -1;
            });
            if (failed) {
                history.textContent = "Retry";
                history.disabled = loading;
            } else if (!expanded) {
                history.innerHTML = 'View all notifications <span aria-hidden="true">›</span>';
                history.disabled = !ready || loading;
            } else {
                history.textContent = cursor ? "Load older notifications" : "All notifications shown";
                history.disabled = !cursor || loading;
            }
            list.querySelectorAll(".kn-item").forEach(item => { item.disabled = opening; });
        }
        function render() {
            const focusKey = document.activeElement?.closest(".kn-item")?.dataset.key;
            const positionBefore = scroller.scrollTop;
            list.replaceChildren();
            let visible = rows.filter(item => filter === "all" || !item.read);
            if (!expanded) visible = visible.slice(0, 6);
            let previousGroup = "";
            visible.forEach(item => {
                const heading = group(item.created_at);
                if (heading !== previousGroup) { list.append(node("h3", "kn-date-group", heading)); previousGroup = heading; }
                const button = node("button", "kn-item" + (item.read ? "" : " is-unread"));
                button.type = "button";
                button.dataset.key = item.key;
                button.setAttribute("aria-label", (item.read ? "" : "Unread: ") + item.title + ". " + item.description);
                const icon = node("span", "kn-item-icon kn-" + (item.type === "request_status" ? (item.status || "status").toLowerCase() : item.type));
                icon.innerHTML = svg(item.type === "request_status" ? item.status : item.type);
                const copy = node("span", "kn-copy");
                const itemTitle = node("strong", "kn-item-title", item.title);
                const description = node("span", "kn-description", item.description);
                if (admin && item.type !== "activity") description.setAttribute("data-staff-content","");
                if (admin && ["announcement","message"].includes(item.type)) itemTitle.setAttribute("data-staff-content","");
                copy.append(itemTitle, description);
                if (item.context) copy.append(node("span", "kn-context", item.context));
                const timestamp = node("time", "kn-time", time(item.created_at));
                timestamp.dateTime = item.created_at;
                timestamp.title = dateFormat.format(new Date(item.created_at));
                copy.append(timestamp);
                button.append(icon, copy);
                if (!item.read) button.append(node("span", "kn-dot"));
                button.addEventListener("click", () => activate(item));
                list.append(button);
            });
            if (!visible.length) list.append(node("p", "kn-empty", !ready ? "Loading notifications…" : filter === "unread" ? "You're all caught up. No unread notifications." : "No notifications yet."));
            updateControls();
            scroller.scrollTop = positionBefore;
            if (focusKey) {
                const target = Array.from(list.querySelectorAll(".kn-item")).find(item => item.dataset.key === focusKey);
                target?.focus({preventScroll: true});
            }
        }
        function schedule(delay = 5000) {
            clearTimeout(timer);
            if (!terminal && !document.hidden) timer = setTimeout(() => load("poll"), delay);
        }
        async function load(mode = "poll") {
            if (document.hidden || posting || (mode === "more" && (!cursor || loading))) { schedule(); return; }
            const version = ++sequence;
            controller?.abort();
            controller = new AbortController();
            loading = true;
            updateControls();
            try {
                const data = await api({filter, before: mode === "more" ? cursor : null}, null, controller.signal);
                if (version !== sequence) return;
                csrf = data.csrf;
                if (mode === "poll" && data.unread_count < unread) loadedOlder = false;
                unread = data.unread_count; total = data.total;
                if (mode === "more" || (mode === "poll" && expanded && loadedOlder)) {
                    const merged = new Map(rows.map(item => [item.key, item]));
                    data.notifications.forEach(item => merged.set(item.key, item));
                    rows = Array.from(merged.values()).sort((a,b) => {
                        const byDate = b.created_at.localeCompare(a.created_at);
                        return byDate || b.type.localeCompare(a.type) || b.source_id - a.source_id;
                    });
                } else rows = data.notifications;
                if (mode === "more") loadedOlder = true;
                if (mode !== "poll" || !loadedOlder) cursor = data.next_cursor;
                ready = true; failed = false; terminal = false;
                feedback.textContent = "";
                bell.removeAttribute("title");
                render();
                if (admin) waitingRequestViews.forEach(requestId => window.KNSNotifications.readRequest(requestId));
            } catch (error) {
                if (error.name === "AbortError" || version !== sequence) return;
                failed = true;
                feedback.textContent = error.message;
                bell.title = error.message;
                terminal = [401,403].includes(error.status);
                if (terminal) { rows = []; unread = 0; ready = false; }
                if (!ready) list.replaceChildren(node("p", "kn-empty", "Notifications are unavailable."));
            } finally {
                if (version === sequence) {
                    loading = false;
                    updateControls();
                    schedule(failed ? 15000 : 5000);
                }
            }
        }
        function mutate(action, fields = {}) {
            const operation = mutationQueue.then(async () => {
                if (!csrf) throw new Error("Wait for notifications to finish loading, then try again.");
                posting = true;
                ++sequence;
                controller?.abort();
                loading = false;
                updateControls();
                const body = new FormData();
                body.set("csrf", csrf);
                body.set("action", action);
                Object.entries(fields).forEach(([key, value]) => body.set(key, value));
                try {
                    const result = await api({}, body);
                    if (action === "read_all") rows.forEach(item => { item.read = true; });
                    else if (action === "read") {
                        const item = rows.find(item => item.type === fields.type && item.source_id === fields.source_id);
                        if (item) item.read = true;
                    } else if (action === "read_request") {
                        rows.forEach(item => {
                            if (item.request_id !== fields.request_id) return;
                            if ((admin && item.type === "request")
                                || (fields.last_message_id != null && item.type !== "request" && item.source_id <= fields.last_message_id)) item.read = true;
                        });
                    }
                    return result;
                } finally {
                    posting = false;
                    await load("poll");
                }
            });
            mutationQueue = operation.catch(() => {});
            return operation;
        }
        function close(focus = false) {
            panel.hidden = true;
            bell.setAttribute("aria-expanded", "false");
            if (focus) bell.focus();
        }
        async function activate(item) {
            if (opening) return;
            opening = true;
            updateControls();
            feedback.textContent = "Opening notification…";
            try {
                const result = await mutate("read", {type: item.type, source_id: item.source_id});
                close();
                await navigate(result.notification);
            } catch (error) {
                panel.hidden = false;
                bell.setAttribute("aria-expanded", "true");
                position();
                feedback.textContent = error.message;
            } finally {
                opening = false;
                updateControls();
            }
        }
        bell.addEventListener("click", () => {
            if (!panel.hidden) { close(); return; }
            panel.hidden = false;
            bell.setAttribute("aria-expanded", "true");
            position();
            render();
            panel.querySelector(".kn-close").focus({preventScroll: true});
            load("poll");
        });
        panel.querySelector(".kn-close").addEventListener("click", () => close(true));
        document.addEventListener("pointerdown", event => {
            if (!panel.hidden && !panel.contains(event.target) && !bell.contains(event.target)) close();
        });
        document.addEventListener("keydown", event => {
            if (!panel.hidden && event.key === "Escape") { event.preventDefault(); close(true); }
        });
        panel.addEventListener("keydown", event => {
            if (!event.target.closest(".kn-tabs") || !["ArrowLeft","ArrowRight","Home","End"].includes(event.key)) return;
            event.preventDefault();
            const tab = event.key === "Home" || event.key === "ArrowLeft" ? tabs[0] : tabs[1];
            tab.click();
            tab.focus();
        });
        tabs.forEach(tab => tab.addEventListener("click", () => {
            filter = tab.dataset.filter;
            expanded = false; loadedOlder = false; cursor = null; rows = [];
            panel.classList.remove("is-expanded");
            scroller.scrollTop = 0;
            render();
            load("reset");
        }));
        readAll.addEventListener("click", async () => {
            try { await mutate("read_all"); } catch (error) { feedback.textContent = error.message; }
        });
        history.addEventListener("click", () => {
            if (failed) { terminal = false; load("reset"); return; }
            if (!expanded) {
                expanded = true;
                panel.classList.add("is-expanded");
                if (filter !== "all") {
                    filter = "all"; rows = []; loadedOlder = false; cursor = null;
                    load("reset");
                }
                render();
            } else load("more");
        });
        if (admin) window.addEventListener("kns-staff-preferences-changed", () => load("reset"));
        window.addEventListener("resize", position);
        window.addEventListener("scroll", position, {passive: true, capture: true});
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) { clearTimeout(timer); controller?.abort(); }
            else load("poll");
        });
        window.addEventListener("pagehide", () => { clearTimeout(timer); controller?.abort(); });
        window.addEventListener("pageshow", event => { if (event.persisted) load("poll"); });
        window.KNSNotifications.readConversation = (requestId, lastMessageId) => {
            if (document.hidden || !csrf || terminal || lastMessageId <= (acknowledged.get(requestId) || 0)
                || lastMessageId <= (pendingAcknowledgements.get(requestId) || 0)) return;
            pendingAcknowledgements.set(requestId, lastMessageId);
            mutate("read_request", {request_id: requestId, last_message_id: lastMessageId})
                .then(() => {
                    acknowledged.set(requestId, Math.max(acknowledged.get(requestId) || 0, lastMessageId));
                    if (admin) { viewedRequests.add(requestId); waitingRequestViews.delete(requestId); }
                })
                .catch(error => { if (!panel.hidden) feedback.textContent = error.message; })
                .finally(() => {
                    if (pendingAcknowledgements.get(requestId) === lastMessageId) pendingAcknowledgements.delete(requestId);
                });
        };
        window.KNSNotifications.readRequest = requestId => {
            if (!admin || document.hidden || terminal || viewedRequests.has(requestId) || pendingRequestViews.has(requestId)) return;
            if (!csrf) { waitingRequestViews.add(requestId); return; }
            waitingRequestViews.delete(requestId);
            pendingRequestViews.add(requestId);
            mutate("read_request", {request_id: requestId})
                .then(() => viewedRequests.add(requestId))
                .catch(error => {
                    if (!error.status || error.status >= 500) waitingRequestViews.add(requestId);
                    if (!panel.hidden) feedback.textContent = error.message;
                })
                .finally(() => pendingRequestViews.delete(requestId));
        };
        render();
        load("reset");
    }
    window.KNSNotifications = {init};
})();
