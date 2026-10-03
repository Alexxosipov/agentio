/* Agentio dashboard: polls the JSON endpoints and renders them without a page reload. No dependencies. */
(() => {
    'use strict';

    const cfg = JSON.parse(document.getElementById('agentio-config').textContent);
    const viewSlot = document.querySelector('[data-slot="view"]');
    const statusSlot = document.querySelector('[data-slot="status"]');
    const projectSlot = document.querySelector('[data-slot="project"]');

    const STATES = ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'Done'];
    const TYPE_SHORT = { Idea: 'ИД', Epic: 'EP', Story: 'ST', Task: 'T' };
    const TYPE_LABEL = { Idea: 'Идея', Epic: 'Эпик', Story: 'История', Task: 'Задача' };
    const STAGE_FALLBACK = [
        ['idea', 'Идея'], ['analysis', 'Требования и аналитика'], ['architecture', 'Архитектура'],
        ['decomposition', 'Декомпозиция'], ['development', 'Разработка'], ['story_review', 'Ревью историй'],
        ['acceptance', 'Приёмка человеком'], ['done', 'Готово'],
    ].map(([key, label]) => ({ key, label }));

    const S = {
        route: { view: 'overview' },
        data: {},
        errors: {},
        open: new Set(),
        expanded: new Set(),
        showDone: load('showDone', false),
        filters: load('filters', { epic: '', type: '', q: '' }),
        timer: null,
        seq: 0,
        lastSuccess: null,
        offline: false,
    };

    /* ---------- storage ---------- */

    function load(key, fallback) {
        try {
            const value = localStorage.getItem('agentio.' + key);
            return value === null ? fallback : JSON.parse(value);
        } catch (e) {
            return fallback;
        }
    }

    function save(key, value) {
        try {
            localStorage.setItem('agentio.' + key, JSON.stringify(value));
        } catch (e) {
            /* storage is optional */
        }
    }

    /* ---------- text helpers ---------- */

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function safeUrl(url) {
        return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : null;
    }

    const idPattern = new RegExp('\\b(' + String(cfg.project || '[A-Z][A-Z0-9_]*').replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '-\\d+)\\b', 'g');

    function issueHref(id) {
        return cfg.youtrackUrl ? cfg.youtrackUrl.replace(/\/+$/, '') + '/issue/' + encodeURIComponent(id) : null;
    }

    /** Link issue ids in already escaped HTML text. */
    function linkIds(html) {
        return html.replace(idPattern, (id) => {
            const href = safeUrl(issueHref(id));
            return href ? `<a class="id" href="${esc(href)}" target="_blank" rel="noopener">${id}</a>` : `<span class="id">${id}</span>`;
        });
    }

    /** Inline markdown: `code`, **bold**, issue links. The text is escaped first. */
    function inline(text) {
        const codes = [];
        let html = esc(text).replace(/`([^`]+)`/g, (_, code) => {
            codes.push(code);
            return '\u0000' + (codes.length - 1) + '\u0000';
        });
        html = linkIds(html)
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${codes[Number(i)]}</code>`);
        return html;
    }

    /** Block markdown subset: paragraphs, lists, headings, tables. */
    function md(text) {
        const lines = String(text ?? '').replace(/\r\n?/g, '\n').split('\n');
        let html = '';
        let list = false;
        let table = null;

        const flushTable = () => {
            if (table) {
                html += '<div class="md-table"><table>' + table.map((row) => '<tr>' + row.map((cell) => `<td>${cell}</td>`).join('') + '</tr>').join('') + '</table></div>';
                table = null;
            }
        };

        for (const raw of lines) {
            const line = raw.replace(/\s+$/, '');

            if (/^\s*\|.*\|$/.test(line)) {
                if (!/^\s*\|[\s:|-]+\|$/.test(line)) {
                    (table = table || []).push(line.trim().slice(1, -1).split('|').map((cell) => inline(cell.trim())));
                }
                continue;
            }
            flushTable();

            const item = line.match(/^\s*(?:[-*]|\d+\.)\s+(.*)$/);
            if (item) {
                if (!list) {
                    html += '<ul>';
                    list = true;
                }
                html += `<li>${inline(item[1])}</li>`;
                continue;
            }
            if (list) {
                html += '</ul>';
                list = false;
            }
            if (line.trim() === '') {
                continue;
            }
            const heading = line.match(/^#{1,6}\s+(.*)$/);
            html += heading ? `<p class="md-h">${inline(heading[1])}</p>` : `<p>${inline(line)}</p>`;
        }
        flushTable();

        return html + (list ? '</ul>' : '');
    }

    /* ---------- time helpers ---------- */

    const pad = (n) => String(n).padStart(2, '0');

    function date(iso) {
        if (!iso) {
            return null;
        }
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function clock(iso, withSeconds = false) {
        const d = date(iso);
        if (!d) {
            return '';
        }
        const time = pad(d.getHours()) + ':' + pad(d.getMinutes()) + (withSeconds ? ':' + pad(d.getSeconds()) : '');
        const now = new Date();
        return d.toDateString() === now.toDateString() ? time : pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + ' ' + time;
    }

    function fullTime(iso) {
        const d = date(iso);
        return d ? d.toLocaleString('ru-RU') : '';
    }

    function duration(ms) {
        if (ms === null || ms === undefined || ms < 0) {
            return '';
        }
        const total = Math.round(ms / 1000);
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        if (h > 0) {
            return `${h} ч ${m} мин`;
        }
        if (m > 0) {
            return `${m} мин${m < 10 && s ? ` ${s} с` : ''}`;
        }
        return `${s} с`;
    }

    function ago(iso) {
        const d = date(iso);
        if (!d) {
            return '';
        }
        const diff = Date.now() - d.getTime();
        if (diff < 45000) {
            return 'только что';
        }
        if (diff < 86400000) {
            return duration(diff).replace(/ \d+ с$/, '') + ' назад';
        }
        return clock(iso);
    }

    function money(usd) {
        return typeof usd === 'number' ? '$' + usd.toFixed(2) : '';
    }

    /* ---------- small components ---------- */

    const slug = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-');

    function idLink(id, url) {
        const href = safeUrl(url) || safeUrl(issueHref(id));
        return href
            ? `<a class="id" href="${esc(href)}" target="_blank" rel="noopener" title="Открыть в YouTrack">${esc(id)}</a>`
            : `<span class="id">${esc(id)}</span>`;
    }

    function typeBadge(type) {
        return type ? `<span class="type t-${slug(type)}" title="${esc(TYPE_LABEL[type] || type)}">${esc(TYPE_SHORT[type] || type.slice(0, 2))}</span>` : '';
    }

    function stateBadge(state) {
        return state ? `<span class="badge state s-${slug(state)}">${esc(state)}</span>` : '';
    }

    function ownerChip(owner, since) {
        if (!owner) {
            return '';
        }
        const short = owner.replace(/^([^:]+):.*\/([^/]+)$/, '$1:…/$2');
        const title = owner + (since ? ' · с ' + fullTime(since) : '');
        return `<span class="owner" title="${esc(title)}"><span>${esc(short)}</span>${since ? `<span class="faint">· ${esc(clock(since))}</span>` : ''}</span>`;
    }

    function summaryLink(issue) {
        const isEpicLike = issue.type === 'Epic' || issue.type === 'Story' || issue.type === 'Idea';
        return isEpicLike
            ? `<a class="summary" href="#/epic/${encodeURIComponent(issue.id)}" title="${esc(issue.summary)}">${esc(issue.summary)}</a>`
            : `<span class="summary" title="${esc(issue.summary)}">${esc(issue.summary)}</span>`;
    }

    function skeleton() {
        return '<div class="skeleton"><i></i><i></i><i></i></div>';
    }

    function empty(html) {
        return `<div class="empty">${html}</div>`;
    }

    function stagesOf(data) {
        return (data && data.stages) || STAGE_FALLBACK;
    }

    function progressText(status) {
        const t = status && status.tasks;
        return t && t.total ? `${t.done}/${t.total}` : '';
    }

    function segments(status, stages, { text = true } = {}) {
        const position = status ? status.position : -1;
        const done = status && status.stage === 'done';
        return stages.map((stage, i) => {
            const cls = [
                'seg',
                done ? 'done-all' : i < position ? 'past' : '',
                i === position ? 'current' : '',
                i === position && status.blocked ? 'blocked' : '',
            ].filter(Boolean).join(' ');
            let label = '';
            if (text && i === position) {
                label = ['development', 'story_review'].includes(stage.key) ? progressText(status) : '';
                label = label || (status.blocked ? '!' : '●');
            }
            return `<div class="${cls}" title="${esc(stage.label)}">${esc(label)}</div>`;
        }).join('');
    }

    function stageLine(status) {
        if (!status) {
            return '';
        }
        const progress = progressText(status);
        return `<b>${esc(status.stageLabel)}</b>${progress ? ` · ${progress} задач` : ''}${status.note ? ` · ${inline(status.note)}` : ''}`;
    }

    function progressBar(byState, total) {
        if (!total) {
            return '<div class="progress"></div>';
        }
        const parts = [['Done', 'p-done'], ['Review', 'p-review'], ['In Progress', 'p-progress'], ['Ready', 'p-ready'], ['Blocked', 'p-blocked']];
        return '<div class="progress">' + parts.map(([state, cls]) => {
            const n = byState[state] || 0;
            return n ? `<i class="${cls}" style="width:${(n / total) * 100}%" title="${esc(state)}: ${n}"></i>` : '';
        }).join('') + '</div>';
    }

    /* ---------- notices ---------- */

    function youtrackNotice() {
        const yt = firstYoutrack();
        if (!yt) {
            return '';
        }
        if (!yt.configured) {
            return '<div class="notice info"><div><b>YouTrack не настроен.</b> Задайте <code>YOUTRACK_URL</code> и <code>YOUTRACK_TOKEN</code> в окружении приложения — без них видны только локальные сессии и журнал цикла.</div></div>';
        }
        if (!yt.ok) {
            return `<div class="notice"><div><b>Нет данных из YouTrack.</b> ${esc(yt.error || 'Ошибка запроса.')} Показаны локальные данные; запрос повторится автоматически.</div></div>`;
        }
        return '';
    }

    function firstYoutrack() {
        const order = ['status', 'sessions', 'pipeline', 'board', 'events', 'epic:' + (S.route.id || '')];
        let found = null;
        for (const key of order) {
            const yt = S.data[key] && S.data[key].youtrack;
            if (yt && (!yt.ok || !found)) {
                found = yt;
                if (!yt.ok) {
                    break;
                }
            }
        }
        return found;
    }

    function requestNotice(keys) {
        const errors = keys.map((key) => S.errors[key]).filter(Boolean);
        if (!errors.length) {
            return '';
        }
        const forbidden = errors.some((e) => e.status === 401 || e.status === 403);
        return `<div class="notice"><div><b>${forbidden ? 'Нет доступа к панели.' : 'Сервер не ответил.'}</b> ${esc(errors[0].message)}${S.lastSuccess ? ` Данные на ${esc(clock(S.lastSuccess.toISOString(), true))}.` : ''}</div></div>`;
    }

    /* ---------- header ---------- */

    function renderStatus() {
        const st = S.data.status;
        if (st) {
            const url = safeUrl(st.project && st.project.url);
            patch(projectSlot, url
                ? `<a href="${esc(url)}" target="_blank" rel="noopener" title="Проект в YouTrack">${esc(st.project.key)} ↗</a>`
                : esc(st.project.key));
        }

        const pills = [];
        if (st) {
            const loop = st.loop;
            const cls = { running: 'ok running', stopping: 'warn', stopped: '' }[loop.status] || '';
            const label = loop.status === 'stopping' ? 'останавливается (стоп-флаг)' : loop.label;
            pills.push(`<span class="pill ${cls}" title="Автономный цикл agent-loop.sh"><span class="dot"></span>Цикл: <b>${esc(label)}</b>${loop.pid ? ` <span class="muted">pid ${esc(loop.pid)}</span>` : ''}</span>`);
            if (loop.stopRequested && loop.status !== 'stopping') {
                pills.push('<span class="pill warn" title="Файл .agent-stop: цикл не продолжит работу"><span class="dot"></span>стоп-флаг</span>');
            }
            pills.push(`<span class="pill" title="Живые сессии Claude Code"><span>Сессий: <b>${esc(st.sessions.alive)}</b></span></span>`);
            pills.push(`<span class="pill" title="MERGE_POLICY"><span>Слияние: <b>${esc(st.mergePolicy)}</b></span></span>`);
            const yt = st.youtrack;
            if (!yt.configured) {
                pills.push('<span class="pill warn" title="Задайте YOUTRACK_URL и YOUTRACK_TOKEN"><span class="dot"></span>YouTrack не настроен</span>');
            } else if (!yt.ok) {
                pills.push(`<span class="pill err" title="${esc(yt.error)}"><span class="dot"></span>YouTrack: ошибка</span>`);
            } else {
                pills.push('<span class="pill ok" title="Подключение к YouTrack в порядке"><span class="dot"></span>YouTrack</span>');
            }
        }
        if (S.offline) {
            pills.push(`<span class="pill err"><span class="dot"></span>нет связи${S.lastSuccess ? ' · ' + esc(clock(S.lastSuccess.toISOString(), true)) : ''}</span>`);
        } else if (S.lastSuccess) {
            pills.push(`<span class="pill pill-muted" title="Обновляется каждые ${esc(cfg.poll)} с">обновлено ${esc(clock(S.lastSuccess.toISOString(), true))}</span>`);
        } else {
            pills.push('<span class="pill pill-muted">загрузка…</span>');
        }
        patch(statusSlot, pills.join(''));
    }

    /* ---------- overview: sessions ---------- */

    function eventWhat(e) {
        switch (e.type) {
            case 'tool_use':
                return esc(String(e.tool || '').replace(/^mcp__([^_]+(?:-[^_]+)*)__/, (_, server) => (server === 'youtrack' ? 'yt' : server.replace('laravel-', '')) + ':'));
            case 'subagent':
                return '⇢ ' + esc(e.subagent || 'subagent');
            case 'text':
                return 'сообщение';
            case 'tool_error':
                return 'ошибка';
            case 'permission_denied':
                return 'запрет ' + esc(e.tool || '');
            case 'task_finished':
                return 'готово' + (e.status && e.status !== 'completed' ? ' · ' + esc(e.status) : '');
            case 'session_start':
                return 'запуск';
            case 'init':
                return 'init';
            default:
                return esc(e.type);
        }
    }

    function eventRow(e) {
        let text = e.type === 'text' ? inline(String(e.text).replace(/```[a-z]*/g, ' ')) : linkIds(esc(e.text));
        if (e.type === 'init') {
            text = `${esc(e.model || '')} <span class="faint">${esc(e.text)}</span>`;
        }
        const detail = e.detail ? `<span class="detail" title="${esc(e.detail)}">${esc(e.detail)}</span>` : '';
        const title = e.inSubagent && e.subagent ? ` title="Внутри субагента: ${esc(e.subagent)}"` : '';
        return `<li class="event e-${esc(e.type)}${e.inSubagent ? ' sub' : ''}"${title}>`
            + `<span class="time">${esc(clock(e.time, true).slice(-8))}</span>`
            + `<span class="what" title="${esc(e.tool || e.subagent || e.type)}">${eventWhat(e)}</span>`
            + `<span class="text"><span class="clamp-2">${text}</span>${detail}</span></li>`;
    }

    function resultFoot(result, extra = '') {
        if (!result) {
            return extra ? `<div class="session-foot">${extra}</div>` : '';
        }
        const first = String(result.text || '').split('\n').find((line) => line.trim() !== '' && !line.trim().startsWith('```')) || '';
        return '<div class="session-foot">'
            + `<span>Итог хода${result.time ? ' ' + esc(clock(result.time)) : ''}</span>`
            + (result.costUsd !== null ? `<span>стоимость <b>${esc(money(result.costUsd))}</b></span>` : '')
            + (result.durationMs !== null ? `<span>длительность <b>${esc(duration(result.durationMs))}</b></span>` : '')
            + (result.turns !== null ? `<span>ходов <b>${esc(result.turns)}</b></span>` : '')
            + (result.status ? `<span class="${result.isError ? 'err-text' : ''}">${esc(result.status)}</span>` : '')
            + extra
            + (first ? `<div class="result-text clamp-2">${inline(first)}</div>` : '')
            + '</div>';
    }

    function sessionTitle(s) {
        const issue = s.issue || { id: s.issueId, summary: '', type: s.kind === 'plan' ? 'Idea' : 'Epic' };
        return `<div class="line">${typeBadge(issue.type)}${idLink(issue.id, issue.url)}${issue.summary ? summaryLink(issue) : '<span class="summary muted">нет данных из YouTrack</span>'}${stateBadge(issue.state)}</div>`;
    }

    function sessionCard(s, stages) {
        const issue = s.issue;
        const status = issue && issue.status;
        const kind = s.kind === 'plan' ? 'Планирование · /plan' : 'Эпик · /work-epic';
        const running = s.startedAt ? `идёт ${esc(duration(Date.now() - date(s.startedAt).getTime()))}` : '';
        const current = issue && issue.current && issue.current.length
            ? '<div class="current-tasks">' + issue.current.map((t) => `<div class="current-task">${typeBadge(t.type)}${idLink(t.id, t.url)}<span class="summary" title="${esc(t.summary)}">${esc(t.summary)}</span>${stateBadge(t.state)}${ownerChip(t.owner, t.since)}</div>`).join('') + '</div>'
            : '';

        return `<article class="session live">`
            + `<div class="session-head"><div class="session-title">${sessionTitle(s)}`
            + `<div class="session-facts"><span>${kind}</span>${issue ? ownerChip(issue.owner, issue.since) : ''}</div></div>`
            + `<div class="session-facts"><span>pid <b>${esc(s.pid)}</b></span>${s.startedAt ? `<span>с <b>${esc(clock(s.startedAt))}</b> · ${running}</span>` : ''}${s.restarts ? `<span>перезапусков: <b>${esc(s.restarts)}</b></span>` : ''}</div></div>`
            + (status ? `<div class="session-stage"><div class="label">Этап: ${stageLine(status)}</div><div class="mini-stages">${segments(status, stages, { text: false })}</div></div>` : '')
            + (status && status.blocked ? `<div class="session-stage"><div class="pipe-blocked"><b>Заблокировано:</b> ${inline(status.blockedReason || '')}</div></div>` : '')
            + current
            + (s.events.length ? `<ul class="events">${s.events.map(eventRow).join('')}</ul>` : '<div class="empty">Событий в логе пока нет.</div>')
            + resultFoot(s.lastResult, s.log && s.log.updatedAt ? `<span>лог обновлён ${esc(ago(s.log.updatedAt))}</span>` : '')
            + '</article>';
    }

    function recentCard(s) {
        const key = 'recent:' + s.name;
        const open = S.open.has(key) ? ' open' : '';
        const r = s.lastResult;
        const facts = [
            s.kind === 'plan' ? 'планирование' : 'эпик',
            s.log && s.log.updatedAt ? 'завершена ' + esc(ago(s.log.updatedAt)) : '',
            r && r.costUsd !== null ? esc(money(r.costUsd)) : '',
            r && r.durationMs !== null ? esc(duration(r.durationMs)) : '',
        ].filter(Boolean).join(' · ');
        return `<details class="fold session" data-key="${esc(key)}"${open}>`
            + `<summary><div class="session-title">${sessionTitle(s)}<div class="session-facts">${facts}</div></div></summary>`
            + (s.events.length ? `<ul class="events">${s.events.map(eventRow).join('')}</ul>` : '')
            + resultFoot(r)
            + '</details>';
    }

    function renderSessions() {
        const d = S.data.sessions;
        if (!d) {
            return S.errors.sessions ? empty('Не удалось загрузить сессии.') : skeleton();
        }
        const stages = stagesOf(S.data.pipeline);
        const st = S.data.status;
        let html = '';

        if (d.sessions.length) {
            html += `<div class="panel-body sessions">${d.sessions.map((s) => sessionCard(s, stages)).join('')}</div>`;
        } else if (st && !st.logs.exists) {
            html += empty(`Каталог логов не найден: <code>${esc(st.logs.path)}</code>. Логи появятся после первого запуска цикла (<code>php artisan agentio:run</code>).`);
        } else {
            html += empty(st && st.loop.status === 'stopped'
                ? '<strong>Сейчас никто не работает.</strong> Цикл остановлен — запустите <code>php artisan agentio:run</code>.'
                : '<strong>Активных сессий нет.</strong> Цикл ждёт готовых эпиков и идей.');
        }

        if (d.claimed.length) {
            html += `<div class="panel-body"><div class="subhead" style="margin-top:0">Захвачено агентами · ${d.claimed.length}</div><div class="claims">`
                + d.claimed.map((c) => `<div class="claim-row">${typeBadge(c.type)}${idLink(c.id, c.url)}<span class="summary" title="${esc(c.summary)}">${esc(c.summary)}</span>${stateBadge(c.state)}${c.owner ? ownerChip(c.owner, c.since) : '<span class="owner"><span>владелец не указан</span></span>'}</div>`).join('')
                + '</div></div>';
        }

        if (d.recent.length) {
            html += `<div class="panel-body"><div class="subhead" style="margin-top:0">Последние сессии</div><div class="sessions">${d.recent.map(recentCard).join('')}</div></div>`;
        }

        return html;
    }

    /* ---------- overview: pipeline ---------- */

    function pipeRow(item, stages, child) {
        const issue = item.issue;
        const status = item.status;
        const meta = [
            stateBadge(issue.state),
            `<span class="pipe-stage-mobile">${stageLine(status)}</span>`,
            ownerChip(issue.owner, issue.since),
        ];
        return `<div class="pipe-row${child ? ' child' : ''}">`
            + `<div class="pipe-issue"><div class="pipe-title">${typeBadge(issue.type)}${idLink(issue.id, issue.url)}${summaryLink(issue)}</div>`
            + `<div class="pipe-meta">${meta.join('')}${status.note ? `<span class="pipe-note-desktop">${inline(status.note)}</span>` : ''}</div></div>`
            + segments(status, stages)
            + (status.blocked ? `<div class="pipe-blocked"><span><b>Заблокировано.</b> ${inline(status.blockedReason || 'Причина не указана.')}</span></div>` : '')
            + '</div>';
    }

    function renderPipeline() {
        const d = S.data.pipeline;
        if (!d) {
            return S.errors.pipeline ? empty('Не удалось загрузить конвейер.') : skeleton();
        }
        if (!d.youtrack.configured) {
            return empty('Конвейер строится по задачам YouTrack — <strong>YouTrack не настроен</strong>.');
        }
        const stages = stagesOf(d);
        const items = d.items.filter((item) => S.showDone || item.active);
        if (!d.items.length) {
            return empty(d.youtrack.ok ? 'Идей и эпиков пока нет. Создайте задачу с типом Idea или меткой <code>idea</code>.' : 'Нет данных из YouTrack.');
        }
        if (!items.length) {
            return empty('Все идеи и эпики завершены. Включите «завершённые», чтобы их увидеть.');
        }
        return `<div class="pipe-head"><span>Идея / эпик</span>${stages.map((s) => `<span>${esc(s.label)}</span>`).join('')}</div>`
            + items.map((item) => `<div class="pipe-group">${pipeRow(item, stages, false)}${item.epics.map((epic) => pipeRow(epic, stages, true)).join('')}</div>`).join('');
    }

    /* ---------- events feed ---------- */

    function feed(events, { compactSummary = false } = {}) {
        if (!events.length) {
            return empty('Событий агентов пока нет.');
        }
        return '<ul class="feed">' + events.map((e) => {
            const key = 'ev:' + e.issueId + ':' + e.kind + ':' + e.createdAt;
            const expanded = S.expanded.has(key);
            const isStart = e.kind === 'START';
            const branch = isStart ? (e.body.match(/^branch:\s*`?([^`\n]+)`?\s*$/mi) || [])[1] : null;
            const body = isStart ? e.body.replace(/^(owner|branch|worktree):.*$/gmi, '').trim() : e.body;
            const long = body.length > 260 || body.split('\n').length > 4;
            return '<li class="feed-item">'
                + `<div class="feed-line"><span class="badge k-${esc(slug(e.kind))}">${esc(e.kind)}</span>${idLink(e.issueId, e.url)}`
                + (e.issueSummary && !compactSummary ? `<span class="summary" title="${esc(e.issueSummary)}">${esc(e.issueSummary)}</span>` : '')
                + `<time class="time" datetime="${esc(e.createdAt)}" title="${esc(fullTime(e.createdAt))}${e.author ? ' · ' + esc(e.author) : ''}">${esc(ago(e.createdAt))}</time></div>`
                + (e.owner || branch ? `<div class="feed-meta">${ownerChip(e.owner)}${branch ? `<span class="faint mono" title="Ветка">${esc(branch)}</span>` : ''}</div>` : '')
                + (body ? `<div class="feed-body${long && !expanded ? ' clamped' : ''}">${md(body)}</div>` : '')
                + (long ? `<button type="button" class="more" data-action="expand" data-key="${esc(key)}">${expanded ? 'свернуть' : 'показать полностью'}</button>` : '')
                + '</li>';
        }).join('') + '</ul>';
    }

    function renderEvents() {
        const d = S.data.events;
        if (!d) {
            return S.errors.events ? empty('Не удалось загрузить события.') : skeleton();
        }
        if (!d.youtrack.configured) {
            return empty('Лента строится по комментариям <code>[AGENT:*]</code> в YouTrack — YouTrack не настроен.');
        }
        return feed(d.events);
    }

    /* ---------- loop log ---------- */

    function logClass(message) {
        if (/agent loop started/.test(message)) {
            return 'l-start';
        }
        if (/agent loop stopped/.test(message)) {
            return 'l-stop';
        }
        if (/fail|error|ошибк|SIGTERM|SIGKILL/i.test(message)) {
            return 'l-err';
        }
        if (/Review|review/.test(message)) {
            return 'l-review';
        }
        return '';
    }

    function renderLoopLog(limit) {
        const d = S.data.loopLog;
        if (!d) {
            return S.errors.loopLog ? empty('Не удалось прочитать loop.log.') : skeleton();
        }
        if (!d.exists) {
            return empty('Файла <code>loop.log</code> нет — цикл ещё не запускался.');
        }
        const entries = limit ? d.entries.slice(-limit) : d.entries;
        if (!entries.length) {
            return empty('Журнал пуст.');
        }
        return '<ul class="loglines">' + entries.map((e) => `<li class="logline ${logClass(e.message)}"><span class="time" title="${esc(fullTime(e.time))}">${esc(e.time ? clock(e.time, true) : '')}</span><span class="msg">${linkIds(esc(e.message))}</span></li>`).join('') + '</ul>';
    }

    /* ---------- board ---------- */

    function boardOptions(d) {
        const epics = d.epics.map((e) => `<option value="${esc(e.id)}">${esc(e.id)} · ${esc(e.summary.slice(0, 60))}</option>`).join('');
        return `<option value="">Все эпики</option>${epics}<option value="-">Без эпика</option>`;
    }

    function renderBoard() {
        const d = S.data.board;
        if (!d) {
            return S.errors.board ? empty('Не удалось загрузить доску.') : skeleton();
        }
        if (!d.youtrack.configured) {
            return empty('Доска строится по задачам YouTrack — <strong>YouTrack не настроен</strong>.');
        }
        const f = S.filters;
        const q = f.q.trim().toLowerCase();
        const match = (card) => (!f.type || card.type === f.type)
            && (!f.epic || (f.epic === '-' ? !card.epicId : card.epicId === f.epic))
            && (!q || (card.id + ' ' + card.summary + ' ' + (card.owner || '')).toLowerCase().includes(q));
        let shown = 0;
        const columns = d.columns.map((column) => {
            const cards = column.issues.filter(match);
            shown += cards.length;
            const limit = column.state === 'Done' && !q ? 60 : Infinity;
            const body = cards.slice(0, limit).map((c) => `<article class="card${c.claimed ? ' claimed' : ''}">`
                + `<div class="card-top">${typeBadge(c.type)}${idLink(c.id, c.url)}${c.epicId && c.epicId !== c.id ? `<a class="epic" href="#/epic/${encodeURIComponent(c.epicId)}" title="Эпик">${esc(c.epicId)}</a>` : ''}</div>`
                + `<div class="card-title">${['Epic', 'Story', 'Idea'].includes(c.type) ? `<a href="#/epic/${encodeURIComponent(c.id)}" style="color:inherit">${esc(c.summary)}</a>` : esc(c.summary)}</div>`
                + (c.owner ? ownerChip(c.owner, c.since) : c.claimed ? '<span class="owner"><span>захвачена</span></span>' : '')
                + (c.unmetDependencies && c.unmetDependencies.length && c.state !== 'Done' ? `<div class="card-wait">ждёт ${linkIds(esc(c.unmetDependencies.join(', ')))}</div>` : '')
                + '</article>').join('') + (cards.length > limit ? `<div class="empty">и ещё ${cards.length - limit}</div>` : '');
            return `<section class="column c-${slug(column.state)}"><div class="column-head"><span class="bullet"></span>${esc(column.state)}<span class="count">${cards.length}</span></div>`
                + `<div class="column-body">${body || '<div class="empty">—</div>'}</div></section>`;
        }).join('');
        patch(viewSlot.querySelector('[data-slot="board-count"]'), `${shown} задач`);
        const select = viewSlot.querySelector('[data-filter="epic"]');
        if (select && patch(select, boardOptions(d))) {
            select.value = f.epic;
        }
        return `<div class="board">${columns}</div>`;
    }

    /* ---------- epic ---------- */

    function treeNode(node, depth) {
        const extra = [
            ownerChip(node.owner, node.since),
            node.ready ? '<span class="tag-ready">готова к взятию</span>' : '',
            node.unmetDependencies.length && node.state !== 'Done' ? `<span class="tag-wait">ждёт ${linkIds(esc(node.unmetDependencies.join(', ')))}</span>` : '',
            node.dependsOn.length && !node.unmetDependencies.length && node.type === 'Task' && node.state !== 'Done' ? `<span>зависимости выполнены: ${linkIds(esc(node.dependsOn.join(', ')))}</span>` : '',
        ].filter(Boolean).join('');
        return `<li><div class="node ${slug(node.type)}" style="--depth:${depth}">${typeBadge(node.type)}${idLink(node.id, node.url)}<span class="summary" title="${esc(node.summary)}">${esc(node.summary)}</span>${stateBadge(node.state)}<div class="extra">${extra}</div></div>`
            + (node.children.length ? `<ul class="tree">${node.children.map((child) => treeNode(child, depth + 1)).join('')}</ul>` : '')
            + '</li>';
    }

    function renderEpic() {
        const id = S.route.id;
        const key = 'epic:' + id;
        const d = S.data[key];
        const error = S.errors[key];
        if (error && error.status === 404) {
            const html = empty(`Задача <strong>${esc(id)}</strong> не найдена в YouTrack.`);
            ['epic-head', 'tree', 'stories', 'ready', 'waiting', 'epic-events'].forEach((slot, i) => patchSlot(slot, i ? '' : html));
            return;
        }
        if (!d) {
            ['epic-head', 'tree', 'stories', 'ready', 'waiting', 'epic-events'].forEach((slot) => patchSlot(slot, error ? empty('Не удалось загрузить эпик.') : skeleton()));
            return;
        }
        const e = d.epic;
        if (!e) {
            const html = empty(d.youtrack.configured ? 'Нет данных из YouTrack.' : 'YouTrack не настроен.');
            ['epic-head', 'tree', 'stories', 'ready', 'waiting', 'epic-events'].forEach((slot, i) => patchSlot(slot, i ? '' : html));
            return;
        }
        const stages = stagesOf(S.data.pipeline);
        const total = Object.values(e.progress).reduce((a, b) => a + b, 0);
        const status = e.status;
        const legend = Object.entries(e.progress).map(([state, n]) => `<span class="${{ Done: 'p-done', Review: 'p-review', 'In Progress': 'p-progress', Ready: 'p-ready', Blocked: 'p-blocked' }[state] || ''}">${esc(state || 'без статуса')}: ${n}</span>`).join('');

        patchSlot('epic-head', '<div class="epic-head">'
            + `<h1>${typeBadge(e.type)}${idLink(e.id, e.url)}<span>${esc(e.summary)}</span>${stateBadge(e.state)}</h1>`
            + `<div class="epic-facts">${status ? `<span>Этап: ${stageLine(status)}</span>` : ''}${e.owner ? `<span>Захвачен: ${ownerChip(e.owner, e.since)}</span>` : ''}</div>`
            + (status ? `<div class="pipe-row" style="padding:0;grid-template-columns:repeat(8,minmax(0,1fr))">${segments(status, stages)}</div><div class="pipe-row pipe-labels" style="padding:0;grid-template-columns:repeat(8,minmax(0,1fr))">${stages.map((s, i) => `<span class="small ${i === status.position ? '' : 'faint'}" style="text-align:center;line-height:1.2">${esc(s.label)}</span>`).join('')}</div>` : '')
            + (status && status.blocked ? `<div class="pipe-blocked"><span><b>Заблокировано.</b> ${inline(status.blockedReason || 'Причина не указана.')}</span></div>` : '')
            + (total ? `${progressBar(e.progress, total)}<div class="legend">${legend}</div>` : '')
            + '</div>');

        patchSlot('tree-count', total ? `${total} задач` : '');
        patchSlot('tree', e.tree.children.length ? `<ul class="tree">${e.tree.children.map((child) => treeNode(child, 0)).join('')}</ul>` : empty('Историй и задач пока нет — эпик ещё декомпозируется.'));
        patchSlot('stories', e.stories.length ? e.stories.map((s) => `<div class="story-row"><div class="line">${idLink(s.id, s.url)}<span class="summary" title="${esc(s.summary)}">${esc(s.summary)}</span>${stateBadge(s.state)}<span class="muted small nowrap">${s.done}/${s.total}</span></div>${progressBar(s.byState, s.total)}</div>`).join('') : empty('Историй нет.'));
        patchSlot('ready', e.readyTasks.length ? '<div class="list-rows">' + e.readyTasks.map((t) => `<div class="list-row">${typeBadge(t.type)}${idLink(t.id, t.url)}<span class="summary" title="${esc(t.summary)}">${esc(t.summary)}</span></div>`).join('') + '</div>' : empty('Готовых к взятию задач нет.'));
        patchSlot('waiting', e.waiting.length ? '<div class="list-rows">' + e.waiting.map((w) => `<div class="list-row">${idLink(w.id)}${stateBadge(w.state)}<span class="muted">ждёт</span> ${linkIds(esc(w.waitingFor.join(', ')))}</div>`).join('') + '</div>' : empty('Никто не ждёт зависимостей.'));
        patchSlot('epic-events', feed(e.events));
    }

    /* ---------- layout ---------- */

    const LAYOUTS = {
        overview: () => '<div data-slot="notice"></div><div class="grid-overview"><div class="col">'
            + '<section class="panel"><div class="panel-head"><h2>Сейчас работают</h2><span class="count" data-slot="sessions-count"></span></div><div data-slot="sessions"></div></section>'
            + '<section class="panel"><div class="panel-head"><h2>Конвейер</h2><span class="count" data-slot="pipeline-count"></span><div class="tools">'
            + `<label class="switch"><input type="checkbox" data-action="show-done"${S.showDone ? ' checked' : ''}> завершённые</label></div></div><div class="panel-body flush" data-slot="pipeline"></div></section>`
            + '</div><div class="col">'
            + '<section class="panel"><div class="panel-head"><h2>Лента событий</h2><span class="count">[AGENT:*]</span></div><div class="scroll scroll-feed" data-slot="events"></div></section>'
            + '<section class="panel"><div class="panel-head"><h2>loop.log</h2><div class="tools"><a href="#/log">весь журнал →</a></div></div><div class="scroll scroll-log" data-slot="looptail" data-autoscroll></div></section>'
            + '</div></div>',
        board: () => '<div data-slot="notice"></div><div class="board-tools">'
            + '<select data-filter="epic" aria-label="Эпик"></select>'
            + '<select data-filter="type" aria-label="Тип"><option value="">Все типы</option>'
            + ['Idea', 'Epic', 'Story', 'Task'].map((t) => `<option value="${t}">${TYPE_LABEL[t]}</option>`).join('') + '</select>'
            + `<input type="search" data-filter="q" placeholder="Поиск: ID, заголовок, владелец" value="${esc(S.filters.q)}" aria-label="Поиск">`
            + '<span class="count" data-slot="board-count"></span></div><div data-slot="board"></div>',
        log: () => '<div data-slot="notice"></div><section class="panel"><div class="panel-head"><h2>Журнал цикла · loop.log</h2><span class="count">последние строки, новые внизу</span></div><div class="scroll scroll-log tall" data-slot="log" data-autoscroll></div></section>',
        epic: () => '<a class="back" href="#/">← к обзору</a><div data-slot="notice"></div>'
            + '<section class="panel" data-slot="epic-head"></section>'
            + '<div class="epic-grid"><div class="col">'
            + '<section class="panel"><div class="panel-head"><h2>Истории и задачи</h2><span class="count" data-slot="tree-count"></span></div><div data-slot="tree"></div></section>'
            + '<section class="panel"><div class="panel-head"><h2>События</h2><span class="count">[AGENT:*] по эпику</span></div><div class="scroll scroll-epic" data-slot="epic-events"></div></section>'
            + '</div><div class="col">'
            + '<section class="panel"><div class="panel-head"><h2>Прогресс историй</h2></div><div data-slot="stories"></div></section>'
            + '<section class="panel"><div class="panel-head"><h2>Готовы к взятию</h2></div><div data-slot="ready"></div></section>'
            + '<section class="panel"><div class="panel-head"><h2>Ждут зависимостей</h2></div><div data-slot="waiting"></div></section>'
            + '</div></div>',
    };

    function patch(el, html) {
        if (!el || el.__html === html) {
            return false;
        }
        el.innerHTML = html;
        el.__html = html;
        return true;
    }

    function patchSlot(name, html) {
        const el = viewSlot.querySelector(`[data-slot="${name}"]`);
        if (!el) {
            return false;
        }
        const auto = el.hasAttribute('data-autoscroll');
        const stick = el.__html === undefined || el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        const changed = patch(el, html);
        if (changed && auto && stick) {
            el.scrollTop = el.scrollHeight;
        }
        return changed;
    }

    function render() {
        renderStatus();
        const view = S.route.view;

        document.querySelectorAll('[data-tab]').forEach((tab) => {
            const active = tab.dataset.tab === view;
            tab.classList.toggle('active', active);
            if (tab.dataset.tab === 'epic') {
                tab.hidden = view !== 'epic';
                tab.textContent = view === 'epic' ? 'Эпик ' + S.route.id : '';
                tab.href = view === 'epic' ? '#/epic/' + encodeURIComponent(S.route.id) : '#/';
            }
        });

        const requestKeys = { overview: ['sessions', 'pipeline', 'events', 'loopLog'], board: ['board'], log: ['loopLog'], epic: ['epic:' + S.route.id] }[view];
        patchSlot('notice', requestNotice(['status', ...requestKeys]) || youtrackNotice());

        if (view === 'overview') {
            const d = S.data.sessions;
            patchSlot('sessions-count', d ? (d.sessions.length ? `живых: ${d.sessions.length}` : 'нет активных') : '');
            patchSlot('sessions', renderSessions());
            const p = S.data.pipeline;
            patchSlot('pipeline-count', p && p.items ? `активных: ${p.items.filter((i) => i.active).length} · всего: ${p.items.length}` : '');
            patchSlot('pipeline', renderPipeline());
            patchSlot('events', renderEvents());
            patchSlot('looptail', renderLoopLog(40));
        } else if (view === 'board') {
            patchSlot('board', renderBoard());
        } else if (view === 'log') {
            patchSlot('log', renderLoopLog(0));
        } else if (view === 'epic') {
            renderEpic();
        }
    }

    /* ---------- data ---------- */

    async function getJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok) {
            const error = new Error(response.status === 403 ? 'Доступ запрещён (403).' : `HTTP ${response.status}.`);
            error.status = response.status;
            throw error;
        }
        return response.json();
    }

    function wanted() {
        const e = cfg.endpoints;
        const view = S.route.view;
        const urls = { status: e.status };
        if (view === 'overview') {
            Object.assign(urls, { sessions: e.sessions, pipeline: e.pipeline, events: e.events, loopLog: e.loopLog });
        } else if (view === 'board') {
            urls.board = e.board;
        } else if (view === 'log') {
            urls.loopLog = e.loopLog;
        } else if (view === 'epic') {
            urls['epic:' + S.route.id] = e.epic.replace('__ID__', encodeURIComponent(S.route.id));
            if (!S.data.pipeline) {
                urls.pipeline = e.pipeline;
            }
        }
        return Object.entries(urls);
    }

    async function tick() {
        clearTimeout(S.timer);
        if (document.hidden) {
            S.timer = setTimeout(tick, cfg.poll * 1000);
            return;
        }
        const seq = ++S.seq;
        const entries = wanted();
        const results = await Promise.allSettled(entries.map(([, url]) => getJson(url)));
        if (seq !== S.seq) {
            return;
        }
        let failed = 0;
        results.forEach((result, i) => {
            const key = entries[i][0];
            if (result.status === 'fulfilled') {
                S.data[key] = result.value;
                delete S.errors[key];
            } else {
                S.errors[key] = result.reason;
                failed++;
            }
        });
        S.offline = failed === entries.length && !results.some((r) => r.reason && r.reason.status);
        if (failed < entries.length) {
            S.lastSuccess = new Date();
        }
        render();
        S.timer = setTimeout(tick, Math.max(1, Number(cfg.poll) || 5) * 1000);
    }

    /* ---------- routing & events ---------- */

    function parseRoute() {
        const hash = decodeURIComponent(location.hash.replace(/^#\/?/, ''));
        if (hash.startsWith('epic/') && /^[A-Za-z][A-Za-z0-9_]*-\d+$/.test(hash.slice(5))) {
            return { view: 'epic', id: hash.slice(5) };
        }
        return { view: ['board', 'log'].includes(hash) ? hash : 'overview' };
    }

    function route() {
        S.route = parseRoute();
        viewSlot.innerHTML = LAYOUTS[S.route.view]();
        const type = viewSlot.querySelector('[data-filter="type"]');
        if (type) {
            type.value = S.filters.type;
        }
        render();
        window.scrollTo(0, 0);
        tick();
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="expand"]');
        if (button) {
            const key = button.dataset.key;
            S.expanded.has(key) ? S.expanded.delete(key) : S.expanded.add(key);
            render();
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (target.matches('[data-action="show-done"]')) {
            S.showDone = target.checked;
            save('showDone', S.showDone);
            render();
        } else if (target.matches('[data-filter]')) {
            S.filters[target.dataset.filter] = target.value;
            save('filters', S.filters);
            render();
        }
    });

    document.addEventListener('input', (event) => {
        if (event.target.matches('[data-filter="q"]')) {
            S.filters.q = event.target.value;
            save('filters', S.filters);
            render();
        }
    });

    document.addEventListener('toggle', (event) => {
        const key = event.target.dataset && event.target.dataset.key;
        if (key) {
            event.target.open ? S.open.add(key) : S.open.delete(key);
            const slot = event.target.closest('[data-slot]');
            if (slot) {
                slot.__html = null;
            }
        }
    }, true);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            tick();
        }
    });

    window.addEventListener('hashchange', route);
    route();
})();
