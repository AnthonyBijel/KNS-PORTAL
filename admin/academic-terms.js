'use strict';
window.KNSAcademicTerms = Object.freeze({
    init(container) {
        const root = container.querySelector('[data-kns-terms]');
        if (!root || root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const find = selector => root.querySelector(selector);
        const dialog = find('.kns-terms-modal');
        const form = find('[data-terms-form]');
        const message = find('[data-terms-message]');
        const submit = form.querySelector('[type=submit]');
        const fields = form.querySelector('fieldset');
        let csrf = root.dataset.csrf;
        let canSchedule = root.dataset.canSchedule === 'true';
        let calendar = null;
        let busy = false;
        let selectedTerm = null;
        let kind = 'enrollments';
        let page = 1;
        let pages = 1;
        let recordVersion = 0;
        const element = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        };
        const dateLabel = (date, short = false) => new Intl.DateTimeFormat('en-US', {
            year: 'numeric', month: short ? 'short' : 'long', day: 'numeric', timeZone: 'UTC'
        }).format(new Date(date + 'T12:00:00Z'));
        const notify = (text = '', error = false) => {
            message.textContent = text;
            message.dataset.state = error ? 'error' : 'success';
            message.setAttribute('role', error ? 'alert' : 'status');
            message.hidden = !text;
        };
        async function request(url, body) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 20000);
            try {
                const response = await fetch(url, {method: body ? 'POST' : 'GET', body,
                    headers: {Accept: 'application/json'}, cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
                if (response.redirected) { window.location.assign(response.url); throw new Error('Please sign in again.'); }
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to complete the term request.');
                if (data.csrf) csrf = data.csrf;
                return data;
            } catch (error) {
                if (error.name === 'AbortError') throw new Error('The term request timed out. Please try again.');
                if (error instanceof SyntaxError) throw new Error('Unable to read the server response. Please try again.');
                throw error;
            } finally { clearTimeout(timer); }
        }
        function badge(node, status) {
            node.textContent = status;
            node.dataset.status = status;
            node.hidden = !status;
        }
        function renderCurrent(term) {
            const bannerName = find('[data-terms-banner-name]');
            const currentName = find('[data-terms-current-name]');
            if (!term) {
                bannerName.textContent = 'No active academic term';
                currentName.textContent = 'No active academic term';
                find('[data-terms-banner-end]').textContent = 'Open Manage Terms to view the calendar.';
                find('[data-terms-current-end]').textContent = 'Schedule a term with its official dates to begin.';
                badge(find('[data-terms-banner-status]'), '');
                badge(find('[data-terms-current-status]'), '');
                return;
            }
            bannerName.textContent = 'AY ' + term.academic_year + '  |  ' + term.semester;
            currentName.textContent = 'Academic Year: ' + term.academic_year + '  |  Semester: ' + term.semester;
            find('[data-terms-banner-end]').textContent = 'Official End Date: ' + dateLabel(term.official_end_date, true);
            find('[data-terms-current-end]').textContent = 'Official End Date: ' + dateLabel(term.official_end_date);
            badge(find('[data-terms-banner-status]'), term.status);
            badge(find('[data-terms-current-status]'), term.status);
        }
        function emptyRow(body, columns, text) {
            const row = element('tr');
            const cell = element('td', 'kns-terms-empty', text);
            cell.colSpan = columns;
            row.append(cell);
            body.replaceChildren(row);
        }
        function renderHistory() {
            const body = find('[data-terms-history-rows]');
            if (!calendar?.terms.length) { emptyRow(body, 6, 'No academic terms have been saved yet.'); return; }
            body.replaceChildren();
            for (const term of calendar.terms) {
                const row = element('tr');
                row.dataset.termId = term.id;
                for (const text of [term.academic_year, term.semester, dateLabel(term.start_date, true), dateLabel(term.official_end_date, true)]) row.append(element('td', '', text));
                const state = element('td');
                const label = element('span', 'kns-terms-badge'); badge(label, term.status); state.append(label); row.append(state);
                const actions = element('td');
                const button = element('button', 'kns-terms-record-button', 'View records');
                button.type = 'button';
                button.dataset.termRecords = term.id;
                button.setAttribute('aria-label', 'View records for ' + term.academic_year + ' ' + term.semester);
                actions.append(button); row.append(actions); body.append(row);
            }
        }
        async function loadCalendar() {
            const data = await request(root.dataset.endpoint);
            if (!root.isConnected) return;
            calendar = data;
            canSchedule = data.can_schedule;
            fields.disabled = !canSchedule || busy;
            submit.disabled = !canSchedule || busy;
            renderCurrent(data.current);
            find('[data-terms-years]').replaceChildren(...data.academic_years.map(year => {
                const option = element('option'); option.value = year; return option;
            }));
            renderHistory();
            updatePlanned();
        }
        function updatePlanned() {
            const start = form.elements.start_date.value;
            const end = form.elements.official_end_date.value;
            const today = calendar?.today;
            let state = 'Scheduled';
            if (today && start && start <= today) state = end && end < today ? 'Completed' : 'Active';
            const planned = find('[data-terms-planned]');
            planned.lastChild.textContent = state;
            form.elements.official_end_date.min = start || '1000-01-01';
        }
        function historyView(show) {
            find('[data-terms-schedule-view]').hidden = show;
            find('[data-terms-history-view]').hidden = !show;
            dialog.classList.toggle('is-history', show);
            notify();
        }
        const columns = {
            enrollments: [['student_number','Student Number'],['student_name','Student'],['course','Course'],['year_level','Year Level'],['section','Section'],['enrollment_status','Enrollment'],['recorded_at','Recorded At']],
            grades: [['student_number','Student Number'],['student_name','Student'],['subject_code','Subject'],['subject_name','Subject Name'],['prelim_grade','Prelim'],['midterm_grade','Midterm'],['final_grade','Final'],['overall_grade','Overall']],
            assessments: [['student_number','Student Number'],['student_name','Student'],['subject_code','Subject'],['grading_period','Period'],['assessment_type','Type'],['assessment_name','Assessment'],['score','Score'],['max_score','Max Score'],['date_posted','Date'],['remarks','Remarks']]
        };
        async function loadRecords() {
            if (!selectedTerm) return;
            const version = ++recordVersion;
            const heading = element('tr');
            for (const [,label] of columns[kind]) heading.append(element('th', '', label));
            find('[data-terms-record-head]').replaceChildren(heading);
            const body = find('[data-terms-record-rows]');
            emptyRow(body, columns[kind].length, 'Loading saved records...');
            root.querySelectorAll('[data-terms-page]').forEach(button => { button.disabled = true; });
            root.querySelectorAll('[data-terms-kind]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.termsKind === kind)));
            const url = new URL(root.dataset.endpoint, window.location.href);
            url.search = new URLSearchParams({term_id: selectedTerm.id, kind, page}).toString();
            try {
                const data = await request(url);
                if (!root.isConnected || version !== recordVersion) return;
                page = data.page; pages = data.pages;
                if (!data.records.length) emptyRow(body, columns[kind].length, 'No ' + kind + ' are recorded for this term.');
                else {
                    body.replaceChildren();
                    for (const record of data.records) {
                        const row = element('tr');
                        for (const [key] of columns[kind]) row.append(element('td', '', record[key] ?? '\u2014'));
                        body.append(row);
                    }
                }
                find('[data-terms-page-label]').textContent = 'Page ' + page + ' of ' + pages + ' (' + data.total + ' records)';
                find('[data-terms-page="previous"]').disabled = page <= 1;
                find('[data-terms-page="next"]').disabled = page >= pages;
            } catch (error) {
                if (root.isConnected && version === recordVersion) emptyRow(body, columns[kind].length, error.message + ' Select a record type to retry.');
            }
        }
        find('[data-terms-open]').addEventListener('click', () => {
            historyView(false);
            dialog.showModal();
            loadCalendar().catch(error => notify(error.message, true));
        });
        root.querySelectorAll('[data-terms-close]').forEach(button => button.addEventListener('click', () => { if (!busy) dialog.close(); }));
        dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
        dialog.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            const controls = [...dialog.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), [tabindex="0"]')]
                .filter(control => control.getClientRects().length > 0);
            const first = controls[0], last = controls[controls.length - 1];
            if (first && event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (last && !event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });
        dialog.addEventListener('click', event => {
            const box = dialog.getBoundingClientRect();
            if (!busy && event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
        });
        find('[data-terms-history]').addEventListener('click', () => {
            historyView(true);
            loadCalendar().catch(error => notify(error.message, true));
        });
        find('[data-terms-back]').addEventListener('click', () => historyView(false));
        find('[data-terms-history-rows]').addEventListener('click', event => {
            const button = event.target.closest('[data-term-records]');
            if (!button || !calendar) return;
            selectedTerm = calendar.terms.find(term => term.id === Number(button.dataset.termRecords));
            kind = 'enrollments'; page = 1;
            find('[data-terms-records]').hidden = false;
            find('[data-terms-record-title]').textContent = selectedTerm.academic_year + ' - ' + selectedTerm.semester;
            loadRecords();
        });
        root.querySelectorAll('[data-terms-kind]').forEach(button => button.addEventListener('click', () => { kind = button.dataset.termsKind; page = 1; loadRecords(); }));
        root.querySelectorAll('[data-terms-page]').forEach(button => button.addEventListener('click', () => { page += button.dataset.termsPage === 'next' ? 1 : -1; loadRecords(); }));
        form.addEventListener('input', updatePlanned);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy || !canSchedule) return;
            const body = new FormData(form); body.set('csrf', csrf);
            busy = true; fields.disabled = true; submit.disabled = true; submit.textContent = 'Saving...';
            root.querySelectorAll('[data-terms-close], [data-terms-history]').forEach(button => { button.disabled = true; });
            notify();
            try {
                const data = await request(root.dataset.endpoint, body);
                if (!root.isConnected) return;
                calendar = data; renderCurrent(data.current); renderHistory(); form.reset(); updatePlanned(); notify(data.message);
            } catch (error) { if (root.isConnected) notify(error.message, true); }
            finally {
                busy = false; fields.disabled = !canSchedule; submit.disabled = !canSchedule; submit.textContent = 'Schedule Term';
                root.querySelectorAll('[data-terms-close], [data-terms-history]').forEach(button => { button.disabled = false; });
            }
        });
        async function refresh() {
            if (!root.isConnected) return;
            try { await loadCalendar(); }
            catch (error) {
                find('[data-terms-banner-name]').textContent = 'Current term unavailable';
                find('[data-terms-banner-end]').textContent = 'Open Manage Terms to retry.';
                if (dialog.open) notify(error.message, true);
            }
            if (root.isConnected) setTimeout(refresh, 60000);
        }
        refresh();
    }
});