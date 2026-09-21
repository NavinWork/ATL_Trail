<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Merchant Dashboard — {{ $data['merchant']['name'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-canvas: #0b0f17;
            --bg-surface: #121826;
            --bg-card: #182234;
            --bg-card-hover: #1e2b42;
            --border-subtle: #233047;
            --border-focus: #3b82f6;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --accent-blue: #3b82f6;
            --accent-cyan: #06b6d4;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-canvas);
            color: var(--text-primary);
            font-family: var(--font-sans);
            min-height: 100vh;
            line-height: 1.5;
            padding: 24px;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Top App Header */
        .app-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #162032 0%, #111726 100%);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 18px 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px -2px rgba(0,0,0,0.5);
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-badge {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-cyan));
            color: #fff;
            padding: 6px 10px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.5px;
        }

        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .header-meta {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .wireframe-badge {
            background: rgba(59, 130, 246, 0.12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.3);
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .api-doc-link {
            color: var(--text-secondary);
            font-size: 13px;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-subtle);
            background: rgba(255,255,255,0.02);
            transition: all 0.2s;
        }

        .api-doc-link:hover {
            color: #fff;
            border-color: var(--text-muted);
            background: rgba(255,255,255,0.06);
        }

        /* Top 3 KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 22px 24px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            border-color: #334155;
        }

        .kpi-card.highlight-amber {
            border-left: 4px solid var(--accent-amber);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, var(--bg-surface) 100%);
        }

        .kpi-card.highlight-blue {
            border-left: 4px solid var(--accent-blue);
        }

        .kpi-card.highlight-emerald {
            border-left: 4px solid var(--accent-emerald);
        }

        .kpi-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .kpi-value {
            font-size: 26px;
            font-weight: 800;
            color: #ffffff;
            font-family: var(--font-mono);
            letter-spacing: -0.03em;
        }

        .kpi-subtext {
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-bar-bg {
            background: rgba(255,255,255,0.08);
            height: 6px;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 12px;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-cyan));
            border-radius: 999px;
            transition: width 0.5s ease;
        }

        /* 2-Column Sections */
        .content-grid {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .panel {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 22px 24px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .panel-title {
            font-size: 15px;
            font-weight: 700;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-caption {
            font-size: 12px;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        /* Top 5 Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table th {
            text-align: left;
            padding: 10px 14px;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-subtle);
        }

        .data-table td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            color: var(--text-primary);
        }

        .data-table tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        .mono {
            font-family: var(--font-mono);
        }

        .pill-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            font-family: var(--font-mono);
        }

        .pill-high {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .pill-normal {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        /* Churn Risk Alert Box */
        .churn-box {
            border: 1px solid rgba(244, 63, 94, 0.4);
            background: linear-gradient(145deg, rgba(244, 63, 94, 0.08) 0%, rgba(18, 24, 38, 0.8) 100%);
        }

        .churn-title {
            color: #fb7185;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .churn-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 8px;
        }

        .churn-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(244, 63, 94, 0.2);
            padding: 12px 16px;
            border-radius: 8px;
        }

        .churn-name {
            font-weight: 600;
            font-size: 13px;
            color: #f1f5f9;
        }

        .churn-stats {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .churn-drop-badge {
            background: rgba(244, 63, 94, 0.2);
            color: #f43f5e;
            font-family: var(--font-mono);
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            border: 1px solid rgba(244, 63, 94, 0.4);
        }

        /* System Status Informational Box */
        .system-status-box {
            border: 1px solid rgba(6, 182, 212, 0.4);
            background: linear-gradient(145deg, rgba(6, 182, 212, 0.06) 0%, rgba(18, 24, 38, 0.8) 100%);
        }

        .system-status-title {
            color: #38bdf8;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 6px;
        }

        .status-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--accent-emerald);
            margin-top: 6px;
            box-shadow: 0 0 8px var(--accent-emerald);
        }

        .status-label {
            font-weight: 600;
            color: #e2e8f0;
        }

        .status-val {
            color: #94a3b8;
            font-family: var(--font-mono);
            font-size: 12px;
        }

        /* SVG Trend Chart */
        .chart-container {
            width: 100%;
            height: 180px;
            position: relative;
            margin-top: 10px;
        }

        .chart-svg {
            width: 100%;
            height: 100%;
            overflow: visible;
        }

        /* Quick Simulator Box */
        .simulator-box {
            margin-top: 24px;
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 20px 24px;
        }

        .simulator-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .simulator-title {
            font-size: 14px;
            font-weight: 700;
            color: #93c5fd;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sim-form {
            display: grid;
            grid-template-columns: repeat(4, 1fr) auto;
            gap: 12px;
            align-items: flex-end;
        }

        .sim-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sim-field input, .sim-field select {
            width: 100%;
            background: #1e293b;
            border: 1px solid #334155;
            color: #fff;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: var(--font-mono);
            font-size: 12px;
            outline: none;
        }

        .sim-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            font-weight: 600;
            font-size: 13px;
            padding: 9px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: opacity 0.2s;
            height: 38px;
        }

        .sim-btn:hover {
            opacity: 0.9;
        }

        .sim-status-output {
            margin-top: 12px;
            font-family: var(--font-mono);
            font-size: 12px;
            display: none;
            padding: 8px 12px;
            border-radius: 6px;
        }

        @media (max-width: 900px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            .content-grid {
                grid-template-columns: 1fr;
            }
            .sim-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <header class="app-header">
        <div class="header-title">
            <span class="brand-badge">SaaS Billing</span>
            <h1>Merchant Dashboard — {{ $data['merchant']['name'] }}</h1>
        </div>
        <div class="header-meta">
            <span class="wireframe-badge">WIREFRAME — layout reference</span>
            <a href="/api/merchants/{{ $data['merchant']['id'] }}/dashboard" target="_blank" class="api-doc-link">Raw API JSON →</a>
        </div>
    </header>

    <!-- Top 3 Metrics (Wireframe top panel) -->
    <section class="kpi-grid">
        <!-- Metric 1: Current Cycle Usage -->
        <div class="kpi-card highlight-blue">
            <div class="kpi-label">
                <span>Current Cycle Usage</span>
                <span>{{ $data['current_cycle_usage']['percentage'] }}%</span>
            </div>
            <div class="kpi-value">
                {{ number_format($data['current_cycle_usage']['total_units']) }} / {{ number_format($data['current_cycle_usage']['total_allowance']) }} <span style="font-size: 16px; color: var(--text-muted);">units</span>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width: {{ min(100, $data['current_cycle_usage']['percentage']) }}%;"></div>
            </div>
            <div class="kpi-subtext">
                Aggregated across {{ count($data['top_5_customers']) }}+ active merchant customer accounts
            </div>
        </div>

        <!-- Metric 2: Projected Overage Revenue -->
        <div class="kpi-card highlight-amber">
            <div class="kpi-label">
                <span>Projected Overage Revenue</span>
                <span style="color: var(--accent-amber);">Cycle End Projection</span>
            </div>
            <div class="kpi-value" style="color: #fbbf24;">
                {{ $data['merchant']['currency'] === 'INR' ? '₹' : '$' }} {{ number_format($data['projected_overage_revenue'], 2) }}
            </div>
            <div class="kpi-subtext">
                Calculated dynamically against tiered overage rates per active plan
            </div>
        </div>

        <!-- Metric 3: Active Plan -->
        <div class="kpi-card highlight-emerald">
            <div class="kpi-label">
                <span>Active Plan</span>
                <span class="pill-badge pill-normal">Active</span>
            </div>
            <div class="kpi-value" style="font-family: var(--font-sans); font-size: 24px;">
                {{ $data['active_plan']['name'] }} — {{ $data['active_plan']['billing_cycle'] }}
            </div>
            <div class="kpi-subtext">
                Base allowance: {{ number_format($data['active_plan']['included_units']) }} units / billing cycle
            </div>
        </div>
    </section>

    <!-- Row 2: Top 5 Customers & Churn Risk -->
    <section class="content-grid">
        <!-- Top 5 Customers Table -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">
                    <span>Top 5 Customers by Usage (this cycle)</span>
                </div>
                <div class="panel-caption">[top 5 sorted desc]</div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th style="text-align: right;">Usage</th>
                        <th style="text-align: right;">% of Allowance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data['top_5_customers'] as $cust)
                        <tr>
                            <td style="font-weight: 600;">{{ $cust['name'] }}</td>
                            <td class="mono" style="text-align: right; font-weight: 600;">
                                {{ number_format($cust['usage']) }}
                            </td>
                            <td style="text-align: right;">
                                <span class="pill-badge {{ $cust['percentage_of_allowance'] >= 80 ? 'pill-high' : 'pill-normal' }}">
                                    {{ $cust['percentage_of_allowance'] }}%
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                No usage events recorded yet for this cycle.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Churn Risk Alert Box (usage ↓ >50% MoM) -->
        <div class="panel churn-box">
            <div class="panel-header" style="border-bottom-color: rgba(244, 63, 94, 0.2);">
                <div class="panel-title churn-title">
                    <span>⚠️ Churn Risk (usage ↓ >50% MoM)</span>
                </div>
            </div>
            <ul class="churn-list">
                @forelse($data['churn_risk'] as $churn)
                    <li class="churn-item">
                        <div>
                            <div class="churn-name">{{ $churn['name'] }}</div>
                            <div class="churn-stats">
                                {{ number_format($churn['last_month_usage']) }} units last mo → {{ number_format($churn['this_month_usage']) }} this mo
                            </div>
                        </div>
                        <span class="churn-drop-badge">-{{ $churn['drop_percentage'] }}% drop</span>
                    </li>
                @empty
                    <li style="color: var(--text-muted); font-size: 13px; padding: 12px 0;">
                        ✓ No customers with >50% month-over-month usage drops detected.
                    </li>
                @endforelse
            </ul>
        </div>
    </section>

    <!-- Row 3: Daily Usage Trend & System Status -->
    <section class="content-grid">
        <!-- Daily Usage Trend (last 30 days) -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">
                    <span>Daily Usage Trend (last 30 days)</span>
                </div>
                <div class="panel-caption">usage / day</div>
            </div>

            <div class="chart-container">
                @php
                    $trendData = $data['daily_usage_trend'];
                    $maxUnits = max(1, ...array_column($trendData, 'units'));
                    $points = [];
                    $count = count($trendData);
                    foreach ($trendData as $idx => $item) {
                        $x = ($count > 1) ? round(($idx / ($count - 1)) * 560 + 20, 1) : 300;
                        $y = round(150 - (($item['units'] / $maxUnits) * 120), 1);
                        $points[] = "{$x},{$y}";
                    }
                    $pointsStr = implode(' ', $points);
                    $firstPoint = $points[0] ?? '20,150';
                    $lastPoint = end($points) ?: '580,150';
                    $areaStr = "{$firstPoint} {$pointsStr} {$lastPoint} 580,160 20,160";
                @endphp

                <svg class="chart-svg" viewBox="0 0 600 170" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="chartGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.35"/>
                            <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <!-- Grid Lines -->
                    <line x1="20" y1="30" x2="580" y2="30" stroke="#1f293d" stroke-dasharray="3,3" />
                    <line x1="20" y1="90" x2="580" y2="90" stroke="#1f293d" stroke-dasharray="3,3" />
                    <line x1="20" y1="150" x2="580" y2="150" stroke="#1f293d" />

                    <!-- Area Fill -->
                    <polygon points="{{ $areaStr }}" fill="url(#chartGradient)" />

                    <!-- Trend Line -->
                    <polyline points="{{ $pointsStr }}" fill="none" stroke="#38bdf8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

                    <!-- Last point indicator -->
                    @if(!empty($points))
                        @php
                            $lastCoords = explode(',', end($points));
                        @endphp
                        <circle cx="{{ $lastCoords[0] }}" cy="{{ $lastCoords[1] }}" r="4" fill="#38bdf8" stroke="#ffffff" stroke-width="2" />
                    @endif
                </svg>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted); font-family: var(--font-mono); margin-top: 8px;">
                <span>{{ $trendData[0]['formatted_date'] ?? 'Day -30' }}</span>
                <span>Peak: {{ number_format($maxUnits) }} units/day</span>
                <span>{{ end($trendData)['formatted_date'] ?? 'Today' }}</span>
            </div>
        </div>

        <!-- System Status Box (Informational) -->
        <div class="panel system-status-box">
            <div class="panel-header" style="border-bottom-color: rgba(6, 182, 212, 0.2);">
                <div class="panel-title system-status-title">
                    <span>System status (informational)</span>
                </div>
            </div>
            <div class="status-items">
                <div class="status-item">
                    <span class="status-dot"></span>
                    <div>
                        <div class="status-label">Plan pricing cache</div>
                        <div class="status-val">{{ strtoupper($data['system_status']['cache_driver']) }}. TTL {{ $data['system_status']['plan_cache_ttl'] }}</div>
                    </div>
                </div>

                <div class="status-item">
                    <span class="status-dot"></span>
                    <div>
                        <div class="status-label">Nightly aggregation job</div>
                        <div class="status-val">{{ $data['system_status']['nightly_job'] }}</div>
                    </div>
                </div>

                <div class="status-item">
                    <span class="status-dot"></span>
                    <div>
                        <div class="status-label">Usage endpoint rate-limit</div>
                        <div class="status-val">{{ $data['system_status']['usage_rate_limit'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Interactive Live Usage Simulator -->
    <section class="simulator-box">
        <div class="simulator-header">
            <div class="simulator-title">
                <span>⚡ Live API Usage Event Simulator (POST /usage)</span>
            </div>
            <span style="font-size: 11px; color: var(--text-muted); font-family: var(--font-mono);">
                Idempotency & High Throughput Test
            </span>
        </div>
        <form id="usageSimForm" class="sim-form" onsubmit="submitUsageEvent(event)">
            <div class="sim-field">
                <label>Customer</label>
                <select id="simCustomerId" required>
                    @foreach($data['top_5_customers'] as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }} (ID: {{ $c['id'] }})</option>
                    @endforeach
                </select>
            </div>
            <div class="sim-field">
                <label>Units</label>
                <input type="number" id="simUnits" value="500" min="1" required>
            </div>
            <div class="sim-field">
                <label>Usage Date</label>
                <input type="date" id="simDate" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="sim-field">
                <label>Idempotency Key</label>
                <input type="text" id="simKey" value="evt_{{ time() }}_{{ rand(100,999) }}" required>
            </div>
            <button type="submit" class="sim-btn" id="simSubmitBtn">Send Event</button>
        </form>
        <div id="simOutput" class="sim-status-output"></div>
    </section>
</div>

<script>
async function submitUsageEvent(e) {
    e.preventDefault();
    const btn = document.getElementById('simSubmitBtn');
    const out = document.getElementById('simOutput');
    btn.disabled = true;
    btn.innerText = 'Posting...';

    const payload = {
        customer_id: parseInt(document.getElementById('simCustomerId').value),
        units: parseInt(document.getElementById('simUnits').value),
        usage_date: document.getElementById('simDate').value,
        idempotency_key: document.getElementById('simKey').value
    };

    try {
        const res = await fetch('/usage', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        out.style.display = 'block';

        if (res.ok) {
            const isIdempotent = data.idempotent;
            out.style.background = isIdempotent ? 'rgba(245, 158, 11, 0.15)' : 'rgba(16, 185, 129, 0.15)';
            out.style.border = isIdempotent ? '1px solid #f59e0b' : '1px solid #10b981';
            out.style.color = isIdempotent ? '#fbbf24' : '#34d399';
            out.innerHTML = `HTTP ${res.status}: ${data.message} (Event ID: ${data.event_id || 'N/A'}, Idempotent: ${data.idempotent})`;
        } else {
            out.style.background = 'rgba(244, 63, 94, 0.15)';
            out.style.border = '1px solid #f43f5e';
            out.style.color = '#fb7185';
            out.innerHTML = `HTTP ${res.status} Error: ${data.message || JSON.stringify(data.errors)}`;
        }
    } catch (err) {
        out.style.display = 'block';
        out.style.background = 'rgba(244, 63, 94, 0.15)';
        out.style.border = '1px solid #f43f5e';
        out.style.color = '#fb7185';
        out.innerText = 'Network error: ' + err.message;
    } finally {
        btn.disabled = false;
        btn.innerText = 'Send Event';
    }
}
</script>
</body>
</html>
