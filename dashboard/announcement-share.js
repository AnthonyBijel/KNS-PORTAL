(() => {
    "use strict";

    const shareSelector = ".share-button[data-announcement-id]";
    let popup;
    let panel;
    let closeButton;
    let title;
    let description;
    let media;
    let link;
    let copyButton;
    let toast;
    let toastTimer;
    let trigger;
    let previousOverflow;
    let nativeSharePending = false;
    const socialLinks = {};

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function makePopup() {
        if (popup) return;

        popup = element("div", "kns-share-overlay");
        popup.hidden = true;
        panel = element("section", "kns-share-popup");
        panel.setAttribute("role", "dialog");
        panel.setAttribute("aria-modal", "true");
        panel.setAttribute("aria-labelledby", "kns-share-heading");
        panel.tabIndex = -1;

        const heading = element("h2", "", "Share announcement");
        heading.id = "kns-share-heading";
        closeButton = element("button", "kns-share-close", "\u00d7");
        closeButton.type = "button";
        closeButton.setAttribute("aria-label", "Close share popup");
        closeButton.dataset.shareClose = "";
        title = element("h3", "kns-share-title");
        description = element("p", "kns-share-description");
        media = element("a", "kns-share-media", "View attachment");
        media.target = "_blank";
        media.rel = "noopener noreferrer";

        const options = element("div", "kns-share-options");
        [
            ["facebook", "Facebook"],
            ["twitter", "X/Twitter"],
            ["whatsapp", "WhatsApp"]
        ].forEach(([name, label]) => {
            const anchor = element("a", "", label);
            anchor.target = "_blank";
            anchor.rel = "noopener noreferrer";
            socialLinks[name] = anchor;
            options.append(anchor);
        });
        copyButton = element("button", "", "Copy Link");
        copyButton.type = "button";
        copyButton.dataset.shareCopy = "";
        options.append(copyButton);

        const label = element("label", "kns-share-link-label", "Announcement link");
        label.htmlFor = "kns-share-link";
        link = element("input", "kns-share-link");
        link.id = "kns-share-link";
        link.type = "text";
        link.readOnly = true;
        panel.append(heading, closeButton, title, description, media, options, label, link);
        popup.append(panel);
        toast = element("p", "kns-share-toast");
        toast.setAttribute("role", "status");
        toast.setAttribute("aria-live", "polite");
        toast.hidden = true;
        document.body.append(popup, toast);
    }

    function socialUrl(base, parameters) {
        const url = new URL(base);
        Object.entries(parameters).forEach(([key, value]) => url.searchParams.set(key, value));
        return url.href;
    }

    function shareData(button) {
        const id = button.dataset.announcementId;
        if (!/^[1-9][0-9]*$/.test(id || "")) return null;

        const url = new URL("/kns_portal/announcement.php", window.location.origin);
        url.searchParams.set("id", id);
        let mediaUrl = "";
        if (button.dataset.mediaUrl) {
            try {
                const attachment = new URL(button.dataset.mediaUrl, window.location.origin);
                if (["http:", "https:"].includes(attachment.protocol)) mediaUrl = attachment.href;
            } catch (_) {
                // An invalid attachment must never prevent sharing the announcement.
            }
        }
        return {
            title: button.dataset.title || "Kolehiyo ng Subic announcement",
            description: button.dataset.description || "",
            mediaUrl,
            url: url.href
        };
    }

    function openPopup(data, button) {
        makePopup();
        trigger = button;
        title.textContent = data.title;
        description.textContent = data.description;
        media.hidden = !data.mediaUrl;
        if (data.mediaUrl) media.href = data.mediaUrl;
        else media.removeAttribute("href");
        link.value = data.url;

        const text = [data.title, data.description, data.mediaUrl].filter(Boolean).join("\n\n");
        // Facebook obtains the title, description and image from the post's Open Graph tags.
        socialLinks.facebook.href = socialUrl("https://www.facebook.com/sharer/sharer.php", {u: data.url});
        socialLinks.twitter.href = socialUrl("https://twitter.com/intent/tweet", {text, url: data.url});
        socialLinks.whatsapp.href = socialUrl("https://wa.me/", {text: text + "\n\n" + data.url});
        if (popup.hidden) previousOverflow = document.body.style.overflow;
        popup.hidden = false;
        document.body.style.overflow = "hidden";
        closeButton.focus();
    }

    function closePopup() {
        if (!popup || popup.hidden) return;
        popup.hidden = true;
        document.body.style.overflow = previousOverflow;
        if (trigger && trigger.isConnected) trigger.focus();
    }

    async function share(button) {
        const data = shareData(button);
        if (!data || nativeSharePending) return;

        if (typeof navigator.share === "function") {
            nativeSharePending = true;
            try {
                // Call immediately during the click gesture. Media is included as an absolute URL.
                await navigator.share({
                    title: data.title,
                    text: [data.description, data.mediaUrl].filter(Boolean).join("\n\n"),
                    url: data.url
                });
                return;
            } catch (error) {
                if (error && error.name === "AbortError") return;
            } finally {
                nativeSharePending = false;
            }
        }
        openPopup(data, button);
    }

    function notify(message) {
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.hidden = false;
        toastTimer = setTimeout(() => { toast.hidden = true; }, 4000);
    }

    function legacyCopy(url) {
        const textarea = element("textarea", "kns-share-copy-buffer");
        textarea.value = url;
        textarea.readOnly = true;
        panel.append(textarea);
        textarea.select();
        textarea.setSelectionRange(0, url.length);
        try {
            return document.execCommand("copy");
        } finally {
            textarea.remove();
            copyButton.focus();
        }
    }

    async function copyLink() {
        if (copyButton.disabled) return;
        const url = link.value;
        copyButton.disabled = true;
        try {
            let copied = false;
            if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                try {
                    await navigator.clipboard.writeText(url);
                    copied = true;
                } catch (_) {
                    // Clipboard permissions can be denied; try the local HTTP fallback.
                }
            }
            if (!copied) copied = legacyCopy(url);
            if (!copied) throw new Error("Copy failed");
            notify("Link copied successfully!");
        } catch (_) {
            notify("Unable to copy automatically. Select and copy the announcement link.");
            if (!popup.hidden) {
                link.focus();
                link.select();
            }
        } finally {
            copyButton.disabled = false;
        }
    }

    // Delegation also handles cards inserted later by either dashboard's page loader.
    document.addEventListener("click", event => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const button = target.closest(shareSelector);
        if (button) {
            event.preventDefault();
            void share(button);
        } else if (popup && !popup.hidden) {
            if (target === popup || target.closest("[data-share-close]")) closePopup();
            else if (target.closest("[data-share-copy]")) void copyLink();
        }
    });

    document.addEventListener("keydown", event => {
        if (popup && !popup.hidden) {
            if (event.key === "Escape") {
                event.preventDefault();
                closePopup();
            } else if (event.key === "Tab") {
                const controls = [closeButton, ...Object.values(socialLinks), copyButton, link];
                if (!media.hidden) controls.splice(1, 0, media);
                const index = controls.indexOf(document.activeElement);
                const next = event.shiftKey
                    ? (index <= 0 ? controls.length - 1 : index - 1)
                    : (index + 1) % controls.length;
                event.preventDefault();
                controls[next].focus();
            }
            return;
        }
        const button = event.target instanceof Element ? event.target.closest(shareSelector) : null;
        if (button && (event.key === "Enter" || event.key === " ")) {
            event.preventDefault();
            void share(button);
        }
    });
})();