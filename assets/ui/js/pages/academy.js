// /assets/ui/js/pages/academy.js
// HOD Academy (modules/academy/index.php). Two views from one page:
//   admin   — order of merit + final panels, grading, programmes/cohorts/
//             applications, courses/materials/assignments/questions, publishing
//   student — apply, wait for review, then the classroom (noticeboard,
//             timetable, materials, assignments, timed exams) or the result slip
// Every request goes to /api/academy_api.php with the same shapes as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/academy_api.php';
    var esc = HOD.esc;
    var root = document.querySelector('[data-academy-mode]');
    if (!root) return;
    var isAdmin = root.getAttribute('data-academy-mode') === 'admin';

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    function person(r) { return ((r.first_name || '') + ' ' + (r.last_name || '')).trim(); }
    function when(value, withTime) {
        if (!value) return '';
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d)) return String(value);
        return withTime
            ? d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
            : HOD.format.date(d);
    }
    function monthName(ym) {
        var m = /^(\d{4})-(\d{2})/.exec(String(ym || ''));
        if (!m) return String(ym || '');
        return new Date(Number(m[1]), Number(m[2]) - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    }
    /** Links from the database: only web addresses and paths on this site. */
    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/|\.\.?\/)/i.test(url) ? url : '';
    }
    function rowEmpty(cols, text) {
        return '<tr><td colspan="' + cols + '" class="py-8 text-center text-ink-subtle">' + esc(text) + '</td></tr>';
    }
    function listEmpty(text) { return '<li class="ui-list__item text-sm text-ink-subtle">' + esc(text) + '</li>'; }
    function button(text, action, data, variant) {
        var attrs = Object.keys(data || {}).map(function (k) { return ' data-' + k + '="' + esc(data[k]) + '"'; }).join('');
        return '<button type="button" class="ui-btn ui-btn--' + (variant || 'secondary') + ' ui-btn--sm" data-action="' + action + '"' + attrs + '>' + esc(text) + '</button>';
    }
    function loadFailed(err) {
        if (err && err.name === 'AbortError') return;
        HOD.toast((err && err.message) || 'The Academy did not load. Reload the page to try again.', 'danger');
    }

    /** Fill every <select data-academy-options="source"> with items [{id, label}]. */
    function fillOptions(source, items, placeholder) {
        document.querySelectorAll('[data-academy-options="' + source + '"]').forEach(function (sel) {
            var current = sel.value;
            sel.innerHTML = '<option value="">' + esc(placeholder) + '</option>' + items.map(function (i) {
                return '<option value="' + esc(i.id) + '">' + esc(i.label) + '</option>';
            }).join('');
            if (current && items.some(function (i) { return String(i.id) === current; })) sel.value = current;
        });
    }

    /** POST a form (FormData keeps file uploads); resolves with the response. */
    function send(form, extra) {
        var data = new FormData(form);
        Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
        var button = form.id ? (document.querySelector('button[form="' + form.id + '"]') || form.querySelector('[type="submit"]')) : form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        return HOD.api(API, { data: data }).finally(function () { HOD.busy(button, false); });
    }

    /* =================================================================== *
     * Admin
     * =================================================================== */

    function renderApplications(apps) {
        $('[data-academy-applications]').innerHTML = apps.length ? apps.map(function (a) {
            return '<tr>'
                + '<td class="is-head"><span class="ui-table__primary">' + esc(a.full_name) + '</span></td>'
                + '<td data-label="Phone" class="ui-num">' + esc(a.phone_number) + '</td>'
                + '<td data-label="Class wanted">' + esc(monthName(a.class_date) || '—') + '</td>'
                + '<td class="is-actions text-right">' + button('Approve', 'academy.approve', { id: a.id, name: a.full_name }, 'soft') + '</td>'
                + '</tr>';
        }).join('') : rowEmpty(4, 'No applications are waiting.');
    }

    function renderPublishing(courses) {
        $('[data-academy-publishing]').innerHTML = courses.length ? courses.map(function (c) {
            var shown = Number(c.is_course_published) === 1;
            var open = Number(c.is_exam_published) === 1;
            var meta = [c.program_name || 'No programme', c.instr_fname ? 'Facilitator: ' + c.instr_fname + ' ' + (c.instr_lname || '') : ''].filter(Boolean).join(' · ');
            return '<tr>'
                + '<td class="is-head"><span class="ui-table__primary">' + esc(c.name) + '</span><span class="ui-table__secondary">' + esc(meta) + '</span></td>'
                + '<td data-label="Students see it">' + (shown ? '<span class="ui-badge ui-badge--success">Shown</span>' : '<span class="ui-badge">Hidden</span>') + '</td>'
                + '<td data-label="Exam">' + (open ? '<span class="ui-badge ui-badge--success">Open</span>' : '<span class="ui-badge">Closed</span>') + '</td>'
                + '<td class="is-actions"><div class="flex flex-wrap justify-end gap-2">'
                + button(shown ? 'Hide course' : 'Show course', 'academy.publish', { kind: 'course', id: c.id, to: shown ? 0 : 1, name: c.name }, shown ? 'ghost' : 'soft')
                + button(open ? 'Close exam' : 'Open exam', 'academy.publish', { kind: 'exam', id: c.id, to: open ? 0 : 1, name: c.name }, open ? 'ghost' : 'primary')
                + '</div></td>'
                + '</tr>';
        }).join('') : rowEmpty(4, 'No courses yet. Create one above.');
    }

    function renderGrading(items) {
        $('[data-academy-grading]').innerHTML = items.length ? items.map(function (g) {
            var url = safeUrl(g.file_url);
            var body = url
                ? '<a class="ui-link mt-1 inline-flex items-center gap-1 text-sm" href="' + esc(url) + '" target="_blank" rel="noopener">' + HOD.icon('file-down') + '<span>Open the attached file</span></a>'
                : '<p class="mt-1 line-clamp-3 whitespace-pre-line text-sm text-ink-muted">' + esc(g.submission_text || '') + '</p>';
            return '<li class="ui-list__item items-start">'
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(g.title) + '</span>'
                + '<span class="ui-list__meta">' + esc(person(g)) + (g.submitted_at ? ' · ' + esc(when(g.submitted_at)) : '') + '</span>' + body + '</span>'
                + '<span class="ui-list__end">' + button('Grade', 'academy.grade', { id: g.id, name: g.title }, 'primary') + '</span>'
                + '</li>';
        }).join('') : listEmpty('Nothing to grade. New hand-ins will appear here.');
    }

    function loadAdmin() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            var d = res.data || {};
            fillOptions('programs', (d.programs || []).map(function (p) { return { id: p.id, label: p.name }; }), 'Choose a programme');
            fillOptions('courses', (d.courses || []).map(function (c) { return { id: c.id, label: c.name + (c.program_name ? ' · ' + c.program_name : '') }; }), 'Choose a course');
            fillOptions('batches', (d.active_batches || []).map(function (b) { return { id: b.id, label: b.batch_name + (b.program_name ? ' · ' + b.program_name : '') }; }), 'Choose a cohort');
            renderApplications(d.pending_applications || []);
            renderPublishing(d.courses || []);
            renderGrading(d.pending_grading || []);
        }).catch(loadFailed);
    }

    function loadInstructors() {
        HOD.api(API, { action: 'fetch_setup_data', quiet: true }).then(function (res) {
            fillOptions('instructors', (res.instructors || []).map(function (i) { return { id: i.id, label: person(i) }; }), 'No facilitator yet');
        }).catch(function () { fillOptions('instructors', [], 'No facilitator yet'); });
    }

    var meritBatch = '';
    function loadMerit() {
        var area = $('[data-academy-merit]');
        if (!meritBatch) return;
        area.innerHTML = '<div class="ui-card__body"><span class="ui-skeleton ui-skeleton--text w-2/3"></span><span class="ui-skeleton ui-skeleton--text mt-2 w-1/2"></span></div>';
        HOD.api(API, { action: 'fetch_batch_merit_list', data: { batch_id: meritBatch }, quiet: true }).then(function (res) {
            var total = Number(res.total_events) || 0;
            var rows = (res.students || []).map(function (s, i) {
                var att = Number(s.attended_events) || 0;
                var good = total > 0 && att / total >= 0.7;
                var exam = s.avg_exam_score !== null && s.avg_exam_score !== undefined ? Number(s.avg_exam_score).toFixed(1) + '%' : '—';
                var panel = s.interview_score !== null && s.interview_score !== undefined
                    ? '<span class="ui-num font-medium">' + esc(s.interview_score) + '/30</span>'
                    : button('Score panel', 'academy.panel', { id: s.enrollment_id, name: person(s) }, 'soft');
                var act = s.graduation_status === 'Graduated'
                    ? '<span class="ui-badge ui-badge--success">Graduated</span>'
                    : button('Graduate', 'academy.graduate', { id: s.student_id, name: person(s) }, 'primary');
                return '<tr>'
                    + '<td data-label="Rank" class="ui-num text-ink-subtle">#' + (i + 1) + '</td>'
                    + '<td class="is-head"><span class="ui-table__primary">' + esc(person(s)) + '</span></td>'
                    + '<td data-label="Attendance" class="ui-num' + (good ? ' text-success' : '') + '">' + att + '/' + total + '</td>'
                    + '<td data-label="Exam average" class="ui-num">' + exam + '</td>'
                    + '<td data-label="Panel">' + panel + '</td>'
                    + '<td class="is-actions text-right">' + act + '</td>'
                    + '</tr>';
            });
            area.innerHTML = '<div class="ui-table-wrap border-0"><table class="ui-table ui-table--stack"><thead><tr>'
                + '<th scope="col">Rank</th><th scope="col">Student</th><th scope="col">Attendance</th><th scope="col">Exam average</th><th scope="col">Panel</th><th scope="col"><span class="sr-only">Actions</span></th>'
                + '</tr></thead><tbody>' + (rows.length ? rows.join('') : rowEmpty(6, 'No students in this cohort yet.')) + '</tbody></table></div>';
        }).catch(function (err) {
            area.innerHTML = '';
            loadFailed(err);
        });
    }

    function initAdmin() {
        HOD.actions.on('academy.merit', function (sel) { meritBatch = sel.value; loadMerit(); });

        HOD.actions.on('academy.material-type', function (sel) {
            document.querySelectorAll('[data-academy-material]').forEach(function (box) {
                var on = box.getAttribute('data-academy-material') === sel.value;
                box.hidden = !on;
                box.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
            });
        });

        HOD.actions.on('academy.save', function (form) {
            send(form).then(function (res) {
                HOD.toast(res.message || 'Saved.', 'success');
                var dialog = form.closest('dialog');
                if (dialog) HOD.dialog.close(dialog.id);
                form.reset();
                if (form.id === 'ac-material-form') HOD.actions.run('academy.material-type', form.material_type);
                if (form.id === 'ac-panel-form') loadMerit();
                loadAdmin();
            }).catch(function () { /* HOD.api showed the message */ });
        });

        HOD.actions.on('academy.approve', function (btn) {
            var form = $('#ac-approve-form');
            form.application_id.value = btn.getAttribute('data-id');
            $('[data-academy-approve-name]').textContent = btn.getAttribute('data-name');
            HOD.dialog.open('ac-approve', btn);
        });

        HOD.actions.on('academy.panel', function (btn) {
            var form = $('#ac-panel-form');
            form.reset();
            form.enrollment_id.value = btn.getAttribute('data-id');
            $('[data-academy-panel-name]').textContent = btn.getAttribute('data-name');
            HOD.dialog.open('ac-panel', btn);
        });

        HOD.actions.on('academy.graduate', function (btn) {
            var name = btn.getAttribute('data-name');
            HOD.confirm({
                title: 'Graduate ' + name + '?',
                message: name + ' becomes a Worker and leaves this cohort as a graduate.',
                confirmLabel: 'Graduate'
            }).then(function (ok) {
                if (!ok) return;
                HOD.busy(btn, true);
                HOD.api(API, { action: 'graduate_student', data: { student_id: btn.getAttribute('data-id'), batch_id: meritBatch } })
                    .then(function (res) { HOD.toast(res.message || 'Graduated.', 'success'); loadMerit(); })
                    .catch(function () {})
                    .finally(function () { HOD.busy(btn, false); });
            });
        });

        HOD.actions.on('academy.publish', function (btn) {
            var exam = btn.getAttribute('data-kind') === 'exam';
            var to = btn.getAttribute('data-to');
            var name = btn.getAttribute('data-name');
            var copy = exam
                ? (to === '1' ? ['Open the exam for ' + name + '?', 'Students can start it straight away.', 'Open exam']
                              : ['Close the exam for ' + name + '?', 'Students who have not started it will not be able to.', 'Close exam'])
                : (to === '1' ? ['Show ' + name + ' to students?', 'Its materials appear in their study room.', 'Show course']
                              : ['Hide ' + name + ' from students?', 'Its materials leave their study room until you show it again.', 'Hide course']);
            HOD.confirm({ title: copy[0], message: copy[1], confirmLabel: copy[2] }).then(function (ok) {
                if (!ok) return;
                HOD.busy(btn, true);
                HOD.api(API, { action: exam ? 'publish_exam' : 'publish_course', data: { course_id: btn.getAttribute('data-id'), publish_status: to } })
                    .then(function (res) { HOD.toast(res.message || 'Updated.', 'success'); loadAdmin(); })
                    .catch(function () {})
                    .finally(function () { HOD.busy(btn, false); });
            });
        });

        HOD.actions.on('academy.grade', function (btn) {
            HOD.prompt({
                title: 'Grade “' + btn.getAttribute('data-name') + '”',
                label: 'Score out of 100',
                required: true,
                confirmLabel: 'Save grade'
            }).then(function (value) {
                if (value === null || value === undefined || value === false) return;
                var score = Number(value);
                if (!isFinite(score) || score < 0 || score > 100) {
                    HOD.toast('Enter a score from 0 to 100.', 'warning');
                    return;
                }
                HOD.api(API, { action: 'grade_assignment', data: { submission_id: btn.getAttribute('data-id'), score: score } })
                    .then(function (res) { HOD.toast(res.message || 'Graded.', 'success'); loadAdmin(); })
                    .catch(function () {});
            });
        });

        loadAdmin();
        loadInstructors();
    }

    /* =================================================================== *
     * Student
     * =================================================================== */

    var enrollment = null;

    function show(state) {
        document.querySelectorAll('[data-academy-state]').forEach(function (el) {
            el.hidden = el.getAttribute('data-academy-state') !== state;
        });
    }

    function renderForum(messages) {
        var box = $('[data-academy-forum]');
        box.innerHTML = messages.length ? '<ol class="ui-stack ui-stack--sm">' + messages.map(function (m) {
            return '<li>'
                + '<p class="flex flex-wrap items-baseline gap-x-2 text-sm"><span class="font-semibold text-ink">' + esc(person(m)) + '</span>'
                + (m.spiritual_status === 'Worker' ? '<span class="ui-badge ui-badge--primary">Worker</span>' : '')
                + '<span class="ui-meta">' + esc(when(m.created_at, true)) + '</span></p>'
                + '<p class="mt-0.5 whitespace-pre-line text-sm text-ink-muted">' + esc(m.message) + '</p>'
                + '</li>';
        }).join('') + '</ol>' : '<p class="ui-meta">No messages yet. Say hello to your cohort.</p>';
        box.scrollTop = box.scrollHeight;
    }

    function loadForum() {
        if (!enrollment) return;
        HOD.api(API, { action: 'fetch_forum', data: { batch_id: enrollment.batch_id }, quiet: true })
            .then(function (res) { renderForum(res.messages || []); })
            .catch(function () {});
    }

    function renderClassroom(d) {
        $('[data-academy-batch-name]').textContent = [enrollment.batch_name, enrollment.program_name].filter(Boolean).join(' · ');
        $('[data-academy-batch-id]').value = enrollment.batch_id;

        var events = d.events || [];
        $('[data-academy-events]').innerHTML = events.length ? events.map(function (e) {
            return '<li class="ui-list__item"><span class="ui-list__icon">' + HOD.icon('calendar') + '</span>'
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(e.title) + '</span>'
                + '<span class="ui-list__meta">' + esc(when(e.event_date, true)) + '</span></span></li>';
        }).join('') : listEmpty('No classes scheduled yet.');

        var assignments = d.assignments || [];
        $('[data-academy-assignments]').innerHTML = assignments.length ? assignments.map(function (a) {
            var end = a.my_score !== null && a.my_score !== undefined
                ? '<span class="ui-badge ui-badge--success">Graded ' + esc(a.my_score) + '/100</span>'
                : button('Hand in', 'academy.open-submit', { id: a.id, name: a.title }, 'primary');
            return '<li class="ui-list__item"><span class="ui-list__main"><span class="ui-list__title">' + esc(a.title) + '</span>'
                + '<span class="ui-list__meta">' + esc(label(a.assignment_type)) + (a.deadline ? ' · due ' + esc(when(a.deadline, true)) : '') + '</span></span>'
                + '<span class="ui-list__end">' + end + '</span></li>';
        }).join('') : listEmpty('No assignments right now.');

        var courses = d.courses || [];
        $('[data-academy-courses]').innerHTML = courses.length ? courses.map(function (c) {
            var mats = (c.materials || []).map(function (m) {
                var url = safeUrl(m.file_url);
                var icon = m.material_type === 'Link' ? 'video' : 'file-down';
                return url
                    ? '<li><a class="ui-list__item" href="' + esc(url) + '" target="_blank" rel="noopener"><span class="ui-list__icon">' + HOD.icon(icon) + '</span><span class="ui-list__main"><span class="ui-list__title">' + esc(m.title) + '</span></span><span class="ui-list__end">' + HOD.icon('external-link') + '</span></a></li>'
                    : '<li class="ui-list__item"><span class="ui-list__icon">' + HOD.icon(icon) + '</span><span class="ui-list__main"><span class="ui-list__title">' + esc(m.title) + '</span></span></li>';
            }).join('');
            return '<section class="ui-card"><div class="ui-card__header ui-card__header--divided"><div><h3 class="ui-card__title">' + esc(c.name) + '</h3>'
                + (c.description ? '<p class="ui-card__description">' + esc(c.description) + '</p>' : '') + '</div></div>'
                + '<ul class="ui-list">' + (mats || listEmpty('No materials yet.')) + '</ul></section>';
        }).join('') : '<div class="ui-card ui-span-full">' + '<p class="ui-card__body ui-meta">Your courses will appear here once they are published.</p></div>';

        $('[data-academy-exams]').innerHTML = courses.length ? courses.map(function (c) {
            var s = c.exam_status;
            var end;
            if (s && s.status === 'Passed') end = '<span class="ui-badge ui-badge--success">Passed · ' + Number(s.score).toFixed(0) + '%</span>';
            else if (s && s.status === 'Failed') end = '<span class="ui-badge ui-badge--danger">Not passed · ' + Number(s.score).toFixed(0) + '%</span>';
            else if (Number(c.is_exam_published) === 1) end = button('Start exam', 'academy.start-exam', { id: c.id, name: c.name, minutes: c.timer_minutes || 30 }, 'primary');
            else end = '<span class="ui-badge">Not open yet</span>';
            return '<li class="ui-list__item"><span class="ui-list__icon">' + HOD.icon('clipboard-list') + '</span>'
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(c.name) + '</span>'
                + '<span class="ui-list__meta">' + esc(c.timer_minutes || 30) + ' minutes</span></span>'
                + '<span class="ui-list__end">' + end + '</span></li>';
        }).join('') : listEmpty('No exams yet.');
    }

    function loadStudent() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            var d = res.data || {};
            if (d.status === 'Graduated') return show('graduated');
            if (d.status === 'Application_Pending') return show('pending');
            if (d.status === 'Enrolled') {
                if (!d.enrollment) return show('missing');
                enrollment = d.enrollment;
                show('enrolled');
                renderClassroom(d);
                loadForum();
                return;
            }
            show('apply');
        }).catch(function (err) { show('apply'); loadFailed(err); });
    }

    /* ---------- timed exam ---------- */

    var exam = null; // { courseId, deadline, timer }

    function leaveGuard(event) { event.preventDefault(); event.returnValue = ''; }

    function tick() {
        var left = Math.max(0, Math.round((exam.deadline - Date.now()) / 1000));
        var out = $('[data-academy-timer]');
        out.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
        out.classList.toggle('text-danger', left <= 60);
        if (left === 0) submitExam($('#ac-exam-form'), true);
    }

    function renderExam(course, name, questions, minutes) {
        $('[data-academy-exam-title]').textContent = name;
        $('[data-academy-questions]').innerHTML = questions.map(function (q, i) {
            var opts = ['A', 'B', 'C', 'D'].filter(function (k) { return q['option_' + k.toLowerCase()]; }).map(function (k) {
                return '<label class="ui-check"><input type="radio" name="q-' + esc(q.id) + '" value="' + k + '"><span><strong class="mr-1">' + k + '.</strong>' + esc(q['option_' + k.toLowerCase()]) + '</span></label>';
            }).join('');
            return '<div class="ui-card"><div class="ui-card__body"><fieldset class="ui-fieldset" data-question="' + esc(q.id) + '">'
                + '<legend class="ui-legend"><span class="ui-num mr-1 text-ink-subtle">' + (i + 1) + '.</span>' + esc(q.question) + '</legend>'
                + '<div class="ui-stack ui-stack--sm">' + opts + '</div></fieldset></div></div>';
        }).join('');
        $('[data-academy-exam-list]').hidden = true;
        $('[data-academy-exam-result]').hidden = true;
        $('[data-academy-exam]').hidden = false;
        exam = { courseId: course, deadline: Date.now() + minutes * 60000, timer: null };
        exam.timer = setInterval(tick, 1000);
        tick();
        window.addEventListener('beforeunload', leaveGuard);
        $('[data-academy-exam]').scrollIntoView({ block: 'start' });
    }

    function startExam(btn) {
        var name = btn.getAttribute('data-name');
        var minutes = Number(btn.getAttribute('data-minutes')) || 30;
        HOD.confirm({
            title: 'Start the ' + name + ' exam?',
            message: 'You have ' + minutes + ' minutes and one attempt. Stay on this page until you submit; your answers are sent when time runs out.',
            confirmLabel: 'Start exam'
        }).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'start_exam', data: { course_id: btn.getAttribute('data-id') } }).then(function (res) {
                if (!res.questions || !res.questions.length) {
                    HOD.toast('This exam has no questions yet. Please tell the Academy team.', 'warning');
                    return;
                }
                renderExam(btn.getAttribute('data-id'), name, res.questions, Number(res.timer_minutes) || minutes);
            }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    }

    function finishExam() {
        if (exam && exam.timer) clearInterval(exam.timer);
        window.removeEventListener('beforeunload', leaveGuard);
    }

    function submitExam(form, timeUp) {
        if (!exam || exam.sending) return;
        var answers = {};
        var unanswered = 0;
        form.querySelectorAll('[data-question]').forEach(function (fs) {
            var picked = fs.querySelector('input:checked');
            answers[fs.getAttribute('data-question')] = picked ? picked.value : null;
            if (!picked) unanswered++;
        });
        var go = timeUp || unanswered === 0 ? Promise.resolve(true) : HOD.confirm({
            title: 'Submit with ' + unanswered + ' unanswered?',
            message: 'Unanswered questions count as wrong.',
            confirmLabel: 'Submit anyway'
        });
        go.then(function (ok) {
            if (!ok) return;
            exam.sending = true;
            if (timeUp) { clearInterval(exam.timer); HOD.toast('Time is up. Your answers are being submitted.', 'warning'); }
            var btn = form.querySelector('[type="submit"]');
            HOD.busy(btn, true);
            HOD.api(API, { action: 'submit_exam', data: { course_id: exam.courseId, answers: JSON.stringify(answers) } }).then(function (res) {
                finishExam();
                showResult(res);
            }).catch(function () {
                exam.sending = false;
            }).finally(function () { HOD.busy(btn, false); });
        });
    }

    function showResult(res) {
        var passed = res.final_status === 'Passed';
        $('[data-academy-result-icon]').innerHTML = HOD.icon(passed ? 'circle-check' : 'circle-x');
        $('[data-academy-result-icon]').className = 'ui-empty__icon mx-auto ' + (passed ? 'text-success' : 'text-danger');
        $('[data-academy-result-title]').textContent = passed ? 'You passed' : 'Not passed this time';
        $('[data-academy-result-text]').textContent = 'Your score: ' + Number(res.final_score || 0).toFixed(1) + '%';
        $('[data-academy-result-breakdown]').innerHTML = (res.breakdown || []).map(function (b, i) {
            return '<li class="flex items-center justify-between gap-3 rounded-ui border border-line px-3 py-2 text-sm">'
                + '<span>Question ' + (i + 1) + '</span>'
                + (b.is_correct
                    ? '<span class="ui-badge ui-badge--success">Correct</span>'
                    : '<span class="ui-badge ui-badge--danger">You: ' + esc(b.your_answer || '—') + ' · Answer: ' + esc(b.correct_answer) + '</span>')
                + '</li>';
        }).join('');
        $('[data-academy-exam]').hidden = true;
        $('[data-academy-exam-result]').hidden = false;
        exam = null;
    }

    function initStudent() {
        HOD.actions.on('academy.marital', function (sel) {
            var box = $('[data-academy-anniversary]');
            box.hidden = sel.value !== 'Married';
            if (box.hidden) box.querySelector('input').value = '';
        });

        HOD.actions.on('academy.apply', function (form) {
            send(form).then(function (res) {
                HOD.toast(res.message || 'Application sent.', 'success');
                show('pending');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }).catch(function () {});
        });

        HOD.actions.on('academy.forum', loadForum);
        HOD.actions.on('academy.post', function (form) {
            send(form).then(function () {
                form.message.value = '';
                loadForum();
            }).catch(function () {});
        });

        HOD.actions.on('academy.open-submit', function (btn) {
            var form = $('#ac-submit-form');
            form.reset();
            form.assignment_id.value = btn.getAttribute('data-id');
            $('[data-academy-submit-title]').textContent = btn.getAttribute('data-name');
            $('[data-academy-submit-error]').textContent = '';
            HOD.dialog.open('ac-submit', btn);
        });
        HOD.actions.on('academy.hand-in', function (form) {
            if (!form.submission_text.value.trim() && !form.submission_file.files.length) {
                $('[data-academy-submit-error]').textContent = 'Type a response or attach a file.';
                return;
            }
            send(form).then(function (res) {
                HOD.toast(res.message || 'Handed in.', 'success');
                HOD.dialog.close('ac-submit');
                loadStudent();
            }).catch(function () {});
        });

        HOD.actions.on('academy.start-exam', startExam);
        HOD.actions.on('academy.submit-exam', function (form) { submitExam(form, false); });
        HOD.actions.on('academy.exam-done', function () {
            $('[data-academy-exam-result]').hidden = true;
            $('[data-academy-exam-list]').hidden = false;
            loadStudent();
        });

        HOD.actions.on('academy.print', function () { window.print(); });

        loadStudent();
    }

    function init() {
        if (isAdmin) initAdmin(); else initStudent();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
