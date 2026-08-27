<?php
declare(strict_types=1);
/**
 * Mikrotik Watch - TV Dashboard
 * Layout fullscreen para televisão. Sem menu lateral.
 * Auto-refresh a cada 30 segundos.
 */

function tvTimeAgo(?string $datetime): string
{
    if ($datetime === null) return '—';
    $now = new \DateTimeImmutable();
    $past = new \DateTimeImmutable($datetime);
    $diff = $now->diff($past);
    if ($diff->d > 0) return "{$diff->d}d {$diff->h}h";
    if ($diff->h > 0) return "{$diff->h}h {$diff->i}m";
    if ($diff->i > 0) return "{$diff->i}m";
    return "{$diff->s}s";
}

function tvMemPct($free, $total): ?float
{
    if (!$total || $total == 0) return null;
    return round(($total - $free) / $total * 100, 0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mikrotik Watch — TV Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg-primary: #0a0a0b;
            --bg-secondary: #0f0f11;
            --bg-card: #111113;
            --bg-elevated: #161618;
            --bg-hover: #1a1a1e;
            --border: #1e1e22;
            --border-subtle: #18181c;

            --text-primary: #e8e8ec;
            --text-secondary: #7a7a88;
            --text-muted: #4a4a55;

            --accent: #0ea5e9;
            --accent-hover: #38bdf8;
            --accent-bg: rgba(14, 165, 233, 0.07);
            --accent-border: rgba(14, 165, 233, 0.2);

            --success: #22c55e;
            --success-bg: rgba(34, 197, 94, 0.08);
            --success-border: rgba(34, 197, 94, 0.2);
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.08);
            --danger-border: rgba(239, 68, 68, 0.2);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.08);
            --warning-border: rgba(245, 158, 11, 0.2);

            --radius: 10px;
            --radius-sm: 6px;
            --radius-pill: 50px;
            --shadow: 0 1px 3px rgba(0,0,0,0.3), 0 1px 2px rgba(0,0,0,0.2);

            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: var(--font-sans);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* ─── Header ────────────────────────────────────────────── */

        .tv-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 28px;
            border-bottom: 1px solid var(--border-subtle);
            background: var(--bg-secondary);
        }

        .tv-header h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.3px;
        }

        .tv-header h1 svg {
            color: var(--accent);
            width: 22px;
            height: 22px;
        }

        .tv-header .meta {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .tv-header .meta .live {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--success);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tv-header .meta .live::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* ─── Summary Cards ─────────────────────────────────────── */

        .tv-summary {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 14px;
            padding: 20px 28px 18px;
        }

        .tv-summary-left {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
        }

        .tv-summary-right {
            display: flex;
        }

        .tv-summary-right .tv-stat {
            width: 100%;
        }

        .tv-stat {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow);
        }

        .tv-stat .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--bg-elevated);
            border: 1px solid var(--border);
            color: var(--text-muted);
        }

        .tv-stat .stat-icon svg { width: 22px; height: 22px; }

        .tv-stat .stat-info h3 {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.5px;
            color: var(--text-primary);
        }

        .tv-stat .stat-info p {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        /* ─── Content Grid ──────────────────────────────────────── */

        .tv-content {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 14px;
            padding: 0 28px 24px;
            height: calc(100vh - 210px);
            min-height: 400px;
        }

        .tv-panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .tv-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-subtle);
            flex-shrink: 0;
        }

        .tv-panel-header h2 {
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--text-secondary);
        }

        .tv-panel-header .count {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--danger-border);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: var(--radius-pill);
        }

        .tv-panel-header .total {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .tv-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        .tv-panel-body::-webkit-scrollbar { width: 4px; }
        .tv-panel-body::-webkit-scrollbar-track { background: var(--bg-primary); }
        .tv-panel-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

        /* ─── Device Grid ───────────────────────────────────────── */

        .tv-devices {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 10px;
            padding: 14px;
        }

        .tv-device {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            transition: border-color 0.15s;
        }

        .tv-device:hover { border-color: var(--border); }

        .tv-device.status-offline { border-left: 3px solid var(--danger); }
        .tv-device.status-unknown { border-left: 3px solid var(--text-muted); }
        .tv-device.status-online { border-left: 3px solid var(--success); }

        .tv-device-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .tv-device-name {
            font-weight: 700;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-primary);
        }

        .tv-device-client {
            font-size: 11px;
            color: var(--text-muted);
        }

        .tv-device-host {
            font-size: 11px;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .tv-device-metrics {
            display: flex;
            gap: 14px;
            font-size: 12px;
            margin-top: 2px;
        }

        .tv-device-metrics .metric {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .tv-device-metrics .metric-label {
            color: var(--text-muted);
            font-size: 11px;
        }

        .tv-device-metrics .metric-value {
            font-weight: 700;
            font-size: 12px;
        }

        /* ─── Badges ────────────────────────────────────────────── */

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: var(--radius-pill);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

        .badge-online {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .badge-offline {
            background: var(--danger-bg);
            color: #f87171;
            border: 1px solid var(--danger-border);
        }

        .badge-unknown {
            background: var(--bg-elevated);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .badge-up {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .badge-down {
            background: var(--danger-bg);
            color: #f87171;
            border: 1px solid var(--danger-border);
        }

        .badge-ping {
            background: var(--bg-elevated);
            color: var(--text-muted);
            border: 1px solid var(--border);
            font-size: 9px;
            padding: 1px 6px;
        }

        /* ─── Down Hosts List ───────────────────────────────────── */

        .tv-hosts {
            padding: 8px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .tv-host-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-left: 3px solid var(--danger);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .tv-host-comment {
            font-weight: 700;
            font-size: 13px;
            color: var(--warning);
            background: var(--warning-bg);
            border: 1px solid var(--warning-border);
            padding: 2px 10px;
            border-radius: var(--radius-sm);
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        .tv-host-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .tv-host-client {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .tv-host-address {
            font-weight: 500;
            font-size: 11px;
            font-family: var(--font-mono);
            color: var(--text-muted);
            white-space: nowrap;
        }

        /* ─── No data ───────────────────────────────────────────── */

        .tv-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: var(--text-muted);
            text-align: center;
            gap: 12px;
        }

        .tv-empty svg {
            width: 48px;
            height: 48px;
            color: var(--success);
            opacity: 0.4;
        }

        .tv-empty p {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        /* ─── Refresh bar ───────────────────────────────────────── */

        .tv-refresh-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--border-subtle);
            z-index: 100;
        }

        .tv-refresh-bar .progress {
            height: 100%;
            background: var(--accent);
            transition: width 1s linear;
            width: 0%;
        }
    </style>
</head>
<body>

<!-- ─── Header ──────────────────────────────────────────────────────────── -->

<div class="tv-header">
    <h1>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        Mikrotik Watch
    </h1>
    <div class="meta">
        <div class="live">AO VIVO</div>
        <span id="clock"></span>
        <span id="last-sync">Última coleta: <?= htmlspecialchars($lastCheck ?? '—') ?></span>
    </div>
</div>

<!-- ─── Summary ─────────────────────────────────────────────────────────── -->

<div class="tv-summary">
    <div class="tv-summary-left">
    <div class="tv-stat">
        <div class="stat-icon icon-accent" style="background: var(--accent-bg); border-color: var(--accent-border); color: var(--accent);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= (int) $deviceSummary['total'] ?></h3>
            <p>Dispositivos</p>
        </div>
    </div>
    <div class="tv-stat">
        <div class="stat-icon" style="background: var(--success-bg); border-color: var(--success-border); color: var(--success);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--success);"><?= (int) $deviceSummary['online'] ?></h3>
            <p>Online</p>
        </div>
    </div>
    <div class="tv-stat">
        <div class="stat-icon" style="background: var(--danger-bg); border-color: var(--danger-border); color: var(--danger);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: #f87171;"><?= (int) $deviceSummary['offline'] ?></h3>
            <p>Offline</p>
        </div>
    </div>
    <div class="tv-stat">
        <div class="stat-icon icon-accent" style="background: var(--accent-bg); border-color: var(--accent-border); color: var(--accent);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= (int) $hostSummary['total'] ?></h3>
            <p>Hosts</p>
        </div>
    </div>
    <div class="tv-stat">
        <div class="stat-icon" style="background: var(--success-bg); border-color: var(--success-border); color: var(--success);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: var(--success);"><?= (int) $hostSummary['up'] ?></h3>
            <p>Hosts Up</p>
        </div>
    </div>
    </div>
    <div class="tv-summary-right">
    <div class="tv-stat">
        <div class="stat-icon" style="background: var(--danger-bg); border-color: var(--danger-border); color: var(--danger);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-info">
            <h3 style="color: #f87171;"><?= (int) $hostSummary['down'] ?></h3>
            <p>Hosts Down</p>
        </div>
    </div>
    </div>
</div>

<!-- ─── Content ─────────────────────────────────────────────────────────── -->

<div class="tv-content">

    <!-- Devices Grid -->
    <div class="tv-panel">
        <div class="tv-panel-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
                Dispositivos
                <?php if ((int)$deviceSummary['offline'] > 0): ?>
                    <span class="count"><?= (int)$deviceSummary['offline'] ?></span>
                <?php endif; ?>
            </h2>
            <span class="total"><?= (int)$deviceSummary['total'] ?> total</span>
        </div>
        <div class="tv-panel-body">
            <div class="tv-devices" id="devices-grid">
                <?php if (empty($devices)): ?>
                    <div class="tv-empty" style="grid-column: 1/-1;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <p>Nenhum dispositivo cadastrado</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($devices as $d): ?>
                        <?php
                            $status = $d['current_status'];
                            $memPct = tvMemPct($d['last_memory_free'], $d['last_memory_total']);
                        ?>
                        <div class="tv-device status-<?= $status ?>">
                            <div class="tv-device-top">
                                <div class="tv-device-name">
                                    <?= htmlspecialchars($d['name']) ?>
                                    <?php if (($d['device_type'] ?? 'mikrotik') === 'ping'): ?>
                                        <span class="badge-ping">PING</span>
                                    <?php endif; ?>
                                </div>
                                <span class="badge badge-<?= $status ?>"><?= $status ?></span>
                            </div>
                            <div class="tv-device-client"><?= htmlspecialchars($d['client_name'] ?? '—') ?></div>
                            <div class="tv-device-host"><?= htmlspecialchars($d['host']) ?></div>
                            <?php if ($status === 'online' && ($d['device_type'] ?? 'mikrotik') === 'mikrotik'): ?>
                                <div class="tv-device-metrics">
                                    <?php if ($d['last_cpu_load'] !== null): ?>
                                        <div class="metric">
                                            <span class="metric-label">CPU</span>
                                            <span class="metric-value" style="color: <?= (int)$d['last_cpu_load'] > 95 ? 'var(--danger)' : ((int)$d['last_cpu_load'] > 80 ? 'var(--warning)' : 'var(--text-primary)') ?>"><?= (int)$d['last_cpu_load'] ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($memPct !== null): ?>
                                        <div class="metric">
                                            <span class="metric-label">RAM</span>
                                            <span class="metric-value" style="color: <?= $memPct > 95 ? 'var(--danger)' : ($memPct > 85 ? 'var(--warning)' : 'var(--text-primary)') ?>"><?= $memPct ?>%</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($d['last_temperature'] !== null): ?>
                                        <div class="metric">
                                            <span class="metric-label">Temp</span>
                                            <span class="metric-value"><?= number_format((float)$d['last_temperature'], 0) ?>°C</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($status === 'online' && ($d['device_type'] ?? '') === 'ping'): ?>
                                <div class="tv-device-metrics">
                                    <?php if ($d['last_rtt_ms'] !== null): ?>
                                        <div class="metric">
                                            <span class="metric-label">RTT</span>
                                            <span class="metric-value"><?= (int)$d['last_rtt_ms'] ?>ms</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($status !== 'online'): ?>
                                <div class="tv-device-metrics">
                                    <div class="metric">
                                        <span class="metric-label">Offline há</span>
                                        <span class="metric-value" style="color: var(--danger);"><?= tvTimeAgo($d['status_since']) ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Down Hosts -->
    <div class="tv-panel">
        <div class="tv-panel-header">
            <h2>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                Hosts Down
                <?php if (count($downHosts) > 0): ?>
                    <span class="count"><?= count($downHosts) ?></span>
                <?php endif; ?>
            </h2>
        </div>
        <div class="tv-panel-body">
            <div id="hosts-list">
                <?php if (empty($downHosts)): ?>
                    <div class="tv-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <p>Todos os hosts respondendo</p>
                    </div>
                <?php else: ?>
                    <div class="tv-hosts">
                        <?php foreach ($downHosts as $h): ?>
                            <div class="tv-host-item">
                                <?php if (!empty($h['comment'])): ?>
                                    <span class="tv-host-comment"><?= htmlspecialchars($h['comment']) ?></span>
                                <?php endif; ?>
                                <div class="tv-host-bottom">
                                    <span class="tv-host-client"><?= htmlspecialchars($h['mikrotik_name'] ?? '—') ?> · <?= tvTimeAgo($h['status_since']) ?></span>
                                    <span class="tv-host-address"><?= htmlspecialchars($h['host_address']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ─── Refresh Bar ─────────────────────────────────────────────────────── -->

<div class="tv-refresh-bar">
    <div class="progress" id="refresh-progress"></div>
</div>

<script>
(function() {
    var REFRESH_INTERVAL = 30;
    var elapsed = 0;

    function updateClock() {
        var now = new Date();
        var h = String(now.getHours()).padStart(2, '0');
        var m = String(now.getMinutes()).padStart(2, '0');
        var s = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('clock').textContent = h + ':' + m + ':' + s;
    }
    updateClock();
    setInterval(updateClock, 1000);

    function refresh() {
        fetch('/tv/api')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var stats = document.querySelectorAll('.tv-stat .stat-info h3');
                if (stats.length >= 6) {
                    stats[0].textContent = data.devices.total;
                    stats[1].textContent = data.devices.online;
                    stats[2].textContent = data.devices.offline;
                    stats[3].textContent = data.hosts.total;
                    stats[4].textContent = data.hosts.up;
                    stats[5].textContent = data.hosts.down;
                }

                if (data.lastCheck) {
                    document.getElementById('last-sync').textContent = 'Última coleta: ' + data.lastCheck;
                }

                // Atualizar contadores nos headers dos painéis
                var panelHeaders = document.querySelectorAll('.tv-panel-header .count');
                if (panelHeaders.length >= 2) {
                    panelHeaders[0].textContent = data.devices.offline;
                    panelHeaders[0].style.display = data.devices.offline > 0 ? '' : 'none';
                    panelHeaders[1].textContent = data.hosts.down;
                    panelHeaders[1].style.display = data.hosts.down > 0 ? '' : 'none';
                }

                var grid = document.getElementById('devices-grid');
                if (grid && data.deviceList) {
                    grid.innerHTML = data.deviceList.map(function(d) {
                        var memPct = (d.last_memory_free && d.last_memory_total > 0)
                            ? Math.round((d.last_memory_total - d.last_memory_free) / d.last_memory_total * 100)
                            : null;
                        var cpuColor = d.last_cpu_load > 95 ? 'var(--danger)' : d.last_cpu_load > 80 ? 'var(--warning)' : 'var(--text-primary)';
                        var memColor = memPct > 95 ? 'var(--danger)' : memPct > 85 ? 'var(--warning)' : 'var(--text-primary)';

                        var metrics = '';
                        if (d.current_status === 'online' && (d.device_type || 'mikrotik') === 'mikrotik') {
                            if (d.last_cpu_load != null) metrics += '<div class="metric"><span class="metric-label">CPU</span> <span class="metric-value" style="color:' + cpuColor + '">' + d.last_cpu_load + '%</span></div>';
                            if (memPct != null) metrics += '<div class="metric"><span class="metric-label">RAM</span> <span class="metric-value" style="color:' + memColor + '">' + memPct + '%</span></div>';
                            if (d.last_temperature != null) metrics += '<div class="metric"><span class="metric-label">Temp</span> <span class="metric-value">' + Math.round(d.last_temperature) + '°C</span></div>';
                        } else if (d.current_status === 'online' && d.device_type === 'ping') {
                            if (d.last_rtt_ms != null) metrics += '<div class="metric"><span class="metric-label">RTT</span> <span class="metric-value">' + d.last_rtt_ms + 'ms</span></div>';
                        } else if (d.current_status !== 'online') {
                            metrics = '<div class="metric"><span class="metric-label">Offline há</span> <span class="metric-value" style="color:var(--danger)">' + timeAgo(d.status_since) + '</span></div>';
                        }

                        var typeBadge = d.device_type === 'ping' ? '<span class="badge-ping">PING</span>' : '';

                        return '<div class="tv-device status-' + d.current_status + '">'
                            + '<div class="tv-device-top">'
                            + '<div class="tv-device-name">' + esc(d.name) + typeBadge + '</div>'
                            + '<span class="badge badge-' + d.current_status + '">' + d.current_status + '</span>'
                            + '</div>'
                            + '<div class="tv-device-client">' + esc(d.client_name || '—') + '</div>'
                            + '<div class="tv-device-host">' + esc(d.host) + '</div>'
                            + (metrics ? '<div class="tv-device-metrics">' + metrics + '</div>' : '')
                            + '</div>';
                    }).join('');
                }

                var hostsList = document.getElementById('hosts-list');
                if (hostsList) {
                    if (data.downHosts.length === 0) {
                        hostsList.innerHTML = '<div class="tv-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><p>Todos os hosts respondendo</p></div>';
                    } else {
                        var html = '<div class="tv-hosts">' + data.downHosts.map(function(h) {
                            var comment = h.comment ? '<span class="tv-host-comment">' + esc(h.comment) + '</span>' : '';
                            return '<div class="tv-host-item">'
                                + comment
                                + '<div class="tv-host-bottom">'
                                + '<span class="tv-host-client">' + esc(h.mikrotik_name || '\u2014') + ' \u00b7 ' + timeAgo(h.status_since) + '</span>'
                                + '<span class="tv-host-address">' + esc(h.host_address) + '</span>'
                                + '</div>'
                                + '</div>';
                        }).join('') + '</div>';
                        hostsList.innerHTML = html;
                    }
                }

                elapsed = 0;
            })
            .catch(function() {});
    }

    function timeAgo(dt) {
        if (!dt) return '—';
        // Banco retorna horários em America/Sao_Paulo (sem offset).
        // NÃO adicionar 'Z' — Date() do browser interpreta como local.
        var diff = (Date.now() - new Date(dt).getTime()) / 1000;
        if (diff < 60) return Math.floor(diff) + 's';
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm';
        return Math.floor(diff / 86400) + 'd ' + Math.floor((diff % 86400) / 3600) + 'h';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    var bar = document.getElementById('refresh-progress');
    setInterval(function() {
        elapsed++;
        var pct = (elapsed / REFRESH_INTERVAL) * 100;
        bar.style.width = pct + '%';
        if (elapsed >= REFRESH_INTERVAL) {
            refresh();
        }
    }, 1000);

    setTimeout(refresh, 2000);
})();
</script>

</body>
</html>
