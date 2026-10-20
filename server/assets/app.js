// Tunnel dashboard. Plain JavaScript with no build step; the PHP app serves it as-is.
(() => {
  'use strict';

  const settings = JSON.parse(document.body.dataset.settings);
  const root = document.getElementById('app');
  const POLL_MS = 2000;
  const DISMISSED_KEY = 'tunnel.dismissed';

  const ui = {
    view: null,
    data: null,
    filter: 'all',
    copied: null,
    closing: new Set(),
  };
  // Last HTML written into each container, so unchanged sections are left alone.
  const rendered = new Map();
  let pollTimer = null;
  let polling = false;

  // ---------- HTML ----------

  class Html {
    constructor(text) { this.text = text; }
    toString() { return this.text; }
  }

  const escape = (value) => String(value).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
  const piece = (value) => {
    if (value instanceof Html) return value.text;
    if (Array.isArray(value)) return value.map(piece).join('');
    if (value === null || value === undefined || value === false) return '';
    return escape(value);
  };
  // Tagged template that escapes every interpolated value unless it is already Html.
  const html = (strings, ...values) =>
    new Html(strings.reduce((out, s, i) => out + s + (i < values.length ? piece(values[i]) : ''), ''));

  const ICONS = {
    copy: '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V4A1.5 1.5 0 0 0 9 2.5H4A1.5 1.5 0 0 0 2.5 4v5A1.5 1.5 0 0 0 4 10.5h1.5"/>',
    check: '<path d="m3.5 8.5 3 3 6-7"/>',
    lock: '<rect x="3.5" y="7" width="9" height="6.5" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/>',
    arrow: '<path d="M3 8h10M9 4l4 4-4 4"/>',
    info: '<circle cx="8" cy="8" r="6"/><path d="M8 7.5V11M8 5.25v.01"/>',
    warn: '<path d="M8 2.5 14 13H2L8 2.5Z"/><path d="M8 6.5v3M8 11.25v.01"/>',
    close: '<path d="m4.5 4.5 7 7m0-7-7 7"/>',
    download: '<path d="M8 2.5v8M4.5 7 8 10.5 11.5 7M3 13.5h10"/>',
  };
  const icon = (name) => new Html(
    `<svg class="icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`,
  );
  const logo = () => new Html(
    '<svg class="logo" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#4f46e5"/>'
    + '<path d="M8 24v-7a8 8 0 0 1 16 0v7" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/>'
    + '<path d="M13.5 24v-6.5a2.5 2.5 0 0 1 5 0V24" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="2.5" stroke-linecap="round"/></svg>',
  );

  // ---------- Formatting ----------

  const formatBytes = (n) => {
    if (n < 1024) return `${n} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = n / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit += 1;
    }
    return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
  };

  const formatSpan = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    if (s < 60) return `${s}s`;
    if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
    return `${Math.floor(s / 86400)}d ${Math.floor((s % 86400) / 3600)}h`;
  };

  const formatDuration = (ms) => {
    if (ms < 1000) return `${Math.round(ms)} ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} s`;
    return formatSpan(ms / 1000);
  };

  const formatClock = (time) => new Date(time * 1000)
    .toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

  const formatAgo = (seconds) => (seconds < 10 ? 'just now' : `${formatSpan(seconds)} ago`);

  const shortUrl = (url) => url.replace(/^https?:\/\//, '').replace(/\/$/, '');

  // ---------- Server ----------

  class HttpError extends Error {
    constructor(message, status) {
      super(message);
      this.status = status;
    }
  }

  async function call(path, body) {
    const post = body !== undefined;
    const response = await fetch(settings.api + path, {
      method: post ? 'POST' : 'GET',
      headers: post ? { 'Content-Type': 'application/json', 'X-Tunnel-Csrf': '1' } : {},
      body: post ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
      cache: 'no-store',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new HttpError(data.error || `The server answered with status ${response.status}.`, response.status);
    return data;
  }

  // ---------- Sign in ----------

  function showLogin() {
    ui.view = 'login';
    clearTimeout(pollTimer);
    rendered.clear();
    root.innerHTML = html`
      <main class="login">
        <div class="brand brand-lg">${logo()}<span>Tunnel</span></div>
        <form class="card login-card" novalidate>
          <h1>Sign in</h1>
          <p class="muted">Use the token from this server's config.php.</p>
          <label class="field">
            <span>Token</span>
            <input type="password" name="token" autocomplete="current-password" spellcheck="false" required>
          </label>
          <p class="form-error" role="alert"></p>
          <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>
        <p class="login-foot">${shortUrl(settings.url)}</p>
      </main>`;

    const form = root.querySelector('form');
    const input = form.elements.token;
    const error = form.querySelector('.form-error');
    const button = form.querySelector('button');
    input.focus();
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!input.value) {
        error.textContent = 'Enter your token.';
        input.focus();
        return;
      }
      button.disabled = true;
      error.textContent = '';
      try {
        await call('login', { token: input.value });
        showDashboard();
      } catch (e) {
        error.textContent = e.message;
        button.disabled = false;
        input.select();
      }
    });
  }

  // ---------- Dashboard ----------

  function showDashboard() {
    ui.view = 'dashboard';
    rendered.clear();
    root.innerHTML = html`
      <header class="topbar">
        <div class="topbar-inner">
          <div class="brand">${logo()}<span>Tunnel</span></div>
          <span class="server-url" title="${settings.url}">${shortUrl(settings.url)}</span>
          <div class="topbar-right">
            <span class="live" id="live">Live</span>
            <button class="btn btn-ghost btn-sm" type="button" data-action="logout">Sign out</button>
          </div>
        </div>
      </header>
      <main class="page">
        <div id="notices" hidden></div>
        <section aria-labelledby="tunnels-title">
          <div class="section-head">
            <h2 id="tunnels-title">Tunnels <span class="count" id="tunnel-count" hidden></span></h2>
          </div>
          <div id="tunnels"></div>
        </section>
        <section aria-labelledby="requests-title">
          <div class="section-head">
            <h2 id="requests-title">Requests</h2>
            <div class="filters" id="filters"></div>
          </div>
          <div id="requests"></div>
        </section>
        <section aria-labelledby="connect-title">
          <div class="section-head"><h2 id="connect-title">Connect a computer</h2></div>
          <div id="connect"></div>
        </section>
      </main>`;
    render();
    poll();
  }

  async function poll() {
    clearTimeout(pollTimer);
    if (polling || ui.view !== 'dashboard') return;
    polling = true;
    try {
      ui.data = await call('state');
      setLive(true);
      render();
    } catch (e) {
      if (e.status === 401) {
        polling = false;
        showLogin();
        return;
      }
      setLive(false);
    }
    polling = false;
    if (ui.view === 'dashboard' && !document.hidden) pollTimer = setTimeout(poll, POLL_MS);
  }

  function setLive(online) {
    const live = document.getElementById('live');
    if (!live) return;
    live.classList.toggle('is-offline', !online);
    live.textContent = online ? 'Live' : 'Reconnecting';
  }

  function render() {
    if (ui.view !== 'dashboard') return;
    update('connect', connectSection());
    const { data } = ui;
    if (!data) {
      update('tunnels', html`<div class="card empty"><p>Loading tunnels</p></div>`);
      return;
    }
    const known = new Set([...data.tunnels.map((t) => t.name), ...data.requests.map((r) => r.tunnel)]);
    if (!known.has(ui.filter)) ui.filter = 'all';

    update('notices', noticesSection(data.server.checks));
    update('tunnel-count', data.tunnels.length || '');
    update('tunnels', tunnelsSection(data));
    update('filters', filtersSection([...known]));
    update('requests', requestsSection(data));
  }

  function update(id, content) {
    const element = document.getElementById(id);
    const text = piece(content);
    if (!element || rendered.get(id) === text) return;
    rendered.set(id, text);
    element.innerHTML = text;
    if (id === 'notices' || id === 'tunnel-count') element.hidden = text === '';
  }

  const copyButton = (text, label = 'Copy') => {
    const done = ui.copied === text;
    return html`<button class="icon-btn${done ? ' is-done' : ''}" type="button" data-copy="${text}" title="${done ? 'Copied' : label}" aria-label="${label}">${icon(done ? 'check' : 'copy')}</button>`;
  };

  const command = (text) => html`<div class="cmd"><code>${text}</code>${copyButton(text, 'Copy command')}</div>`;

  function noticesSection(checks) {
    const dismissed = readDismissed();
    const visible = checks.filter((check) => check.level === 'warn' || !dismissed.includes(check.message));
    if (visible.length === 0) return '';
    return html`<div class="notices">${visible.map((check) => html`
      <div class="notice notice-${check.level}">
        ${icon(check.level === 'warn' ? 'warn' : 'info')}
        <p>${check.message}</p>
        ${check.level === 'warn' ? '' : html`<button class="icon-btn" type="button" data-dismiss="${check.message}" title="Dismiss" aria-label="Dismiss">${icon('close')}</button>`}
      </div>`)}</div>`;
  }

  function tunnelsSection(data) {
    if (data.tunnels.length === 0) {
      return html`
        <div class="card empty">
          <h3>No tunnels are open</h3>
          <p>Run tunnel.py on the computer whose port you want to share. Its tunnels show up here as soon as it connects.</p>
          ${command('python3 tunnel.py 3000')}
        </div>`;
    }
    return html`<div class="tunnels">${data.tunnels.map((tunnel) => tunnelCard(tunnel, data.now))}</div>`;
  }

  function tunnelCard(t, now) {
    const closing = ui.closing.has(t.name);
    const stat = (label, value) => html`<div class="stat"><dt>${label}</dt><dd>${value}</dd></div>`;
    return html`
      <article class="card tunnel">
        <div class="tunnel-head">
          <span class="dot${t.online ? '' : ' is-off'}" title="${t.online ? 'Online' : 'Offline'}"></span>
          <span class="tunnel-name">${t.name}</span>
          ${t.protected ? html`<span class="badge" title="Visitors need a username and password">${icon('lock')}Password</span>` : ''}
          ${t.online ? '' : html`<span class="badge badge-off">Offline, seen ${formatAgo(now - t.lastSeen)}</span>`}
          <button class="btn btn-sm btn-danger" type="button" data-action="close" data-name="${t.name}"${closing ? ' disabled' : ''}>${closing ? 'Closing' : 'Close'}</button>
        </div>
        <div class="urls">${t.urls.map((url) => html`
          <div class="url"><a href="${url}" target="_blank" rel="noopener">${shortUrl(url)}</a>${copyButton(url, 'Copy address')}</div>`)}
        </div>
        <div class="target">${icon('arrow')}<code>${t.target}</code><span title="${t.clientIp}">on ${t.client}</span></div>
        <dl class="stats">
          ${stat('Requests', t.requests.toLocaleString())}
          ${stat('Received', formatBytes(t.bytesIn))}
          ${stat('Sent', formatBytes(t.bytesOut))}
          ${stat('In flight', t.active)}
          ${stat('Open for', formatSpan(now - t.createdAt))}
        </dl>
      </article>`;
  }

  function filtersSection(names) {
    if (names.length < 2) return '';
    const chip = (value, label) => html`<button class="chip${ui.filter === value ? ' is-active' : ''}" type="button" data-filter="${value}" aria-pressed="${ui.filter === value}">${label}</button>`;
    return [chip('all', 'All'), ...names.map((name) => chip(name, name))];
  }

  function requestsSection(data) {
    const rows = data.requests.filter((r) => ui.filter === 'all' || r.tunnel === ui.filter);
    if (rows.length === 0) {
      return html`<div class="card log"><div class="log-empty">Requests show up here as visitors arrive.</div></div>`;
    }
    return html`
      <div class="card log" role="table" aria-label="Recent requests">
        <div class="log-row log-head" role="row">
          <span role="columnheader">Time</span>
          <span role="columnheader">Tunnel</span>
          <span role="columnheader">Method</span>
          <span role="columnheader">Path</span>
          <span role="columnheader">Status</span>
          <span class="num" role="columnheader">Duration</span>
          <span class="num" role="columnheader">Size</span>
        </div>
        ${rows.map((r) => requestRow(r, data.now))}
      </div>`;
  }

  function requestRow(r, now) {
    const elapsed = formatDuration(r.durationMs === null ? (now - r.at) * 1000 : r.durationMs);
    const note = r.error || (r.state === 'abandoned' ? 'The visitor left before the response finished.' : '');
    // Red for failures, amber for refusals like a password prompt, gray for everything else.
    const tone = !r.status || r.status >= 500 ? 'err' : r.status >= 400 ? 'warn' : 'muted';
    const status = r.status
      ? html`<span class="status status-${String(r.status)[0]}" role="cell">${r.status}</span>`
      : html`<span class="status" role="cell" title="In progress">···</span>`;
    const size = r.bytes ? formatBytes(r.bytes) : '-';
    return html`
      <div class="log-row" role="row">
        <span class="time" role="cell">${formatClock(r.at)}</span>
        <span class="tunnel-col" role="cell" title="${r.tunnel}">${r.tunnel}</span>
        <span class="method" role="cell">${r.method}</span>
        <span class="path" role="cell"><code title="${r.uri}">${r.uri}</code>${note ? html`<span class="note note-${tone}">${note}</span>` : ''}</span>
        ${status}
        <span class="num" role="cell">${elapsed}</span>
        <span class="num" role="cell">${size}</span>
        <span class="meta">${formatClock(r.at)} · ${r.tunnel} · ${elapsed} · ${size}</span>
      </div>`;
  }

  function connectSection() {
    return html`
      <ol class="card steps">
        <li>
          <p>Get <code>tunnel.py</code> onto the computer whose port you want to share. It needs Python 3.8 or newer and nothing else.</p>
          ${settings.client
            ? html`<div class="actions">${command(`curl -fsSLO ${settings.client}`)}<a class="btn btn-sm" href="${settings.client}" download>${icon('download')}Download</a></div>`
            : html`<p class="muted">You will find it in the <code>client</code> folder of this project.</p>`}
        </li>
        <li>
          <p>Sign in once, with the token from this server's config.php.</p>
          ${command(`python3 tunnel.py login ${settings.url} YOUR_TOKEN`)}
        </li>
        <li>
          <p>Share a port. Give it a name to pick its address, and list several to share more than one.</p>
          ${command('python3 tunnel.py 3000')}
          ${command('python3 tunnel.py myapp=3000 api=8080')}
        </li>
      </ol>`;
  }

  // ---------- Actions ----------

  async function copy(text) {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      // The Clipboard API needs HTTPS; fall back to the older selection-based copy.
      const area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.append(area);
      area.select();
      document.execCommand('copy');
      area.remove();
    }
    ui.copied = text;
    render();
    clearTimeout(copy.timer);
    copy.timer = setTimeout(() => {
      ui.copied = null;
      render();
    }, 1500);
  }

  async function closeTunnel(name) {
    const question = `Close the tunnel "${name}"?\n\nIts address stops working right away, and tunnel.py stops serving it.`;
    if (!window.confirm(question)) return;
    ui.closing.add(name);
    render();
    try {
      await call('close', { name });
    } catch (e) {
      if (e.status === 401) {
        showLogin();
        return;
      }
      window.alert(e.message);
    } finally {
      ui.closing.delete(name);
    }
    poll();
  }

  async function logout() {
    await call('logout', {}).catch(() => {});
    showLogin();
  }

  function readDismissed() {
    try {
      return JSON.parse(localStorage.getItem(DISMISSED_KEY)) || [];
    } catch {
      return [];
    }
  }

  function dismiss(message) {
    try {
      localStorage.setItem(DISMISSED_KEY, JSON.stringify([...readDismissed(), message]));
    } catch {
      // Without storage the notice simply comes back on the next visit.
    }
    render();
  }

  root.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button) return;
    const { dataset } = button;
    if (dataset.copy !== undefined) copy(dataset.copy);
    else if (dataset.filter) {
      ui.filter = dataset.filter;
      render();
    } else if (dataset.dismiss) dismiss(dataset.dismiss);
    else if (dataset.action === 'close') closeTunnel(dataset.name);
    else if (dataset.action === 'logout') logout();
  });

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) poll();
  });

  call('session')
    .then(({ authenticated }) => (authenticated ? showDashboard() : showLogin()))
    .catch(() => showLogin());
})();
