(function () {
  "use strict";

  const rowsEl = document.getElementById("optipower-rows");
  const summaryEl = document.getElementById("optipower-summary");
  const healthKpisEl = document.getElementById("optipower-health-kpis");
  const healthCanvas = document.getElementById("optipower-health-chart");
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

  async function fetchHealth() {
    try {
      const res = await fetch(window.OptiPowerData.healthEndpoint, { headers, credentials: "same-origin" });
      if (!res.ok) throw new Error(`REST health HTTP ${res.status}`);
      return await res.json();
    } catch (e) {
      const params = new URLSearchParams({ action: "optipower_get_health" });
      const ajaxRes = await fetch(window.OptiPowerData.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: params.toString(),
      });
      const ajaxData = await ajaxRes.json();
      if (!ajaxData || !ajaxData.success) {
        throw new Error((ajaxData && ajaxData.data && ajaxData.data.error) || e.message || "Failed health");
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

  function renderHealth(health) {
    if (!healthKpisEl || !healthCanvas || !health) return;

    const current = health.current || {};
    const context = current.context || {};
    const components = current.components || {};
    const trend = health.trend || "stable";
    const score = Number(current.score || 0);

    healthKpisEl.innerHTML = `
      <div class="optipower-health-kpi">
        <strong>Current Score</strong>
        <span>${esc(score)}</span>
      </div>
      <div class="optipower-health-kpi">
        <strong>Trend</strong>
        <span>${esc(trend)}</span>
      </div>
      <div class="optipower-health-kpi">
        <strong>24h Avg</strong>
        <span>${esc(Number(context.recent_avg_ms || 0).toFixed(2))} ms</span>
      </div>
      <div class="optipower-health-kpi">
        <strong>24h Peak</strong>
        <span>${esc(Number(context.recent_max_ms || 0).toFixed(2))} ms</span>
      </div>
      <div class="optipower-health-kpi">
        <strong>24h Slow Queries</strong>
        <span>${esc(context.recent_total_logs || 0)}</span>
      </div>
      <div class="optipower-health-kpi">
        <strong>Score Drivers</strong>
        <span>Avg -${esc(components.db_avg_penalty || 0)} | Peak -${esc(components.db_peak_penalty || 0)} | Volume -${esc(components.volume_penalty || 0)}</span>
      </div>
    `;

    drawHealthChart(healthCanvas, Array.isArray(health.history) ? health.history : []);
  }

  function drawHealthChart(canvas, history) {
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const width = canvas.width;
    const height = canvas.height;
    ctx.clearRect(0, 0, width, height);

    // Background
    ctx.fillStyle = "#f8fcf8";
    ctx.fillRect(0, 0, width, height);

    // Grid
    ctx.strokeStyle = "#dce9de";
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const y = 20 + (i * (height - 40)) / 4;
      ctx.beginPath();
      ctx.moveTo(40, y);
      ctx.lineTo(width - 20, y);
      ctx.stroke();
    }

    if (!Array.isArray(history) || history.length === 0) {
      ctx.fillStyle = "#666B6A";
      ctx.font = "14px Avenir Next, sans-serif";
      ctx.fillText("No weekly snapshots yet. First snapshot is generated automatically.", 50, height / 2);
      return;
    }

    const points = history.map((row, index) => {
      const score = Math.max(0, Math.min(100, Number(row.score || 0)));
      const x = 40 + (index * (width - 60)) / Math.max(1, history.length - 1);
      const y = 20 + ((100 - score) * (height - 40)) / 100;
      return { x, y, score, label: String((row.created_at || "")).slice(0, 10) };
    });

    // Line
    ctx.strokeStyle = "#72A276";
    ctx.lineWidth = 2;
    ctx.beginPath();
    points.forEach((p, i) => {
      if (i === 0) ctx.moveTo(p.x, p.y);
      else ctx.lineTo(p.x, p.y);
    });
    ctx.stroke();

    // Points
    points.forEach((p) => {
      ctx.fillStyle = "#86CD82";
      ctx.beginPath();
      ctx.arc(p.x, p.y, 3.5, 0, Math.PI * 2);
      ctx.fill();
    });

    // Labels (first/last)
    const first = points[0];
    const last = points[points.length - 1];
    ctx.fillStyle = "#666B6A";
    ctx.font = "12px Avenir Next, sans-serif";
    ctx.fillText(first.label, first.x - 20, height - 6);
    if (points.length > 1) {
      ctx.fillText(last.label, last.x - 20, height - 6);
    }
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
      const [summary, logs, health] = await Promise.all([fetchSummary(), fetchLogs(), fetchHealth()]);
      renderSummary(summary);
      renderRows(logs);
      renderHealth(health);
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
  refresh();
})();
