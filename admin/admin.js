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
        initializers: [initStudentUpload, initGradeUpload, initStudentList],
    },
    "requests.html": {
        navigation: ".may-request-button",
        initializers: [initRequestDetails],
    },
    "profile.php": {
        navigation: ".student-information-button",
        initializers: [initAdminProfile, initAdminPhoto],
    },
};

let latestPageRequest = 0;
let mediaPreviewURL = null;
let dashboardInitialized = false;
let requestMessengerCleanup = null;

// Page loading and navigation.
// Keep loadPage global because the sidebar HTML calls it through onclick.
async function loadPage(page, context = {}) {
    const container = document.getElementById("main-content-contianer");
    const config = Object.hasOwn(ADMIN_PAGES, page) ? ADMIN_PAGES[page] : null;

    if (!container || !config) {
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

        clearMediaPreviewURL();
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

// Overview: announcement categories and expandable descriptions.
function initAnnouncements(container) {

    container.querySelectorAll(".see-more-btn").forEach(button => {
        button.onclick = () => {
            const text = button.parentElement.querySelector(".description-text");
            if (!text) return;

            const expanded = text.classList.toggle("expanded");
            button.textContent = expanded ? "See less" : "See more";
        };
    });
}

// Overview: image/video preview and temporary URL cleanup.
function clearMediaPreviewURL() {
    if (!mediaPreviewURL) return;

    URL.revokeObjectURL(mediaPreviewURL);
    mediaPreviewURL = null;
}

function initMediaPreview(container) {
    const input = container.querySelector("#mediaInput");
    const preview = container.querySelector("#mediaPreview");
    if (!input || !preview) return;

    input.onchange = () => {
        preview.replaceChildren();
        clearMediaPreviewURL();

        const file = input.files[0];
        if (!file) return;

        let media;
        if (file.type.startsWith("image/")) {
            media = document.createElement("img");
            media.alt = "Selected image preview";
        } else if (file.type.startsWith("video/")) {
            media = document.createElement("video");
            media.controls = true;
        } else {
            preview.textContent = "Please select an image or video.";
            return;
        }

        mediaPreviewURL = URL.createObjectURL(file);
        media.src = mediaPreviewURL;
        preview.appendChild(media);
    };
}

// The profile fragment is inserted with innerHTML, so bind its controls after loading it.
function initAdminPhoto(container) {
    const form = container.querySelector("#admin-photo-form");
    if (!form) return;
    const input = form.querySelector("[name='profile_photo']");
    const button = form.querySelector("[data-choose-photo]");
    const label = button.querySelector("[data-photo-label]");
    const message = container.querySelector("#admin-photo-message");
    const image = button.querySelector("[data-admin-photo]");
    const buttonLabel = button.getAttribute("aria-label");
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
        if (file.size > 2 * 1024 * 1024) {
            message.textContent = "Choose a photo at most 2 MB in size.";
            message.dataset.state = "error";
            input.value = "";
            return;
        }
        const body = new FormData(form);
        uploading = true;
        button.disabled = true;
        input.disabled = true;
        form.setAttribute("aria-busy", "true");
        button.setAttribute("aria-label", "Uploading your profile photo");
        label.textContent = "Uploading...";
        message.textContent = "Saving your profile photo...";
        message.dataset.state = "pending";
        try {
            const response = await fetch(form.action, {
                method: "POST",
                credentials: "same-origin",
                headers: { Accept: "application/json" },
                body,
            });
            if (response.status === 401) {
                window.location.assign("../login.php");
                return;
            }
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.message || "Unable to save your photo.");
            }
            image.src = "../" + data.photo_path;
            message.textContent = data.message;
            message.dataset.state = "success";
        } catch (error) {
            message.textContent = error.message || "Unable to upload your photo. Please try again.";
            message.dataset.state = "error";
        } finally {
            input.value = ""; // Selecting the same file again must still trigger a change.
            uploading = false;
            button.disabled = false;
            input.disabled = false;
            form.removeAttribute("aria-busy");
            button.setAttribute("aria-label", buttonLabel);
            label.textContent = "Change photo";
        }
    });
}

// Profile: save the signed-in admin's details and refresh the dashboard labels.
function initAdminProfile(container) {
    const form = container.querySelector("#admin-profile-form");
    if (!form) return;

    const message = form.querySelector("#admin-profile-message");
    const fieldset = form.querySelector("fieldset");
    const saveButton = form.querySelector("button[type='submit']");

    form.addEventListener("input", event => {
        event.target.removeAttribute("aria-invalid");
    });
    form.addEventListener("reset", () => {
        message.textContent = "";
        message.removeAttribute("data-state");
        form.querySelectorAll("[aria-invalid]").forEach(field => field.removeAttribute("aria-invalid"));
    });

    form.addEventListener("submit", async event => {
        event.preventDefault();
        const body = new FormData(form);
        fieldset.disabled = true;
        saveButton.textContent = "Saving...";
        message.textContent = "Saving changes...";
        message.dataset.state = "pending";
        form.querySelectorAll("[aria-invalid]").forEach(field => field.removeAttribute("aria-invalid"));
        let firstInvalidField = null;

        try {
            const response = await fetch(form.action, {
                method: "POST",
                body,
                headers: { Accept: "application/json" },
            });
            const data = await response.json();

            if (response.status === 401) {
                window.location.assign("../login.php");
                return;
            }
            if (!response.ok || !data.ok) {
                Object.entries(data.errors || {}).forEach(([name]) => {
                    const field = form.elements.namedItem(name);
                    if (field) {
                        field.setAttribute("aria-invalid", "true");
                        firstInvalidField ||= field;
                    }
                });
                throw new Error(Object.values(data.errors || {}).join(" ") || data.message || "Unable to save your profile.");
            }

            Object.entries(data.profile).forEach(([name, value]) => {
                const field = form.elements.namedItem(name);
                if (field) {
                    field.value = value ?? "";
                    field.defaultValue = value ?? "";
                }
            });
            const labels = {
                admin_name: "[data-admin-name]",
                role: "[data-admin-role]",
                school_name: "[data-school-name]",
            };
            Object.entries(labels).forEach(([name, selector]) => {
                document.querySelectorAll(selector).forEach(label => {
                    label.textContent = data.profile[name] ?? "";
                });
            });
            message.textContent = data.message;
            message.dataset.state = "success";
        } catch (error) {
            message.textContent = error.message || "Unable to save your profile. Please try again.";
            message.dataset.state = "error";
        } finally {
            fieldset.disabled = false;
            saveButton.textContent = "Save Changes";
            if (firstInvalidField) firstInvalidField.focus();
        }
    });
}

// Startup: run once, after the dashboard shell is available.
function initAdminDashboard() {
    if (dashboardInitialized) return;
    if (!document.getElementById("main-content-contianer")) return;

    dashboardInitialized = true;
    window.KNSNotifications.init({
        role: "admin",
        navigate: async notification => {
            const opened = await loadPage("requests.html", {
                requestId: notification.request_id,
                openMessenger: notification.type === "message"
            });
            if (!opened) throw new Error("Unable to open this request notification. Please try again.");
        }
    });
    const view = new URLSearchParams(window.location.search).get("view");
    const page = view === "profile" ? "profile.php" : view === "results" ? "results.php" : "overview.php";
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
