/**
 * AI WordPress OS — admin console application.
 *
 * Sections (Phase 1, all real, REST-driven):
 *   Dashboard, Site Intelligence, Tools, Approvals, Activity,
 *   MCP & Clients, Security, Settings, Roadmap
 *
 * Phase 2-4 features are labeled as not-yet-shipped — no fake
 * buttons (spec §65).
 */
import React, { useState, useEffect, useCallback } from 'react';
import { createRoot } from 'react-dom/client';

/* ------------------------------------------------------------------ utils */

const BOOT = window.AIOS_BOOT || {};

async function api(path, options = {}) {
  const res = await fetch(`${BOOT.restBase}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': BOOT.nonce,
      ...(options.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(body?.error?.message || body?.message || `Request failed (${res.status})`);
  }
  return body;
}

const RISK_COLORS = { 0: 'ok', 1: 'info', 2: 'warn', 3: 'danger', 4: 'danger' };
const RISK_NAMES = { 0: 'READ', 1: 'SAFE WRITE', 2: 'SENSITIVE', 3: 'DESTRUCTIVE', 4: 'DEPLOYMENT' };

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(String(s).includes('T') ? s : s.replace(' ', 'T') + 'Z');
  return isNaN(d) ? String(s) : d.toLocaleString();
}

function useApi(path, deps = []) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const reload = useCallback(() => {
    setLoading(true);
    setError(null);
    api(path)
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [path]);
  useEffect(() => { reload(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [reload, ...deps]);
  return { data, error, loading, reload };
}

/* --------------------------------------------------------------- shell */

const NAV = [
  { id: 'dashboard', label: 'Dashboard', icon: '▦' },
  { id: 'context', label: 'Site Intelligence', icon: '◈' },
  { id: 'tools', label: 'Tools', icon: '⌘' },
  { id: 'approvals', label: 'Approvals', icon: '✓' },
  { id: 'activity', label: 'Activity', icon: '≡' },
  { id: 'mcp', label: 'MCP & Clients', icon: '⇋' },
  { id: 'security', label: 'Security', icon: '⛨' },
  { id: 'settings', label: 'Settings', icon: '⚙' },
  { id: 'roadmap', label: 'Roadmap', icon: '◇' },
];

function App() {
  const [section, setSection] = useState('dashboard');
  const [pending, setPending] = useState(0);

  useEffect(() => {
    api('approvals')
      .then((d) => setPending((d.pending || []).length))
      .catch(() => setPending(0));
  }, [section]);

  return (
    <div className="aios-app">
      <aside className="aios-sidebar">
        <div className="aios-brand">
          <span className="aios-brand-mark">AI</span>
          <div>
            <div className="aios-brand-name">AI WordPress OS</div>
            <div className="aios-brand-sub">v{BOOT.version} · Phase 1</div>
          </div>
        </div>
        <nav className="aios-nav">
          {NAV.map((item) => (
            <button
              key={item.id}
              className={`aios-nav-item ${section === item.id ? 'active' : ''}`}
              onClick={() => setSection(item.id)}
            >
              <span className="aios-nav-icon">{item.icon}</span>
              <span>{item.label}</span>
              {item.id === 'approvals' && pending > 0 && <span className="aios-nav-badge">{pending}</span>}
            </button>
          ))}
        </nav>
        <div className="aios-sidebar-foot">
          <span className="aios-dot ok" /> MCP {BOOT.mcp ? 'ready' : '—'}
        </div>
      </aside>
      <main className="aios-main">
        {section === 'dashboard' && <Dashboard />}
        {section === 'context' && <ContextView />}
        {section === 'tools' && <ToolsView />}
        {section === 'approvals' && <ApprovalsView />}
        {section === 'activity' && <ActivityView />}
        {section === 'mcp' && <McpView />}
        {section === 'security' && <SecurityView />}
        {section === 'settings' && <SettingsView />}
        {section === 'roadmap' && <RoadmapView />}
      </main>
    </div>
  );
}

/* ----------------------------------------------------------- dashboard */

function Dashboard() {
  const { data, error, loading, reload } = useApi('status');
  const { data: toolsData } = useApi('tools');

  if (loading) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const health = data?.activity_7d || { calls: 0, errors: 0, avg_ms: 0 };
  const audit = data?.audit_24h || {};

  return (
    <div className="aios-section">
      <SectionHeader title="Dashboard" subtitle="System status at a glance" onRefresh={reload} />
      <div className="aios-cards">
        <StatCard label="Security mode" value={data?.ai_os?.mode || '—'} tone={data?.ai_os?.mode === 'safe' ? 'ok' : 'warn'} />
        <StatCard label="Tools available" value={`${data?.tools?.available ?? 0} / ${data?.tools?.registered ?? 0}`} tone="info" />
        <StatCard label="Pending approvals" value={data?.approvals?.pending ?? 0} tone={(data?.approvals?.pending ?? 0) > 0 ? 'warn' : 'ok'} />
        <StatCard label="Tool calls (7d)" value={health.calls} tone="info" />
        <StatCard label="Errors (7d)" value={health.errors} tone={health.errors > 0 ? 'danger' : 'ok'} />
        <StatCard label="Avg duration" value={`${Math.round(health.avg_ms)} ms`} tone="info" />
        <StatCard label="Audit events (24h)" value={audit.total_24h ?? 0} tone="info" />
        <StatCard label="Blocked (24h)" value={audit.blocked_24h ?? 0} tone={(audit.blocked_24h ?? 0) > 0 ? 'warn' : 'ok'} />
      </div>

      <div className="aios-grid-2">
        <Panel title="Integration surface">
          <div className="aios-kv">
            <Row k="MCP endpoint" v={<code className="aios-code-inline">{data?.mcp?.endpoint}</code>} />
            <Row k="MCP protocol" v={data?.mcp?.protocol} />
            <Row k="Database" v={data?.ai_os?.db_up_to_date ? 'migrations up to date' : 'MIGRATION PENDING'} />
            <Row k="File inspection" v={data?.ai_os?.file_read ? 'enabled (read-only)' : 'disabled'} />
            <Row k="API keys" v={data?.ai_os?.api_keys ? 'enabled' : 'disabled'} />
          </div>
        </Panel>
        <Panel title="Capability surface">
          <ToolUsageTable tools={toolsData?.tools || []} />
        </Panel>
      </div>
    </div>
  );
}

function ToolUsageTable({ tools }) {
  return (
    <table className="aios-table">
      <thead>
        <tr><th>Tool</th><th>Category</th><th>Risk</th><th>Approval</th></tr>
      </thead>
      <tbody>
        {(tools || []).slice(0, 10).map((t) => (
          <tr key={t.name}>
            <td className="aios-mono">{t.name}</td>
            <td>{t.category}</td>
            <td><span className={`aios-chip ${RISK_COLORS[t.riskLevel]}`}>{RISK_NAMES[t.riskLevel]}</span></td>
            <td>{t.confirmation === 'approval' ? 'required' : '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/* ------------------------------------------------------------ context */

function ContextView() {
  const { data, error, loading, reload } = useApi('context');
  const [refreshing, setRefreshing] = useState(false);

  if (loading) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const doRefresh = async () => {
    setRefreshing(true);
    try {
      await api('context?refresh=1');
      reload();
    } finally {
      setRefreshing(false);
    }
  };

  return (
    <div className="aios-section">
      <SectionHeader
        title="Site Intelligence"
        subtitle={`Structured knowledge map · generated ${fmtDate(data?.generated_at)}`}
        onRefresh={doRefresh}
        refreshing={refreshing}
      />
      <div className="aios-cards">
        <StatCard label="Active theme" value={data?.theme?.name || '—'} sub={`${data?.theme?.version || ''} ${data?.theme?.is_child ? '(child)' : ''}`} tone="info" />
        <StatCard label="Plugins" value={`${data?.plugins?.active ?? 0} active / ${data?.plugins?.installed ?? 0}`} tone="info" />
        <StatCard label="Public post types" value={(data?.content?.post_types || []).length} tone="info" />
        <StatCard label="Taxonomies" value={(data?.content?.taxonomies || []).length} tone="info" />
        <StatCard label="Nav menus" value={(data?.menus || []).length} tone="info" />
        <StatCard label="REST namespaces" value={data?.system?.rest_count ?? 0} tone="info" />
      </div>
      <div className="aios-grid-2">
        <Panel title="Content types">
          <table className="aios-table">
            <thead><tr><th>Type</th><th>Label</th><th>Published</th><th>Block editor</th></tr></thead>
            <tbody>
              {(data?.content?.post_types || []).map((pt) => (
                <tr key={pt.name}>
                  <td className="aios-mono">{pt.name}</td><td>{pt.label}</td><td>{pt.count}</td><td>{pt.gutenberg ? 'yes' : 'no'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
        <Panel title="Detected integrations">
          {(data?.integrations || []).length === 0 ? (
            <p className="aios-muted">No known builder/SEO/form integrations detected.</p>
          ) : (
            <table className="aios-table">
              <thead><tr><th>Integration</th><th>Version</th><th>Status</th></tr></thead>
              <tbody>
                {(data?.integrations || []).map((i) => (
                  <tr key={i.slug}><td>{i.name}</td><td>{i.version}</td><td>{i.active ? 'active' : 'installed'}</td></tr>
                ))}
              </tbody>
            </table>
          )}
          <p className="aios-muted aios-note">Adapters for these plugins ship in Phase 3; detection only for now.</p>
        </Panel>
      </div>
      <div className="aios-grid-2">
        <Panel title="Menus">
          {(data?.menus || []).map((m) => (
            <Row key={m.term_id} k={m.name} v={(m.locations || []).join(', ') || '(unassigned)'} />
          ))}
          {(data?.menus || []).length === 0 && <p className="aios-muted">No navigation menus.</p>}
        </Panel>
        <Panel title="Shortcodes">
          <div className="aios-tags">
            {(data?.system?.shortcodes || []).map((s) => <span key={s} className="aios-tag aios-mono">{s}</span>)}
          </div>
        </Panel>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------- tools */

function ToolsView() {
  const { data, error, loading, reload } = useApi('tools');
  const [filter, setFilter] = useState('');
  const [category, setCategory] = useState('all');

  if (loading) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const tools = data?.tools || [];
  const categories = ['all', ...Array.from(new Set(tools.map((t) => t.category)))];
  const visible = tools.filter(
    (t) => (category === 'all' || t.category === category) && (!filter || t.name.toLowerCase().includes(filter.toLowerCase()))
  );

  return (
    <div className="aios-section">
      <SectionHeader title="Tools" subtitle={`${data?.count ?? 0} registered capabilities`} onRefresh={reload} />
      <div className="aios-toolbar">
        <input
          className="aios-input"
          placeholder="Filter tools…"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
        />
        <select className="aios-select" value={category} onChange={(e) => setCategory(e.target.value)}>
          {categories.map((c) => <option key={c} value={c}>{c === 'all' ? 'All categories' : c}</option>)}
        </select>
      </div>
      <div className="aios-grid-cards">
        {visible.map((t) => (
          <div key={t.name} className="aios-tool-card">
            <div className="aios-tool-head">
              <span className="aios-mono aios-tool-name">{t.name}</span>
              <span className={`aios-chip ${RISK_COLORS[t.riskLevel]}`}>{RISK_NAMES[t.riskLevel]}</span>
            </div>
            <p className="aios-tool-desc">{t.description}</p>
            <div className="aios-tool-meta">
              <span className="aios-tag">{t.category}</span>
              <span className="aios-tag">v{t.version}</span>
              {t.confirmation === 'approval' && <span className="aios-tag warn">approval required</span>}
              {!t.available && <span className="aios-tag danger">unavailable</span>}
            </div>
          </div>
        ))}
        {visible.length === 0 && <EmptyState title="No tools match" body="Adjust the filter." />}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------- approvals */

function ApprovalsView() {
  const { data, error, loading, reload } = useApi('approvals');
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState('');

  if (loading) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const act = async (id, verb) => {
    setBusy(`${id}:${verb}`);
    setMessage('');
    try {
      const res = await api(`approvals/${id}/${verb}`, { method: 'POST' });
      setMessage(
        verb === 'approve'
          ? `Approved and executed. ${res?.result?.ok ? 'Succeeded.' : 'Execution failed: ' + (res?.result?.error?.message || '')}`
          : 'Rejected.'
      );
      reload();
    } catch (e) {
      setMessage(`Action failed: ${e.message}`);
    } finally {
      setBusy('');
    }
  };

  const approveAllSafe = async () => {
    setBusy('bulk');
    setMessage('');
    try {
      const res = await api('approvals/approve-safe', { method: 'POST' });
      setMessage(`Bulk-approved ${res.approved} request(s); ${res.executed_ok} executed successfully.`);
      reload();
    } catch (e) {
      setMessage(`Bulk approval failed: ${e.message}`);
    } finally {
      setBusy('');
    }
  };

  const pending = data?.pending || [];
  const history = data?.history || [];

  return (
    <div className="aios-section">
      <SectionHeader
        title="Approvals"
        subtitle="Human gate for sensitive and destructive AI actions"
        onRefresh={reload}
        action={pending.length > 0 ? <button className="aios-btn" onClick={approveAllSafe} disabled={busy !== ''}>Approve all safe</button> : null}
      />
      {message && <div className="aios-toast">{message}</div>}
      {pending.length === 0 ? (
        <EmptyState title="No pending approvals" body="Actions that need your approval queue here. Until approved, the AI is explicitly told the action was NOT executed." />
      ) : (
        <div className="aios-approval-list">
          {pending.map((a) => (
            <div key={a.id} className="aios-approval-card">
              <div className="aios-approval-head">
                <span className="aios-mono aios-tool-name">{a.tool}</span>
                <span className={`aios-chip ${RISK_COLORS[a.risk]}`}>{RISK_NAMES[a.risk]}</span>
                <span className="aios-muted">#{a.id} · expires {fmtDate(a.expires_at)}</span>
              </div>
              <p className="aios-approval-reason">{a.reason}</p>
              <details className="aios-approval-args">
                <summary>Arguments</summary>
                <pre className="aios-pre">{JSON.stringify(a.args, null, 2)}</pre>
              </details>
              <div className="aios-approval-actions">
                <button className="aios-btn primary" disabled={busy !== ''} onClick={() => act(a.id, 'approve')}>
                  {busy === `${a.id}:approve` ? 'Executing…' : 'Approve & execute'}
                </button>
                <button className="aios-btn danger" disabled={busy !== ''} onClick={() => act(a.id, 'reject')}>
                  {busy === `${a.id}:reject` ? 'Rejecting…' : 'Reject'}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
      {history.length > 0 && (
        <Panel title="Recent decisions">
          <table className="aios-table">
            <thead><tr><th>When</th><th>Tool</th><th>Risk</th><th>Status</th><th>Exec</th></tr></thead>
            <tbody>
              {history.slice(0, 20).map((h) => (
                <tr key={h.id}>
                  <td>{fmtDate(h.decided_at || h.created_at)}</td>
                  <td className="aios-mono">{h.tool}</td>
                  <td>{RISK_NAMES[h.risk]}</td>
                  <td>{h.status}</td>
                  <td>{h.execution_status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}

/* ---------------------------------------------------------- activity */

function ActivityView() {
  const [filters, setFilters] = useState({ status: '', tool: '', risk: '' });
  const query = Object.entries(filters).filter(([, v]) => v !== '').map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
  const { data, error, loading, reload } = useApi(`logs${query ? '?' + query : ''}`);

  return (
    <div className="aios-section">
      <SectionHeader title="Activity" subtitle="Every AI action, audited" onRefresh={reload} />
      <div className="aios-toolbar">
        <select className="aios-select" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
          <option value="">All statuses</option>
          {['ok', 'error', 'blocked', 'rejected', 'approval_required'].map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <select className="aios-select" value={filters.risk} onChange={(e) => setFilters({ ...filters, risk: e.target.value })}>
          <option value="">All risk levels</option>
          {[0, 1, 2, 3, 4].map((r) => <option key={r} value={r}>{RISK_NAMES[r]}</option>)}
        </select>
        <input className="aios-input" placeholder="Filter by tool name…" value={filters.tool} onChange={(e) => setFilters({ ...filters, tool: e.target.value })} />
      </div>
      {loading ? <Loading /> : error ? <ErrorBox message={error} onRetry={reload} /> : (
        <Panel title={`Audit log (${data?.count ?? 0})`}>
          <table className="aios-table">
            <thead><tr><th>When</th><th>Tool</th><th>Status</th><th>Risk</th><th>Client</th><th>Duration</th><th>Error</th></tr></thead>
            <tbody>
              {(data?.items || []).map((l) => (
                <tr key={l.id} className={l.status !== 'ok' ? `aios-row-${l.status}` : ''}>
                  <td>{fmtDate(l.occurred_at)}</td>
                  <td className="aios-mono">{l.tool}</td>
                  <td><span className={`aios-chip ${l.status === 'ok' ? 'ok' : l.status === 'blocked' || l.status === 'error' ? 'danger' : 'warn'}`}>{l.status}</span></td>
                  <td>{RISK_NAMES[l.risk]}</td>
                  <td>{l.client}</td>
                  <td>{l.duration_ms} ms</td>
                  <td className="aios-err-cell">{l.error || ''}</td>
                </tr>
              ))}
              {(data?.items || []).length === 0 && <tr><td colSpan={7} className="aios-muted">No audit entries match.</td></tr>}
            </tbody>
          </table>
        </Panel>
      )}
    </div>
  );
}

/* ---------------------------------------------------------------- mcp */

function McpView() {
  const { data: status } = useApi('status');
  const { data: settings } = useApi('settings');
  const { data: keys, reload: reloadKeys, error: keysError } = useApi('keys');
  const [newKey, setNewKey] = useState(null);
  const [form, setForm] = useState({ label: '', max_level: 1, user_id: 1 });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const createKey = async () => {
    setBusy(true);
    setError('');
    try {
      const res = await api('keys', {
        method: 'POST',
        body: JSON.stringify({ label: form.label, max_level: form.max_level, user_id: Number(form.user_id) }),
      });
      setNewKey(res.key);
      setForm({ ...form, label: '' });
      reloadKeys();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const revokeKey = async (id) => {
    setBusy(true);
    try {
      await api(`keys/${id}/revoke`, { method: 'POST' });
      reloadKeys();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const endpoint = status?.mcp?.endpoint || BOOT.mcp?.endpoint || '';

  return (
    <div className="aios-section">
      <SectionHeader title="MCP & Clients" subtitle="Connect ChatGPT, Claude, Claude Code and Cursor" />
      <div className="aios-grid-2">
        <Panel title="MCP server">
          <div className="aios-kv">
            <Row k="Endpoint" v={<code className="aios-code-inline">{endpoint}</code>} />
            <Row k="Protocol" v={status?.mcp?.protocol || BOOT.mcp?.protocol || ''} />
            <Row k="Status" v={(settings?.settings?.mcp_enabled ?? true) ? 'enabled' : 'disabled'} />
            <Row k="Tools exposed" v={status?.tools?.available ?? '—'} />
          </div>
          <h4 className="aios-subhead">Client configuration</h4>
          <p className="aios-muted aios-note">Add the server to your MCP client config:</p>
          <pre className="aios-pre">{JSON.stringify({
            mcpServers: {
              'ai-wordpress-os': {
                type: 'http',
                url: endpoint,
                headers: { Authorization: 'Basic <base64(user:application-password)>' },
              },
            },
          }, null, 2)}</pre>
          <p className="aios-muted aios-note">
            Create an Application Password under Users → Profile → Application Passwords, then base64-encode
            <code className="aios-code-inline">username:xxxx xxxx xxxx xxxx</code> for the Authorization header.
            Or use an AI OS API key with the <code className="aios-code-inline">X-AI-OS-Key</code> header (keys below).
          </p>
        </Panel>
        <Panel title="AI OS API keys">
          {newKey && (
            <div className="aios-key-reveal">
              <strong>New key (shown once):</strong>
              <code className="aios-key-code">{newKey.key}</code>
              <span className="aios-muted">Store it now — only its hash is saved.</span>
            </div>
          )}
          {error && <div className="aios-error-text">{error}</div>}
          {keysError && <div className="aios-error-text">{keysError}</div>}
          <div className="aios-key-form">
            <input className="aios-input" placeholder="Key label (e.g. Claude Code)" value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} />
            <input className="aios-input aios-input-num" type="number" min="1" placeholder="User id" value={form.user_id} onChange={(e) => setForm({ ...form, user_id: Number(e.target.value) })} />
            <select className="aios-select" value={form.max_level} onChange={(e) => setForm({ ...form, max_level: Number(e.target.value) })}>
              {[0, 1, 2].map((l) => <option key={l} value={l}>Level {l} ({RISK_NAMES[l]})</option>)}
            </select>
            <button className="aios-btn primary" onClick={createKey} disabled={busy || !form.label}>Issue key</button>
          </div>
          <table className="aios-table">
            <thead><tr><th>Label</th><th>Prefix</th><th>Level</th><th>Last used</th><th></th></tr></thead>
            <tbody>
              {(keys?.keys || []).map((k) => (
                <tr key={k.id} className={k.revoked_at ? 'aios-row-revoked' : ''}>
                  <td>{k.label}</td>
                  <td className="aios-mono">{k.key_prefix}…</td>
                  <td>{k.max_level}</td>
                  <td>{fmtDate(k.last_used_at)}</td>
                  <td>{!k.revoked_at && <button className="aios-btn small danger" onClick={() => revokeKey(k.id)} disabled={busy}>Revoke</button>}</td>
                </tr>
              ))}
              {(keys?.keys || []).length === 0 && <tr><td colSpan={5} className="aios-muted">No keys issued.</td></tr>}
            </tbody>
          </table>
        </Panel>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------- security */

function SecurityView() {
  const { data, error, loading, reload } = useApi('settings');

  if (loading) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const s = data?.settings || {};
  const max = { safe: 1, balanced: 2, advanced: 3 }[s.mode] ?? 1;
  const threshold = { safe: 2, balanced: 3, advanced: 4 }[s.mode] ?? 2;

  return (
    <div className="aios-section">
      <SectionHeader title="Security" subtitle="Permission levels and hardening" onRefresh={reload} />
      <div className="aios-grid-2">
        <Panel title={`Permission levels (mode: ${s.mode})`}>
          <table className="aios-table">
            <thead><tr><th>Level</th><th>Scope</th><th>Auto-execute</th><th>Approval</th></tr></thead>
            <tbody>
              {[
                { l: 0, d: 'Read: site/content/theme inspection' },
                { l: 1, d: 'Safe write: create/update posts, pages, media' },
                { l: 2, d: 'Sensitive: trash content, flush cache' },
                { l: 3, d: 'Destructive: delete files, media' },
                { l: 4, d: 'Deployment: publish staged changes (Phase 2+)' },
              ].map((row) => (
                <tr key={row.l}>
                  <td>{row.l}</td>
                  <td>{row.d}</td>
                  <td>{row.l <= max ? <span className="aios-chip ok">allowed</span> : <span className="aios-chip warn">gated</span>}</td>
                  <td>{row.l >= threshold ? <span className="aios-chip danger">required</span> : <span className="aios-chip ok">not required</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>
        <Panel title="Hardening">
          <div className="aios-kv">
            <Row k="Mode" v={s.mode} />
            <Row k="HTTPS required" v={s.require_https ? 'yes' : 'no'} />
            <Row k="File inspection" v={s.file_read_enabled ? 'enabled (read-only)' : 'disabled'} />
            <Row k="Max read size" v={`${Math.round((s.file_read_max_bytes || 0) / 1024)} KB`} />
            <Row k="Rate limit (requests/min)" v={s.rate_limit_requests} />
            <Row k="Rate limit (executions/min)" v={s.rate_limit_executions} />
            <Row k="Approval TTL" v={`${s.approval_ttl_minutes} min`} />
            <Row k="Audit retention" v={`${s.audit_retention_days} days`} />
          </div>
          <p className="aios-muted aios-note">
            Escalation guard: tools can never create admins, change roles, edit AI OS settings, or touch their own
            audit trail — refused at the executor level and logged as blocked.
          </p>
        </Panel>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------- settings */

function SettingsView() {
  const { data, error, loading, reload } = useApi('settings');
  const [form, setForm] = useState(null);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (data?.settings && !form) setForm({ ...data.settings });
  }, [data, form]);

  if (loading) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!form) return <Loading />;

  const save = async () => {
    setBusy(true);
    setMessage('');
    try {
      const res = await api('settings', { method: 'POST', body: JSON.stringify(form) });
      setMessage('Settings saved.');
      setForm({ ...res.settings });
      reload();
    } catch (e) {
      setMessage(`Save failed: ${e.message}`);
    } finally {
      setBusy(false);
    }
  };

  const set = (k, v) => setForm({ ...form, [k]: v });

  return (
    <div className="aios-section">
      <SectionHeader title="Settings" subtitle="General, security, limits and logging" onRefresh={reload} />
      {message && <div className="aios-toast">{message}</div>}
      <div className="aios-grid-2">
        <Panel title="General & security">
          <Field label="Security mode">
            <select className="aios-select" value={form.mode} onChange={(e) => set('mode', e.target.value)}>
              <option value="safe">Safe (approval for level 2+)</option>
              <option value="balanced">Balanced (approval for level 3+)</option>
              <option value="advanced">Advanced (approval for level 4)</option>
            </select>
          </Field>
          <Field label="MCP server">
            <Toggle checked={!!form.mcp_enabled} onChange={(v) => set('mcp_enabled', v)} />
          </Field>
          <Field label="Require HTTPS for MCP/REST">
            <Toggle checked={!!form.require_https} onChange={(v) => set('require_https', v)} />
          </Field>
          <Field label="AI OS API keys enabled">
            <Toggle checked={!!form.api_keys_enabled} onChange={(v) => set('api_keys_enabled', v)} />
          </Field>
          <Field label="Approval TTL (minutes)">
            <input type="number" min="1" max="1440" className="aios-input" value={form.approval_ttl_minutes} onChange={(e) => set('approval_ttl_minutes', Number(e.target.value))} />
          </Field>
        </Panel>
        <Panel title="Limits, files, logging">
          <Field label="Rate limit — MCP requests/min">
            <input type="number" min="10" max="10000" className="aios-input" value={form.rate_limit_requests} onChange={(e) => set('rate_limit_requests', Number(e.target.value))} />
          </Field>
          <Field label="Rate limit — tool executions/min">
            <input type="number" min="10" max="10000" className="aios-input" value={form.rate_limit_executions} onChange={(e) => set('rate_limit_executions', Number(e.target.value))} />
          </Field>
          <Field label="File inspection (read-only)">
            <Toggle checked={!!form.file_read_enabled} onChange={(v) => set('file_read_enabled', v)} />
          </Field>
          <Field label="Max file read size (KB)">
            <input type="number" min="1" max="5120" className="aios-input" value={Math.round(form.file_read_max_bytes / 1024)} onChange={(e) => set('file_read_max_bytes', Number(e.target.value) * 1024)} />
          </Field>
          <Field label="Store (redacted) tool arguments in audit log">
            <Toggle checked={!!form.audit_log_args} onChange={(v) => set('audit_log_args', v)} />
          </Field>
          <Field label="Audit retention (days)">
            <input type="number" min="7" max="3650" className="aios-input" value={form.audit_retention_days} onChange={(e) => set('audit_retention_days', Number(e.target.value))} />
          </Field>
          <Field label="Remove all AI OS data on uninstall">
            <Toggle checked={!!form.remove_data_on_uninstall} onChange={(v) => set('remove_data_on_uninstall', v)} />
          </Field>
        </Panel>
      </div>
      <div className="aios-actions">
        <button className="aios-btn primary" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save settings'}</button>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------- roadmap */

function RoadmapView() {
  const phases = BOOT.phases || {};
  return (
    <div className="aios-section">
      <SectionHeader title="Roadmap" subtitle="What is shipped vs. in development" />
      <Panel title="Phase status">
        <div className="aios-kv">
          {Object.entries(phases).map(([key, label]) => (
            <Row key={key} k={key.toUpperCase()} v={label} />
          ))}
        </div>
        <p className="aios-muted aios-note">
          Phase 1 ships the complete security, approval, audit and MCP foundation plus inspection/content tools.
          Theme/plugin file writing, rollback, tasks and testing land in Phase 2; builder adapters (Elementor,
          WooCommerce, ACF, SEO) in Phase 3; multi-agent, visual browser, sandbox and Figma in Phase 4.
          No buttons exist for unshipped features.
        </p>
      </Panel>
    </div>
  );
}

/* --------------------------------------------------------- primitives */

function Loading() {
  return <div className="aios-loading"><span className="aios-spinner" /> Loading…</div>;
}

function ErrorBox({ message, onRetry }) {
  return (
    <div className="aios-error-box">
      <div>{message}</div>
      {onRetry && <button className="aios-btn" onClick={onRetry}>Retry</button>}
    </div>
  );
}

function SectionHeader({ title, subtitle, onRefresh, refreshing, action }) {
  return (
    <div className="aios-section-head">
      <div>
        <h2>{title}</h2>
        {subtitle && <p className="aios-muted">{subtitle}</p>}
      </div>
      <div className="aios-section-head-actions">
        {action}
        {onRefresh && <button className="aios-btn small" onClick={onRefresh} disabled={refreshing}>{refreshing ? '…' : '↻ Refresh'}</button>}
      </div>
    </div>
  );
}

function StatCard({ label, value, sub, tone = 'info' }) {
  return (
    <div className={`aios-stat aios-stat-${tone}`}>
      <div className="aios-stat-label">{label}</div>
      <div className="aios-stat-value">{value}</div>
      {sub && <div className="aios-stat-sub">{sub}</div>}
    </div>
  );
}

function Panel({ title, children }) {
  return (
    <div className="aios-panel">
      <h3 className="aios-panel-title">{title}</h3>
      {children}
    </div>
  );
}

function Row({ k, v }) {
  return <div className="aios-row"><span className="aios-row-k">{k}</span><span className="aios-row-v">{v}</span></div>;
}

function Field({ label, children }) {
  return (
    <label className="aios-field">
      <span className="aios-field-label">{label}</span>
      {children}
    </label>
  );
}

function Toggle({ checked, onChange }) {
  return (
    <button type="button" className={`aios-toggle ${checked ? 'on' : ''}`} onClick={() => onChange(!checked)} role="switch" aria-checked={checked}>
      <span className="aios-toggle-knob" />
    </button>
  );
}

function EmptyState({ title, body }) {
  return (
    <div className="aios-empty">
      <h3>{title}</h3>
      <p className="aios-muted">{body}</p>
    </div>
  );
}

/* -------------------------------------------------------------- mount */

const rootEl = document.getElementById('ai-os-app');
if (rootEl) {
  createRoot(rootEl).render(<App />);
}
