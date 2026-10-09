// Live traffic card (views/_traffic_live.php): polls the requests Traefik
// logged since the last poll (Live_app_state::_send_traffic) and keeps them in
// this tab only. Stats, the request list and the CSV recording are all built
// here; nothing is stored on the server.
document.addEventListener('DOMContentLoaded', function () {
    const card = document.getElementById('traffic');
    if (!card) return;

    const POLL_MS = 3000;
    const WINDOW_MS = 5 * 60 * 1000;   // stats and the live chart cover this much
    const MAX_KEPT = 20000;            // requests kept for the stats
    const MAX_SHOWN = 200;             // rows in the list
    // A recording stops itself (and downloads) at whichever comes first, so a
    // tab forgotten overnight neither grows without end nor polls all night
    const MAX_RECORDED = 200000;
    const MAX_RECORDING_MS = 60 * 60 * 1000;

    const apps = card.dataset.apps ? JSON.parse(card.dataset.apps) : null;
    const status = document.getElementById('traffic-status');
    const svg = document.getElementById('traffic-chart');
    const chartFrom = document.getElementById('traffic-chart-from');
    const chartTo = document.getElementById('traffic-chart-to');
    const chartP95 = document.getElementById('traffic-chart-p95');
    const list = document.getElementById('traffic-requests');
    const empty = document.getElementById('traffic-empty');
    const appRows = document.getElementById('traffic-apps');
    const errorsOnly = document.getElementById('traffic-errors-only');
    const recordButton = document.getElementById('traffic-record');
    const recordingLabel = document.getElementById('traffic-recording');

    let cursor = '';
    let kept = [];
    let recording = null; // {started: Date, rows: []} while recording
    let timer = null;
    let inFlight = false;

    const number = function (n) { return n.toLocaleString('en'); };
    const duration = function (ms) {
        if (ms === null) return 'n/a';
        return ms < 1000 ? Math.round(ms) + ' ms' : (ms / 1000).toFixed(1) + ' s';
    };
    const size = function (bytes) {
        const units = [['GB', 1e9], ['MB', 1e6], ['KB', 1e3]];
        for (const [unit, factor] of units) {
            if (bytes >= factor) return (bytes / factor).toFixed(bytes >= factor * 10 ? 0 : 1) + ' ' + unit;
        }
        return bytes + ' B';
    };
    const percent = function (part, whole) {
        if (whole === 0) return '0%';
        const value = part / whole * 100;
        return (part * 1000 < whole ? value.toFixed(2) : value.toFixed(1)).replace(/\.?0+$/, '') + '%';
    };
    const quantile = function (sorted, q) {
        if (sorted.length === 0) return null;
        return sorted[Math.min(sorted.length - 1, Math.floor(q * sorted.length))];
    };
    const kind = function (status) {
        return status >= 500 ? 'server' : status >= 400 ? 'client' : 'ok';
    };
    const time = function (request) { return Date.parse(request.time); };

    const summarize = function (requests) {
        const ms = requests.map(function (r) { return r.ms; }).sort(function (a, b) { return a - b; });
        const sum = { requests: requests.length, ok: 0, client: 0, server: 0, bytes: 0, median: quantile(ms, 0.5), p95: quantile(ms, 0.95) };
        for (const r of requests) {
            sum[kind(r.status)]++;
            sum.bytes += r.bytes;
        }
        return sum;
    };

    const set = function (name, text) {
        const el = card.querySelector('[data-stat="' + name + '"]');
        if (el) el.textContent = text;
    };

    const cell = function (row, text, className) {
        const td = document.createElement('td');
        td.textContent = text;
        if (className) td.className = className;
        row.appendChild(td);
        return td;
    };

    // --- Chart: requests per interval as stacked bars (answered, 4xx, 5xx)
    // and the p95 time taken as a line on its own scale. The last 5 minutes,
    // or the whole recording while one runs. ---

    const SVG = 'http://www.w3.org/2000/svg';
    const W = 600, H = 160, BARS = 60;
    const STEPS = [1, 2, 5, 10, 30, 60, 120, 300, 600, 900, 1800, 3600].map(function (s) { return s * 1000; });

    const svgEl = function (name, attributes) {
        const el = document.createElementNS(SVG, name);
        for (const key in attributes) el.setAttribute(key, attributes[key]);
        return el;
    };

    const clock = function (ms) { return new Date(ms).toISOString().slice(11, 19); };

    const drawChart = function (requests, from, to) {
        const step = STEPS.find(function (s) { return s * BARS >= to - from; }) || STEPS[STEPS.length - 1];
        const count = Math.max(1, Math.ceil((to - from) / step));
        const buckets = [];
        for (let i = 0; i < count; i++) buckets.push({ ok: 0, client: 0, server: 0, ms: [] });

        for (const r of requests) {
            const index = Math.floor((time(r) - from) / step);
            if (index >= 0 && index < count) {
                buckets[index][kind(r.status)]++;
                buckets[index].ms.push(r.ms);
            }
        }

        const peak = Math.max(1, ...buckets.map(function (b) { return b.ok + b.client + b.server; }));
        const p95s = buckets.map(function (b) { return quantile(b.ms.sort(function (x, y) { return x - y; }), 0.95); });
        const p95Peak = Math.max(1, ...p95s.filter(function (v) { return v !== null; }));
        const width = W / count;
        const children = [];

        // Grid: quarter lines
        for (let i = 1; i < 4; i++) {
            children.push(svgEl('line', { x1: 0, x2: W, y1: H * i / 4, y2: H * i / 4, class: 'traffic-grid' }));
        }

        buckets.forEach(function (b, i) {
            let y = H;
            const group = svgEl('g', {});
            const title = svgEl('title', {});
            title.textContent = clock(from + i * step) + ' UTC: ' + (b.ok + b.client + b.server) + ' requests, ' + b.client + ' 4xx, ' + b.server + ' 5xx' + (p95s[i] === null ? '' : ', p95 ' + duration(p95s[i]));
            group.appendChild(title);
            group.appendChild(svgEl('rect', { x: i * width, y: 0, width: width, height: H, class: 'traffic-hit' }));
            for (const [key, className] of [['ok', 'traffic-ok'], ['client', 'traffic-warn'], ['server', 'traffic-error']]) {
                if (b[key] === 0) continue;
                const h = b[key] / peak * (H - 8);
                y -= h;
                group.appendChild(svgEl('rect', { x: i * width + width * 0.1, y: y, width: width * 0.8, height: h, class: className }));
            }
            children.push(group);
        });

        const points = [];
        p95s.forEach(function (v, i) {
            if (v !== null) points.push(((i + 0.5) * width).toFixed(1) + ',' + (H - v / p95Peak * (H - 8)).toFixed(1));
        });
        if (points.length > 1) {
            children.push(svgEl('polyline', { points: points.join(' '), class: 'traffic-p95' }));
        }

        svg.replaceChildren(...children);
        svg.setAttribute('aria-label', number(requests.length) + ' requests from ' + clock(from) + ' to ' + clock(to) + ' UTC, ' + count + ' bars, highest ' + number(peak));
        chartFrom.textContent = recording ? 'Recording since ' + clock(from) : '5 min ago';
        chartTo.textContent = recording ? clock(to) : 'now';
        chartP95.textContent = points.length === 0 ? 'n/a' : duration(p95Peak);
    };

    const render = function () {
        const now = Date.now();
        const recent = kept.filter(function (r) { return time(r) >= now - WINDOW_MS; });
        const sum = summarize(recent);

        set('requests', number(sum.requests));
        set('per_minute', number(recent.filter(function (r) { return time(r) >= now - 60000; }).length));
        set('server_errors', percent(sum.server, sum.requests));
        card.querySelector('[data-stat="server_errors"]').classList.toggle('ov-traffic-bad', sum.server > 0);
        set('median', duration(sum.median));
        set('p95', duration(sum.p95));
        set('bytes', size(sum.bytes));
        set('ok', number(sum.ok));
        set('client_errors', number(sum.client));
        set('server_error_count', number(sum.server));

        if (recording) {
            drawChart(recording.rows, recording.started.getTime(), Math.max(now, recording.started.getTime() + 60000));
        } else {
            drawChart(recent, now - WINDOW_MS, now);
        }

        if (appRows) {
            const byApp = {};
            for (const r of recent) (byApp[r.app_id] = byApp[r.app_id] || []).push(r);
            const ids = Object.keys(byApp).sort(function (a, b) { return byApp[b].length - byApp[a].length; });
            appRows.replaceChildren(...ids.filter(function (id) { return apps[id] !== undefined; }).map(function (id) {
                const s = summarize(byApp[id]);
                const row = document.createElement('tr');
                const link = document.createElement('a');
                link.href = 'apps/' + id + '/traffic';
                link.textContent = apps[id];
                cell(row, '').appendChild(link);
                cell(row, number(s.requests));
                cell(row, percent(s.server, s.requests), s.server > 0 ? 'ov-traffic-bad' : '');
                cell(row, duration(s.median), 'muted');
                cell(row, duration(s.p95), 'muted');
                return row;
            }));
        }

        const shown = [];
        for (let i = kept.length - 1; i >= 0 && shown.length < MAX_SHOWN; i--) {
            if (!errorsOnly.checked || kept[i].status >= 400) shown.push(kept[i]);
        }
        list.replaceChildren(...shown.map(function (r) {
            const row = document.createElement('tr');
            cell(row, r.time.slice(11, 19), 'muted');
            if (apps) cell(row, apps[r.app_id] || '');
            const request = cell(row, r.method + ' ', 'traffic-path');
            request.title = r.host + r.path;
            const path = document.createElement('span');
            path.className = 'ov-mono';
            path.textContent = r.path;
            request.appendChild(path);
            cell(row, String(r.status), kind(r.status) === 'ok' ? '' : (kind(r.status) === 'server' ? 'ov-traffic-bad' : 'ov-warn-text'));
            cell(row, duration(r.ms), 'muted');
            cell(row, size(r.bytes), 'muted');
            return row;
        }));
        empty.hidden = shown.length > 0;
    };

    // --- Recording: everything from Start until Stop, downloaded as CSV on Stop ---

    const csvCell = function (value) {
        let text = String(value);
        // A leading = + - @ would run as a formula in a spreadsheet
        if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };

    const download = function (rows, started) {
        const header = ['time', 'app', 'method', 'host', 'path', 'status', 'duration_ms', 'bytes'];
        const lines = [header.join(',')].concat(rows.map(function (r) {
            return [r.time, apps ? (apps[r.app_id] || r.app_id) : card.dataset.label, r.method, r.host, r.path, r.status, r.ms, r.bytes].map(csvCell).join(',');
        }));
        const blob = new Blob([lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = card.dataset.file + '-' + started.toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 10000);
    };

    const updateRecording = function () {
        if (!recording) {
            recordingLabel.hidden = true;
            recordButton.textContent = 'Start recording';
            return;
        }
        const seconds = Math.floor((Date.now() - recording.started) / 1000);
        recordingLabel.hidden = false;
        recordingLabel.textContent = 'Recording ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0') + ' of 60:00 · ' + number(recording.rows.length) + ' requests';
        recordButton.textContent = 'Stop and download CSV';
    };

    const stopRecording = function () {
        const done = recording;
        recording = null;
        updateRecording();
        if (done) download(done.rows, done.started);
    };

    recordButton.addEventListener('click', function () {
        if (recording) {
            stopRecording();
        } else {
            recording = { started: new Date(), rows: [] };
            updateRecording();
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (recording && recording.rows.length > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    errorsOnly.addEventListener('change', render);

    // --- Polling ---

    const poll = function () {
        timer = null;
        inFlight = true;
        fetch(card.dataset.pollUrl + '?since=' + encodeURIComponent(cursor), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : { ok: false }; })
            .then(function (data) {
                if (!data.ok) {
                    status.textContent = 'Traffic is unavailable right now; retrying';
                    return;
                }
                cursor = data.cursor;
                kept = kept.concat(data.requests);
                if (kept.length > MAX_KEPT) kept = kept.slice(-MAX_KEPT);
                if (recording) {
                    recording.rows.push(...data.requests);
                    if (recording.rows.length >= MAX_RECORDED || Date.now() - recording.started >= MAX_RECORDING_MS) stopRecording();
                }
                status.textContent = 'Live · updated ' + new Date().toISOString().slice(11, 19) + ' UTC';
            }, function () {
                status.textContent = 'Connection lost; retrying';
            })
            .then(function () {
                inFlight = false;
                render();
                updateRecording();
                if (!document.hidden || recording) timer = setTimeout(poll, POLL_MS);
            });
    };

    // A hidden tab stops polling unless it is recording; the cursor catches up on return
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && timer === null && !inFlight) poll();
    });

    render();
    poll();
});
