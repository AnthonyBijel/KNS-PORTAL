(() => {
    "use strict";
    const overlay = document.getElementById("password-reset-modal");
    const opener = document.getElementById("forgot-password-link");
    if (!overlay || !opener) return;

    const card = overlay.querySelector(".kns-reset-card");
    const close = document.getElementById("reset-close");
    const message = document.getElementById("reset-message");
    const heading = document.getElementById("reset-heading");
    const step = document.getElementById("reset-step");
    const numberInput = document.getElementById("reset-student-number");
    const otpInput = document.getElementById("reset-otp");
    const resend = document.getElementById("reset-resend");
    const loginForm = document.querySelector(".left-form");
    const csrf = loginForm.querySelector("[name='csrf']").value;
    const forms = {
        lookup: document.getElementById("reset-lookup-form"),
        send: document.getElementById("reset-send-form"),
        otp: document.getElementById("reset-otp-form"),
        password: document.getElementById("reset-password-form"),
        success: document.getElementById("reset-success")
    };
    let stage = "lookup";
    let studentNumber = "";
    let channel = "";
    let cooldownUntil = 0;
    let otpExpires = 0;
    let grantExpires = 0;
    let busy = false;
    let generation = 0;
    let controller;
    let timer;
    let previousOverflow;

    function showMessage(text, state = "success") {
        message.textContent = text;
        message.dataset.state = state;
        message.hidden = !text;
        message.setAttribute("role", state === "error" ? "alert" : "status");
    }

    function showStage(name) {
        stage = name;
        Object.entries(forms).forEach(([key, form]) => { form.hidden = key !== name; });
        const titles = {
            lookup: "Recover your password", send: "Choose a recovery option", otp: "Verify recovery code",
            password: "Choose a new password", success: "Password updated"
        };
        heading.textContent = titles[name];
        step.textContent = ["lookup", "send"].includes(name) ? "Step 1 of 2" : name === "success" ? "" : "Step 2 of 2";
        const focus = forms[name].querySelector("input:not(:disabled), button:not(:disabled)");
        if (focus) focus.focus();
        updateControls();
    }

    function remaining(deadline) {
        const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        return Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0");
    }

    function updateControls() {
        card.setAttribute("aria-busy", String(busy));
        card.querySelectorAll("button[type='submit'], [data-reset-back]").forEach(button => { button.disabled = busy; });
        const wait = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
        resend.disabled = busy || wait > 0;
        resend.textContent = wait > 0 ? "Resend in " + wait + "s" : "Resend code";
        document.getElementById("reset-expiry").textContent = otpExpires > Date.now()
            ? "Code expires in " + remaining(otpExpires) + "." : "Code expired. Request a new code.";
        document.getElementById("reset-grant-expiry").textContent = grantExpires > Date.now()
            ? "Finish within " + remaining(grantExpires) + "." : "Recovery session expired. Request a new code.";
    }

    function openModal(event) {
        event.preventDefault();
        if (!overlay.hidden) return;
        generation++;
        busy = false;
        studentNumber = "";
        channel = "";
        cooldownUntil = otpExpires = grantExpires = 0;
        Object.values(forms).forEach(form => { if (form instanceof HTMLFormElement) form.reset(); });
        numberInput.value = loginForm.querySelector("[name='student_number']").value;
        showMessage("");
        previousOverflow = document.body.style.overflow;
        overlay.hidden = false;
        document.body.style.overflow = "hidden";
        showStage("lookup");
        clearInterval(timer);
        timer = setInterval(updateControls, 1000);
    }

    function closeModal() {
        if (overlay.hidden) return;
        const done = stage === "success";
        generation++;
        controller?.abort();
        busy = false;
        clearInterval(timer);
        overlay.hidden = true;
        document.body.style.overflow = previousOverflow;
        Object.values(forms).forEach(form => { if (form instanceof HTMLFormElement) form.reset(); });
        if (done) loginForm.querySelector("[name='password']").focus();
        else opener.focus();
    }

    async function post(endpoint, payload, success) {
        if (busy) return;
        const requestGeneration = generation;
        busy = true;
        showMessage("Please wait...");
        updateControls();
        controller = new AbortController();
        try {
            const response = await fetch("php/auth/" + endpoint + ".php", {
                method: "POST",
                credentials: "same-origin",
                headers: {"Content-Type": "application/json", "Accept": "application/json"},
                body: JSON.stringify({...payload, csrf}),
                signal: controller.signal
            });
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error("Password recovery is temporarily unavailable. Please try again."); }
            if (requestGeneration !== generation || overlay.hidden) return;
            if (!response.ok || !result.ok) {
                if (result.retry_after) cooldownUntil = Date.now() + Number(result.retry_after) * 1000;
                if (response.status === 410 && stage === "password") showStage("send");
                throw new Error(result.message || "Unable to complete password recovery.");
            }
            success(result);
            showMessage(result.message);
        } catch (error) {
            if (requestGeneration !== generation || overlay.hidden || error.name === "AbortError") return;
            showMessage(error.message || "Unable to connect. Please try again.", "error");
        } finally {
            if (requestGeneration === generation) {
                busy = false;
                updateControls();
                // Inputs in the new step may have been disabled during the request.
                const focus = forms[stage].querySelector("input:not(:disabled), button:not(:disabled)");
                if (focus && !forms[stage].contains(document.activeElement)) focus.focus();
            }
        }
    }

    function sendCode(selectedChannel) {
        void post("request_password_reset", {
            action: "send", student_number: studentNumber, channel: selectedChannel
        }, result => {
            channel = selectedChannel;
            cooldownUntil = Date.now() + result.resend_after * 1000;
            otpExpires = Date.now() + result.expires_in * 1000;
            otpInput.value = "";
            document.getElementById("reset-destination").textContent = "Enter the code sent to " + result.masked + ".";
            showStage("otp");
        });
    }

    forms.lookup.addEventListener("submit", event => {
        event.preventDefault();
        const number = numberInput.value.trim();
        if (!number) return;
        void post("request_password_reset", {action: "lookup", student_number: number}, result => {
            studentNumber = number;
            document.getElementById("reset-student-label").textContent = "Student number: " + number;
            let selected = false;
            ["email", "sms"].forEach(name => {
                const option = document.getElementById("reset-channel-" + name);
                const details = result.channels[name];
                option.disabled = !details.available;
                option.checked = details.available && !selected;
                if (option.checked) selected = true;
                document.getElementById("reset-masked-" + name).textContent = details.masked || "No saved contact available";
            });
            showStage("send");
        });
    });

    forms.send.addEventListener("submit", event => {
        event.preventDefault();
        const selected = forms.send.querySelector("[name='channel']:checked:not(:disabled)");
        if (!selected) { showMessage("Choose Email or SMS.", "error"); return; }
        sendCode(selected.value);
    });

    forms.otp.addEventListener("submit", event => {
        event.preventDefault();
        void post("verify_password_reset", {otp: otpInput.value.trim()}, result => {
            otpInput.value = "";
            grantExpires = Date.now() + result.expires_in * 1000;
            showStage("password");
        });
    });

    forms.password.addEventListener("submit", event => {
        event.preventDefault();
        const password = forms.password.elements.password.value;
        const confirmation = forms.password.elements.password_confirmation.value;
        if ([...password].length < 12 || new TextEncoder().encode(password).length > 72) {
            showMessage("Use a password of at least 12 characters and at most 72 bytes.", "error");
            return;
        }
        if (password !== confirmation) { showMessage("The new passwords do not match.", "error"); return; }
        void post("reset_password", {password, password_confirmation: confirmation}, () => {
            forms.password.reset();
            if (typeof window.selectStudentLogin === "function") window.selectStudentLogin();
            loginForm.querySelector("[name='student_number']").value = studentNumber;
            loginForm.querySelector("[name='password']").value = "";
            showStage("success");
        });
    });

    otpInput.addEventListener("input", () => { otpInput.value = otpInput.value.replace(/[^0-9]/g, "").slice(0, 6); });
    resend.addEventListener("click", () => { if (!resend.disabled && channel) sendCode(channel); });
    card.querySelectorAll("[data-reset-back]").forEach(button => button.addEventListener("click", () => {
        if (!busy) {
            showMessage("");
            showStage(button.dataset.resetBack);
        }
    }));
    opener.addEventListener("click", openModal);
    close.addEventListener("click", closeModal);
    document.getElementById("reset-done").addEventListener("click", closeModal);
    overlay.addEventListener("click", event => { if (event.target === overlay) closeModal(); });
    document.addEventListener("keydown", event => {
        if (overlay.hidden) return;
        if (event.key === "Escape") { event.preventDefault(); closeModal(); return; }
        if (event.key !== "Tab") return;
        const controls = [...card.querySelectorAll("input:not(:disabled), button:not(:disabled)")]
            .filter(control => control.getClientRects().length);
        const index = controls.indexOf(document.activeElement);
        const next = event.shiftKey ? (index <= 0 ? controls.length - 1 : index - 1) : (index + 1) % controls.length;
        event.preventDefault();
        controls[next]?.focus();
    });
})();