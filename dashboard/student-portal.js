/* Account-wide presentation, restricted to the authenticated student dashboard. */
(() => {
    'use strict';
    const html = document.documentElement;
    if (!html.hasAttribute('data-student-portal')) return;
    const dictionary = Object.assign({}, window.KNSStaffTranslations || {}, Object.fromEntries(String.raw`
Student services|Mga serbisyo ng estudyante
Student information|Impormasyon ng Estudyante
STUDENT INFORMATION|IMPORMASYON NG ESTUDYANTE
View your personal and academic details.|Tingnan ang iyong personal at akademikong detalye.
Personal Information|Personal na Impormasyon
Full Name|Buong Pangalan
Student ID|Student ID
Course|Kurso
Year Level|Taon
Section|Seksyon
Email|Email
Contact Number|Numero ng Telepono
Address|Tirahan
Birthday|Kaarawan
Guardian|Tagapag-alaga
Not yet provided|Wala pang nakalagay
My Information|Aking Impormasyon
My Request|Aking Kahilingan
My Results|Aking mga Resulta
ANNOUNCEMENT|ANUNSYO
Stay Informed.|Manatiling may Kaalaman.
Announcements|Mga Anunsyo
Search announcements...|Maghanap ng anunsyo...
All announcements|Lahat ng anunsyo
All|Lahat
Academic|Akademiko
Events|Mga Kaganapan
Guidelines|Mga Alituntunin
Others|Iba pa
HIGHLIGHTS|MAHAHALAGANG DETALYE
Share|Ibahagi
See more|Tingnan pa
See less|Tingnan nang kaunti
ACADEMIC RESULTS|MGA RESULTANG AKADEMIKO
See Your Progress|Tingnan ang Iyong Pag-unlad
Celebrate Every Achievement|Ipagdiwang ang Bawat Tagumpay
Academic Year|Taong Akademiko
Semester|Semestre
Subject|Asignatura
Score|Iskor
REQUEST|KAHILINGAN
Submit a Request|Magsumite ng Kahilingan
Request Type|Uri ng Kahilingan
Purpose|Layunin
Submit Request|Isumite ang Kahilingan
My Requests|Aking mga Kahilingan
Request updates|Mga update sa kahilingan
Message|Mensahe
Approved|Aprubado
Pending|Nakabinbin
Processing|Pinoproseso
Rejected|Tinanggihan
Notifications|Mga Abiso
Account activity|Aktibidad ng account
Your account|Iyong account
Account type|Uri ng account
Your activity scores, quiz results, exam scores, and subject grades in one place.|Ang iyong mga iskor at marka sa iisang lugar.
Choose a request and fill out the details below|Pumili ng kahilingan at punan ang mga detalye sa ibaba
Submit and track your school-related request|Magsumite at subaybayan ang iyong kahilingan sa paaralan
Get the latest updates, events, and important announcement from Kolehiyo ng Subic|Tingnan ang mga bagong balita, kaganapan, at mahahalagang anunsyo mula sa Kolehiyo ng Subic
`.trim().split('\n').map(line => line.split('|'))));
    const normalize = value => value.replace(/\s+/g, ' ').trim();
    const originals = new WeakMap(), attributes = new WeakMap();
    // These contain account/student data or authored content, rather than interface labels.
    const skip = ['[data-student-content]','.student-name','.username-top','.info-type p','.sa-publisher','.sa-card-copy h3','.sa-highlights p','.description-text','.my-requests-table tbody td:not(:last-child)','[data-subject-grades] td','[data-assessment-results] td','#academic-course option','script','style','code','pre','[data-student-content]','[translate="no"]',
        '[data-admin-name]','[data-school-name]','.kns-author-name','.kns-post-copy h3','.kns-highlights p','.kns-description',
        '[data-delete-title]','.kns-share-title','.kns-share-description','[data-terms-record-rows] td:not([colspan])','.rd-file-info strong','.kns-gallery-title','.kns-share-preview-title','.kns-share-preview-description',
        '.kns-office-accounts-name-text','.kns-office-accounts-email','.kns-office-accounts-detail','[data-selected-office-account-name]',
        '.rm-student','.rm-reference','.rm-sender','.rm-body','.rm-file-name','.rm-selected','#mediaPreview figcaption',
        '#student-results-body td:not([colspan]):nth-child(n+3):nth-child(-n+7)',
        '[data-request-field="student_name"]','[data-request-field="student_number"]','[data-request-field="course"]',
        '[data-request-field="section"]','[data-request-field="email"]','[data-request-field="purpose"]',
        '[data-request-field="subject"]','[data-request-field="reference_no"]'].join(',');
    const filipino = () => html.dataset.studentLanguage === 'fil';
    const parameterized = [
        [/^(Good morning|Good afternoon|Good evening)(, .+)$/, (all,greeting,name) => dictionary[greeting]+name],
        [/^(\d+) (?:post|posts)(?: of (\d+))?$/, (all,n,total) => n+' post'+(total?' sa '+total:'')],
        [/^(\d+) (?:request|requests)$/, (all,n) => n+' kahilingan'],
        [/^(\d+)(?:st|nd|rd|th) Year$/, (all,n) => 'Ika-'+n+' Taon'],
        [/^(\d+) (?:minute|minutes) ago$/, (all,n) => n+' minuto ang nakalipas'],
        [/^(\d+) (?:hour|hours) ago$/, (all,n) => n+' oras ang nakalipas'],
        [/^(\d+) new$/, (all,n) => n+' bago'],
        [/^Notifications, (\d+) unread$/, (all,n) => 'Mga abiso, '+n+' hindi pa nabasa'],
        [/^AY (.+)\s+\|\s+(.+)$/, (all,year,semester) => 'TA '+year+' | '+(dictionary[semester]||semester)],
        [/^Page (\d+)$/, (all,n) => 'Pahina '+n],
        [/^(\d+) (?:file|files) selected$/, (all,n) => n+' napiling file'],
        [/^Current office: (.+)$/, (all,office) => 'Kasalukuyang opisina: '+office],
        [/^(.+) \(no active account\)$/, (all,office) => office+' (walang aktibong account)'],
        [/^Academic Year: (.+) \| Semester: (.+)$/, (all,year,semester) => 'Taong Akademiko: '+year+' | Semestre: '+(dictionary[semester]||semester)],
        [/^Official End Date: (.+)$/, (all,date) => 'Opisyal na Petsa ng Pagtatapos: '+date],
        [/^(\d+) (?:student|students)$/, (all,n) => n+' estudyante'],
        [/^Showing (\d+)-(\d+) of (\d+)$/, (all,start,end,total) => 'Ipinapakita ang '+start+'-'+end+' sa '+total],
        [/^Review the students below\. Download Excel includes all (\d+) students\.$/, (all,n) => 'Suriin ang mga estudyante sa ibaba. Kasama sa Excel ang lahat ng '+n+' estudyante.'],
        [/^Excel file ready: (\d+) students\.$/, (all,n) => 'Handa na ang Excel file: '+n+' estudyante.']
    ];
    function translate(source) {
        const key = normalize(source);
        if (!filipino() || !key) return source;
        let translated = dictionary[key];
        if (!translated) {
            for (const [pattern,format] of parameterized) {
                if (pattern.test(key)) { translated = key.replace(pattern,format); break; }
            }
        }
        if (!translated) {
            // Keep decorative glyphs around an otherwise exact interface string.
            const decorated = key.match(/^([^A-Za-z0-9]*)([A-Za-z].*?)([^A-Za-z0-9.?!:]*)$/);
            if (decorated && dictionary[decorated[2]]) translated = decorated[1]+dictionary[decorated[2]]+decorated[3];
        }
        return translated ? source.replace(source.trim(),translated) : source;
    }
    function textNode(node) {
        if (!node.parentElement || node.parentElement.closest(skip+',textarea') || !node.data.trim()) return;
        const previous = originals.get(node);
        const source = previous && node.data === previous.rendered ? previous.source : node.data;
        const rendered = translate(source);
        originals.set(node,{source,rendered});
        if (node.data !== rendered) node.data = rendered;
    }
    function element(node) {
        if (node.closest(skip)) return;
        // Translating an option without a value must never change its submitted value.
        if (node.matches('option') && !node.hasAttribute('value')) node.setAttribute('value',node.value);
        const record = attributes.get(node) || {};
        for (const name of ['placeholder','title','aria-label','alt']) {
            if (!node.hasAttribute(name)) { delete record[name]; continue; }
            const value = node.getAttribute(name), previous = record[name];
            const source = previous && value === previous.rendered ? previous.source : value;
            const rendered = translate(source);
            record[name] = {source,rendered};
            if (value !== rendered) node.setAttribute(name,rendered);
        }
        attributes.set(node,record);
    }
    function refresh(root = document.body) {
        if (!root || !root.isConnected) return;
        if (root.nodeType === Node.TEXT_NODE) { textNode(root); return; }
        if (root.nodeType !== Node.ELEMENT_NODE || root.closest(skip)) return;
        element(root);
        const walker = document.createTreeWalker(root,NodeFilter.SHOW_ELEMENT|NodeFilter.SHOW_TEXT,{
            acceptNode(node) { return node.nodeType === Node.ELEMENT_NODE && node.matches(skip) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT; }
        });
        while (walker.nextNode()) {
            if (walker.currentNode.nodeType === Node.TEXT_NODE) textNode(walker.currentNode);
            else element(walker.currentNode);
        }
    }
    const observer = new MutationObserver(records => {
        // Disconnect for our own changes to avoid repeated translation loops.
        observer.disconnect();
        const roots = new Set();
        for (const record of records) {
            if (record.type === 'childList') record.addedNodes.forEach(node => roots.add(node));
            else roots.add(record.target);
        }
        roots.forEach(refresh);
        observe();
    });
    function observe() {
        observer.observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,
            attributeFilter:['placeholder','title','aria-label','alt']});
    }
    function apply(preferences) {
        if (!preferences) return;
        html.dataset.studentTheme = preferences.theme === 'dark' ? 'dark' : 'light';
        html.dataset.studentLanguage = preferences.language === 'fil' ? 'fil' : 'en';
        html.lang = html.dataset.studentLanguage;
        document.querySelectorAll('.student-info-container').forEach(area => {
            area.dataset.studentTheme = html.dataset.studentTheme;
            area.dataset.studentLanguage = html.dataset.studentLanguage;
        });
        observer.disconnect(); refresh(); observe();
        window.dispatchEvent(new CustomEvent('kns-student-presentation-changed',{detail:{
            theme:html.dataset.studentTheme,language:html.dataset.studentLanguage}}));
    }
    window.KNSStudentPortal = Object.freeze({apply,refresh,t:translate});
    refresh(); observe();
})();
