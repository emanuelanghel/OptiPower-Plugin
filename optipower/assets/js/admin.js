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
    try {
      const res = await fetch(window.OptiPowerData.summaryEndpoint, { headers, credentials: "same-origin" });
      if (!res.ok) throw new Error(`REST summary HTTP ${res.status}`);
      return await res.json();
    } catch (e) {
      const params = new URLSearchParams({ action: "optipower_get_summary" });
      const ajaxRes = await fetch(window.OptiPowerData.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: params.toString(),
      });
      const ajaxData = await ajaxRes.json();
      if (!ajaxData || !ajaxData.success) {
        throw new Error((ajaxData && ajaxData.data && ajaxData.data.error) || e.message || "Failed summary");
      }
      return ajaxData.data;
    }
  }

  async function fetchLogs() {
    const minDuration = Number(minDurationEl.value || 0);
    const url = `${window.OptiPowerData.logsEndpoint}?limit=25&min_duration=${encodeURIComponent(minDuration)}`;
    try {
      const res = await fetch(url, { headers, credentials: "same-origin" });
      if (!res.ok) throw new Error(`REST logs HTTP ${res.status}`);
      return await res.json();
    } catch (e) {
      const params = new URLSearchParams({
        action: "optipower_get_logs",
        limit: "25",
        min_duration: String(minDuration),
      });
      const ajaxRes = await fetch(window.OptiPowerData.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: params.toString(),
      });
      const ajaxData = await ajaxRes.json();
      if (!ajaxData || !ajaxData.success) {
        throw new Error((ajaxData && ajaxData.data && ajaxData.data.error) || e.message || "Failed logs");
      }
      return ajaxData.data;
    }
  }

  async function analyzeWithAI(queryHash) {
    try {
      const res = await fetch(window.OptiPowerData.analyzeEndpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          ...headers,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ query_hash: queryHash }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data && data.error ? data.error : `REST analyze HTTP ${res.status}`);
      }
      return data;
    } catch (e) {
      const params = new URLSearchParams({
        action: "optipower_ai_analyze",
        query_hash: queryHash,
      });
      const ajaxRes = await fetch(window.OptiPowerData.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: params.toString(),
      });
      const ajaxData = await ajaxRes.json();
      if (!ajaxData || !ajaxData.success) {
        throw new Error((ajaxData && ajaxData.data && ajaxData.data.error) || e.message || "AI analysis failed");
      }
      return ajaxData.data;
    }
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
      <div class="optipower-summary-card">
        <strong>Insert Failures</strong>
        <span>${esc(d.insert_failures || 0)}</span>
      </div>
      <div class="optipower-summary-card">
        <strong>DB Error</strong>
        <span>${esc(d.db_last_error || "none")}</span>
      </div>
    `;
  }

  function renderRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      rowsEl.innerHTML = '<tr><td colspan="9">No matching logs yet.</td></tr>';
      return;
    }

    rowsEl.innerHTML = rows
      .map((row) => {
        const hash = esc(row.query_hash || "");
        return `
          <tr>
            <td>${esc(Number(row.duration_ms || 0).toFixed(2))} ms</td>
            <td>${esc(row.source_type)} (${esc(row.source_hint)})</td>
            <td>${esc(row.request_uri)}</td>
            <td><span class="optipower-sev optipower-sev-${esc(row.severity)}">${esc(row.severity)}</span></td>
            <td>${esc(row.recommendation)}</td>
            <td><code>${esc((row.query_sample || "").slice(0, 180))}</code></td>
            <td id="optipower-ai-${hash}" class="optipower-ai-cell">Not analyzed</td>
            <td>
              <button class="button button-small optipower-ai-btn" data-query-hash="${hash}">Analyze with AI</button>
            </td>
            <td>${esc(row.created_at)}</td>
          </tr>
        `;
      })
      .join("");
  }

  function renderAIResult(targetEl, analysis, cached) {
    const fixes = Array.isArray(analysis && analysis.fixes) ? analysis.fixes.slice(0, 2) : [];
    const fixesHtml = fixes
      .map((f) => `<li><strong>${esc(f.title || "Fix")}:</strong> ${esc(f.action || "")}</li>`)
      .join("");
    targetEl.innerHTML = `
      <div class="optipower-ai-result">
        <p><strong>${cached ? "Cached" : "Fresh"}:</strong> ${esc((analysis && analysis.summary) || "No summary")}</p>
        <p><strong>Root cause:</strong> ${esc((analysis && analysis.root_cause) || "n/a")}</p>
        <p><strong>Confidence:</strong> ${esc(Number((analysis && analysis.confidence) || 0).toFixed(2))}</p>
        ${fixesHtml ? `<ul>${fixesHtml}</ul>` : ""}
      </div>
    `;
  }

  async function onAnalyzeClick(btn) {
    const hash = btn.getAttribute("data-query-hash");
    const target = document.getElementById(`optipower-ai-${hash}`);
    if (!hash || !target) return;

    btn.disabled = true;
    btn.textContent = "Analyzing...";
    target.textContent = "Running AI analysis...";
    try {
      const result = await analyzeWithAI(hash);
      renderAIResult(target, result.analysis || {}, !!result.cached);
    } catch (e) {
      target.textContent = e.message || "AI analysis failed.";
    } finally {
      btn.disabled = false;
      btn.textContent = "Analyze with AI";
    }
  }

  async function refresh() {
    refreshBtn.disabled = true;
    refreshBtn.textContent = "Refreshing...";
    try {
      const [summary, logs] = await Promise.all([fetchSummary(), fetchLogs()]);
      renderSummary(summary);
      renderRows(logs);
    } catch (e) {
      rowsEl.innerHTML = `<tr><td colspan="9">Failed to load data: ${esc(e.message || "unknown error")}</td></tr>`;
    } finally {
      refreshBtn.disabled = false;
      refreshBtn.textContent = "Refresh Now";
    }
  }

  rowsEl.addEventListener("click", (event) => {
    const btn = event.target && event.target.closest(".optipower-ai-btn");
    if (btn) {
      onAnalyzeClick(btn);
    }
  });

  refreshBtn.addEventListener("click", refresh);
  setInterval(refresh, 5000);
  refresh();
})();
