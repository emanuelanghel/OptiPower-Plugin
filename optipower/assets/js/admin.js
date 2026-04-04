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

  function metricClass(ok) {
    return ok ? "optipower-kpi-good" : "optipower-kpi-bad";
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
    const avgMs = Number(s.avg_duration_ms || 0);
    const maxMs = Number(s.max_duration_ms || 0);
    const totalLogs = Number(s.total_logs || 0);
    const insertFailures = Number(d.insert_failures || 0);
    const dbError = String(d.db_last_error || "none").toLowerCase();
    const collectorOk = String(d.reason || "") === "ok";
    summaryEl.innerHTML = `
      <div class="optipower-summary-card ${metricClass(totalLogs <= 80)}">
        <strong>Total Logs</strong>
        <span>${esc(totalLogs)}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(avgMs <= 120)}">
        <strong>Average</strong>
        <span>${esc(avgMs.toFixed(2))} ms</span>
      </div>
      <div class="optipower-summary-card ${metricClass(maxMs <= 400)}">
        <strong>Peak</strong>
        <span>${esc(maxMs.toFixed(2))} ms</span>
      </div>
      <div class="optipower-summary-card ${metricClass(available)}">
        <strong>Instrumentation</strong>
        <span>${esc(status)}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(Number(d.queries_seen || 0) > 0)}">
        <strong>Queries Seen</strong>
        <span>${esc(d.queries_seen || 0)}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(Number(d.captured_logs || 0) > 0 || totalLogs > 0)}">
        <strong>Captured (Last)</strong>
        <span>${esc(d.captured_logs || 0)}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(collectorOk)}">
        <strong>Collector State</strong>
        <span>${esc(d.reason || "n/a")}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(!!d.last_run)}">
        <strong>Last Run</strong>
        <span>${esc(d.last_run || "n/a")}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(insertFailures === 0)}">
        <strong>Insert Failures</strong>
        <span>${esc(insertFailures)}</span>
      </div>
      <div class="optipower-summary-card ${metricClass(dbError === "none" || dbError === "")}">
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
    const trend = health.trend || "stable";
    const score = Number(current.score || 0);
    const avgMs = Number(context.recent_avg_ms || 0);
    const peakMs = Number(context.recent_max_ms || 0);
    const totalLogs = Number(context.recent_total_logs || 0);
    const p3 = Number(context.priority_p3 || 0);
    const p4 = Number(context.priority_p4 || 0);

    healthKpisEl.innerHTML = `
      <div class="optipower-health-kpi ${metricClass(score >= 80)}">
        <strong>Current Score</strong>
        <span>${esc(score)}</span>
      </div>
      <div class="optipower-health-kpi ${metricClass(trend !== "down")}">
        <strong>Trend</strong>
        <span>${esc(trend)}</span>
      </div>
      <div class="optipower-health-kpi ${metricClass(avgMs <= 120)}">
        <strong>24h Avg</strong>
        <span>${esc(avgMs.toFixed(2))} ms</span>
      </div>
      <div class="optipower-health-kpi ${metricClass(peakMs <= 400)}">
        <strong>24h Peak</strong>
        <span>${esc(peakMs.toFixed(2))} ms</span>
      </div>
      <div class="optipower-health-kpi ${metricClass(totalLogs <= 40)}">
        <strong>24h Slow Queries</strong>
        <span>${esc(totalLogs)}</span>
      </div>
      <div class="optipower-health-kpi ${metricClass((p3 + p4) === 0)}">
        <strong>Duration Priority</strong>
        <span>P1 ${esc(context.priority_p1 || 0)} | P2 ${esc(context.priority_p2 || 0)} | P3 ${esc(context.priority_p3 || 0)} | P4 ${esc(context.priority_p4 || 0)}</span>
      </div>
    `;

    drawHealthDonut(healthCanvas, score, trend, Array.isArray(health.history) ? health.history.length : 0);
  }

  function drawHealthDonut(canvas, score, trend, points) {
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const width = canvas.width;
    const height = canvas.height;
    ctx.clearRect(0, 0, width, height);

    ctx.fillStyle = "#f8fcf8";
    ctx.fillRect(0, 0, width, height);

    const cx = width / 2;
    const cy = height / 2 - 6;
    const r = Math.min(width, height) * 0.28;
    const lineW = 20;
    const start = -Math.PI / 2;
    const normalized = Math.max(0, Math.min(100, Number(score || 0)));
    const end = start + (normalized / 100) * Math.PI * 2;

    let color = "#86CD82";
    if (normalized >= 85) color = "#72A276";
    else if (normalized >= 65) color = "#86CD82";
    else if (normalized >= 45) color = "#8b7a39";
    else color = "#915c5f";

    ctx.strokeStyle = "#dce9de";
    ctx.lineWidth = lineW;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();

    ctx.strokeStyle = color;
    ctx.lineCap = "round";
    ctx.beginPath();
    ctx.arc(cx, cy, r, start, end);
    ctx.stroke();
    ctx.lineCap = "butt";

    ctx.fillStyle = "#2f3534";
    ctx.font = "700 42px Avenir Next, sans-serif";
    const scoreText = String(Math.round(normalized));
    const tw = ctx.measureText(scoreText).width;
    ctx.fillText(scoreText, cx - tw / 2, cy + 12);

    ctx.fillStyle = "#666B6A";
    ctx.font = "600 12px Avenir Next, sans-serif";
    const subtitle = "Health Score";
    const sw = ctx.measureText(subtitle).width;
    ctx.fillText(subtitle, cx - sw / 2, cy + 32);

    const trendText = `Trend: ${trend || "stable"} | Weekly points: ${points}`;
    const tw2 = ctx.measureText(trendText).width;
    ctx.fillText(trendText, cx - tw2 / 2, height - 16);

    if (points === 0) {
      const note = "First weekly snapshot will appear automatically.";
      const nw = ctx.measureText(note).width;
      ctx.fillText(note, cx - nw / 2, 24);
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
