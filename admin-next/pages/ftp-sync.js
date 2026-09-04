/**
 * FTP Sync — Admin2 plugin page.
 *
 * Faithful reimplementation of the classic-admin "FTP Sync" page
 * (admin/templates/ftp-sync.html.twig) against the new REST endpoints in
 * classes/FtpSyncApiController.php. The job-queue polling shape
 * (start -> repeated step calls until finished) and every SyncManager
 * call are unchanged — only the transport moved from FormData +
 * onAdminTaskExecute to fetch + REST.
 */

const TAG = window.__GRAV_PAGE_TAG;
const API_BASE = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
// Fallback only: window.__GRAV_API_TOKEN is a one-time snapshot taken when
// admin2 first imports this page component (see the SvelteKit host's
// PluginPage loader) and is never refreshed afterwards, even though the host
// app keeps rotating the real access token in localStorage every time it
// silently refreshes (see chunks/CA2JBzYV.js's setTokens()/refresh cycle).
// A ftp-sync session commonly stays open long enough (reviewing a diff,
// then hitting Sync) to outlive the snapshot, which used to surface as a
// bare "Request failed (401)" on whatever action ran after the token had
// since rotated. currentAccessToken() below always re-reads the live token
// from the same localStorage key the host app itself writes to.
const API_TOKEN_FALLBACK = window.__GRAV_API_TOKEN;

function currentAccessToken() {
    try {
        const keys = ['grav_admin_auth::/admin2', 'grav_admin_auth'];
        for (const key of keys) {
            const raw = localStorage.getItem(key);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.accessToken === 'string' && parsed.accessToken) {
                    return parsed.accessToken;
                }
            }
        }
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.indexOf('grav_admin_auth') === 0) {
                const raw = localStorage.getItem(key);
                const parsed = raw ? JSON.parse(raw) : null;
                if (parsed && typeof parsed.accessToken === 'string' && parsed.accessToken) {
                    return parsed.accessToken;
                }
            }
        }
    } catch (e) {
        // localStorage unavailable -> fall back to the load-time snapshot.
    }
    return API_TOKEN_FALLBACK;
}

const KINDS = [
    ['pages', 'Pages', 'user/pages (all page content)'],
    ['themes', 'Themes', "The site's active theme (auto-detected, not configured manually)"],
    ['plugins', 'Plugins', 'Plugins listed in the config, or every plugin under user/plugins/ if that list is left empty'],
    ['config', 'Config', 'user/config (excluding sensitive files such as the FTP password)'],
    ['accounts', 'Accounts', 'user/accounts (Admin login accounts)'],
];

const TYPE_LABELS = {
    missing_remote: 'Missing on Hosting',
    missing_local: 'Missing on Local',
    changed: 'Different — size or mtime differ',
};

const RESOLUTION_OPTIONS = [
    ['', 'No action'],
    ['local', 'Use Local version'],
    ['remote', 'Use Hosting version'],
    ['delete_local', 'Delete on Local'],
    ['delete_remote', 'Delete on Hosting'],
];

function defaultResolution(row) {
    if (row.type === 'changed') {
        if (row.newer === 'local') return 'local';
        if (row.newer === 'remote') return 'remote';
        return '';
    }
    if (row.type === 'missing_remote') return 'local';
    if (row.type === 'missing_local') return 'remote';
    return '';
}

function kindOfPath(path) {
    const prefix = path.split('/')[0];
    if (prefix === 'pages') return 'pages';
    if (prefix === 'config') return 'config';
    if (prefix === 'accounts') return 'accounts';
    if (prefix.indexOf('theme:') === 0) return 'themes';
    if (prefix.indexOf('plugin:') === 0) return 'plugins';
    return '';
}

function statusGroup(type) {
    if (type === 'changed') return 'changed';
    if (type === 'missing_remote') return 'local_only';
    if (type === 'missing_local') return 'host_only';
    return '';
}

function statusCells(row) {
    const empty = { text: '', cls: '' };
    const diffCell = { text: 'Diff', cls: 'fts-status-conflict' };
    const newerCell = { text: 'Newer', cls: 'fts-status-newer' };
    const olderCell = { text: 'Older', cls: 'fts-status-older' };
    const xCell = { text: 'X', cls: 'fts-status-x' };

    switch (row.type) {
        case 'changed':
            if (row.newer === 'local') return [newerCell, olderCell];
            if (row.newer === 'remote') return [olderCell, newerCell];
            return [diffCell, diffCell];
        case 'missing_remote':
            return [xCell, empty];
        case 'missing_local':
            return [empty, xCell];
        default:
            return [empty, empty];
    }
}

function formatSize(bytes) {
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

function formatDate(unixTime) {
    const d = new Date(unixTime * 1000);
    const pad = (n) => (n < 10 ? '0' + n : '' + n);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

class FtpSyncPage extends HTMLElement {
    constructor() {
        super();
        this._isLocal = false;
        this._isEnabled = false;
        this._backupPath = '';
        this._lastRows = {};
        this._fullDeployCancelToken = null;
    }

    connectedCallback() {
        this.dispatchEvent(new CustomEvent('page-state', {
            detail: { title: 'FTP Sync', icon: 'fa-exchange' },
        }));
        this.innerHTML = `${this._styles()}<div class="fts-wrapper"><p class="fts-loading">Đang tải…</p></div>`;
        this._init();
    }

    async _fetch(path, options = {}) {
        const token = currentAccessToken();
        const res = await fetch(API_BASE + path, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...(options.headers || {}),
            },
        });
        if (res.status === 204) return {};
        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(body?.detail || body?.error?.message || body?.message || `Request failed (${res.status})`);
        }
        return body.data ?? body;
    }

    async _init() {
        try {
            const status = await this._fetch('/ftp-sync/status');
            this._isLocal = !!status.is_local;
            this._isEnabled = !!status.is_enabled;
            this._backupPath = status.backup_path || 'user/data/ftp-sync/backups';
            this._render();
        } catch (err) {
            this.querySelector('.fts-wrapper').innerHTML = `<p class="fts-error">${this._escape(err.message || 'Load failed')}</p>`;
        }
    }

    _render() {
        const wrapper = this.querySelector('.fts-wrapper');
        wrapper.innerHTML = `
            ${!this._isEnabled ? `<div class="fts-notice fts-notice-error"><i class="fa fa-exclamation-triangle"></i> Plugin not work on live site, only local host.</div>` : ''}
            ${this._isEnabled && !this._isLocal ? `<div class="fts-notice fts-notice-error">This does not look like a local environment (no .ddev/ folder found) — "Sync now" / "Full deploy" are locked. Enable <code>force_allow_remote</code> in the plugin config if you really want to skip this check.</div>` : ''}

            <div class="fts-top">
                <div class="fts-notice">
                    <p>Diff is based on <b>mtime + size</b> over FTP (no hashing). On the first run (no baseline yet), every file that differs shows up as <b>Conflict</b> so you can resolve it manually once. Before overwriting/deleting, the previous version is backed up to <code>${this._escape(this._backupPath)}</code>.</p>
                    <div class="fts-kinds">
                        ${KINDS.map(([value, label, desc]) => `
                            <label class="fts-kind-row">
                                <input type="checkbox" class="fts-kind" value="${value}" checked ${this._isEnabled ? '' : 'disabled'}>
                                <span class="fts-kind-name">${label}</span>
                                <span class="fts-kind-desc">${desc}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
                <div class="fts-toolbar">
                    <button type="button" class="fts-btn" data-action="check-diff" ${this._isEnabled ? '' : 'disabled'}><i class="fa fa-refresh"></i> Check differences</button>
                    <button type="button" class="fts-btn fts-btn-primary" data-action="sync-now" disabled><i class="fa fa-cloud-upload"></i> Sync now</button>
                    <button type="button" class="fts-btn" data-action="full-deploy" ${this._isEnabled && this._isLocal ? '' : 'disabled'} title="Bundles the ENTIRE site into one .zip. Deleting old files on hosting and uploading is up to you."><i class="fa fa-rocket"></i> Compress full site</button>
                    <button type="button" class="fts-btn" data-action="mark-synced" style="display:none" ${this._isLocal ? '' : 'disabled'} title="Click ONLY after you have manually uploaded and extracted this zip on Hosting."><i class="fa fa-check"></i> Mark as deployed</button>
                    <button type="button" class="fts-btn" data-action="show-backups" ${this._isEnabled ? '' : 'disabled'}><i class="fa fa-archive"></i> Show backups</button>
                </div>
            </div>

            <div class="fts-progress" style="display:none">
                <div class="fts-progress-track"><div class="fts-progress-fill"></div></div>
                <div class="fts-progress-label"></div>
                <button type="button" class="fts-btn" data-action="cancel-compress" style="display:none"><i class="fa fa-times"></i> Cancel compressing</button>
            </div>

            <div class="fts-backups" style="display:none">
                <div class="fts-backup-location">
                    <span>Backup folder: <code>${this._escape(this._backupPath)}</code> (relative to the project root)</span>
                    <div class="fts-backup-location-actions">
                        <button type="button" class="fts-btn" data-action="delete-selected-backups"><i class="fa fa-trash"></i> Delete selected</button>
                        <button type="button" class="fts-btn" data-action="copy-backup-path"><i class="fa fa-clipboard"></i> Copy location</button>
                    </div>
                </div>
                <table class="fts-table fts-backups-table">
                    <thead><tr><th class="fts-col-checkbox"><input type="checkbox" class="fts-backup-select-all"></th><th class="fts-col-name">File name</th><th class="fts-col-meta fts-col-size">Size</th><th class="fts-col-meta fts-col-created">Created</th><th class="fts-col-action"></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="fts-status"></div>
            <div class="fts-hint" style="display:none"><i class="fa fa-info-circle"></i> Đã tự tay upload + giải nén file zip này lên Hosting? Bấm "Mark as deployed" để cập nhật lại trạng thái đồng bộ.</div>

            <div class="fts-results" style="display:none">
                <div class="fts-filters">
                    <label>Category:
                        <select class="fts-filter-kind">
                            <option value="">All</option>
                            ${KINDS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
                        </select>
                    </label>
                    <label>Status:
                        <select class="fts-filter-status">
                            <option value="">All</option>
                            <option value="changed">Changed (size/mtime differ)</option>
                            <option value="local_only">Local only</option>
                            <option value="host_only">Hosting only</option>
                        </select>
                    </label>
                    <button type="button" class="fts-btn" data-action="apply-filter">Apply filter</button>
                </div>
                <div class="fts-bulk">
                    <label>Select all:
                        <select class="fts-quick-select">
                            <option value="">— Choose —</option>
                            <option value="local_newer">Local Newer</option>
                            <option value="host_newer">Hosting Newer</option>
                            <option value="local_only">Only Local</option>
                            <option value="host_only">Only Hosting</option>
                        </select>
                    </label>
                    <label>Apply to selected rows:
                        <select class="fts-bulk-action">
                            <option value="local">Use Local version</option>
                            <option value="remote">Use Hosting version</option>
                            <option value="">No action</option>
                            <option value="delete_local">Delete on Local</option>
                            <option value="delete_remote">Delete on Hosting</option>
                        </select>
                    </label>
                    <button type="button" class="fts-btn" data-action="bulk-apply">Apply</button>
                </div>
                <table class="fts-table fts-diff-table">
                    <thead><tr><th class="fts-col-checkbox"><input type="checkbox" class="fts-select-all"></th><th>File</th><th class="fts-col-status">Local</th><th class="fts-col-status">Host</th><th class="fts-col-action">Action</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        `;

        this._bindEvents();
    }

    _q(sel) { return this.querySelector(sel); }

    _bindEvents() {
        this._q('[data-action="check-diff"]')?.addEventListener('click', () => this._runCheckDiff());
        this._q('[data-action="sync-now"]')?.addEventListener('click', () => this._runSync());
        this._q('[data-action="full-deploy"]')?.addEventListener('click', () => this._runFullDeploy());
        this._q('[data-action="mark-synced"]')?.addEventListener('click', () => this._runMarkSynced());
        this._q('[data-action="cancel-compress"]')?.addEventListener('click', () => this._requestCancelFullDeploy());
        this._q('[data-action="show-backups"]')?.addEventListener('click', () => this._toggleBackups());
        this._q('[data-action="copy-backup-path"]')?.addEventListener('click', () => this._copyBackupPath());
        this._q('[data-action="apply-filter"]')?.addEventListener('click', () => this._applyFilters());
        this._q('[data-action="bulk-apply"]')?.addEventListener('click', () => this._bulkApply());
        this._q('.fts-select-all')?.addEventListener('change', (e) => this._selectAll(e.target.checked));
        this._q('.fts-quick-select')?.addEventListener('change', (e) => {
            this._quickSelect(e.target.value);
            e.target.value = '';
        });
        this._q('.fts-backups-table tbody')?.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-name]');
            if (btn) this._deleteBackup(btn.dataset.name);
        });
        this._q('.fts-backup-select-all')?.addEventListener('change', (e) => {
            this.querySelectorAll('.fts-backup-select').forEach((cb) => { cb.checked = e.target.checked; });
        });
        this._q('[data-action="delete-selected-backups"]')?.addEventListener('click', () => this._deleteSelectedBackups());
    }

    _setStatus(message, isError) {
        const el = this._q('.fts-status');
        if (el) {
            el.textContent = message;
            el.className = 'fts-status' + (isError ? ' fts-status-error' : '');
        }
    }

    _showProgress(done, total, label) {
        const box = this._q('.fts-progress');
        const fill = this._q('.fts-progress-fill');
        const labelEl = this._q('.fts-progress-label');
        box.style.display = '';
        const pct = total > 0 ? Math.round((done / total) * 100) : 100;
        fill.style.width = pct + '%';
        labelEl.textContent = `${label} (${done}/${total})`;
    }

    _hideProgress() {
        const box = this._q('.fts-progress');
        box.style.display = 'none';
        this._q('.fts-progress-fill').style.width = '0%';
        this._q('[data-action="cancel-compress"]').style.display = 'none';
    }

    _selectedKinds() {
        return [...this.querySelectorAll('.fts-kind')].filter((cb) => cb.checked).map((cb) => cb.value);
    }

    async _runBatchedJob(stepFn, label, onDone, onError, cancelToken) {
        try {
            const data = await stepFn();
            if (cancelToken && cancelToken.cancelled) {
                cancelToken.onCancelled();
                return;
            }
            this._showProgress(data.done, data.total, data.label || label);
            if (data.finished) {
                this._hideProgress();
                onDone(data);
            } else {
                this._runBatchedJob(stepFn, label, onDone, onError, cancelToken);
            }
        } catch (err) {
            if (cancelToken && cancelToken.cancelled) {
                cancelToken.onCancelled();
                return;
            }
            this._hideProgress();
            onError(err.message || String(err));
        }
    }

    async _runCheckDiff() {
        const kinds = this._selectedKinds();
        if (kinds.length === 0) {
            this._setStatus('Select at least 1 category (Pages/Themes/Plugins/Config/Accounts) to check.', true);
            return;
        }

        const checkBtn = this._q('[data-action="check-diff"]');
        const syncBtn = this._q('[data-action="sync-now"]');
        this._setStatus('Checking...', false);
        checkBtn.disabled = true;
        syncBtn.disabled = true;

        try {
            const data = await this._fetch('/ftp-sync/check-diff', { method: 'POST', body: JSON.stringify({ kinds }) });
            this._showProgress(0, data.total, data.label || 'Checking');
            await this._runBatchedJob(
                () => this._fetch(`/ftp-sync/check-diff/${data.job_id}/step`, { method: 'POST', body: '{}' }),
                'Checking',
                (finalData) => {
                    checkBtn.disabled = false;
                    this._renderRows(finalData.rows || {});
                    syncBtn.disabled = !this._isLocal || Object.keys(finalData.rows || {}).length === 0;
                },
                (message) => {
                    this._setStatus('Error: ' + message, true);
                    checkBtn.disabled = false;
                },
            );
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
            checkBtn.disabled = false;
        }
    }

    _renderRows(rows) {
        this._lastRows = rows || {};
        const tbody = this._q('.fts-diff-table tbody');
        const results = this._q('.fts-results');
        tbody.innerHTML = '';
        const paths = Object.keys(this._lastRows).sort();

        if (paths.length === 0) {
            results.style.display = 'none';
            this._setStatus('No differences — local and hosting are in sync.', false);
            return;
        }

        tbody.innerHTML = paths.map((path) => {
            const row = this._lastRows[path];
            const cells = statusCells(row);
            const options = RESOLUTION_OPTIONS.map(([v, l]) => `<option value="${v}" ${defaultResolution(row) === v ? 'selected' : ''}>${l}</option>`).join('');
            return `
                <tr data-path="${this._escape(path)}" data-kind="${kindOfPath(path)}" data-status-group="${statusGroup(row.type)}">
                    <td class="fts-col-checkbox"><input type="checkbox" class="fts-row-select"></td>
                    <td title="${this._escape(TYPE_LABELS[row.type] || row.type)}">${this._escape(path)}</td>
                    <td class="fts-col-status ${cells[0].cls}">${cells[0].text}</td>
                    <td class="fts-col-status ${cells[1].cls}">${cells[1].text}</td>
                    <td class="fts-col-action"><select class="fts-resolution" data-path="${this._escape(path)}">${options}</select></td>
                </tr>
            `;
        }).join('');

        this._q('.fts-select-all').checked = false;
        this._applyFilters();
        results.style.display = '';
        this._setStatus(`${paths.length} file(s) differ.`, false);
    }

    _applyFilters() {
        const kind = this._q('.fts-filter-kind').value;
        const status = this._q('.fts-filter-status').value;
        this.querySelectorAll('.fts-diff-table tbody tr').forEach((tr) => {
            const matchKind = !kind || tr.dataset.kind === kind;
            const matchStatus = !status || tr.dataset.statusGroup === status;
            tr.style.display = matchKind && matchStatus ? '' : 'none';
        });
    }

    _selectAll(checked) {
        this.querySelectorAll('.fts-diff-table tbody tr').forEach((tr) => {
            if (tr.style.display === 'none') return;
            const cb = tr.querySelector('.fts-row-select');
            if (cb) cb.checked = checked;
        });
    }

    /**
     * "Select all: [Local Newer / Hosting Newer / Only Local / Only Hosting]"
     * — checks exactly the rows matching that quick category (unchecking
     * everything else), scoped to whatever the Category/Status filters
     * above currently show. Meant to be chained straight into the existing
     * "Apply to selected rows" bulk action below, e.g. pick "Local Newer"
     * then bulk-apply "Use Local version" to push just those.
     */
    _quickSelect(kind) {
        const matchers = {
            local_newer: (row) => row.type === 'changed' && row.newer === 'local',
            host_newer: (row) => row.type === 'changed' && row.newer === 'remote',
            local_only: (row) => row.type === 'missing_remote',
            host_only: (row) => row.type === 'missing_local',
        };
        const matcher = matchers[kind];
        if (!matcher) return;

        let count = 0;
        this.querySelectorAll('.fts-diff-table tbody tr').forEach((tr) => {
            if (tr.style.display === 'none') return;
            const cb = tr.querySelector('.fts-row-select');
            const row = this._lastRows[tr.dataset.path];
            if (!cb || !row) return;
            cb.checked = matcher(row);
            if (cb.checked) count++;
        });

        const allSelectAll = this._q('.fts-select-all');
        if (allSelectAll) {
            const visibleRows = [...this.querySelectorAll('.fts-diff-table tbody tr')].filter((tr) => tr.style.display !== 'none');
            allSelectAll.checked = visibleRows.length > 0 && visibleRows.every((tr) => tr.querySelector('.fts-row-select')?.checked);
        }

        this._setStatus(count === 0 ? `No rows match "${kind.replace('_', ' ')}".` : `Selected ${count} row(s) matching "${kind.replace('_', ' ')}".`, count === 0);
    }

    _bulkApply() {
        const value = this._q('.fts-bulk-action').value;
        let count = 0;
        this.querySelectorAll('.fts-diff-table tbody tr').forEach((tr) => {
            const cb = tr.querySelector('.fts-row-select');
            if (!cb || !cb.checked) return;
            const select = tr.querySelector('.fts-resolution');
            if (select) { select.value = value; count++; }
        });
        this._setStatus(count === 0 ? 'No rows selected to apply the bulk action to.' : `Applied to ${count} row(s).`, count === 0);
    }

    async _runSync() {
        if (!window.confirm('Sync now? Files about to be overwritten will be backed up automatically first.')) return;

        const resolutions = {};
        this.querySelectorAll('.fts-resolution').forEach((select) => {
            if (select.value) resolutions[select.dataset.path] = select.value;
        });

        const syncBtn = this._q('[data-action="sync-now"]');
        this._setStatus('Starting sync...', false);
        syncBtn.disabled = true;

        try {
            const data = await this._fetch('/ftp-sync/sync', { method: 'POST', body: JSON.stringify({ resolutions }) });
            if (data.total === 0) {
                this._setStatus('Nothing to sync (no rows had an action selected).', false);
                syncBtn.disabled = !this._isLocal;
                return;
            }
            this._showProgress(0, data.total, 'Syncing');
            await this._runBatchedJob(
                () => this._fetch(`/ftp-sync/sync/${data.job_id}/step`, { method: 'POST', body: '{}' }),
                'Syncing',
                (finalData) => {
                    let msg = `Applied ${finalData.applied} change(s), skipped ${finalData.skipped}.`;
                    if (finalData.backup) msg += ` Backup: ${finalData.backup}`;
                    if (finalData.errors && Object.keys(finalData.errors).length > 0) {
                        msg += ` ${Object.keys(finalData.errors).length} file(s) failed (see console).`;
                        console.error('FTP Sync errors:', finalData.errors);
                    }
                    this._setStatus(msg, false);
                    this._q('.fts-results').style.display = 'none';
                    syncBtn.disabled = !this._isLocal;
                },
                (message) => {
                    this._setStatus('Error: ' + message, true);
                    syncBtn.disabled = !this._isLocal;
                },
            );
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
            syncBtn.disabled = !this._isLocal;
        }
    }


    async _runFullDeploy() {
        const btn = this._q('[data-action="full-deploy"]');
        const markBtn = this._q('[data-action="mark-synced"]');
        const hint = this._q('.fts-hint');
        const cancelBtn = this._q('[data-action="cancel-compress"]');

        this._setStatus('Compressing...', false);
        btn.disabled = true;
        markBtn.style.display = 'none';
        hint.style.display = 'none';

        try {
            const data = await this._fetch('/ftp-sync/full-deploy', { method: 'POST', body: '{}' });
            if (data.total === 0) {
                this._setStatus('Nothing to compress.', false);
                btn.disabled = !this._isLocal;
                return;
            }

            const jobId = data.job_id;
            this._fullDeployCancelToken = {
                cancelled: false,
                onCancelled: () => { this._hideProgress(); this._finishCancelFullDeploy(jobId); },
            };

            this._showProgress(0, data.total, 'Compressing');
            cancelBtn.style.display = '';
            cancelBtn.disabled = false;

            await this._runBatchedJob(
                () => this._fetch(`/ftp-sync/full-deploy/${jobId}/step`, { method: 'POST', body: '{}' }),
                'Compressing',
                (finalData) => {
                    this._fullDeployCancelToken = null;
                    this._setStatus(`Compressed ${data.total} file(s) into "${finalData.zip_file}" — find it under user/data/ftp-sync/ and upload it to Hosting yourself.`, false);
                    btn.disabled = !this._isLocal;
                    markBtn.style.display = '';
                    markBtn.disabled = !this._isLocal;
                    hint.style.display = '';
                },
                (message) => {
                    this._fullDeployCancelToken = null;
                    this._setStatus('Error: ' + message, true);
                    btn.disabled = !this._isLocal;
                },
                this._fullDeployCancelToken,
            );
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
            btn.disabled = !this._isLocal;
        }
    }

    _requestCancelFullDeploy() {
        if (!this._fullDeployCancelToken || this._fullDeployCancelToken.cancelled) return;
        if (!window.confirm('Huỷ nén? File .zip đang nén dở sẽ bị xoá.')) return;
        this._fullDeployCancelToken.cancelled = true;
        this._q('[data-action="cancel-compress"]').disabled = true;
        this._setStatus('Cancelling...', false);
    }

    async _finishCancelFullDeploy(jobId) {
        const btn = this._q('[data-action="full-deploy"]');
        try {
            await this._fetch(`/ftp-sync/full-deploy/${jobId}/cancel`, { method: 'POST', body: '{}' });
            btn.disabled = !this._isLocal;
            this._setStatus('Compression cancelled — the partial zip file was deleted.', false);
        } catch (err) {
            btn.disabled = !this._isLocal;
            this._setStatus('Error while cancelling: ' + err.message, true);
        }
    }

    async _runMarkSynced() {
        if (!window.confirm('Bạn đã tự tay upload VÀ giải nén file zip này lên Hosting chưa?\n\nThao tác này sẽ kết nối FTP để đọc mtime/size THẬT trên Hosting rồi lưu lại làm baseline (không upload/download gì) — chỉ bấm Yes khi chắc chắn đã deploy xong.')) return;

        const markBtn = this._q('[data-action="mark-synced"]');
        markBtn.disabled = true;
        this._setStatus('Reading current state from Hosting via FTP...', false);

        try {
            const data = await this._fetch('/ftp-sync/full-deploy/mark-synced', { method: 'POST', body: '{}' });
            this._setStatus(`Baseline updated for ${data.files} file(s) across ${data.groups} group(s) — "Check differences" will now treat Hosting as matching Local for these.`, false);
            markBtn.style.display = 'none';
            this._q('.fts-hint').style.display = 'none';
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
            markBtn.disabled = !this._isLocal;
        }
    }

    async _toggleBackups() {
        const box = this._q('.fts-backups');
        if (box.style.display !== 'none') {
            box.style.display = 'none';
            return;
        }
        this._setStatus('Loading backups...', false);
        try {
            const data = await this._fetch('/ftp-sync/backups');
            this._renderBackups(data.backups || []);
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
        }
    }

    _renderBackups(backups) {
        const box = this._q('.fts-backups');
        const tbody = this._q('.fts-backups-table tbody');
        tbody.innerHTML = '';

        if (!backups || backups.length === 0) {
            box.style.display = 'none';
            this._setStatus('No backups yet.', false);
            return;
        }

        tbody.innerHTML = backups.map((b) => `
            <tr>
                <td class="fts-col-checkbox"><input type="checkbox" class="fts-backup-select" value="${this._escape(b.name)}"></td>
                <td class="fts-col-name" title="${this._escape(b.name)}">${this._escape(b.name)}</td>
                <td class="fts-col-meta fts-col-size">${formatSize(b.size)}</td>
                <td class="fts-col-meta fts-col-created">${formatDate(b.created)}</td>
                <td class="fts-col-action"><button type="button" class="fts-btn" data-name="${this._escape(b.name)}"><i class="fa fa-trash"></i></button></td>
            </tr>
        `).join('');

        const selectAll = this._q('.fts-backup-select-all');
        if (selectAll) selectAll.checked = false;

        box.style.display = '';
        this._setStatus(`${backups.length} backup(s).`, false);
    }

    _copyBackupPath() {
        navigator.clipboard?.writeText(this._backupPath).then(() => {
            this._setStatus('Path copied — paste it into File Explorer after your project folder.', false);
        }).catch(() => {
            this._setStatus('Could not copy automatically — path: ' + this._backupPath, true);
        });
    }

    async _deleteBackup(name) {
        if (!window.confirm(`Delete backup "${name}"? This cannot be undone.`)) return;
        this._setStatus('Deleting backup...', false);
        try {
            await this._fetch(`/ftp-sync/backups/${encodeURIComponent(name)}`, { method: 'DELETE' });
            this._setStatus('Backup deleted.', false);
            const data = await this._fetch('/ftp-sync/backups');
            this._renderBackups(data.backups || []);
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
        }
    }

    /**
     * "Delete selected" — there's no bulk-delete route (see
     * FtpSyncApiController::deleteBackup(), one file per request), so this
     * just calls the same single-delete endpoint once per checked row and
     * refreshes the list at the end, reporting how many failed if any did.
     */
    async _deleteSelectedBackups() {
        const names = [...this.querySelectorAll('.fts-backup-select')].filter((cb) => cb.checked).map((cb) => cb.value);
        if (names.length === 0) {
            this._setStatus('No backups selected to delete.', true);
            return;
        }
        if (!window.confirm(`Delete ${names.length} selected backup(s)? This cannot be undone.`)) return;

        this._setStatus(`Deleting ${names.length} backup(s)...`, false);
        let failed = 0;
        for (const name of names) {
            try {
                await this._fetch(`/ftp-sync/backups/${encodeURIComponent(name)}`, { method: 'DELETE' });
            } catch (err) {
                failed++;
                console.error(`Failed to delete backup "${name}":`, err);
            }
        }

        try {
            const data = await this._fetch('/ftp-sync/backups');
            this._renderBackups(data.backups || []);
        } catch (err) {
            this._setStatus('Error: ' + err.message, true);
            return;
        }

        const deleted = names.length - failed;
        this._setStatus(
            failed === 0 ? `Deleted ${deleted} backup(s).` : `Deleted ${deleted} backup(s), ${failed} failed (see console).`,
            failed > 0,
        );
    }

    _escape(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    _styles() {
        return `
            <style>
                .fts-wrapper { display: flex; flex-direction: column; gap: 14px; font-family: inherit; padding: 4px; font-size: 13px; color: var(--foreground, #1f2937); }
                .fts-loading { color: var(--muted-foreground, #6b7280); }
                .fts-notice { padding: 10px 14px; border-radius: 6px; background: var(--accent, #f3f4f6); }
                .fts-notice-error { background: color-mix(in srgb, var(--destructive, #dc2626) 12%, transparent); color: var(--destructive, #dc2626); }
                .fts-top { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; }
                .fts-top .fts-notice { flex: 1 1 320px; }
                .fts-kinds { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
                .fts-kind-row { display: flex; align-items: center; gap: 8px; cursor: pointer; }
                .fts-kind-name { font-weight: 600; flex: 0 0 6rem; }
                .fts-kind-desc { color: var(--muted-foreground, #6b7280); font-size: 12px; }
                .fts-toolbar { display: flex; flex-direction: column; gap: 6px; flex: 0 0 16rem; }
                .fts-btn { border: 1px solid var(--border, #e5e7eb); background: var(--card, #fff); border-radius: 6px; padding: 6px 12px; font-size: 12.5px; cursor: pointer; color: var(--foreground, #1f2937); }
                .fts-btn:hover:not(:disabled) { background: var(--accent, #f3f4f6); }
                .fts-btn:disabled { opacity: 0.5; cursor: default; }
                .fts-btn-primary { background: var(--primary, #3b82f6); color: var(--primary-foreground, #fff); border-color: var(--primary, #3b82f6); }
                .fts-progress-track { height: 8px; border-radius: 4px; background: var(--accent, #f3f4f6); overflow: hidden; }
                .fts-progress-fill { height: 100%; background: var(--primary, #3b82f6); width: 0; transition: width 0.2s ease; }
                .fts-progress-label { font-size: 12px; color: var(--muted-foreground, #6b7280); margin-top: 4px; }
                .fts-backup-location { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; background: var(--accent, #f3f4f6); border-radius: 6px; font-size: 12.5px; }
                .fts-backup-location-actions { display: flex; flex-wrap: wrap; gap: 8px; }
                .fts-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
                .fts-table th { text-align: left; padding: 6px 8px; color: var(--muted-foreground, #6b7280); border-bottom: 1px solid var(--border, #e5e7eb); }
                .fts-table td { padding: 6px 8px; border-bottom: 1px solid var(--border, #e5e7eb); vertical-align: middle; }
                .fts-col-checkbox, .fts-col-status, .fts-col-meta { text-align: center; }
                .fts-col-action { text-align: right; }
                .fts-backups-table { table-layout: fixed; }
                .fts-backups-table .fts-col-checkbox { width: 34px; }
                .fts-backups-table .fts-col-name { text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
                .fts-backups-table .fts-col-size { width: 90px; }
                .fts-backups-table .fts-col-created { width: 150px; }
                .fts-backups-table .fts-col-action { width: 50px; }
                .fts-status-conflict { color: var(--destructive, #dc2626); font-weight: 600; }
                .fts-status-x { color: var(--success, #16a34a); font-weight: 700; }
                .fts-status-newer { color: #1f6fb2; font-weight: 600; }
                .fts-status-older { color: #c87f0a; font-weight: 600; }
                .fts-resolution { width: 100%; border: 1px solid var(--border, #e5e7eb); border-radius: 4px; padding: 3px 6px; font-size: 12px; }
                .fts-filters, .fts-bulk { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; }
                .fts-filters select, .fts-bulk select { border: 1px solid var(--border, #e5e7eb); border-radius: 4px; padding: 4px 8px; font-size: 12.5px; }
                .fts-status { font-size: 13px; color: var(--foreground, #1f2937); }
                .fts-status-error { color: var(--destructive, #dc2626); }
                .fts-hint { font-size: 12.5px; color: var(--muted-foreground, #6b7280); }
                .fts-error { color: var(--destructive, #dc2626); }
                code { background: var(--accent, #f3f4f6); padding: 1px 5px; border-radius: 3px; font-size: 12px; }
            </style>
        `;
    }
}

customElements.define(TAG, FtpSyncPage);
