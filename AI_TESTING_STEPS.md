# OptiPower AI Testing Steps

Follow these steps to test the AI-powered query analysis in WordPress.

## 1) Update Plugin Files
- Replace your installed `optipower` plugin folder with this latest version.
- Reactivate plugin only if needed (normal update is enough).

## 2) Baseline Monitor Setup
- In `wp-config.php`, ensure:
```php
define('SAVEQUERIES', true);
```
- In `OptiPower > General`:
  - Enable Monitoring: ON
  - Slow Query Threshold: `10` (for testing)
- In `OptiPower > Cache`:
  - Disable page cache while testing Monitor behavior.

## 3) Configure AI
- Open `OptiPower > AI`.
- Set:
  - Enable AI Analysis: ON
  - Provider: `openai`
  - Model: `gpt-4.1-mini` (or your preferred model)
  - OpenAI API Key: your valid key (`sk-...`)
  - AI Cache (hours): `24`
  - Max AI Requests / Day: `100`
  - Redact Query Literals: ON
- Click `Save AI Settings`.

## 4) Generate Test Logs
- Open `OptiPower > Monitor`.
- Click `Run Monitor Self-Test`.
- Wait 2-5 seconds, then click `Refresh Now`.
- Confirm at least one row appears.

## 5) Run AI Analysis
- In the Monitor row, click `Analyze with AI`.
- Expected result in `AI Insight` column:
  - Summary
  - Root cause
  - Confidence
  - Top fixes

## 6) Validate Cache Behavior
- Click `Analyze with AI` again on the same query hash.
- Result should show as `Cached` (uses saved insight instead of new API call).

## 7) Troubleshooting
- If no logs appear:
  - Check Monitor summary cards:
    - `Collector State`
    - `Queries Seen`
    - `Insert Failures`
    - `DB Error`
- If AI analysis fails:
  - Recheck API key and model in AI tab.
  - Ensure hosting allows outbound HTTPS requests.
  - Verify daily limit has not been reached.

## 8) Production Guidance
- Raise threshold to realistic levels (e.g., `80-150ms`).
- Keep redaction enabled unless deep SQL literals are required.
- Set a daily AI request cap that matches your budget.

