'use strict';
window.KNSStudentSettings = Object.freeze({
    init(container) {
        const root = container.querySelector('[data-student-settings]');
        if (!root || root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const area = root.closest('.student-info-container');
        const find = selector => root.querySelector(selector);
        const gear = find('[data-settings-gear]');
        const menu = find('.ks-menu');
        const items = [...menu.querySelectorAll('[role=menuitem]')];
        const dialog = find('.ks-modal');
        const feedback = find('[data-settings-feedback]');
        const forms = [...root.querySelectorAll('[data-settings-action]')];
        const formFor = action => forms.find(form => form.dataset.settingsAction === action);
        const en = Object.fromEntries([...root.querySelectorAll('[data-ks-text]')].map(node => [node.dataset.ksText, node.textContent]));
        Object.assign(en, {loading:'Loading your settings...', saving:'Saving...', saved:'Your changes have been saved.',
            password_saved:'Your password has been changed.', photo_saved:'Your profile photo has been saved.',
            code_sent:'A verification code was sent to your new email.', email_saved:'Your new email was verified and saved.',
            close:'Close settings', open:'Open account settings', show:'Show password', hide:'Hide password',
            email_help:'Your current email changes only after you verify the new address.',
            mail_unavailable:'Email delivery is unavailable. Your current email will remain unchanged.',
            resend:'Resend verification code', wait:'Request another code in', code_for:'Enter the code sent to',
            expired:'This code has expired. Request a new verification code.'});
        const fil = {
            settings:'Mga Setting',profile:'Mga Setting ng Profile',notifications:'Mga Abiso',security:'Seguridad',
            appearance:'Hitsura',language:'Wika',email:'Palitan ang Email',contacts:'Mga Detalye ng Kontak',
            address:'Tirahan',full_name:'Buong pangalan',role:'Uri ng account',school:'Paaralan',
            photo_help:'Awtomatikong sine-save ang larawan. JPG, PNG o WEBP; hanggang 2 MB.',
            save_changes:'I-save ang mga pagbabago',system_announcements:'Mga anunsyo ng sistema',student_requests:'Mga update sa kahilingan',
            account_activity:'Aktibidad ng account',email_notifications:'Mga abiso sa email',
            notification_help:'Para sa mga opsyonal na abiso ng pagbabago sa account ang email preference. Ipinapadala pa rin ang verification code kapag hiniling.',
            save_preferences:'I-save ang mga kagustuhan',current_password:'Kasalukuyang password',new_password:'Bagong password',
            confirm_password:'Ulitin ang bagong password',password_help:'Gumamit ng hindi bababa sa 12 character. Dapat magkapareho ang bagong password.',
            change_password:'Palitan ang password',theme:'Tema',light:'Maliwanag',dark:'Madilim',
            appearance_help:'Para sa buong portal ng iyong naka-sign in na account.',save:'I-save',
            language_help:'Piliin ang wikang gagamitin sa buong portal ng iyong account.',current_email:'Kasalukuyang email',new_email:'Bagong email',
            confirm_email:'Ulitin ang bagong email',send_code:'Ipadala ang verification code',verification_code:'Verification code',
            verify_email:'I-verify at palitan ang email',phone:'Numero ng telepono',alternate:'Alternatibong kontak',
            loading:'Kinukuha ang iyong mga setting...',saving:'Sine-save...',saved:'Na-save na ang iyong mga pagbabago.',
            password_saved:'Napalitan na ang iyong password.',photo_saved:'Na-save na ang iyong larawan.',
            code_sent:'Naipadala ang verification code sa bagong email.',email_saved:'Na-verify at na-save na ang bagong email.',
            close:'Isara ang mga setting',open:'Buksan ang mga setting ng account',show:'Ipakita ang password',hide:'Itago ang password',
            email_help:'Mapapalitan lamang ang kasalukuyang email matapos ma-verify ang bagong email.',
            mail_unavailable:'Hindi magagamit ang email delivery. Mananatili ang kasalukuyang email.',
            resend:'Ipadala muli ang code',wait:'Maaaring humiling muli pagkalipas ng',code_for:'Ilagay ang code na ipinadala sa',
            expired:'Nag-expire na ang code. Humiling ng bagong verification code.'
        };
        let state = null, panel = 'profile', busy = false, saving = false, csrf = root.dataset.csrf;
        let version = 0, controller = null, timer = null;
        const events = new AbortController();
        const t = key => (area.dataset.studentLanguage === 'fil' ? fil[key] : en[key]) || en[key] || key;
        const roleName = role => ({Administrator:'School Administrator',OSA:'OSA Office',Registrar:'Registrar Office'})[role] || role;
        function notify(text = '', error = false, warning = false) {
            feedback.textContent = text; feedback.hidden = !text;
            feedback.dataset.error = String(error); feedback.dataset.warning = String(warning);
            feedback.setAttribute('role', error ? 'alert' : 'status');
        }
        function translate() {
            root.querySelectorAll('[data-ks-text]').forEach(node => { node.textContent = t(node.dataset.ksText); });
            gear.setAttribute('aria-label', t('open')); gear.title = t('settings');
            find('[data-settings-close]').setAttribute('aria-label', t('close'));
            find('#ks-panel-title').textContent = t(panel);
            root.querySelectorAll('[data-settings-show-password]').forEach(button => {
                button.setAttribute('aria-label', t(button.previousElementSibling.type === 'password' ? 'show' : 'hide'));
            });
            dialog.lang = area.dataset.studentLanguage === 'fil' ? 'fil' : 'en';
        }
        function controls() {
            forms.forEach(form => { form.querySelector('fieldset').disabled = busy; });
            gear.disabled = saving; find('[data-settings-close]').disabled = saving;
            dialog.setAttribute('aria-busy', String(busy));
            const send = find('[data-settings-send-email]');
            const pending = state?.pending_email;
            const wait = pending ? Math.max(0, Math.ceil((Date.parse(pending.created_at.replace(' ', 'T')+'Z')+60000-Date.now())/1000)) : 0;
            send.disabled = busy || !state?.email_available || wait > 0;
            send.textContent = wait ? t('wait')+' '+wait+'s' : t(pending ? 'resend' : 'send_code');
            const verify = formFor('verify_email');
            const expired = pending && Date.parse(pending.expires_at.replace(' ', 'T')+'Z') <= Date.now();
            verify.querySelector('[type=submit]').disabled = busy || !!expired;
            if (pending) find('[data-settings-pending-email]').textContent = expired ? t('expired') : t('code_for')+' '+pending.new_email+'.';
        }
        function apply(next) {
            state = next;
            area.dataset.studentTheme = next.preferences.theme;
            area.dataset.studentLanguage = next.preferences.language;
            window.KNSStudentPortal?.apply(next.preferences);
            translate();
            find('[data-settings-account-role]').textContent = (next.profile.role === 'Student' && area.dataset.studentLanguage === 'fil' ? 'Estudyante' : roleName(next.profile.role));
            find('[data-settings-school]').textContent = next.profile.school_name;
            root.querySelectorAll('[data-settings-avatar]').forEach(image => { image.src = next.profile.photo_url; });
            formFor('profile').elements.full_name.value = next.profile.full_name;
            find('[data-settings-role]').value = (next.profile.role === 'Student' && area.dataset.studentLanguage === 'fil' ? 'Estudyante' : roleName(next.profile.role));
            find('[data-settings-student-number]').value = next.profile.student_number;
            find('[data-settings-school-input]').value = next.profile.school_name;
            find('[data-settings-school-input]').readOnly = next.profile.role !== 'Administrator';
            find('[data-settings-school-input]').disabled = next.profile.role !== 'Administrator';
            formFor('contacts').elements.address.value = next.profile.address || '';
            const notification = formFor('notifications');
            for (const key of ['notify_announcements','notify_requests','notify_activity','notify_email']) notification.elements[key].checked = next.preferences[key];
            formFor('appearance').elements.theme.value = next.preferences.theme;
            formFor('language').elements.language.value = next.preferences.language;
            formFor('contacts').elements.phone_number.value = next.profile.phone_number || '';
            formFor('contacts').elements.phone_number.required = !next.profile.email;
            formFor('contacts').elements.alternate_contact.value = next.preferences.alternate_contact || '';
            find('[data-settings-current-email]').value = next.profile.email || '';
            formFor('send_email').elements.new_email.value = next.pending_email?.new_email || '';
            formFor('send_email').elements.email_confirmation.value = next.pending_email?.new_email || '';
            formFor('verify_email').hidden = !next.pending_email;
            find('[data-settings-mail-availability]').textContent = t(next.email_available ? 'email_help' : 'mail_unavailable');
            clearInterval(timer);
            if (dialog.open && next.pending_email) timer = setInterval(() => { if (dialog.open) controls(); }, 1000);
            controls();
        }
        function syncProfile(action) {
            const changes = action === 'contacts' ? {contact:state.profile.phone_number,address:state.profile.address}
                : action === 'verify_email' ? {email:state.profile.email} : {};
            for (const [key,value] of Object.entries(changes)) {
                const field = container.querySelector('[data-student-profile="' + key + '"]');
                if (field) field.textContent = value || (area.dataset.studentLanguage === 'fil' ? 'Wala pang nakalagay' : 'Not yet provided');
            }
        }
        async function request(body, endpoint = root.dataset.endpoint) {
            const abort = new AbortController(); controller = abort;
            const timeout = setTimeout(() => abort.abort(), 30000);
            try {
                const response = await fetch(endpoint, {method:body?'POST':'GET', body, credentials:'same-origin',
                    cache:'no-store', headers:{Accept:'application/json'}, signal:abort.signal});
                if (response.redirected) { window.location.assign(response.url); throw new Error('Please sign in again.'); }
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    const error = new Error(data.message || 'Unable to save your settings.'); error.status = response.status; throw error;
                }
                if (data.csrf) csrf = data.csrf;
                return data;
            } catch (error) {
                if (abort.signal.aborted) throw new Error('The request was interrupted. Reopen settings to check the saved values.');
                if (error instanceof SyntaxError) throw new Error('Unable to read the server response. Please try again.');
                throw error;
            } finally { clearTimeout(timeout); if (controller === abort) controller = null; }
        }
        function closeMenu(focus = false) {
            menu.hidden = true; gear.setAttribute('aria-expanded','false'); if (focus) gear.focus();
        }
        function position() {
            if (menu.hidden) return;
            const rect = gear.getBoundingClientRect();
            menu.style.left = Math.max(12,Math.min(rect.right-menu.offsetWidth,window.innerWidth-menu.offsetWidth-12))+'px';
            menu.style.top = Math.max(12,Math.min(rect.bottom+8,window.innerHeight-menu.offsetHeight-12))+'px';
        }
        function openMenu() {
            menu.hidden = false; gear.setAttribute('aria-expanded','true'); position(); items[0].focus();
        }
        async function openPanel(key) {
            if (saving) return;
            closeMenu(); panel = key;
            root.querySelectorAll('[data-settings-panel]').forEach(section => { section.hidden = section.dataset.settingsPanel !== key; });
            find('[data-settings-title-icon]').replaceChildren(items.find(item => item.dataset.settingsOpen === key).querySelector('svg').cloneNode(true));
            translate(); notify(t('loading'));
            if (!dialog.open) dialog.showModal();
            const active = ++version; busy = true; controls();
            try {
                const data = await request();
                if (!root.isConnected || active !== version) return;
                apply(data.state); notify();
            } catch (error) { if (root.isConnected && active === version) notify(error.message,true); }
            finally { if (active === version) { busy = false; controls(); } }
        }
        gear.addEventListener('click', () => { if (menu.hidden) openMenu(); else closeMenu(true); });
        gear.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); openMenu(); }
        });
        items.forEach(item => item.addEventListener('click', () => openPanel(item.dataset.settingsOpen)));
        menu.addEventListener('keydown', event => {
            const index = items.indexOf(document.activeElement);
            if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); }
            else if (event.key === 'Tab') closeMenu(true);
            else if (['ArrowDown','ArrowUp','Home','End'].includes(event.key)) {
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length-1
                    : (index+(event.key === 'ArrowDown'?1:-1)+items.length)%items.length;
                items[next].focus();
            }
        });
        document.addEventListener('pointerdown', event => { if (!root.contains(event.target)) closeMenu(); }, {signal:events.signal});
        window.addEventListener('resize',position,{signal:events.signal});
        window.addEventListener('scroll',position,{capture:true,passive:true,signal:events.signal});
        container.addEventListener('click', event => {
            if (event.target.closest('[data-student-settings-email]')) openPanel('email');
        }, {signal:events.signal});
        find('[data-settings-close]').addEventListener('click', () => { if (!saving) dialog.close(); });
        dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
        dialog.addEventListener('click', event => {
            const rect = dialog.getBoundingClientRect();
            if (!saving && event.target === dialog && (event.clientX<rect.left || event.clientX>rect.right || event.clientY<rect.top || event.clientY>rect.bottom)) dialog.close();
        });
        dialog.addEventListener('close', () => {
            ++version; controller?.abort(); busy=false; saving=false; clearInterval(timer);
            formFor('security').reset(); formFor('verify_email').reset();
            root.querySelectorAll('.ks-password input').forEach(input => { input.type='password'; });
            notify(); controls(); translate(); if (root.isConnected) gear.focus();
        });
        root.querySelectorAll('[data-settings-show-password]').forEach(button => button.addEventListener('click', () => {
            const input = button.previousElementSibling; input.type=input.type==='password'?'text':'password';
            button.setAttribute('aria-label',t(input.type==='password'?'show':'hide'));
        }));
        forms.forEach(form => form.addEventListener('submit', async event => {
            event.preventDefault(); if (busy || !state) return;
            const action = form.dataset.settingsAction, body = new FormData(form);
            body.set('action',action); body.set('csrf',csrf);
            if (action === 'notifications') for (const input of form.querySelectorAll('input[type=checkbox]')) body.set(input.name,input.checked?'1':'0');
            const active=++version; busy=true; saving=true; controls(); notify(t('saving'));
            try {
                const data=await request(body);
                if (!root.isConnected || active!==version) return;
                apply(data.state); syncProfile(action);
                const key = action==='security'?'password_saved':action==='send_email'?'code_sent':action==='verify_email'?'email_saved':'saved';
                notify(data.notice || t(key),false,!!data.notice);
                if (action==='notifications') window.dispatchEvent(new Event('kns-student-preferences-changed'));
            } catch (error) {
                if (root.isConnected && active===version) {
                    if (action==='verify_email' && [410,429].includes(error.status)) {
                        try { const data=await request(); apply(data.state); } catch {}
                    }
                    notify(error.message,true);
                }
            } finally {
                body.delete('current_password'); body.delete('new_password'); body.delete('password_confirmation'); body.delete('verification_code');
                if (action==='security' || action==='verify_email') form.reset();
                if (active===version) {
                    busy=false; saving=false; controls();
                    if (action==='send_email' && state?.pending_email) formFor('verify_email').elements.verification_code.focus();
                }
            }
        }));
        find('[data-settings-choose-photo]').addEventListener('click', () => { if (!busy) find('[data-settings-photo]').click(); });
        find('[data-settings-photo]').addEventListener('change', async event => {
            if (busy || !event.target.files.length) return;
            const file=event.target.files[0];
            if (!file.size || file.size>2097152) { notify('Choose a nonempty photo of at most 2 MB.',true); event.target.value=''; return; }
            const body=new FormData(); body.set('csrf',csrf); body.set('profile_photo',file);
            const active=++version; busy=true; saving=true; controls(); notify(t('saving'));
            try {
                const data=await request(body,root.dataset.photoEndpoint);
                if (!root.isConnected || active!==version) return;
                const url='../'+data.photo_path;
                container.querySelectorAll('[data-student-photo],[data-settings-avatar]').forEach(image => { image.src=url; });
                state.profile.photo_url=url; notify(t('photo_saved'));
            } catch (error) { if (root.isConnected && active===version) notify(error.message,true); }
            finally { event.target.value=''; if (active===version) { busy=false; saving=false; controls(); } }
        });
        const observer = new MutationObserver(() => {
            if (!root.isConnected) { events.abort(); controller?.abort(); clearInterval(timer); observer.disconnect(); }
        });
        observer.observe(container,{childList:true});
        translate();
    }
});
