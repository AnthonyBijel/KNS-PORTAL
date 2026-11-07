(() => {
    "use strict";

    const root = new URL("../", document.currentScript.src);
    const page = new URL(window.location.href);
    const parameter = "kns_tab";
    let tab = page.searchParams.get(parameter);

    // A fresh login URL in another tab starts an independent browser session.
    if (!/^[a-f0-9]{32}$/.test(tab || "")) {
        tab = Array.from(crypto.getRandomValues(new Uint8Array(16)),
            byte => byte.toString(16).padStart(2, "0")).join("");
        page.searchParams.set(parameter, tab);
        window.location.replace(page.href);
        return;
    }

    function tabUrl(value) {
        const raw = String(value);
        if (!raw || raw.startsWith("#")) return raw;
        const url = new URL(raw, document.baseURI);
        if (url.origin !== root.origin || !url.pathname.startsWith(root.pathname)
            || !/\.(?:php|html)$/i.test(url.pathname)) return raw;
        url.searchParams.set(parameter, tab);
        return url.href;
    }

    // Existing page loaders and API calls keep their own tab's identity.
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, options) => {
        if (input instanceof Request) {
            const url = tabUrl(input.url);
            return originalFetch(url === input.url ? input : new Request(url, input), options);
        }
        return originalFetch(tabUrl(input), options);
    };

    function prepareLink(link) {
        const value = link.getAttribute("href");
        if (value === null) return;
        const url = tabUrl(value);
        if (url !== value) link.setAttribute("href", url);
    }

    function prepareForm(form) {
        const url = tabUrl(form.action);
        if (url === form.action && new URL(url).origin !== root.origin) return;
        if (!new URL(url).pathname.startsWith(root.pathname)) return;
        if (form.action !== url) form.action = url;
        let field = form.querySelector('input[name="' + parameter + '"]');
        if (!field) {
            field = document.createElement("input");
            field.type = "hidden";
            field.name = parameter;
            form.appendChild(field);
        }
        field.value = tab;
    }

    document.addEventListener("submit", event => {
        if (event.target instanceof HTMLFormElement) prepareForm(event.target);
    }, true);

    function prepare(node) {
        if (!(node instanceof Element)) return;
        if (node.matches("a[href], area[href]")) prepareLink(node);
        if (node instanceof HTMLFormElement) prepareForm(node);
        node.querySelectorAll("a[href], area[href]").forEach(prepareLink);
        node.querySelectorAll("form").forEach(prepareForm);
    }

    // Also cover links and forms loaded into the dashboard after initial render.
    new MutationObserver(records => {
        for (const record of records) {
            if (record.type === "attributes") {
                if (record.target instanceof HTMLFormElement) prepareForm(record.target);
                else prepareLink(record.target);
            } else {
                record.addedNodes.forEach(prepare);
            }
        }
    }).observe(document.documentElement, {
        childList: true, subtree: true, attributes: true, attributeFilter: ["href", "action"]
    });
    prepare(document.documentElement);
})();

