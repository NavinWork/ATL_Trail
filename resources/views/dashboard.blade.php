<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Merchant Dashboard — {{ $data['merchant']['name'] }}</title>
</head>
<body>

<div>
    <!-- Header -->
    <header>
        <div>
            <span>SaaS Billing</span>
            <h1>Merchant Dashboard — {{ $data['merchant']['name'] }}</h1><!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Merchant Dashboard — {{ $data['merchant']['name'] }}</title>
                @vite('resources/css/app.css')
            </head>
            <body class="bg-gray-50 text-gray-800 font-sans antialiased">

            <div class="container mx-auto px-6 py-4">
                <!-- Header -->
                <header class="flex justify-between items-center bg-gray-100 p-4 rounded-lg shadow-md">
                    <div class="flex items-center gap-4">
                        <span class="bg-blue-600 text-white text-sm font-semibold py-1 px-3 rounded">SaaS Billing</span>
                        <h1 class="text-lg font-bold">Merchant Dashboard — {{ $data['merchant']['name'] }}</h1>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="text-gray-600 text-sm font-mono">WIREFRAME — layout reference</span>
                        <a href="/api/merchants/{{ $data['merchant']['id'] }}/dashboard" target="_blank" class="text-blue-600 underline">Raw API JSON →</a>
                    </div>
                </header>

                <!-- Top 3 Metrics (Wireframe top panel) -->
                <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                    <!-- Metric 1: Current Cycle Usage -->
                    <div class="bg-white p-4 rounded-lg shadow-md">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500 font-semibold uppercase text-xs">Current Cycle Usage</span>
                            <span class="text-lg font-bold">{{ $data['current_cycle_usage']['percentage'] }}%</span>
                        </div>
                        <div class="text-gray-600 mt-2">
                            {{ number_format($data['current_cycle_usage']['total_units']) }} / {{ number_format($data['current_cycle_usage']['total_allowance']) }} units
                        </div>
                        <div class="bg-gray-200 h-2 rounded-full mt-3 overflow-hidden">
                            <div class="bg-blue-600 h-full" style="width: {{ min(100, $data['current_cycle_usage']['percentage']) }}%;"></div>
                        </div>
                        <div class="text-sm text-gray-500 mt-2">
                            Aggregated across {{ count($data['top_5_customers']) }}+ active merchant customer accounts
                        </div>
                    </div>

                    <!-- Metric 2: Projected Overage Revenue -->
                    <div class="bg-white p-4 rounded-lg shadow-md">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500 font-semibold uppercase text-xs">Projected Overage Revenue</span>
                            <span class="text-xs text-gray-400">Cycle End Projection</span>
                        </div>
                        <div class="text-gray-800 text-2xl font-bold mt-2">
                            {{ $data['merchant']['currency'] === 'INR' ? '₹' : '$' }} {{ number_format($data['projected_overage_revenue'], 2) }}
                        </div>
                        <div class="text-sm text-gray-500 mt-2">
                            Calculated dynamically against tiered overage rates per active plan
                        </div>
                    </div>

                    <!-- Metric 3: Active Plan -->
                    <div class="bg-white p-4 rounded-lg shadow-md">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500 font-semibold uppercase text-xs">Active Plan</span>
                            <span class="bg-green-100 text-green-700 text-xs font-semibold px-2 py-1 rounded">Active</span>
                        </div>
                        <div class="mt-2">
                            <span class="text-gray-800 font-bold">{{ $data['active_plan']['name'] }}</span> —
                            <span class="text-sm text-gray-600">{{ $data['active_plan']['billing_cycle'] }}</span>
                        </div>
                        <div class="text-sm text-gray-500 mt-2">
                            Base allowance: {{ number_format($data['active_plan']['included_units']) }} units / billing cycle
                        </div>
                    </div>
                </section>

                <!-- Row 2: Top 5 Customers & Churn Risk -->
                <section class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
                    <!-- Top 5 Customers Table -->
                    <div class="bg-white p-4 rounded-lg shadow-md overflow-hidden">
                        <div class="border-b border-gray-200 py-2">
                            <h2 class="text-gray-800 text-lg font-bold">Top 5 Customers by Usage (this cycle)</h2>
                        </div>
                        <table class="w-full text-left mt-3">
                            <thead>
                            <tr class="bg-gray-100 text-xs text-gray-600 uppercase">
                                <th class="py-2 px-4">Customer</th>
                                <th class="py-2 px-4">Usage</th>
                                <th class="py-2 px-4">% of Allowance</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($data['top_5_customers'] as $cust)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 px-4">{{ $cust['name'] }}</td>
                                    <td class="py-2 px-4">{{ number_format($cust['usage']) }}</td>
                                    <td class="py-2 px-4">
                                        <span class="text-blue-600">{{ $cust['percentage_of_allowance'] }}%</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="py-2 px-4 text-center text-gray-500 text-sm">
                                        No usage events recorded yet for this cycle.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Churn Risk Alert Box (usage ↓ >50% MoM) -->
                    <div class="bg-white p-4 rounded-lg shadow-md">
                        <h2 class="text-red-600 text-lg font-bold flex items-center gap-2">
                            ⚠️ Churn Risk (usage ↓ >50% MoM)
                        </h2>
                        <ul class="mt-4 space-y-3">
                            @forelse($data['churn_risk'] as $churn)
                                <li class="border border-red-300 bg-red-50 rounded p-3">
                                    <div class="text-gray-800 font-semibold">{{ $churn['name'] }}</div>
                                    <div class="text-gray-600 text-sm">
                                        {{ number_format($churn['last_month_usage']) }} units last mo → {{ number_format($churn['this_month_usage']) }} this mo
                                    </div>
                                    <span class="text-sm text-red-600 font-bold">-{{ $churn['drop_percentage'] }}% drop</span>
                                </li>
                            @empty
                                <li class="text-green-600 font-semibold">
                                    ✓ No customers with >50% month-over-month usage drops detected.
                                </li>
                            @endforelse
                        </ul>
                    </div>
                </section>
            </div>

            </body>
            </html>
        </div>
        <div>
            <span>WIREFRAME — layout reference</span>
            <a href="/api/merchants/{{ $data['merchant']['id'] }}/dashboard" target="_blank">Raw API JSON →</a>
        </div>
    </header>

    <!-- Top 3 Metrics (Wireframe top panel) -->
    <section>
        <!-- Metric 1: Current Cycle Usage -->
        <div>
            <div>
                <span>Current Cycle Usage</span>
                <span>{{ $data['current_cycle_usage']['percentage'] }}%</span>
            </div>
            <div>
                {{ number_format($data['current_cycle_usage']['total_units']) }} / {{ number_format($data['current_cycle_usage']['total_allowance']) }} units
            </div>
            <div>
                <div style="width: {{ min(100, $data['current_cycle_usage']['percentage']) }}%;"></div>
            </div>
            <div>
                Aggregated across {{ count($data['top_5_customers']) }}+ active merchant customer accounts
            </div>
        </div>

        <!-- Metric 2: Projected Overage Revenue -->
        <div>
            <div>
                <span>Projected Overage Revenue</span>
                <span>Cycle End Projection</span>
            </div>
            <div>
                {{ $data['merchant']['currency'] === 'INR' ? '₹' : '$' }} {{ number_format($data['projected_overage_revenue'], 2) }}
            </div>
            <div>
                Calculated dynamically against tiered overage rates per active plan
            </div>
        </div>

        <!-- Metric 3: Active Plan -->
        <div>
            <div>
                <span>Active Plan</span>
                <span>Active</span>
            </div>
            <div>
                {{ $data['active_plan']['name'] }} — {{ $data['active_plan']['billing_cycle'] }}
            </div>
            <div>
                Base allowance: {{ number_format($data['active_plan']['included_units']) }} units / billing cycle
            </div>
        </div>
    </section>

    <!-- Row 2: Top 5 Customers & Churn Risk -->
    <section>
        <!-- Top 5 Customers Table -->
        <div>
            <div>
                <div>
                    <span>Top 5 Customers by Usage (this cycle)</span>
                </div>
                <div>[top 5 sorted desc]</div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Usage</th>
                        <th>% of Allowance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data['top_5_customers'] as $cust)
                        <tr>
                            <td>{{ $cust['name'] }}</td>
                            <td>{{ number_format($cust['usage']) }}</td>
                            <td>
                                <span>{{ $cust['percentage_of_allowance'] }}%</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                No usage events recorded yet for this cycle.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Churn Risk Alert Box (usage ↓ >50% MoM) -->
        <div>
            <div>
                <div>
                    <span>⚠️ Churn Risk (usage ↓ >50% MoM)</span>
                </div>
            </div>
            <ul>
                @forelse($data['churn_risk'] as $churn)
                    <li>
                        <div>
                            <div>{{ $churn['name'] }}</div>
                            <div>
                                {{ number_format($churn['last_month_usage']) }} units last mo → {{ number_format($churn['this_month_usage']) }} this mo
                            </div>
                        </div>
                        <span>-{{ $churn['drop_percentage'] }}% drop</span>
                    </li>
                @empty
                    <li>
                        ✓ No customers with >50% month-over-month usage drops detected.
                    </li>
                @endforelse
            </ul>
        </div>
    </section>

    <!-- Row 3: Daily Usage Trend & System Status -->
    <section>
        <!-- Daily Usage Trend (last 30 days) -->
        <div>
            <div>
                <div>
                    <span>Daily Usage Trend (last 30 days)</span>
                </div>
                <div>usage / day</div>
            </div>

            <div>
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

                <svg viewBox="0 0 600 170" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="chartGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.35"/>
                            <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <!-- Grid Lines -->
                    <line x1="20" y1="30" x2="580" y2="30" stroke="#ccc" stroke-dasharray="3,3" />
                    <line x1="20" y1="90" x2="580" y2="90" stroke="#ccc" stroke-dasharray="3,3" />
                    <line x1="20" y1="150" x2="580" y2="150" stroke="#999" />

                    <!-- Area Fill -->
                    <polygon points="{{ $areaStr }}" fill="url(#chartGradient)" />

                    <!-- Trend Line -->
                    <polyline points="{{ $pointsStr }}" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

                    <!-- Last point indicator -->
                    @if(!empty($points))
                        @php
                            $lastCoords = explode(',', end($points));
                        @endphp
                        <circle cx="{{ $lastCoords[0] }}" cy="{{ $lastCoords[1] }}" r="4" fill="#3b82f6" stroke="#ffffff" stroke-width="2" />
                    @endif
                </svg>
            </div>
            <div>
                <span>{{ $trendData[0]['formatted_date'] ?? 'Day -30' }}</span>
                <span>Peak: {{ number_format($maxUnits) }} units/day</span>
                <span>{{ end($trendData)['formatted_date'] ?? 'Today' }}</span>
            </div>
        </div>

        <!-- System Status Box (Informational) -->
        <div>
            <div>
                <div>
                    <span>System status (informational)</span>
                </div>
            </div>
            <div>
                <div>
                    <div>
                        <div>Plan pricing cache</div>
                        <div>{{ strtoupper($data['system_status']['cache_driver']) }}. TTL {{ $data['system_status']['plan_cache_ttl'] }}</div>
                    </div>
                </div>

                <div>
                    <div>
                        <div>Nightly aggregation job</div>
                        <div>{{ $data['system_status']['nightly_job'] }}</div>
                    </div>
                </div>

                <div>
                    <div>
                        <div>Usage endpoint rate-limit</div>
                        <div>{{ $data['system_status']['usage_rate_limit'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Subscription Plans Section ──────────────────────── -->
    <section id="subscription-plans">
        <!-- Plan Cards -->
        <div>
            <div>
                📋 Subscription Plans
            </div>
            <span>
                {{ $data['plans']->count() }} plan(s) · {{ $data['subscriptions']->where('status','active')->count() }} active subscriptions
            </span>
        </div>

        <div>
            @foreach($data['plans'] as $plan)
                @php
                    $slug = strtolower($plan->name);
                    $currency = $data['merchant']['currency'] === 'INR' ? '₹' : '$';
                    $subCount = $data['subscriptions']->where('plan_id', $plan->id)->where('status','active')->count();
                @endphp
                <div>
                    <span>{{ $plan->is_active ? 'Active' : 'Inactive' }}</span>
                    <div>{{ $plan->name }}</div>
                    <div>{{ $plan->code }}</div>
                    <div>
                        {{ $currency }}{{ number_format($plan->base_price, 0) }}
                        <span>/ {{ $plan->billing_cycle }}</span>
                    </div>
                    <hr>
                    <div>
                        <span>Included units</span>
                        <span>{{ number_format($plan->included_usage_units) }}</span>
                    </div>
                    <div>
                        <span>Overage rate</span>
                        <span>{{ $currency }}{{ number_format($plan->overage_rate_per_unit, 2) }} / unit</span>
                    </div>
                    <div>
                        <span>Active subscribers</span>
                        <span>{{ $subCount }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- All Subscriptions Table -->
        <div>
            <div>
                <div>
                    <span>Customer Subscriptions</span>
                </div>
                <div>click ▶ to expand billing segments</div>
            </div>
            <table id="subscriptionsTable">
                <thead>
                    <tr>
                        <th></th>
                        <th>Customer</th>
                        <th>Plan</th>
                        <th>Cycle</th>
                        <th>Status</th>
                        <th>Segments</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data['subscriptions'] as $sub)
                        @php
                            $currency = $data['merchant']['currency'] === 'INR' ? '₹' : '$';
                            $segCount = $sub->segments->count();
                        @endphp
                        <tr id="sub-row-{{ $sub->id }}">
                            <td>
                                @if($segCount > 0)
                                    <button onclick="toggleSegments({{ $sub->id }})">▶</button>
                                @endif
                            </td>
                            <td>{{ $sub->customer->name }}</td>
                            <td>
                                <span>{{ $sub->plan->name }}</span>
                            </td>
                            <td>
                                {{ \Carbon\Carbon::parse($sub->current_cycle_start)->format('d M') }}
                                →
                                {{ \Carbon\Carbon::parse($sub->current_cycle_end)->format('d M Y') }}
                            </td>
                            <td>
                                <span>{{ ucfirst($sub->status) }}</span>
                            </td>
                            <td>
                                {{ $segCount }} segment{{ $segCount !== 1 ? 's' : '' }}
                            </td>
                        </tr>
                        {{-- Expandable segments rows --}}
                        @if($segCount > 0)
                            <tr id="seg-{{ $sub->id }}" style="display: none;">
                                <td colspan="6">
                                    <div>
                                        @foreach($sub->segments as $i => $seg)
                                            <div>
                                                <span>SEG {{ $i + 1 }}</span>
                                                <span>
                                                    {{ \Carbon\Carbon::parse($seg->starts_at)->format('d M') }}
                                                    →
                                                    {{ \Carbon\Carbon::parse($seg->ends_at)->format('d M') }}
                                                </span>
                                                <span>
                                                    {{ $currency }}{{ number_format($seg->prorated_base_price, 2) }}
                                                </span>
                                                <span>
                                                    {{ number_format($seg->prorated_included_units) }} units incl.
                                                </span>
                                                <span>
                                                    overage {{ $currency }}{{ number_format($seg->overage_rate_per_unit, 2) }}/unit
                                                </span>
                                                @if($seg->plan)
                                                    <span>{{ $seg->plan->name }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6">
                                No subscriptions yet. Use <code>POST /subscriptions</code> to create one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- Interactive Live Usage Simulator -->
    <section>
        <div>
            <div>
                <span>⚡ Live API Usage Event Simulator (POST /usage)</span>
            </div>
            <span>Idempotency & High Throughput Test</span>
        </div>
        <form id="usageSimForm" onsubmit="submitUsageEvent(event)">
            <div>
                <label>Customer</label>
                <select id="simCustomerId" required>
                    @foreach($data['top_5_customers'] as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }} (ID: {{ $c['id'] }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Units</label>
                <input type="number" id="simUnits" value="500" min="1" required>
            </div>
            <div>
                <label>Usage Date</label>
                <input type="date" id="simDate" value="{{ date('Y-m-d') }}" required>
            </div>
            <div>
                <label>Idempotency Key</label>
                <input type="text" id="simKey" value="evt_{{ time() }}_{{ rand(100,999) }}" required>
            </div>
            <button type="submit" id="simSubmitBtn">Send Event</button>
        </form>
        <div id="simOutput"></div>
    </section>
</div>

<script>
function toggleSegments(subId) {
    const segRow = document.getElementById('seg-' + subId);
    const btn = document.querySelector('#sub-row-' + subId + ' button');
    if (!segRow) return;
    const isHidden = segRow.style.display === 'none';
    segRow.style.display = isHidden ? 'table-row' : 'none';
    if (btn) btn.textContent = isHidden ? '▼' : '▶';
}

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
            out.innerHTML = `HTTP ${res.status}: ${data.message} (Event ID: ${data.event_id || 'N/A'}, Idempotent: ${data.idempotent})`;
        } else {
            out.innerHTML = `HTTP ${res.status} Error: ${data.message || JSON.stringify(data.errors)}`;
        }
    } catch (err) {
        out.style.display = 'block';
        out.innerText = 'Network error: ' + err.message;
    } finally {
        btn.disabled = false;
        btn.innerText = 'Send Event';
    }
}
</script>
</body>
</html>
