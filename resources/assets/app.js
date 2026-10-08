/* Agentio dashboard: polls the JSON endpoints and renders them without a page reload. No dependencies. */
(() => {
    'use strict';

    const cfg = JSON.parse(document.getElementById('agentio-config').textContent);
    const viewSlot = document.querySelector('[data-slot="view"]');
    const statusSlot = document.querySelector('[data-slot="status"]');
    const projectSlot = document.querySelector('[data-slot="project"]');

    const STATES = ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Review', 'Blocked', 'On Hold', 'Done'];
    // The stages an epic may be paused in (EpicPause::PAUSABLE).
    const PAUSABLE = ['Backlog', 'Analysis', 'Ready', 'In Progress', 'Blocked'];
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
        // Per project: dashboards of several projects on one origin (localhost:8000) share localStorage.
        filters: load('filters.' + cfg.project, { epic: '', type: '', q: '' }),
        modal: null,
        timer: null,
        seq: 0,
        forms: {},
        results: {},
        diffs: {},
        busy: null,
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
                pills.push('<span class="pill warn" title="Стоп-флаг (php artisan agentio:run --stop): цикл не продолжит работу"><span class="dot"></span>стоп-флаг</span>');
            }
            if (loop.usageLimit) {
                const resumesAt = loop.usageLimit.resumesAt ? new Date(loop.usageLimit.resumesAt) : null;
                const time = resumesAt ? resumesAt.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
                pills.push(`<span class="pill warn" title="Claude Code упёрся в ${esc(loop.usageLimit.label)}: цикл не запускает сессии, задачи остаются в своих статусах и продолжатся после сброса"><span class="dot"></span>Пауза: лимит Claude Code${time ? ` до <b>${esc(time)}</b>` : ''}</span>`);
            }
            pills.push(`<span class="pill" title="Живые сессии Claude Code"><span>Сессий: <b>${esc(st.sessions.alive)}</b></span></span>`);
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
        const kind = s.kind === 'plan' ? 'Планирование · /agentio-plan' : 'Эпик · /agentio-work-epic';
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
            + holdLine(item.kind, status)
            + '</div>';
    }

    function holdLine(kind, status) {
        if (!status || !status.onHold) {
            return '';
        }
        return kind === 'idea'
            ? '<div class="pipe-hold"><span><b>Отложенная идея.</b> Системный анализ — в статье «Идеи», в разработку не взята.</span></div>'
            : '<div class="pipe-hold"><span><b>На паузе.</b> Агенты эпик не берут; захват и worktree сохранены.</span></div>';
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
        if (f.epic && f.epic !== '-' && d.youtrack.ok && !d.epics.some((e) => e.id === f.epic)) {
            // An epic that is gone (or of another project) would hide every card while the select shows "Все эпики".
            f.epic = '';
            save('filters.' + cfg.project, f);
        }
        const q = f.q.trim().toLowerCase();
        const match = (card) => (!f.type || card.type === f.type)
            && (!f.epic || (f.epic === '-' ? !card.epicId : card.epicId === f.epic))
            && (!q || (card.id + ' ' + card.summary + ' ' + (card.owner || '')).toLowerCase().includes(q));
        let shown = 0;
        const columns = d.columns.map((column) => {
            const cards = column.issues.filter(match);
            shown += cards.length;
            const limit = column.state === 'Done' && !q ? 60 : Infinity;
            const body = cards.slice(0, limit).map((c) => `<article class="card${c.claimed ? ' claimed' : ''}" data-issue="${esc(c.id)}" tabindex="0" role="button" aria-haspopup="dialog" title="Лог выполнения ${esc(c.id)}">`
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
            + holdLine('epic', status)
            + (total ? `${progressBar(e.progress, total)}<div class="legend">${legend}</div>` : '')
            + pauseControls(e)
            + '</div>');

        patchSlot('tree-count', total ? `${total} задач` : '');
        patchSlot('tree', e.tree.children.length ? `<ul class="tree">${e.tree.children.map((child) => treeNode(child, 0)).join('')}</ul>` : empty('Историй и задач пока нет — эпик ещё декомпозируется.'));
        patchSlot('stories', e.stories.length ? e.stories.map((s) => `<div class="story-row"><div class="line">${idLink(s.id, s.url)}<span class="summary" title="${esc(s.summary)}">${esc(s.summary)}</span>${stateBadge(s.state)}<span class="muted small nowrap">${s.done}/${s.total}</span></div>${progressBar(s.byState, s.total)}</div>`).join('') : empty('Историй нет.'));
        patchSlot('ready', e.readyTasks.length ? '<div class="list-rows">' + e.readyTasks.map((t) => `<div class="list-row">${typeBadge(t.type)}${idLink(t.id, t.url)}<span class="summary" title="${esc(t.summary)}">${esc(t.summary)}</span></div>`).join('') + '</div>' : empty('Готовых к взятию задач нет.'));
        patchSlot('waiting', e.waiting.length ? '<div class="list-rows">' + e.waiting.map((w) => `<div class="list-row">${idLink(w.id)}${stateBadge(w.state)}<span class="muted">ждёт</span> ${linkIds(esc(w.waitingFor.join(', ')))}</div>`).join('') + '</div>' : empty('Никто не ждёт зависимостей.'));
        patchSlot('epic-events', feed(e.events));
    }

    /* ---------- pause of an epic ---------- */

    function pauseControls(e) {
        const res = S.results['pause:' + e.id];
        const dependents = e.dependents && e.dependents.length
            ? `<div class="dependents">Ждут этот эпик: ${e.dependents.map((d) => `${idLink(d.id, d.url)} ${stateBadge(d.state)}${d.via ? ` <span class="faint">через ${esc(d.via)}</span>` : ''}`).join(', ')}</div>`
            : '';
        const review = S.data['review:' + e.id];
        const allowed = cfg.actions && (!review || review.actions !== false);
        let buttons = '';
        if (allowed && e.state === 'On Hold') {
            buttons = `<button type="button" class="btn primary" data-action="resume"${S.busy ? ' disabled' : ''}>${S.busy === 'resume' ? 'Снимаю паузу…' : '▶ Продолжить'}</button>`
                + '<span class="small muted">Эпик на паузе: агенты его не берут, захват и worktree сохранены.</span>';
        } else if (allowed && PAUSABLE.includes(e.state)) {
            buttons = `<button type="button" class="btn" data-action="pause"${S.busy ? ' disabled' : ''}>${S.busy === 'pause' ? 'Ставлю на паузу…' : '⏸ Пауза после текущей волны'}</button>`
                + `<button type="button" class="btn" data-action="pause-now"${S.busy ? ' disabled' : ''}>${S.busy === 'pause-now' ? 'Останавливаю…' : '⏹ Остановить сейчас'}</button>`;
        }
        const result = res ? `<div class="notice ${res.ok ? 'ok' : ''}"><div>${linkIds(esc(res.message)).replace(/\n/g, '<br>')}</div></div>` : '';
        if (!buttons && !dependents && !result) {
            return '';
        }
        return `<div class="epic-pause">${buttons ? `<div class="pause-buttons">${buttons}</div>` : ''}${dependents}${result}</div>`;
    }

    async function actPause(kind) {
        const id = S.route.id;
        const epic = (S.data['epic:' + id] || {}).epic;
        if (!epic || S.busy !== null) {
            return;
        }
        const waiting = epic.dependents && epic.dependents.length ? `\n\nПока он на паузе, стоят и зависимые эпики: ${epic.dependents.map((d) => d.id).join(', ')}.` : '';
        const question = {
            pause: `Поставить эпик ${id} на паузу? Агенты доделают текущую волну задач и остановятся.${waiting}`,
            'pause-now': `Остановить эпик ${id} сейчас? Сессия по нему прервётся, незаконченные задачи продолжатся после паузы.${waiting}`,
            resume: `Снять паузу с эпика ${id}?`,
        }[kind];
        if (!window.confirm(question)) {
            return;
        }
        S.busy = kind;
        delete S.results['pause:' + id];
        render();
        try {
            const data = await postJson(cfg.endpoints[kind === 'resume' ? 'resume' : 'pause'].replace('__ID__', encodeURIComponent(id)), kind === 'resume' ? {} : { now: kind === 'pause-now' });
            S.results['pause:' + id] = { ok: true, message: data.message };
        } catch (e) {
            S.results['pause:' + id] = { ok: false, message: e.message };
        }
        S.busy = null;
        tick();
    }

    /* ---------- review & acceptance ---------- */

    const FILE_STATUS = { A: 'добавлен', M: 'изменён', D: 'удалён', T: 'тип изменён' };
    const VERDICT = { APPROVED: ['одобрена', 'v-ok'], CHANGES_REQUESTED: ['есть замечания', 'v-warn'], BLOCKED: ['заблокирована', 'v-err'] };

    function reviewForm(id) {
        return S.forms[id] || (S.forms[id] = { removeWorktree: true, deleteBranch: false, close: true, story: '', remark: '' });
    }

    function plural(n, one, few, many) {
        const mod10 = n % 10;
        const mod100 = n % 100;
        return n + ' ' + (mod10 === 1 && mod100 !== 11 ? one : mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? few : many);
    }

    function diffView(diff) {
        if (!diff) {
            return '<div class="empty">загрузка…</div>';
        }
        if (diff.error) {
            return `<div class="empty err-text">${esc(diff.error)}</div>`;
        }
        const lines = diff.diff.replace(/\n$/, '').split('\n').map((line) => {
            const cls = /^(diff |index |--- |\+\+\+ |new file|deleted file|old mode|new mode|similarity|Binary files)/.test(line) ? 'd-meta'
                : line.startsWith('@@') ? 'd-hunk' : line.startsWith('+') ? 'd-add' : line.startsWith('-') ? 'd-del' : '';
            return `<span class="${cls}">${esc(line) || ' '}</span>`;
        });
        return `<pre class="diff">${lines.join('\n')}</pre>${diff.truncated ? '<div class="empty">Дифф обрезан: он слишком большой для панели.</div>' : ''}`;
    }

    function fileRow(id, file) {
        const key = 'diff:' + id + ':' + file.path;
        const open = S.open.has(key);
        const counts = file.added === null ? '<span class="faint">бинарный</span>'
            : `<span class="add">+${esc(file.added)}</span><span class="del">−${esc(file.deleted)}</span>`;
        return `<details class="fold file" data-key="${esc(key)}" data-diff="${esc(file.path)}"${open ? ' open' : ''}>`
            + `<summary><span class="fstatus f-${esc(file.status)}" title="${esc(FILE_STATUS[file.status] || file.status)}">${esc(file.status)}</span>`
            + `<span class="path mono" title="${esc(file.path)}">${esc(file.path)}</span><span class="counts">${counts}</span></summary>`
            + (open ? diffView(S.diffs[key]) : '')
            + '</details>';
    }

    function checkRow(check) {
        const [icon, cls] = check.ok === true ? ['✓', 'c-ok'] : check.ok === false ? ['✗', 'c-err'] : ['?', 'c-unknown'];
        return `<li class="check ${cls}"><span class="icon" aria-hidden="true">${icon}</span><span>${esc(check.label)}${check.detail ? ` <span class="muted">— ${linkIds(esc(check.detail))}</span>` : ''}</span></li>`;
    }

    function reviewBody(r, id) {
        const b = r.branch;
        if (!b) {
            return empty(`В главном каталоге нет ветки <code>epic/${esc(id)}-*</code>: эпик ещё не начат, или ветку удалили после слияния.`);
        }
        const facts = [
            `<span class="mono"><b>${esc(b.name)}</b> → ${esc(r.base)}</span>`,
            b.head ? `<span class="mono faint">${esc(b.head)}</span>` : '',
            b.merged ? `<span class="badge v-ok">уже в ${esc(r.base)}</span>` : '',
            r.pullRequest && safeUrl(r.pullRequest.url) ? `<a class="mono" href="${esc(r.pullRequest.url)}" target="_blank" rel="noopener">PR #${esc(r.pullRequest.number)}</a>${r.pullRequest.state === 'MERGED' ? ' <span class="badge v-ok">слит</span>' : r.pullRequest.draft ? ' <span class="badge">draft</span>' : ''}` : '',
            `<span>${esc(plural(b.ahead, 'коммит', 'коммита', 'коммитов'))} · ${esc(plural(b.files.length, 'файл', 'файла', 'файлов'))} · <span class="add">+${esc(b.added)}</span> <span class="del">−${esc(b.deleted)}</span></span>`,
            b.behind ? `<span class="warn-text">${esc(r.base)} ушла вперёд на ${esc(plural(b.behind, 'коммит', 'коммита', 'коммитов'))}</span>` : '',
        ].filter(Boolean).join('');
        const wt = b.worktree;
        const worktree = wt && wt.exists
            ? `<div class="review-worktree small muted">Worktree: <span class="mono">${esc(wt.path)}</span>${safeUrl(wt.url) ? ` · запустить: <code>cd ${esc(wt.path)} &amp;&amp; php artisan serve --port=${esc((wt.url.match(/:(\d+)/) || [])[1] || '8000')}</code> → <a href="${esc(wt.url)}" target="_blank" rel="noopener">${esc(wt.url)}</a>` : ''}</div>`
            : '';

        const summaryKey = 'review-summary:' + id;
        const expanded = S.expanded.has(summaryKey);
        const summary = r.summary
            ? `<div class="subhead">Итог оркестратора · [AGENT:DONE] <span class="faint" title="${esc(fullTime(r.summary.createdAt))}">${esc(ago(r.summary.createdAt))}</span></div>`
              + `<div class="feed-body${expanded ? '' : ' clamped'}">${md(r.summary.body)}</div>`
              + `<button type="button" class="more" data-action="expand" data-key="${esc(summaryKey)}">${expanded ? 'свернуть' : 'показать полностью'}</button>`
            : '';
        const stories = r.stories.length
            ? '<div class="subhead">Истории</div><div class="review-stories">' + r.stories.map((s) => {
                const [label, cls] = VERDICT[s.verdict] || ['ревью не было', ''];
                return `<div class="list-row">${idLink(s.id, s.url)}<span class="summary" title="${esc(s.summary)}">${esc(s.summary)}</span>${stateBadge(s.state)}<span class="badge ${cls}" title="${esc(s.verdictAt ? fullTime(s.verdictAt) : '')}">${esc(label)}</span></div>`;
            }).join('') + '</div>'
            : '';

        const commitsKey = 'commits:' + id;
        const commits = b.commits.length
            ? `<details class="fold review-fold" data-key="${esc(commitsKey)}"${S.open.has(commitsKey) ? ' open' : ''}><summary>Коммиты · ${esc(b.commits.length)}${b.ahead > b.commits.length ? ` из ${esc(b.ahead)}` : ''}</summary><ul class="commits">`
              + b.commits.map((c) => `<li><span class="mono faint" title="${esc(c.hash)}">${esc(c.short)}</span><span class="subject">${linkIds(esc(c.subject))}</span><span class="faint small nowrap" title="${esc(fullTime(c.date))}">${esc(c.author)} · ${esc(clock(c.date))}</span></li>`).join('')
              + '</ul></details>'
            : '';
        const filesKey = 'files:' + id;
        const files = b.files.length
            ? `<details class="fold review-fold" data-key="${esc(filesKey)}"${S.open.has(filesKey) || !S.open.has('closed:' + filesKey) ? ' open' : ''}><summary>Изменённые файлы · ${esc(b.files.length)} <span class="faint small">нажмите на файл, чтобы увидеть дифф</span></summary><div class="files">`
              + b.files.map((f) => fileRow(id, f)).join('')
              + '</div></details>'
            : '';

        return `<div class="review-head"><div class="review-facts">${facts}</div>${worktree}</div>`
            + `<div class="review-grid"><div class="review-main">${summary}${stories}</div>`
            + `<div class="review-side"><div class="subhead">Проверки перед слиянием</div><ul class="checks">${r.checks.map(checkRow).join('')}</ul></div></div>`
            + commits + files;
    }

    function reviewActions(r, epic, id) {
        if (!epic || epic.state !== 'Review' || !r.branch) {
            return '';
        }
        if (!cfg.actions || !r.actions) {
            return '<div class="review-actions"><div class="muted small">Действия в панели отключены (<code>AGENTIO_UI_ACTIONS=false</code>): примите эпик по руководству, раздел 6.5.</div></div>';
        }
        const busy = S.busy !== null;
        const merged = r.branch.merged;
        const accept = '<div class="action-card"><h3>Принять</h3>'
            + `<p class="muted small">${merged
                ? `Ветка уже в <code>${esc(r.base)}</code>: останется убрать worktree и закрыть задачи.`
                : `Сливает pull request ветки <code>${esc(r.branch.name)}</code> в <code>${esc(r.base)}</code> на GitHub${r.pullRequest && r.pullRequest.state === 'OPEN' ? ` (PR #${esc(r.pullRequest.number)})` : ': ветка запушится, и PR откроется'} и обновляет локальную <code>${esc(r.base)}</code>.`}</p>`
            + '<label class="check-option"><input type="checkbox" data-field="removeWorktree"> удалить worktree эпика</label>'
            + '<label class="check-option"><input type="checkbox" data-field="deleteBranch"> удалить ветку эпика</label>'
            + '<label class="check-option"><input type="checkbox" data-field="close"> перевести истории (из Review) и эпик в Done</label>'
            + `<div class="action-foot"><button type="button" class="btn primary" data-action="accept"${busy || !r.canAccept ? ' disabled' : ''}>${S.busy === 'accept' ? 'Сливаю…' : merged ? 'Принять' : 'Принять и слить'}</button>`
            + (r.canAccept ? '' : '<span class="small muted">Сначала устраните то, что отмечено ✗.</span>') + '</div></div>';
        const options = r.stories.map((s) => `<option value="${esc(s.id)}">${esc(s.id)} · ${esc(s.summary.slice(0, 70))}</option>`).join('');
        const rework = '<div class="action-card"><h3>Вернуть на доработку</h3>'
            + '<p class="muted small">В выбранной истории появится задача с замечанием (Ready), история и эпик вернутся в Ready, и цикл продолжит эпик в том же worktree.</p>'
            + `<select data-field="story" aria-label="История"><option value="">Выберите историю…</option>${options}</select>`
            + '<textarea data-field="remark" rows="4" placeholder="Что не так и как должно быть: файл, сценарий, ожидаемое поведение"></textarea>'
            + `<div class="action-foot"><button type="button" class="btn" data-action="rework"${busy || !r.stories.length ? ' disabled' : ''}>${S.busy === 'rework' ? 'Отправляю…' : 'Вернуть на доработку'}</button></div></div>`;
        return `<div class="review-actions" data-form="${esc(id)}">${accept}${rework}</div>`;
    }

    function reviewResult(id) {
        const res = S.results[id];
        if (!res) {
            return '';
        }
        const list = (items) => (items && items.length ? `<ul>${items.map((item) => `<li>${linkIds(esc(item))}</li>`).join('')}</ul>` : '');
        return `<div class="notice ${res.ok ? 'ok' : ''}"><div><b>${linkIds(esc(res.message))}</b>${list(res.details)}${list(res.warnings)}</div></div>`;
    }

    /** Put the remembered form values back after the actions were re-rendered. */
    function restoreForm(id) {
        const form = reviewForm(id);
        viewSlot.querySelectorAll(`[data-form="${CSS.escape(id)}"] [data-field]`).forEach((el) => {
            if (el.type === 'checkbox') {
                el.checked = Boolean(form[el.dataset.field]);
            } else {
                el.value = form[el.dataset.field] ?? '';
            }
        });
    }

    function renderReview() {
        const id = S.route.id;
        const r = S.data['review:' + id];
        const epic = (S.data['epic:' + id] || {}).epic;
        const section = viewSlot.querySelector('[data-slot="review-panel"]');
        if (!section) {
            return;
        }
        section.hidden = !r || (!r.branch && !(epic && epic.state === 'Review'));
        if (section.hidden) {
            return;
        }
        patchSlot('review-count', r.branch ? (r.branch.merged ? `слита в ${esc(r.base)}` : epic && epic.state === 'Review' ? 'ждёт решения человека' : 'изменения ветки') : '');
        patchSlot('review', reviewBody(r, id));
        if (patchSlot('review-actions', reviewActions(r, epic, id))) {
            restoreForm(id);
        }
        patchSlot('review-result', reviewResult(id));
    }

    async function loadDiff(key, path) {
        const id = S.route.id;
        try {
            S.diffs[key] = await getJson(cfg.endpoints.diff.replace('__ID__', encodeURIComponent(id)) + '?file=' + encodeURIComponent(path));
        } catch (e) {
            S.diffs[key] = { error: 'Не удалось загрузить дифф: ' + e.message };
        }
        render();
    }

    function acceptedText(data) {
        return [
            data.merged ? `PR${data.pullRequest ? ' #' + data.pullRequest.number : ''} ветки ${data.branch} слит в ${data.base} (${data.commit}).` : `Ветка ${data.branch} уже была в ${data.base}.`,
            data.worktreeRemoved ? 'Worktree удалён.' : '',
            data.branchDeleted ? 'Ветка удалена.' : '',
            data.closed.length ? `В Done: ${data.closed.join(', ')}.` : '',
        ].filter(Boolean).join(' ');
    }

    async function act(kind) {
        const id = S.route.id;
        const r = S.data['review:' + id];
        const form = reviewForm(id);
        if (!r || !r.branch || S.busy !== null) {
            return;
        }
        let body;
        if (kind === 'accept') {
            const steps = [
                r.branch.merged ? null : `слить pull request ${r.branch.name} в ${r.base} на GitHub`,
                form.removeWorktree ? 'удалить worktree' : null,
                form.deleteBranch ? 'удалить ветку' : null,
                form.close ? 'закрыть истории и эпик в YouTrack' : null,
            ].filter(Boolean);
            if (!window.confirm(`Принять эпик ${id}: ${steps.join(', ') || 'ничего не менять'}?`)) {
                return;
            }
            body = { removeWorktree: form.removeWorktree, deleteBranch: form.deleteBranch, close: form.close };
        } else {
            if (!form.story || !form.remark.trim()) {
                S.results[id] = { ok: false, message: 'Выберите историю и опишите замечание.' };
                render();
                return;
            }
            if (!window.confirm(`Вернуть эпик ${id} на доработку с замечанием к ${form.story}?`)) {
                return;
            }
            body = { story: form.story, remark: form.remark };
        }

        S.busy = kind;
        delete S.results[id];
        render();
        try {
            const data = await postJson(cfg.endpoints[kind].replace('__ID__', encodeURIComponent(id)), body);
            S.results[id] = kind === 'accept'
                ? { ok: true, message: acceptedText(data), warnings: data.warnings }
                : { ok: true, message: `Создана задача ${data.task}, эпик возвращён в Ready: цикл продолжит его в том же worktree.`, warnings: data.warnings };
            if (kind === 'rework') {
                form.remark = '';
            }
        } catch (e) {
            S.results[id] = { ok: false, message: e.message, details: e.details };
        }
        S.busy = null;
        tick();
    }

    /* ---------- issue log modal ---------- */

    const modalSlot = document.createElement('div');
    modalSlot.className = 'modal-root';
    document.body.appendChild(modalSlot);

    function findCard(id) {
        const board = S.data.board;
        for (const column of (board && board.columns) || []) {
            const card = column.issues.find((c) => c.id === id);
            if (card) {
                return card;
            }
        }
        return null;
    }

    function issueLogBody(d) {
        if (!d) {
            return S.errors['issueLog:' + S.modal.id] ? empty('Не удалось загрузить лог.') : skeleton();
        }
        if (!d.session) {
            return empty('Лога выполнения нет: задачу ещё не брали в работу (или её сессия шла на другой машине).');
        }
        if (!d.events.length) {
            return empty(d.filtered
                ? `В логе сессии <code>${esc(d.session.logPath)}</code> событий по задаче нет.`
                : `Событий в <code>${esc(d.session.logPath)}</code> пока нет.`);
        }
        return `<ul class="events">${d.events.map(eventRow).join('')}</ul>`;
    }

    function renderModal() {
        if (!S.modal) {
            patch(modalSlot, '');
            return;
        }
        const id = S.modal.id;
        const d = S.data['issueLog:' + id];
        const issue = (d && d.issue) || S.modal.card || { id, summary: '', type: null, state: null };
        const href = safeUrl(d && d.url) || safeUrl(issue.url) || safeUrl(issueHref(id));
        const session = d && d.session;
        const facts = session ? [
            `<span>${session.kind === 'plan' ? 'Планирование' : 'Сессия эпика'} <b class="mono">${esc(session.logPath)}</b></span>`,
            session.alive ? '<span class="ok-text">идёт сейчас</span>' : '',
            session.updatedAt ? `<span>лог обновлён ${esc(ago(session.updatedAt))}</span>` : '',
            d.filtered ? `<span class="faint">только события ${esc(id)} и её подзадач</span>` : '',
        ].filter(Boolean).join('') : '';

        const scroller = modalSlot.querySelector('[data-modal-log]');
        const stick = !scroller || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 24;
        const changed = patch(modalSlot, '<div class="modal-backdrop" data-action="close-modal">'
            + `<section class="modal" role="dialog" aria-modal="true" aria-label="Лог выполнения ${esc(id)}">`
            + '<div class="modal-head">'
            + `<div class="line">${typeBadge(issue.type)}<span class="id">${esc(id)}</span><span class="summary" title="${esc(issue.summary)}">${esc(issue.summary)}</span>${stateBadge(issue.state)}</div>`
            + '<button type="button" class="modal-close" data-action="close-modal" aria-label="Закрыть">×</button></div>'
            + '<div class="modal-links">'
            + (href ? `<a href="${esc(href)}" target="_blank" rel="noopener">Открыть в YouTrack ↗</a>` : '')
            + (['Epic', 'Story', 'Idea'].includes(issue.type) ? `<a href="#/epic/${encodeURIComponent(id)}">Страница ${issue.type === 'Idea' ? 'идеи' : issue.type === 'Story' ? 'истории' : 'эпика'} →</a>` : '')
            + (issue.epicId && issue.epicId !== id ? `<a href="#/epic/${encodeURIComponent(issue.epicId)}">Эпик ${esc(issue.epicId)} →</a>` : '')
            + '</div>'
            + (facts ? `<div class="session-facts modal-facts">${facts}</div>` : '')
            + `<div class="modal-body scroll" data-modal-log>${issueLogBody(d)}</div>`
            + (d && d.lastResult ? resultFoot(d.lastResult) : '')
            + '</section></div>');
        const log = modalSlot.querySelector('[data-modal-log]');
        if (changed && log && stick) {
            log.scrollTop = log.scrollHeight;
        }
    }

    function openModal(id) {
        S.modal = { id, card: findCard(id), opener: document.activeElement };
        document.body.classList.add('modal-open');
        renderModal();
        const close = modalSlot.querySelector('.modal-close');
        if (close) {
            close.focus();
        }
        tick();
    }

    function closeModal() {
        if (!S.modal) {
            return;
        }
        const opener = S.modal.opener;
        S.modal = null;
        document.body.classList.remove('modal-open');
        renderModal();
        if (opener && document.contains(opener)) {
            opener.focus();
        }
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
            + '<section class="panel review" data-slot="review-panel" hidden><div class="panel-head"><h2>Ветка и приёмка</h2><span class="count" data-slot="review-count"></span></div>'
            + '<div data-slot="review"></div><div data-slot="review-actions"></div><div data-slot="review-result"></div></section>'
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
            renderReview();
        }
        renderModal();
    }

    /* ---------- data ---------- */

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(cfg.csrf ? { 'X-CSRF-TOKEN': cfg.csrf } : {}) },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(response.status === 419 ? 'Сессия истекла: обновите страницу.' : data.message || `HTTP ${response.status}.`);
            error.status = response.status;
            error.details = Array.isArray(data.details) ? data.details : [];
            throw error;
        }
        return data;
    }

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
            urls['review:' + S.route.id] = e.review.replace('__ID__', encodeURIComponent(S.route.id));
            if (!S.data.pipeline) {
                urls.pipeline = e.pipeline;
            }
        }
        if (S.modal) {
            urls['issueLog:' + S.modal.id] = e.issueLog.replace('__ID__', encodeURIComponent(S.modal.id));
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
        closeModal();
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
        const closer = event.target.closest('[data-action="close-modal"]');
        if (closer && (closer.classList.contains('modal-close') || event.target === closer)) {
            closeModal();
            return;
        }
        const card = event.target.closest('.card[data-issue]');
        if (card && !event.target.closest('a, button, select, input')) {
            openModal(card.dataset.issue);
            return;
        }
        const button = event.target.closest('[data-action="expand"]');
        if (button) {
            const key = button.dataset.key;
            S.expanded.has(key) ? S.expanded.delete(key) : S.expanded.add(key);
            render();
        }
        const action = event.target.closest('[data-action="accept"], [data-action="rework"]');
        if (action && !action.disabled) {
            act(action.dataset.action);
        }
        const pause = event.target.closest('[data-action="pause"], [data-action="pause-now"], [data-action="resume"]');
        if (pause && !pause.disabled) {
            actPause(pause.dataset.action);
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (target.matches('[data-action="show-done"]')) {
            S.showDone = target.checked;
            save('showDone', S.showDone);
            render();
        } else if (target.matches('[data-form] [data-field]')) {
            reviewForm(target.closest('[data-form]').dataset.form)[target.dataset.field] = target.type === 'checkbox' ? target.checked : target.value;
        } else if (target.matches('[data-filter]')) {
            S.filters[target.dataset.filter] = target.value;
            save('filters.' + cfg.project, S.filters);
            render();
        }
    });

    document.addEventListener('input', (event) => {
        if (event.target.matches('[data-filter="q"]')) {
            S.filters.q = event.target.value;
            save('filters.' + cfg.project, S.filters);
            render();
        } else if (event.target.matches('[data-form] textarea[data-field]')) {
            reviewForm(event.target.closest('[data-form]').dataset.form)[event.target.dataset.field] = event.target.value;
        }
    });

    document.addEventListener('toggle', (event) => {
        const key = event.target.dataset && event.target.dataset.key;
        if (key) {
            event.target.open ? S.open.add(key) : S.open.delete(key);
            event.target.open ? S.open.delete('closed:' + key) : S.open.add('closed:' + key);
            const path = event.target.dataset.diff;
            if (event.target.open && path !== undefined && !S.diffs[key]) {
                loadDiff(key, path);
            }
            const slot = event.target.closest('[data-slot]');
            if (slot) {
                slot.__html = null;
            }
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && S.modal) {
            closeModal();
        } else if ((event.key === 'Enter' || event.key === ' ') && event.target.matches && event.target.matches('.card[data-issue]')) {
            event.preventDefault();
            openModal(event.target.dataset.issue);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            tick();
        }
    });

    window.addEventListener('hashchange', route);
    route();
})();
