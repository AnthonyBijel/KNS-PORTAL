"use strict";

// Page configuration and shared state.
// Register each new admin page here, together with its setup functions.
const ADMIN_PAGES = {
    "overview.php": {
        navigation: ".overview-button",
        initializers: [initAnnouncements, initMediaPreview],
    },
    "results.html": {
        navigation: ".results-button",
        initializers: [],
    },
    "requests.html": {
        navigation: ".may-request-button",
        initializers: [],
    },
    "profile.html": {
        navigation: ".student-information-button",
        initializers: [],
    },
};

let latestPageRequest = 0;
let mediaPreviewURL = null;
let dashboardInitialized = false;

// Page loading and navigation.
// Keep loadPage global because the sidebar HTML calls it through onclick.
async function loadPage(page) {
    const container = document.getElementById("main-content-contianer");
    const config = Object.hasOwn(ADMIN_PAGES, page) ? ADMIN_PAGES[page] : null;

    if (!container || !config) {
        console.error("Cannot load admin page:", page);
        return;
    }

    const requestId = ++latestPageRequest;
    container.setAttribute("aria-busy", "true");

    try {
        const response = await fetch(page);
        if (!response.ok) {
            throw new Error(`Failed to load ${page}: ${response.status}`);
        }

        const html = await response.text();

        // A slower, earlier request must not replace the most recent page.
        if (requestId !== latestPageRequest) return;

        clearMediaPreviewURL();
        container.innerHTML = html;

        // Page elements only exist after their HTML has been inserted.
        config.initializers.forEach(initialize => initialize(container));
        updateNavigation(config.navigation);
    } catch (error) {
        if (requestId === latestPageRequest) {
            console.error("Error loading admin page:", error);
        }
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

// Startup: run once, after the dashboard shell is available.
function initAdminDashboard() {
    if (dashboardInitialized) return;
    if (!document.getElementById("main-content-contianer")) return;

    dashboardInitialized = true;
    return loadPage("overview.php");
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAdminDashboard, { once: true });
} else {
    initAdminDashboard();
}
