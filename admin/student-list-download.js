'use strict';
window.KNSStudentListDownload = Object.freeze({
    init(container) {
        const root = container.querySelector('[data-student-download]');
        if (!root || root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const find = selector => root.querySelector(selector);
        const dialog = find('.kns-list-modal');
        const form = find('[data-list-filters]');
        const fields = [...form.querySelectorAll('select')];
        const previewButton = find('[data-list-preview]');
        const downloadButton = find('[data-list-download]');
        const message = find('[data-list-message]');
        const labels = ['academic year', 'course', 'year level', 'section'];
        let csrf = root.dataset.csrf;
        let cohorts = [], students = [], signature = '';
        let busy = false, page = 1, version = 0, controller = null;
        const pageSize = 25;
        const notify = (text, error = false) => {
            message.textContent = text;
            message.dataset.error = String(error);
            message.setAttribute('role', error ? 'alert' : 'status');
        };
        const complete = () => fields.every(field => field.value);
        function controls() {
            fields.forEach((field, index) => {
                field.disabled = busy || !cohorts.length || fields.slice(0, index).some(previous => !previous.value) || field.options.length <= 1;
            });
            previewButton.disabled = busy || !complete();
            downloadButton.disabled = busy || !signature || !students.length;
            find('[data-list-reset]').disabled = busy;
            previewButton.textContent = busy ? 'Please wait...' : 'Preview List';
            find('[data-list-preview-section]').setAttribute('aria-busy', String(busy));
        }
        function invalidate(text = 'Select all four filters, then click Preview List.') {
            students = []; signature = ''; page = 1;
            find('[data-list-preview-section]').hidden = true;
            find('[data-list-rows]').replaceChildren();
            notify(text);
            controls();
        }
        function options(preferred = {}) {
            fields.forEach((field, index) => {
                const previous = preferred[field.name] ?? field.value;
                const available = cohorts.filter(cohort => fields.slice(0, index).every(before => !before.value || cohort[before.name] === before.value));
                const values = [...new Set(available.map(cohort => cohort[field.name]))];
                values.sort((a, b) => field.name === 'academic_year' ? b.localeCompare(a) : a.localeCompare(b, 'en', {numeric: true}));
                const placeholder = new Option('Select ' + labels[index], '');
                field.replaceChildren(placeholder, ...values.map(value => new Option(field.name === 'year_level' ? 'Year ' + value : value, value)));
                field.value = values.includes(previous) ? previous : '';
            });
            controls();
        }
        async function request(body, binary = false) {
            const current = new AbortController();
            controller = current;
            const timer = setTimeout(() => current.abort(), 30000);
            try {
                const response = await fetch(root.dataset.endpoint, {
                    method: body ? 'POST' : 'GET', body, credentials: 'same-origin', cache: 'no-store',
                    headers: {Accept: 'application/json'}, signal: current.signal
                });
                if (response.redirected) { window.location.assign(response.url); throw new Error('Please sign in again.'); }
                if (binary && response.ok && response.headers.get('Content-Type')?.includes('spreadsheetml.sheet')) {
                    return {blob: await response.blob(), disposition: response.headers.get('Content-Disposition')};
                }
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    const error = new Error(data.message || 'Unable to prepare the student list.');
                    error.status = response.status; throw error;
                }
                if (data.csrf) csrf = data.csrf;
                return data;
            } catch (error) {
                if (current.signal.aborted && dialog.open) throw new Error('The request timed out. Please try again.');
                if (error instanceof SyntaxError) throw new Error('Unable to read the server response. Please try again.');
                throw error;
            } finally {
                clearTimeout(timer);
                if (controller === current) controller = null;
            }
        }
        async function loadOptions() {
            const active = ++version;
            busy = true; invalidate('Loading available student groups...');
            try {
                const data = await request();
                if (!root.isConnected || active !== version) return;
                cohorts = data.cohorts;
                const preferred = Object.fromEntries([...container.querySelectorAll('[data-student-filter]')].map(field => [field.name, field.value]));
                options(preferred);
                notify(cohorts.length ? 'Select all four filters, then click Preview List.' : 'No students have a complete academic year, course, year level, and section yet.');
            } catch (error) {
                if (root.isConnected && active === version) notify(error.message + ' Close and reopen Download List to retry.', true);
            } finally { if (active === version) { busy = false; controls(); } }
        }
        function render() {
            const body = find('[data-list-rows]');
            const start = (page - 1) * pageSize;
            const rows = students.slice(start, start + pageSize).map(student => {
                const row = document.createElement('tr');
                for (const key of ['student_number', 'full_name', 'course', 'section', 'year_level', 'academic_year']) {
                    const cell = document.createElement('td'); cell.setAttribute('data-staff-content',''); cell.textContent = student[key]; row.append(cell);
                }
                return row;
            });
            if (!rows.length) {
                const row = document.createElement('tr'), cell = document.createElement('td');
                cell.colSpan = 6; cell.textContent = 'No matching students.'; row.append(cell); rows.push(row);
            }
            body.replaceChildren(...rows);
            find('[data-list-preview-section]').hidden = false;
            find('[data-list-count]').textContent = students.length + (students.length === 1 ? ' student' : ' students');
            find('[data-list-page-label]').textContent = students.length ? 'Showing ' + (start + 1) + '-' + Math.min(start + pageSize, students.length) + ' of ' + students.length : '0 students';
            find('[data-list-page="previous"]').disabled = page <= 1;
            find('[data-list-page="next"]').disabled = page * pageSize >= students.length;
        }
        find('[data-list-open]').addEventListener('click', () => {
            form.reset(); cohorts = []; options(); dialog.showModal(); loadOptions();
        });
        root.querySelectorAll('[data-list-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('close', () => {
            ++version; controller?.abort(); busy = false; downloadButton.textContent = 'Download Excel'; invalidate();
            if (root.isConnected) find('[data-list-open]').focus();
        });
        dialog.addEventListener('click', event => {
            const box = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
        });
        form.addEventListener('change', () => { options(); invalidate('Filters changed. Click Preview List to review this group.'); });
        find('[data-list-reset]').addEventListener('click', () => { form.reset(); options(); invalidate(); });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy || !complete()) return;
            const active = ++version;
            const body = new FormData(form); body.set('action', 'preview'); body.set('csrf', csrf);
            busy = true; invalidate('Loading student preview...');
            try {
                const data = await request(body);
                if (!root.isConnected || active !== version) return;
                students = data.students; signature = data.signature; page = 1;
                render();
                notify(students.length ? 'Review the students below. Download Excel includes all ' + students.length + ' matching students.' : 'No students match these filters. Choose another group.');
            } catch (error) {
                if (root.isConnected && active === version) notify(error.message, true);
            } finally { if (active === version) { busy = false; controls(); } }
        });
        root.querySelectorAll('[data-list-page]').forEach(button => button.addEventListener('click', () => {
            page += button.dataset.listPage === 'next' ? 1 : -1; render();
            find('.kns-list-table-scroll').scrollTop = 0;
        }));
        downloadButton.addEventListener('click', async () => {
            if (busy || !signature || !students.length) return;
            const active = ++version;
            const body = new FormData(form); body.set('action', 'download'); body.set('csrf', csrf); body.set('signature', signature);
            busy = true; controls(); downloadButton.textContent = 'Preparing Excel...'; notify('Preparing the Excel file...');
            try {
                const data = await request(body, true);
                if (!root.isConnected || active !== version) return;
                const url = URL.createObjectURL(data.blob), link = document.createElement('a');
                link.href = url; link.download = data.disposition?.match(/filename="([^"]+)"/)?.[1] || 'KNS_Student_List.xlsx';
                dialog.append(link); link.click(); link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
                notify('Excel file ready: ' + students.length + ' students.');
            } catch (error) {
                if (root.isConnected && active === version) {
                    if (error.status === 409) invalidate(error.message);
                    notify(error.message, true);
                }
            } finally {
                if (active === version) { busy = false; controls(); downloadButton.textContent = 'Download Excel'; }
            }
        });
    }
});
