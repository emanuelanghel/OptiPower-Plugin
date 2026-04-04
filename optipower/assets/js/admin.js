(function () {
  "use strict";

  const rowsEl = document.getElementById("optipower-rows");
  const summaryEl = document.getElementById("optipower-summary");
  const minDurationEl = document.getElementById("optipower-min-duration");
  const refreshBtn = document.getElementById("optipower-refresh");

  if (!rowsEl || !summaryEl || !minDurationEl || !refreshBtn || !window.OptiPowerData) {
    return;
  }

  const headers = {
    "X-WP-Nonce": window.OptiPowerData.nonce,
  };

  function esc(text) {
    const div = document.createElement("div");
    div.textContent = String(text == null ? "" : text);
    return div.innerHTML;
  }

  async function fetchSummary() {
    const res = await fetch(window.OptiPowerData.summaryEndpoint, { headers });
    if (!res.ok) throw new Error("Failed summary");
    return res.json();
  }

  async function fetchLogs() {
    const minDuration = Number(minDurationEl.value || 0);
    const url = `${window.OptiPowerData.logsEndpoint}?limit=25&min_duration=${encodeURIComponent(minDuration)}`;
    const res = await fetch(url, { headers });
    if (!res.ok) throw new Error("Failed logs");
    return res.json();
  }

  function renderSummary(data) {
    const s = data && data.summary ? data.summary : {};
    const available = data && data.instrumentation_available;
    const d = data && data.monitor_debug ? data.monitor_debug : {};
    const status = available ? "Enabled" : "Unavailable";
    summaryEl.innerHTML = `
      <div class="optipower-summary-card">
        <strong>Total Logs</strong>
        <span>${esc(s.total_logs || 0)}</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Average</strong>
        <span>${esc(Number(s.avg_duration_ms || 0).toFixed(2))} ms</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Peak</strong>
        <span>${esc(Number(s.max_duration_ms || 0).toFixed(2))} ms</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Instrumentation</strong>
        <span>${esc(status)}</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Queries Seen</strong>
        <span>${esc(d.queries_seen || 0)}</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Captured (Last)</strong>
        <span>${esc(d.captured_logs || 0)}</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Collector State</strong>
        <span>${esc(d.reason || "n/a")}</span>
      </div>
      <div class="optipower-summary-card">
        <strong>Last Run</strong>
        <span>${esc(d.last_run || "n/a")}</span>
      </div>
    `;
  }

  function renderRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      rowsEl.innerHTML = '<tr><td colspan="7">No matching logs yet.</td></tr>';
      return;
    }

    rowsEl.innerHTML = rows
      .map((row) => {
        return `
          <tr>
            <td>${esc(Number(row.duration_ms || 0).toFixed(2))} ms</td>
            <td>${esc(row.source_type)} (${esc(row.source_hint)})</td>
            <td>${esc(row.request_uri)}</td>
            <td><span class="optipower-sev optipower-sev-${esc(row.severity)}">${esc(row.severity)}</span></td>
            <td>${esc(row.recommendation)}</td>
            <td><code>${esc((row.query_sample || "").slice(0, 180))}</code></td>
            <td>${esc(row.created_at)}</td>
          </tr>
        `;
      })
      .join("");
  }

  async function refresh() {
    refreshBtn.disabled = true;
    refreshBtn.textContent = "Refreshing...";
    try {
      const [summary, logs] = await Promise.all([fetchSummary(), fetchLogs()]);
      renderSummary(summary);
      renderRows(logs);
    } catch (e) {
      rowsEl.innerHTML = `<tr><td colspan="7">Failed to load data.</td></tr>`;
    } finally {
      refreshBtn.disabled = false;
      refreshBtn.textContent = "Refresh Now";
    }
  }

  refreshBtn.addEventListener("click", refresh);
  setInterval(refresh, 5000);
  refresh();
})();

