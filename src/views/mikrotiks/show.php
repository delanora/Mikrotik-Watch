<?php
declare(strict_types=1);
/**
 * @var array $mikrotik
 */
$isPing = ($mikrotik['device_type'] ?? 'mikrotik') === 'ping';
$deviceId = htmlspecialchars($mikrotik['id']);
?>

<style>
    .charts-controls { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .charts-controls label { font-size: 13px; font-weight: 600; color: var(--text-secondary); white-space: nowrap; }
    .charts-controls input[type="date"] { padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; background: var(--bg-secondary); color: var(--text-primary); font-family: var(--font-mono); }
    .charts-controls input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(0.6); }
    .period-btn { padding: 6px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg-secondary); color: var(--text-secondary); font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s; }
    .period-btn:hover { border-color: var(--accent-border); color: var(--accent); }
    .period-btn.active { background: var(--accent); border-color: var(--accent); color: white; }
    .chart-card { margin-bottom: 16px; }
    .chart-card .card-body { padding: 16px 20px; }
    .chart-wrap { position: relative; height: 220px; }
    .uptime-bar { display: flex; height: 32px; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border); }
    .uptime-segment { position: relative; min-width: 2px; }
    .uptime-segment.online { background: var(--success); }
    .uptime-segment.offline { background: var(--danger); }
    .uptime-segment.unknown { background: var(--text-muted); opacity: 0.4; }
    .uptime-legend { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 8px; font-size: 12px; color: var(--text-muted); }
    .uptime-legend-item { display: flex; align-items: center; gap: 6px; }
    .uptime-legend-dot { width: 10px; height: 10px; border-radius: 2px; }
    .uptime-stats { display: flex; gap: 20px; font-size: 13px; }
    .uptime-stats span { color: var(--text-secondary); }
    .uptime-stats strong { color: var(--text-primary); }
    .chart-loading { display: flex; align-items: center; justify-content: center; height: 220px; color: var(--text-muted); font-size: 14px; }
    .chart-loading::after { content: ''; width: 20px; height: 20px; border: 2px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.6s linear infinite; margin-left: 10px; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="breadcrumb">
    <a href="/mikrotiks">Equipamentos</a>
    <span class="breadcrumb-sep">›</span>
    <span class="breadcrumb-current"><?= htmlspecialchars($mikrotik['name']) ?></span>
</div>

<div class="page-header">
    <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        <?= htmlspecialchars($mikrotik['name']) ?>
    </h1>
    <div style="display: flex; gap: 8px;">
        <a href="/mikrotiks/<?= $deviceId ?>/edit" class="btn btn-secondary">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Editar
        </a>
    </div>
</div>

<!-- Status Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon icon-accent">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= htmlspecialchars($mikrotik['current_status'] ?? 'unknown') ?></h3>
            <p>Status Atual</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= htmlspecialchars($mikrotik['routeros_version'] ?? '—') ?></h3>
            <p>RouterOS</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>
        </div>
        <div class="stat-info">
            <h3><?= htmlspecialchars($mikrotik['board_name'] ?? '—') ?></h3>
            <p>Board</p>
        </div>
    </div>
</div>

<!-- Detalhes do Equipamento -->
<div class="card" style="margin-bottom: 16px;">
    <div class="card-header">
        <h2>Informações</h2>
    </div>
    <div class="card-body">
        <div class="peer-details">
            <div class="peer-detail">
                <span class="peer-detail-label">Cliente</span>
                <span class="peer-detail-value"><?= htmlspecialchars($mikrotik['client_name'] ?? '—') ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">Host</span>
                <span class="peer-detail-mono"><?= htmlspecialchars($mikrotik['host']) ?></span>
            </div>
            <?php if (!$isPing): ?>
            <div class="peer-detail">
                <span class="peer-detail-label">Porta</span>
                <span class="peer-detail-value"><?= (int) $mikrotik['port'] ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">HTTPS</span>
                <span class="peer-detail-value"><?= !empty($mikrotik['use_ssl']) ? 'Sim' : 'Não' ?></span>
            </div>
            <div class="peer-detail">
                <span class="peer-detail-label">Usuário</span>
                <span class="peer-detail-mono"><?= htmlspecialchars($mikrotik['username']) ?></span>
            </div>
            <?php endif; ?>
            <div class="peer-detail">
                <span class="peer-detail-label">Cadastrado em</span>
                <span class="peer-detail-value"><?= date('d/m/Y H:i', strtotime($mikrotik['created_at'])) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- ─── Timeline de Disponibilidade ─────────────────────────────────────────── -->

<div class="card" style="margin-bottom: 0;">
    <div class="card-body" style="padding: 16px 24px;">
        <div class="charts-controls">
            <label>Período:</label>
            <button class="period-btn active" data-days="7">7 dias</button>
            <button class="period-btn" data-days="15">15 dias</button>
            <button class="period-btn" data-days="30">30 dias</button>
            <button class="period-btn" data-days="90">90 dias</button>
            <span style="color: var(--text-muted); font-size: 12px;">ou</span>
            <input type="date" id="chart-start" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
            <span style="color: var(--text-muted); font-size: 12px;">até</span>
            <input type="date" id="chart-end" value="<?= date('Y-m-d') ?>">
            <button class="btn btn-secondary" id="chart-apply" style="font-size: 12px; height: 32px;">Aplicar</button>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Disponibilidade
        </h2>
    </div>
    <div class="card-body">
        <div id="uptime-bar" class="uptime-bar">
            <div class="uptime-segment unknown" style="width: 100%;" title="Carregando…"></div>
        </div>
        <div class="uptime-legend">
            <div style="display: flex; gap: 16px; align-items: center;">
                <div class="uptime-legend-item"><div class="uptime-legend-dot" style="background: var(--success);"></div> Online</div>
                <div class="uptime-legend-item"><div class="uptime-legend-dot" style="background: var(--danger);"></div> Offline</div>
                <div class="uptime-legend-item"><div class="uptime-legend-dot" style="background: var(--text-muted); opacity: 0.4;"></div> Desconhecido</div>
            </div>
            <div id="uptime-stats" class="uptime-stats"></div>
        </div>
    </div>
</div>

<?php if (!$isPing): ?>
<!-- ─── Gráficos de Métricas ──────────────────────────────────────────────── -->

<div class="card" style="margin-bottom: 16px;">
    <div class="card-header">
        <h2>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Métricas ao Longo do Tempo
        </h2>
    </div>
    <div class="card-body">
        <div class="chart-card">
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header"><h2 style="font-size: 14px;">CPU (%)</h2></div>
                <div class="card-body">
                    <div id="cpu-chart-loading" class="chart-loading">Carregando dados…</div>
                    <div class="chart-wrap" style="display:none;" id="cpu-chart-wrap"><canvas id="cpuChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="chart-card">
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header"><h2 style="font-size: 14px;">Memória (%)</h2></div>
                <div class="card-body">
                    <div id="mem-chart-loading" class="chart-loading" style="display:none;">Carregando…</div>
                    <div class="chart-wrap" style="display:none;" id="mem-chart-wrap"><canvas id="memChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="chart-card">
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header"><h2 style="font-size: 14px;">Temperatura (°C)</h2></div>
                <div class="card-body">
                    <div id="temp-chart-loading" class="chart-loading" style="display:none;">Carregando…</div>
                    <div class="chart-wrap" style="display:none;" id="temp-chart-wrap"><canvas id="tempChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="chart-card">
            <div class="card" style="margin-bottom: 0;">
                <div class="card-header"><h2 style="font-size: 14px;">Tensão (V)</h2></div>
                <div class="card-body">
                    <div id="volt-chart-loading" class="chart-loading" style="display:none;">Carregando…</div>
                    <div class="chart-wrap" style="display:none;" id="volt-chart-wrap"><canvas id="voltChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function() {
    var deviceId = '<?= $deviceId ?>';
    var isPing = <?= $isPing ? 'true' : 'false' ?>;
    var cpuChart = null, memChart = null, tempChart = null, voltChart = null;

    var style = getComputedStyle(document.documentElement);
    var accent = style.getPropertyValue('--accent').trim() || '#6366f1';
    var success = style.getPropertyValue('--success').trim() || '#22c55e';
    var danger = style.getPropertyValue('--danger').trim() || '#ef4444';
    var warning = style.getPropertyValue('--warning').trim() || '#f59e0b';
    var textMuted = style.getPropertyValue('--text-muted').trim() || '#6b7280';
    var border = style.getPropertyValue('--border').trim() || '#1e1e22';
    var bgSecondary = style.getPropertyValue('--bg-secondary').trim() || '#0d0f14';

    Chart.defaults.color = textMuted;
    Chart.defaults.borderColor = border;
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.font.size = 12;

    function chartOpts(label, color, yMin, yMax, yTitle) {
        return {
            type: 'line',
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: bgSecondary, titleColor: '#e2e8f0', bodyColor: '#e2e8f0',
                        borderColor: border, borderWidth: 1, padding: 10, cornerRadius: 6,
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxTicksLimit: 12, maxRotation: 0,
                            callback: function(v) {
                                var d = new Date(this.getLabelForValue(v));
                                return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) + ' ' +
                                       d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
                            }
                        }
                    },
                    y: { min: yMin, max: yMax, title: { display: true, text: yTitle, font: { size: 11 } }, grid: { color: border } }
                }
            },
            data: {
                labels: [],
                datasets: [{
                    label: label, data: [], borderColor: color, backgroundColor: color + '18',
                    borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 4, tension: 0.3, fill: true,
                }]
            }
        };
    }

    function loadData() {
        var start = document.getElementById('chart-start').value;
        var end = document.getElementById('chart-end').value;

        if (!isPing) {
            ['cpu', 'mem', 'temp', 'volt'].forEach(function(k) {
                document.getElementById(k + '-chart-loading').style.display = 'flex';
                document.getElementById(k + '-chart-wrap').style.display = 'none';
            });
        }

        fetch('/mikrotiks/' + deviceId + '/health-data?start=' + start + '&end=' + end)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) return;
                renderUptime(data.uptime, start, end);
                if (isPing) return;

                var labels = data.labels.map(function(dt) { return dt.replace('T', ' ').substring(0, 16); });
                cpuChart.data.labels = labels; cpuChart.data.datasets[0].data = data.cpu; cpuChart.update('none');
                memChart.data.labels = labels; memChart.data.datasets[0].data = data.memory; memChart.update('none');
                tempChart.data.labels = labels; tempChart.data.datasets[0].data = data.temp; tempChart.update('none');
                voltChart.data.labels = labels; voltChart.data.datasets[0].data = data.voltage; voltChart.update('none');

                ['cpu', 'mem', 'temp', 'volt'].forEach(function(k) {
                    document.getElementById(k + '-chart-loading').style.display = 'none';
                    document.getElementById(k + '-chart-wrap').style.display = 'block';
                });
            });
    }

    function renderUptime(segments, startStr, endStr) {
        var bar = document.getElementById('uptime-bar');
        var stats = document.getElementById('uptime-stats');
        var rangeStart = new Date(startStr).getTime();
        var rangeEnd = new Date(endStr).getTime();
        var totalMs = rangeEnd - rangeStart;

        if (totalMs <= 0 || segments.length === 0) {
            bar.innerHTML = '<div class="uptime-segment unknown" style="width:100%;" title="Sem dados"></div>';
            stats.innerHTML = '<span>Sem dados de disponibilidade para este período.</span>';
            return;
        }

        var html = '', onlineMs = 0, offlineMs = 0;
        segments.forEach(function(seg) {
            var segStart = new Date(seg.from).getTime();
            var segEnd = new Date(seg.to).getTime();
            var widthPct = Math.max(0.15, Math.min(100, ((segEnd - segStart) / totalMs) * 100));
            if (seg.status === 'online') onlineMs += (segEnd - segStart);
            else if (seg.status === 'offline') offlineMs += (segEnd - segStart);
            var title = seg.status.toUpperCase() + '\n' + new Date(seg.from).toLocaleString('pt-BR') + ' → ' + new Date(seg.to).toLocaleString('pt-BR');
            html += '<div class="uptime-segment ' + seg.status + '" style="width:' + widthPct.toFixed(3) + '%;" title="' + title.replace(/"/g, '&quot;') + '"></div>';
        });
        bar.innerHTML = html;

        var onlinePct = ((onlineMs / totalMs) * 100).toFixed(1);
        var offlinePct = ((offlineMs / totalMs) * 100).toFixed(1);
        var sampleCount = isPing ? 0 : (cpuChart ? cpuChart.data.labels.length : 0);
        stats.innerHTML =
            '<span>Online: <strong style="color:' + success + ';">' + onlinePct + '%</strong></span>' +
            '<span>Offline: <strong style="color:' + danger + ';">' + offlinePct + '%</strong></span>' +
            (sampleCount > 0 ? '<span>Total de amostras: <strong>' + sampleCount + '</strong></span>' : '');
    }

    document.querySelectorAll('.period-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var days = parseInt(btn.dataset.days);
            var end = new Date(); var start = new Date();
            start.setDate(start.getDate() - days);
            document.getElementById('chart-start').value = start.toISOString().split('T')[0];
            document.getElementById('chart-end').value = end.toISOString().split('T')[0];
            loadData();
        });
    });

    document.getElementById('chart-apply').addEventListener('click', function() {
        document.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
        loadData();
    });

    // Aguardar DOM + Chart.js do CDN estarem prontos
    window.addEventListener('load', function() {
        // Criar gráficos depois que DOM e Chart.js estiverem prontos
        if (!isPing) {
            cpuChart = new Chart(document.getElementById('cpuChart'), chartOpts('CPU', accent, 0, 100, '%'));
            memChart = new Chart(document.getElementById('memChart'), chartOpts('Memória', warning, 0, 100, '%'));
            tempChart = new Chart(document.getElementById('tempChart'), chartOpts('Temperatura', danger, null, null, '°C'));
            voltChart = new Chart(document.getElementById('voltChart'), chartOpts('Tensão', success, null, null, 'V'));
        }
        loadData();
    });
})();
</script>
