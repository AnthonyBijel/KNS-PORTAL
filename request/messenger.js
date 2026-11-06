/* Shared request records and human-to-human Messenger for both dashboard loaders. */
(() => {
    "use strict";
    const apiRoot = new URL("../php/request/", window.location.href);
    const imageTypes = new Set(["image/jpeg", "image/png", "image/gif", "image/webp"]);
    const chatIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.5 3 2 6.6 2 11c0 2.4 1.3 4.6 3.5 6.1L4.5 21l4.7-2.3c.9.2 1.8.3 2.8.3 5.5 0 10-3.6 10-8S17.5 3 12 3Z"/></svg>';
    const photoIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg>';
    const clipIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 13 7-7a3 3 0 0 1 4 4l-9 9a5 5 0 0 1-7-7l9-9a7 7 0 0 1 10 10l-8 8"/></svg>';

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function token() {
        if (crypto.randomUUID) return crypto.randomUUID();
        const values = crypto.getRandomValues(new Uint8Array(16));
        values[6] = (values[6] & 15) | 64;
        values[8] = (values[8] & 63) | 128;
        const hex = Array.from(values, value => value.toString(16).padStart(2, "0")).join("");
        return hex.slice(0,8) + "-" + hex.slice(8,12) + "-" + hex.slice(12,16) + "-" + hex.slice(16,20) + "-" + hex.slice(20);
    }
    function date(value) {
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? "" : new Intl.DateTimeFormat("en-PH", {
            dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Manila"
        }).format(parsed);
    }
    function size(value) {
        return value >= 1048576 ? (value / 1048576).toFixed(1) + " MB" : Math.max(1, Math.ceil(value / 1024)) + " KB";
    }
    function attachmentURL(id, preview = false) {
        const url = new URL("attachment.php", apiRoot);
        url.searchParams.set("id", id);
        if (preview) url.searchParams.set("preview", "1");
        return url.href;
    }
    async function api(path, {params = {}, body, signal} = {}) {
        const url = new URL(path, apiRoot);
        for (const [key, value] of Object.entries(params)) if (value !== "" && value != null) url.searchParams.set(key, value);
        const response = await fetch(url, {
            method: body ? "POST" : "GET", body, signal, cache: "no-store",
            credentials: "same-origin", headers: {Accept: "application/json"}
        });
        let data;
        try { data = await response.json(); } catch {
            throw new Error("The server could not read this request. Check the upload limits or try again.");
        }
        if (!response.ok || !data.ok) {
            const error = new Error(data.message || "Unable to complete this request.");
            error.status = response.status;
            error.data = data;
            throw error;
        }
        return data;
    }
    function fileCard(file, preview = true) {
        const card = element("div", "rm-file");
        if (preview && file.preview) {
            const link = element("a", "rm-photo-link");
            link.href = attachmentURL(file.id);
            link.download = file.name;
            const img = element("img", "rm-photo");
            img.src = attachmentURL(file.id, true);
            img.alt = file.name;
            img.loading = "lazy";
            link.append(img);
            card.append(link);
        }
        const link = element("a", "rm-file-link");
        link.href = attachmentURL(file.id);
        link.download = file.name;
        link.append(element("span", "rm-file-name", file.name), element("small", "", "Download · " + size(file.size)));
        card.append(link);
        return card;
    }
    function makePanel() {
        const panel = element("aside", "rm-panel");
        panel.hidden = true;
        panel.setAttribute("aria-label", "Request Messenger");
        // Constant UI markup only. Names, references, messages, and filenames use textContent.
        panel.innerHTML = '<header class="rm-header"><div><h2>Request Messenger</h2><strong class="rm-student"></strong><p class="rm-reference"></p></div><button type="button" class="rm-close" aria-label="Close Messenger">&times;</button></header>' +
            '<div class="rm-history"><button type="button" class="rm-older" hidden>Load earlier messages</button><div class="rm-messages" role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions"></div></div>' +
            '<p class="rm-notice" role="status" aria-live="polite"></p><form class="rm-compose"><div class="rm-selected" aria-label="Selected attachments"></div>' +
            '<label class="rm-input-label">Message<textarea class="rm-input" maxlength="5000" rows="2" placeholder="Write a message…"></textarea></label>' +
            '<div class="rm-compose-actions"><button type="button" class="rm-photo-button rm-icon-button" aria-label="Attach photos">' + photoIcon + '</button>' +
            '<button type="button" class="rm-file-button rm-icon-button" aria-label="Attach files">' + clipIcon + '</button><small>3 files · 5 MB each</small>' +
            '<button type="submit" class="rm-send">Send</button></div><input class="rm-photo-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>' +
            '<input class="rm-file-input" type="file" multiple hidden></form>';
        return panel;
    }

    function init(container, role) {
        const admin = role === "admin";
        const table = container.querySelector(admin ? ".request-table" : ".my-requests-table");
        if (!table) return null;
        const lifecycle = new AbortController();
        const listen = (target, name, callback) => target?.addEventListener(name, callback, {signal: lifecycle.signal});
        let disposed = false;
        let csrf = "";
        let listController = null, detailController = null, listVersion = 0, detailVersion = 0;
        let listTimer = null, pollTimer = null, chatController = null, sendController = null;
        let chatVersion = 0, chatRecord = null, selectedRequest = null, chatTrigger = null, detailsTrigger = null;
        let files = [], nonce = null, sending = false, refreshing = false;
        let statusBusy = false, statusNonce = null, statusSignature = "", statusNoticeTimer = null;
        let statusRevision = 0;
        let canForward = false, officeAvailability = {}, routingBusy = false, routingNonce = null, routingSignature = "", queueRefreshTimer = null;
        let lastId = 0, firstId = 0, hasOlder = false, initialLoaded = false;
        const seen = new Set();
        function requireRole(data) {
            if (data.role !== role) {
                const error = new Error("Your signed-in role changed. Reload the dashboard for your current account.");
                error.status = 401;
                throw error;
            }
            if (admin && typeof data.can_forward === "boolean") canForward = data.can_forward;
            if (data.office_availability) officeAvailability = data.office_availability;
        }
        let currentPage = 1, listPages = 1, total = 0, filtersReady = false;
        const modal = admin ? container.querySelector("#requestDetailsModal") : element("div", "rm-student-overlay");
        const panel = makePanel();
        panel.id = admin ? "adminRequestMessenger" : "studentRequestMessenger";
        if (admin) modal.querySelector(".rd-messenger").setAttribute("aria-controls", panel.id);
        if (admin) {
            const card = modal.querySelector(".rd-card");
            const shell = element("div", "rd-shell");
            shell.setAttribute("role", "dialog");
            shell.setAttribute("aria-modal", "true");
            shell.setAttribute("aria-labelledby", "rdTitle");
            card.removeAttribute("role");
            card.removeAttribute("aria-modal");
            card.removeAttribute("aria-labelledby");
            card.replaceWith(shell);
            shell.append(card, panel);
        } else {
            modal.hidden = true;
            panel.setAttribute("role", "dialog");
            panel.setAttribute("aria-modal", "true");
            modal.append(panel);
            container.append(modal);
        }
        const messages = panel.querySelector(".rm-messages");
        const history = panel.querySelector(".rm-history");
        const notice = panel.querySelector(".rm-notice");
        const older = panel.querySelector(".rm-older");
        const form = panel.querySelector(".rm-compose");
        const input = panel.querySelector(".rm-input");
        const sendButton = panel.querySelector(".rm-send");
        const selected = panel.querySelector(".rm-selected");
        const tbody = table.querySelector("tbody");
        const feedback = element("p", "request-feedback");
        feedback.setAttribute("role", "status");
        table.parentElement.before(feedback);
        const responseInput = admin ? modal.querySelector("#rdResponseFile") : null;
        const remarksInput = admin ? modal.querySelector("#rdRemarks") : null;
        const responseDropzone = responseInput?.closest(".rd-dropzone");
        const responseCaption = responseDropzone?.querySelector("strong");
        const defaultResponseCaption = responseCaption?.textContent;
        const clearResponse = admin ? element("button", "rm-response-clear", "×") : null;
        const statusNotice = admin ? element("div", "rm-action-notice") : null;
        if (admin) {
            clearResponse.type = "button";
            clearResponse.hidden = true;
            clearResponse.setAttribute("aria-label", "Remove selected response files");
            responseDropzone.append(clearResponse);
            statusNotice.hidden = true;
            statusNotice.setAttribute("aria-live", "polite");
            modal.append(statusNotice);
        }

        const routingWarning = admin ? element("p", "request-routing-warning") : null;
        const routingPanel = admin ? element("section", "rd-panel request-routing-panel") : null;
        const routingCurrent = admin ? element("p", "request-routing-current") : null;
        const routingHistory = admin ? element("ol", "request-routing-history") : null;
        const forwardForm = admin ? element("form", "request-routing-form") : null;
        const forwardTarget = admin ? element("select", "request-routing-select") : null;
        const forwardButton = admin ? element("button", "request-routing-forward", "Forward Request") : null;
        let routingRenderedKey = "";
        if (admin) {
            routingWarning.hidden = true;
            routingWarning.setAttribute("role", "alert");
            feedback.before(routingWarning);
            routingPanel.hidden = true;
            routingPanel.append(element("h3", "", "Office Assignment"), routingCurrent, routingHistory);
            const label = element("label", "", "Forward to office");
            forwardTarget.id = "requestRoutingOffice";
            label.htmlFor = forwardTarget.id;
            ["Admin", "Registrar", "OSA"].forEach(office => forwardTarget.add(new Option(office, office)));
            forwardButton.type = "submit";
            forwardForm.append(label, forwardTarget, forwardButton,
                element("small", "request-routing-help", "Forwarding keeps the current status, messages, and files."));
            modal.querySelector(".rd-response-panel").before(routingPanel);
            listen(forwardTarget, "change", () => { routingNonce = null; updateActionControls(); });
            listen(forwardForm, "submit", event => { event.preventDefault(); forwardRequest(); });
        }

        function showStatusNotice(message = "", kind = "info") {
            if (!admin) return;
            clearTimeout(statusNoticeTimer);
            statusNotice.textContent = message;
            statusNotice.dataset.kind = kind;
            statusNotice.setAttribute("role", kind === "error" ? "alert" : "status");
            statusNotice.hidden = !message;
            if (kind === "success") statusNoticeTimer = setTimeout(() => {
                statusNotice.hidden = true;
                statusNotice.textContent = "";
            }, 6500);
        }
        function updateActionControls() {
            if (!admin) return;
            const current = selectedRequest?.status;
            const final = current === "Approved" || current === "Rejected";
            modal.querySelectorAll(".rd-reject,.rd-processing,.rd-approve").forEach(button => {
                button.disabled = statusBusy || routingBusy || !current || final || (button.classList.contains("rd-processing") && current === "Processing");
                button.title = final ? "This request is already " + current.toLowerCase() + "." : "";
            });
            responseInput.disabled = remarksInput.disabled = clearResponse.disabled = statusBusy || routingBusy || !current || final;
            forwardTarget.disabled = statusBusy || routingBusy;
            forwardButton.disabled = !canForward || statusBusy || routingBusy || !selectedRequest || forwardTarget.value === selectedRequest.assigned_office;
            forwardButton.textContent = routingBusy ? "Forwarding..." : "Forward Request";
            responseDropzone.classList.toggle("is-disabled", responseInput.disabled);
            table.querySelectorAll(".request-eye-btn").forEach(button => { button.disabled = statusBusy || routingBusy; });
        }
        function syncRequestStatus(record) {
            const row = table.querySelector('tr[data-request-id="' + record.id + '"]');
            const badge = row?.querySelector(admin ? ".request-status-badge" : ".request-status");
            if (badge) {
                badge.textContent = record.status;
                badge.className = admin ? "request-status-badge badge-" + record.status.toLowerCase() : "request-status " + record.status.toLowerCase();
            }
            if (chatRecord?.id === record.id) chatRecord = {...chatRecord, ...record};
            if (admin && selectedRequest?.id === record.id) {
                selectedRequest = {...selectedRequest, ...record};
                const modalBadge = modal.querySelector(".rd-status");
                modalBadge.textContent = record.status;
                modalBadge.dataset.status = record.status.toLowerCase();
                modal.querySelector('[data-request-field="assigned_office"]').textContent = selectedRequest.assigned_office;
                renderRouting(selectedRequest);
                updateActionControls();
            }
        }
        function responseFilesValid(values) {
            if (values.length > 3 || values.some(file => file.size < 1 || file.size > 5 * 1024 * 1024)) {
                showStatusNotice("Choose up to 3 nonempty response files, at most 5 MB each.", "error");
                return false;
            }
            if (values.some(file => !/\.(pdf|jpe?g|png)$/i.test(file.name))) {
                showStatusNotice("Response files must be PDF, JPG, or PNG.", "error");
                return false;
            }
            return true;
        }
        function updateResponseSelection() {
            if (!admin) return;
            const values = Array.from(responseInput.files);
            if (!responseFilesValid(values)) responseInput.value = "";
            const count = responseInput.files.length;
            responseCaption.textContent = count ? count + (count === 1 ? " file selected" : " files selected") : defaultResponseCaption;
            responseDropzone.title = Array.from(responseInput.files, file => file.name).join(", ");
            clearResponse.hidden = count === 0;
            statusNonce = null;
            statusSignature = "";
        }
        async function saveRequestAction(action) {
            if (!admin || !selectedRequest || statusBusy || routingBusy) return;
            const record = selectedRequest;
            const version = detailVersion;
            const responseFiles = Array.from(responseInput.files);
            if (!responseFilesValid(responseFiles)) return;
            if (action !== "approve" && responseFiles.length) {
                showStatusNotice("Use Approve & Send for the selected files, or press × in the upload area to remove them before changing the status.", "error");
                return;
            }
            const remarks = remarksInput.value.trim();
            const signature = JSON.stringify([record.id, record.status, action, remarks,
                responseFiles.map(file => [file.name, file.size, file.lastModified, file.type])]);
            if (!statusNonce || statusSignature !== signature) {
                statusNonce = token();
                statusSignature = signature;
            }
            const body = new FormData();
            body.set("request_id", record.id);
            body.set("action", action);
            body.set("expected_status", record.status);
            body.set("assignment_revision", record.assignment_revision);
            body.set("remarks", remarks);
            body.set("csrf", csrf);
            body.set("client_token", statusNonce);
            responseFiles.forEach(file => body.append("response_files[]", file));
            statusBusy = true;
            ++statusRevision;
            updateActionControls();
            showStatusNotice("Saving request response…");
            try {
                const data = await api("../admin/requests.php", {body, signal: lifecycle.signal});
                if (disposed) return;
                requireRole(data);
                syncRequestStatus(data.request);
                if (version === detailVersion && selectedRequest?.id === record.id) {
                    remarksInput.value = "";
                    modal.querySelector(".rd-char-count").textContent = "0/500";
                    responseInput.value = "";
                    updateResponseSelection();
                }
                const refreshed = await loadList(currentPage);
                if (disposed) return;
                if (version === detailVersion && selectedRequest?.id === record.id) {
                    showStatusNotice(data.message + (refreshed ? "" : " Refresh the request list to see the latest totals."), "success");
                }
                if (chatRecord?.id === record.id) refreshChat();
            } catch (error) {
                if (disposed || error.name === "AbortError") return;
                if (loseRequestAccess(error)) { await loadList(currentPage); return; }
                if (error.data?.role === "admin" && error.data.request) {
                    syncRequestStatus(error.data.request);
                    await loadList(currentPage);
                }
                if (!disposed && version === detailVersion && selectedRequest?.id === record.id) showStatusNotice(error.message, "error");
            } finally {
                statusBusy = false;
                ++statusRevision;
                if (!disposed) updateActionControls();
            }
        }

        function renderRouting(record) {
            if (!admin) return;
            const historyRows = record.assignment_history || [];
            routingPanel.hidden = !canForward && historyRows.length === 0;
            routingCurrent.textContent = "Current office: " + record.assigned_office;
            if (canForward) {
                if (!forwardForm.isConnected) routingHistory.before(forwardForm);
                const key = record.id + ":" + record.assignment_revision;
                if (key !== routingRenderedKey) {
                    forwardTarget.value = record.assigned_office;
                    routingRenderedKey = key;
                    routingNonce = null;
                }
                [...forwardTarget.options].forEach(option => {
                    option.textContent = option.value + (officeAvailability[option.value] === 0 ? " (no active account)" : "");
                });
            } else forwardForm.remove();
            routingHistory.replaceChildren();
            routingHistory.hidden = historyRows.length === 0;
            historyRows.forEach(entry => routingHistory.append(element("li", "",
                entry.previous_office + " \u2192 " + entry.new_office + " \u00b7 " + entry.reassigned_by + " \u00b7 " + date(entry.created_at))));
        }
        function loseRequestAccess(error) {
            if (!admin || ![401, 403, 404].includes(error.status)) return false;
            closeDetails();
            messages.replaceChildren();
            modal.querySelectorAll("[data-request-field]").forEach(node => { node.textContent = "\u2014"; });
            modal.querySelector("[data-request-attachments]").replaceChildren();
            routingHistory.replaceChildren();
            routingPanel.hidden = true;
            feedback.textContent = error.status === 404 && !canForward
                ? "This request is no longer assigned to your office. Refreshing the list." : error.message;
            return true;
        }
        async function forwardRequest() {
            if (!canForward || !selectedRequest || routingBusy || statusBusy) return;
            const record = selectedRequest, version = detailVersion;
            if (forwardTarget.value === record.assigned_office) return;
            const signature = JSON.stringify([record.id, record.assignment_revision, forwardTarget.value]);
            if (!routingNonce || signature !== routingSignature) { routingNonce = token(); routingSignature = signature; }
            const body = new FormData();
            body.set("csrf", csrf);
            body.set("request_id", record.id);
            body.set("assigned_office", forwardTarget.value);
            body.set("expected_office", record.assigned_office);
            body.set("assignment_revision", record.assignment_revision);
            body.set("client_token", routingNonce);
            routingBusy = true;
            ++statusRevision;
            updateActionControls();
            showStatusNotice("Forwarding request...");
            try {
                const data = await api("../admin/forward-request.php", {body, signal: lifecycle.signal});
                if (disposed) return;
                requireRole(data);
                syncRequestStatus(data.request);
                await loadList(currentPage);
                if (version === detailVersion && selectedRequest?.id === record.id) {
                    showStatusNotice(data.message + (data.routing_warning ? " " + data.routing_warning : ""), data.routing_warning ? "info" : "success");
                }
            } catch (error) {
                if (disposed || error.name === "AbortError") return;
                if (error.data?.request) syncRequestStatus(error.data.request);
                if (loseRequestAccess(error)) await loadList(currentPage);
                else if (version === detailVersion) showStatusNotice(error.message, "error");
            } finally {
                routingBusy = false;
                ++statusRevision;
                if (!disposed) updateActionControls();
            }
        }
        async function refreshRequestQueues() {
            try {
                if (!document.hidden && !statusBusy && !routingBusy && !sending) {
                    await loadList(currentPage);
                    if (admin && selectedRequest && !disposed) {
                        const id = selectedRequest.id, version = detailVersion, revision = statusRevision;
                        try {
                            const data = await api("records.php", {params: {id}, signal: lifecycle.signal});
                            if (!disposed && version === detailVersion && revision === statusRevision && !statusBusy && !routingBusy) {
                                requireRole(data);
                                syncRequestStatus(data.request);
                            }
                        } catch (error) {
                            if (!disposed && version === detailVersion && error.name !== "AbortError") loseRequestAccess(error);
                        }
                    }
                }
            } finally {
                if (!disposed) queueRefreshTimer = setTimeout(refreshRequestQueues, 15000);
            }
        }

        function clearFiles() {
            files.forEach(file => { if (file.url) URL.revokeObjectURL(file.url); });
            files = [];
            selected.replaceChildren();
        }
        function closeChat(focus = true) {
            ++chatVersion;
            clearTimeout(pollTimer);
            chatController?.abort();
            sendController?.abort();
            panel.hidden = true;
            modal.classList.remove("has-messenger");
            if (admin) modal.querySelector(".rd-messenger").setAttribute("aria-expanded", "false");
            if (!admin) modal.hidden = true;
            chatRecord = null;
            refreshing = false;
            sending = false;
            clearFiles();
            input.value = "";
            nonce = null;
            if (focus && chatTrigger?.isConnected) chatTrigger.focus();
        }
        function closeDetails() {
            ++detailVersion;
            detailController?.abort();
            closeChat(false);
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
            selectedRequest = null;
            showStatusNotice();
            updateActionControls();
            if (detailsTrigger?.isConnected) detailsTrigger.focus();
        }
        function updateBusy() {
            form.querySelectorAll("button,input,textarea").forEach(control => { control.disabled = sending; });
            sendButton.textContent = sending ? "Sending…" : "Send";
        }
        function renderSelected() {
            selected.replaceChildren();
            files.forEach((entry, index) => {
                const item = element("div", "rm-selected-file");
                if (entry.url) {
                    const img = element("img");
                    img.src = entry.url;
                    img.alt = "";
                    item.append(img);
                }
                item.append(element("span", "", entry.file.name + " · " + size(entry.file.size)));
                const remove = element("button", "", "×");
                remove.type = "button";
                remove.setAttribute("aria-label", "Remove " + entry.file.name);
                remove.disabled = sending;
                remove.addEventListener("click", () => {
                    if (entry.url) URL.revokeObjectURL(entry.url);
                    files.splice(index, 1);
                    nonce = null;
                    renderSelected();
                });
                item.append(remove);
                selected.append(item);
            });
        }
        function pickFiles(fileInput) {
            const additions = Array.from(fileInput.files);
            fileInput.value = "";
            if (files.length + additions.length > 3) {
                notice.textContent = "Attach at most 3 files per message.";
                return;
            }
            if (additions.some(file => file.size < 1 || file.size > 5 * 1024 * 1024)) {
                notice.textContent = "Each file must be nonempty and at most 5 MB.";
                return;
            }
            additions.forEach(file => files.push({file, url: imageTypes.has(file.type) ? URL.createObjectURL(file) : null}));
            nonce = null;
            notice.textContent = "";
            renderSelected();
        }
        listen(panel.querySelector(".rm-photo-button"), "click", () => panel.querySelector(".rm-photo-input").click());
        listen(panel.querySelector(".rm-file-button"), "click", () => panel.querySelector(".rm-file-input").click());
        listen(panel.querySelector(".rm-photo-input"), "change", event => pickFiles(event.target));
        listen(panel.querySelector(".rm-file-input"), "change", event => pickFiles(event.target));
        listen(input, "input", () => { nonce = null; });
        listen(panel.querySelector(".rm-close"), "click", () => closeChat());
        listen(modal, "click", event => {
            if (event.target === modal || (admin && event.target.classList.contains("rd-shell"))) admin ? closeDetails() : closeChat();
        });
        listen(modal, "keydown", event => {
            if (event.key === "Escape") {
                event.preventDefault();
                if (!panel.hidden) closeChat(); else if (admin) closeDetails();
                return;
            }
            if (event.key !== "Tab") return;
            const controls = Array.from(modal.querySelectorAll("button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled]),a[href]"))
                .filter(node => node.getClientRects().length > 0);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });

        function renderMessages(rows, prepend = false) {
            messages.querySelector(".rm-empty")?.remove();
            const fragment = document.createDocumentFragment();
            rows.forEach(message => {
                if (seen.has(message.id)) return;
                seen.add(message.id);
                lastId = Math.max(lastId, message.id);
                firstId = firstId ? Math.min(firstId, message.id) : message.id;
                const bubble = element("article", "rm-message" + (message.sender_role === role ? " rm-mine" : ""));
                bubble.append(element("strong", "rm-sender", message.sender_name));
                if (message.body) bubble.append(element("p", "rm-body", message.body));
                message.attachments.forEach(file => bubble.append(fileCard(file)));
                const timestamp = element("time", "rm-time", date(message.created_at));
                timestamp.dateTime = message.created_at;
                bubble.append(timestamp);
                fragment.append(bubble);
            });
            if (prepend) messages.prepend(fragment); else messages.append(fragment);
            if (!seen.size) messages.append(element("p", "rm-empty", "No messages yet. Start the conversation."));
        }
        async function refreshChat() {
            if (refreshing || !chatRecord || panel.hidden || disposed) return;
            refreshing = true;
            const version = chatVersion;
            const requestId = chatRecord.id;
            chatController = new AbortController();
            clearTimeout(pollTimer);
            let retry = true;
            const nearBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 80;
            try {
                let more;
                do {
                    const params = {request_id: requestId};
                    if (lastId) params.after = lastId;
                    const statusReadRevision = statusRevision;
                    const data = await api("messages.php", {params, signal: chatController.signal});
                    if (disposed || version !== chatVersion) return;
                    requireRole(data);
                    if (statusReadRevision === statusRevision && !statusBusy) syncRequestStatus(data.request);
                    csrf = data.csrf;
                    if (!initialLoaded) {
                        hasOlder = data.has_more;
                        older.hidden = !hasOlder;
                    }
                    renderMessages(data.messages);
                    more = initialLoaded && data.has_more && data.messages.length > 0;
                    initialLoaded = true;
                } while (more);
                notice.textContent = "";
                if (lastId && !document.hidden) window.KNSNotifications?.readConversation?.(requestId, lastId);
                if (nearBottom) history.scrollTop = history.scrollHeight;
            } catch (error) {
                if (version !== chatVersion || error.name === "AbortError") return;
                notice.textContent = error.message;
                if ([401, 403, 404].includes(error.status)) {
                    retry = false;
                    if (loseRequestAccess(error)) loadList(currentPage);
                }
            } finally {
                if (version === chatVersion && !disposed && chatRecord) {
                    refreshing = false;
                    if (retry) pollTimer = setTimeout(refreshChat, 4000);
                }
            }
        }
        function openChat(record, trigger) {
            closeChat(false);
            chatRecord = record;
            chatTrigger = trigger;
            seen.clear();
            lastId = firstId = 0;
            hasOlder = initialLoaded = false;
            older.hidden = true;
            messages.replaceChildren();
            notice.textContent = "Loading conversation…";
            panel.querySelector(".rm-student").textContent = record.student_name;
            panel.querySelector(".rm-reference").textContent = record.reference_no + " · " + record.student_number;
            panel.dataset.requestId = record.id;
            panel.dataset.studentId = record.student_id;
            panel.hidden = false;
            modal.classList.add("has-messenger");
            if (admin) modal.querySelector(".rd-messenger").setAttribute("aria-expanded", "true");
            if (!admin) modal.hidden = false;
            updateBusy();
            input.focus();
            refreshChat();
        }
        listen(older, "click", async () => {
            if (!chatRecord || !firstId) return;
            const version = chatVersion;
            older.disabled = true;
            const height = history.scrollHeight;
            const position = history.scrollTop;
            try {
                const data = await api("messages.php", {params: {request_id: chatRecord.id, before: firstId}, signal: lifecycle.signal});
                if (version !== chatVersion || disposed) return;
                requireRole(data);
                renderMessages(data.messages, true);
                hasOlder = data.has_more;
                older.hidden = !hasOlder;
                history.scrollTop = position + history.scrollHeight - height;
            } catch (error) {
                if (version === chatVersion && error.name !== "AbortError") notice.textContent = error.message;
            } finally {
                older.disabled = false;
            }
        });
        listen(form, "submit", async event => {
            event.preventDefault();
            if (sending || !chatRecord) return;
            const body = input.value.trim();
            if (!body && !files.length) { notice.textContent = "Write a message or attach a file."; return; }
            const version = chatVersion;
            const data = new FormData();
            data.set("request_id", chatRecord.id);
            data.set("csrf", csrf);
            data.set("body", body);
            nonce ||= token();
            data.set("client_token", nonce);
            files.forEach(entry => data.append("attachments[]", entry.file));
            sending = true;
            updateBusy();
            sendController = new AbortController();
            notice.textContent = "Sending…";
            try {
                await api("messages.php", {body: data, signal: sendController.signal});
                if (version !== chatVersion || disposed) return;
                input.value = "";
                clearFiles();
                nonce = null;
                notice.textContent = "";
                await refreshChat();
                history.scrollTop = history.scrollHeight;
            } catch (error) {
                if (version === chatVersion && error.name !== "AbortError") {
                    notice.textContent = error.message;
                    if (loseRequestAccess(error)) await loadList(currentPage);
                }
            } finally {
                if (version === chatVersion) { sending = false; updateBusy(); input.focus(); }
            }
        });

        function fillDetails(record) {
            const values = {...record, year_level: record.year_level ? (["", "1st", "2nd", "3rd", "4th", "5th", "6th"][record.year_level] || String(record.year_level)) + " Year" : "—",
                created_at: date(record.created_at)};
            modal.querySelectorAll("[data-request-field]").forEach(node => {
                node.textContent = values[node.dataset.requestField] || "—";
            });
            const badge = modal.querySelector(".rd-status");
            badge.dataset.status = record.status.toLowerCase();
            const attachments = modal.querySelector("[data-request-attachments]");
            attachments.replaceChildren();
            if (!record.attachments.length) {
                // Keep the existing attachment row and layout, showing the real empty state.
                const row = element("div", "rd-attachment");
                row.append(element("div", "rd-file-preview", "▤"));
                const info = element("div", "rd-file-info");
                info.append(element("strong", "", "No supporting attachments"));
                row.append(info);
                attachments.append(row);
            }
            record.attachments.forEach(file => {
                const row = element("div", "rd-attachment");
                row.append(element("div", "rd-file-preview", "▤"));
                const info = element("div", "rd-file-info");
                info.append(element("strong", "", file.name), element("span", "", date(file.created_at) + " · " + size(file.size)));
                const link = element("a", "rd-view-button", "View");
                link.href = attachmentURL(file.id);
                row.append(info, link);
                attachments.append(row);
            });
            modal.querySelector("#rdRemarks").value = "";
            modal.querySelector("#rdResponseFile").value = "";
            modal.querySelector(".rd-char-count").textContent = "0/500";
            updateResponseSelection();
            renderRouting(record);
            updateActionControls();
        }
        async function openDetails(id, trigger) {
            const version = ++detailVersion;
            detailController?.abort();
            detailController = new AbortController();
            closeChat(false);
            selectedRequest = null;
            showStatusNotice();
            updateActionControls();
            feedback.textContent = "Loading request details…";
            try {
                const data = await api("records.php", {params: {id}, signal: detailController.signal});
                if (disposed || version !== detailVersion) return false;
                requireRole(data);
                selectedRequest = data.request;
                csrf = data.csrf;
                fillDetails(selectedRequest);
                detailsTrigger = trigger;
                modal.classList.add("is-open");
                modal.setAttribute("aria-hidden", "false");
                modal.querySelector(".rd-messenger").setAttribute("aria-expanded", "false");
                modal.querySelector(".rd-close").focus();
                feedback.textContent = "";
                window.KNSNotifications?.readRequest?.(selectedRequest.id);
                return true;
            } catch (error) {
                if (version === detailVersion && error.name !== "AbortError") feedback.textContent = error.message;
                return false;
            }
        }
        if (admin) {
            listen(document, "visibilitychange", () => {
                if (!document.hidden && selectedRequest && modal.classList.contains("is-open")) {
                    window.KNSNotifications?.readRequest?.(selectedRequest.id);
                }
            });
            listen(modal.querySelector(".rd-close"), "click", closeDetails);
            listen(modal.querySelector(".rd-messenger"), "click", event => {
                if (selectedRequest) openChat(selectedRequest, event.currentTarget);
            });
            listen(remarksInput, "input", event => {
                modal.querySelector(".rd-char-count").textContent = event.target.value.length + "/500";
                statusNonce = null;
                statusSignature = "";
            });
            listen(modal.querySelector(".rd-reject"), "click", () => saveRequestAction("reject"));
            listen(modal.querySelector(".rd-processing"), "click", () => saveRequestAction("processing"));
            listen(modal.querySelector(".rd-approve"), "click", () => saveRequestAction("approve"));
            listen(responseInput, "change", updateResponseSelection);
            listen(clearResponse, "click", event => {
                event.preventDefault();
                event.stopPropagation();
                responseInput.value = "";
                updateResponseSelection();
                showStatusNotice();
            });
            listen(responseDropzone, "dragover", event => {
                event.preventDefault();
                if (!responseInput.disabled) responseDropzone.classList.add("is-dragging");
            });
            listen(responseDropzone, "dragleave", () => responseDropzone.classList.remove("is-dragging"));
            listen(responseDropzone, "drop", event => {
                event.preventDefault();
                responseDropzone.classList.remove("is-dragging");
                if (responseInput.disabled || !event.dataTransfer?.files.length) return;
                if (!responseFilesValid(Array.from(event.dataTransfer.files))) return;
                responseInput.files = event.dataTransfer.files;
                updateResponseSelection();
            });
            updateActionControls();
        }

        const search = container.querySelector(admin ? ".request-header-search input" : ".requests-search");
        const statusFilter = container.querySelector(admin ? "#request-status" : ".requests-status-filter");
        const entries = container.querySelector(admin ? ".request-entries select" : ".requests-entries select");
        const pagination = container.querySelector(admin ? ".request-pagination" : ".requests-pagination");
        const filterNames = {"#request-year": "academic_year", "#request-semester": "semester", "#request-type": "request_type"};
        let appliedFilters = {};
        function filterValue(select) { return select && select.selectedIndex > 0 ? select.value : ""; }
        function renderPagination() {
            pagination.replaceChildren();
            const candidates = [1, currentPage - 1, currentPage, currentPage + 1, listPages];
            const pageNumbers = [...new Set(candidates.filter(value => value >= 1 && value <= listPages))].sort((a,b) => a-b);
            function add(label, page, disabled, active = false) {
                const button = element("button", active ? (admin ? "page-active" : "current-page") : "", label);
                button.type = "button";
                button.dataset.requestPage = page;
                button.disabled = disabled;
                button.setAttribute("aria-label", label === "‹" ? "Previous page" : label === "›" ? "Next page" : "Page " + page);
                if (active) button.setAttribute("aria-current", "page");
                pagination.append(button);
            }
            add("‹", currentPage - 1, currentPage <= 1);
            pageNumbers.forEach(page => add(String(page), page, false, page === currentPage));
            add("›", currentPage + 1, currentPage >= listPages);
        }
        function renderList(data) {
            if (admin) {
                routingWarning.textContent = (data.routing_warnings || []).join(" ");
                routingWarning.hidden = !routingWarning.textContent;
            }
            tbody.replaceChildren();
            data.requests.forEach((record, index) => {
                const row = element("tr");
                row.dataset.requestId = record.id;
                row.dataset.studentId = record.student_id;
                if (admin) {
                    const cell = element("td"), check = element("input");
                    check.type = "checkbox";
                    check.setAttribute("aria-label", "Select " + record.reference_no);
                    cell.append(check);
                    row.append(cell);
                }
                const values = [(data.page - 1) * data.per_page + index + 1, record.reference_no];
                if (admin) values.push(record.student_name);
                values.push(record.request_type, date(record.created_at));
                values.forEach((value, index) => {
                    const cell = element("td", "", String(value));
                    if (admin && index < 3) cell.setAttribute("data-staff-content","");
                    if (admin && index === 3) cell.append(element("small", "request-routing-office", record.assigned_office));
                    row.append(cell);
                });
                const status = element("td");
                status.append(element("span", admin ? "request-status-badge badge-" + record.status.toLowerCase() : "request-status " + record.status.toLowerCase(), record.status));
                row.append(status);
                const action = element("td");
                const button = element("button", admin ? "request-eye-btn" : "request-action view");
                button.type = "button";
                button.dataset.requestId = record.id;
                button.setAttribute("aria-haspopup", "dialog");
                button.setAttribute("aria-label", (admin ? "View request " : "Message about ") + record.reference_no);
                if (admin) {
                    button.disabled = statusBusy || routingBusy;
                    button.setAttribute("aria-controls", modal.id);
                    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
                } else {
                    button.innerHTML = chatIcon;
                    button.append(document.createTextNode(" Message"));
                    button.classList.add("rm-row-button");
                }
                button.addEventListener("click", () => admin ? openDetails(record.id, button) : openChat(record, button));
                action.append(button);
                row.append(action);
                tbody.append(row);
            });
            if (!data.requests.length) {
                const row = element("tr"), cell = element("td", "", "No requests found.");
                cell.colSpan = admin ? 8 : 6;
                row.append(cell);
                tbody.append(row);
            }
            currentPage = data.page; listPages = data.pages; total = data.total;
            renderPagination();
            feedback.textContent = total + (total === 1 ? " request" : " requests");
            if (admin) {
                for (const key of ["Total", "Pending", "Processing", "Approved", "Rejected"]) {
                    container.querySelector(".summary-" + key.toLowerCase() + " strong").textContent = data.summary[key];
                }
                const year = container.querySelector("#request-year");
                const previous = year.value;
                year.replaceChildren(new Option("All Academic Years", ""));
                data.academic_years.forEach(value => year.add(new Option(value, value)));
                year.value = previous;
                if (!year.value) year.selectedIndex = 0;
                if (!filtersReady) {
                    const types = container.querySelector("#request-type");
                    types.replaceChildren(new Option("All Types", ""));
                    data.request_types.forEach(value => types.add(new Option(value, value)));
                }
            } else if (!filtersReady) {
                initSubmission(data);
            }
            filtersReady = true;
        }
        async function loadList(page = 1) {
            const version = ++listVersion;
            listController?.abort();
            listController = new AbortController();
            feedback.textContent = "Loading requests…";
            table.setAttribute("aria-busy", "true");
            try {
                const params = {...appliedFilters, q: search.value.trim().slice(0,200), page, per_page: entries.value};
                if (!admin) params.status = filterValue(statusFilter);
                const data = await api("records.php", {params, signal: listController.signal});
                if (disposed || version !== listVersion) return;
                requireRole(data);
                csrf = data.csrf;
                renderList(data);
                return true;
            } catch (error) {
                if (version === listVersion && error.name !== "AbortError") feedback.textContent = error.message;
                return false;
            } finally {
                if (version === listVersion) table.setAttribute("aria-busy", "false");
            }
        }
        function scheduleList() {
            clearTimeout(listTimer);
            listController?.abort();
            ++listVersion;
            listTimer = setTimeout(() => loadList(), 300);
        }
        listen(search, "input", scheduleList);
        listen(search, "keydown", event => {
            if (event.key === "Enter") { event.preventDefault(); clearTimeout(listTimer); loadList(); }
        });
        listen(entries, "change", () => loadList());
        listen(pagination, "click", event => {
            const button = event.target.closest("button[data-request-page]");
            if (button && !button.disabled) loadList(Number(button.dataset.requestPage));
        });
        if (admin) {
            listen(container.querySelector(".request-apply-btn"), "click", () => {
                appliedFilters = {status: filterValue(statusFilter)};
                for (const [selector, name] of Object.entries(filterNames)) appliedFilters[name] = filterValue(container.querySelector(selector));
                loadList();
            });
            listen(container.querySelector(".request-reset-btn"), "click", () => {
                appliedFilters = {};
                search.value = "";
                [statusFilter, ...Object.keys(filterNames).map(selector => container.querySelector(selector))].forEach(select => { select.selectedIndex = 0; });
                loadList();
            });
            listen(container.querySelector(".request-header-search button"), "click", () => loadList());
        } else listen(statusFilter, "change", () => loadList());

        function initSubmission(data) {
            const submission = container.querySelector("#studentRequestForm");
            if (!submission) return;
            submission.querySelector(".submitButton").disabled = false;
            let submissionNonce = token(), submitting = false;
            const message = submission.querySelector("[data-submission-feedback]");
            const year = submission.elements.namedItem("academic_year");
            const semester = submission.elements.namedItem("semester");
            const currentYear = Number(new Intl.DateTimeFormat("en", {year: "numeric", timeZone: "Asia/Manila"}).format(new Date()));
            const years = new Set(data.academic_years);
            if (data.profile?.academic_year) years.add(data.profile.academic_year.replace(/\s*-\s*/, " - "));
            for (let start = currentYear - 2; start <= currentYear + 1; start++) years.add(start + " - " + (start + 1));
            year.replaceChildren(new Option("Choose academic year", ""));
            [...years].sort().reverse().forEach(value => year.add(new Option(value, value)));
            const profileYear = data.profile?.academic_year?.replace(/\s*-\s*/, " - ");
            if (profileYear) year.value = profileYear;
            if (data.profile?.semester) semester.value = data.profile.semester;
            const choices = Array.from(submission.querySelectorAll(".file-request-selection"));
            const typeField = submission.elements.namedItem("request_type");
            choices.forEach((choice, index) => {
                choice.setAttribute("role", "button");
                choice.tabIndex = 0;
                choice.setAttribute("aria-pressed", "false");
                const select = () => {
                    submissionNonce = token();
                    typeField.value = data.request_types[index];
                    choices.forEach(item => {
                        item.classList.toggle("is-selected", item === choice);
                        item.setAttribute("aria-pressed", String(item === choice));
                    });
                };
                listen(choice, "click", select);
                listen(choice, "keydown", event => {
                    if (event.key === "Enter" || event.key === " ") { event.preventDefault(); select(); }
                });
            });
            const attachments = submission.querySelector("#fileInput");
            listen(attachments, "change", () => {
                const values = Array.from(attachments.files);
                submission.querySelector("#fileList").textContent = values.map(file => file.name + " · " + size(file.size)).join(", ");
            });
            listen(submission, "input", () => { submissionNonce = token(); });
            listen(submission, "change", () => { submissionNonce = token(); });
            listen(submission, "reset", () => {
                submissionNonce = token();
                choices.forEach(choice => { choice.classList.remove("is-selected"); choice.setAttribute("aria-pressed", "false"); });
                typeField.value = "";
                submission.querySelector("#fileList").textContent = "";
                message.textContent = "";
            });
            listen(submission, "submit", async event => {
                event.preventDefault();
                if (submitting) return;
                if (!typeField.value) { message.textContent = "Choose a request type."; choices[0]?.focus(); return; }
                const values = Array.from(attachments.files);
                if (values.length > 3 || values.some(file => file.size < 1 || file.size > 5 * 1024 * 1024)) {
                    message.textContent = "Attach up to 3 nonempty files, at most 5 MB each.";
                    return;
                }
                const body = new FormData(submission);
                body.set("csrf", csrf);
                body.set("submission_token", submissionNonce);
                const controls = Array.from(submission.querySelectorAll("button,input,textarea,select"));
                submitting = true;
                controls.forEach(control => { control.disabled = true; });
                choices.forEach(choice => { choice.inert = true; });
                message.textContent = "Submitting request…";
                try {
                    const result = await api("create-request.php", {body, signal: lifecycle.signal});
                    if (disposed) return;
                    submission.reset();
                    message.textContent = "Request submitted: " + result.request.reference_no + ". Assigned to " + result.request.assigned_office + ". Open Message in My Requests to contact your assigned office.";
                    await loadList();
                } catch (error) {
                    if (!disposed && error.name !== "AbortError") message.textContent = error.message;
                } finally {
                    submitting = false;
                    controls.forEach(control => { control.disabled = false; });
                    choices.forEach(choice => { choice.inert = false; });
                }
            });
        }

        listen(container.querySelector("#studentRequestForm"), "submit", event => event.preventDefault());
        loadList();
        queueRefreshTimer = setTimeout(refreshRequestQueues, 15000);
        const cleanup = () => {
            disposed = true;
            closeChat(false);
            clearTimeout(listTimer);
            clearTimeout(statusNoticeTimer);
            clearTimeout(queueRefreshTimer);
            listController?.abort();
            detailController?.abort();
            lifecycle.abort();
        };
        cleanup.openRequest = async (requestId, {messenger = false} = {}) => {
            if (!admin || disposed) return false;
            const trigger = container.querySelector('button[data-request-id="' + Number(requestId) + '"]')
                || document.getElementById("adminNotificationsBell");
            const opened = await openDetails(requestId, trigger);
            if (!opened || disposed || selectedRequest?.id !== Number(requestId)) return false;
            if (messenger) openChat(selectedRequest, modal.querySelector(".rd-messenger"));
            return true;
        };
        cleanup.openConversation = async requestId => {
            if (admin || disposed) return false;
            const data = await api("records.php", {params: {id: requestId}, signal: lifecycle.signal});
            if (disposed) return false;
            requireRole(data);
            csrf = data.csrf;
            const trigger = container.querySelector('button[data-request-id="' + data.request.id + '"]')
                || document.getElementById("studentNotificationsBell");
            openChat(data.request, trigger);
            return true;
        };
        return cleanup;
    }
    window.KNSRequests = {init};
})();
