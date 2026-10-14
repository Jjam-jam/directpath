<?php
// Shared one-page dashboard layout used by every role.
// Each dashboard collects its own data and calls dashRender($config).

function dEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dMoney($n): string { return '₱' . number_format((float)$n, 2); }
function dMoneyShort($n): string {
    $n = (float)$n;
    if ($n >= 1000000) return '₱' . round($n / 1000000, 2) . 'M';
    if ($n >= 1000)    return '₱' . round($n / 1000, 1) . 'K';
    return '₱' . number_format($n, 0);
}
function dAgo(?string $ts): string {
    if (!$ts) return '';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' hr ago';
    if ($d < 86400 * 7) return floor($d / 86400) . ' day' . (floor($d / 86400) > 1 ? 's' : '') . ' ago';
    return date('M j', strtotime($ts));
}
function dBadge(string $status): string {
    $map = ['approved' => 'ok', 'released' => 'ok', 'present' => 'ok', 'finalized' => 'ok', 'resolved' => 'ok', 'active' => 'ok',
            'pending' => 'warn', 'generated' => 'info', 'pending_ceo' => 'warn', 'open' => 'warn', 'in_progress' => 'info',
            'rejected' => 'bad', 'absent' => 'bad', 'documents_rejected' => 'bad'];
    $cls = $map[$status] ?? 'info';
    return '<span class="d-badge ' . $cls . '">' . dEsc(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function dKpi(string $icon, string $label, string $value, string $sub = '', string $trend = '', string $href = ''): string {
    $tag = $href ? 'a' : 'div';
    $h  = '<' . $tag . ($href ? ' href="' . dEsc($href) . '"' : '') . ' class="d-kpi">';
    $h .= '<div class="d-kpi-top"><span class="d-ico"><i class="bx ' . dEsc($icon) . '"></i></span><span class="d-kpi-label">' . dEsc($label) . '</span></div>';
    $h .= '<div class="d-kpi-val">' . dEsc($value) . '</div>';
    if ($sub !== '') $h .= '<div class="d-kpi-sub ' . dEsc($trend) . '">' . dEsc($sub) . '</div>';
    return $h . '</' . $tag . '>';
}

function dCard(string $icon, string $title, string $body, string $link = '', string $linkLabel = 'View all', string $class = ''): string {
    $h  = '<div class="d-card ' . dEsc($class) . '"><div class="d-card-h"><div class="d-card-t"><span class="d-ico sm"><i class="bx ' . dEsc($icon) . '"></i></span>' . dEsc($title) . '</div>';
    if ($link !== '') $h .= '<a class="d-link" href="' . dEsc($link) . '">' . dEsc($linkLabel) . '</a>';
    return $h . '</div><div class="d-card-b">' . $body . '</div></div>';
}

// $rows: array of arrays of ready-made HTML cells
function dTable(array $cols, array $rows, string $empty = 'Nothing to show yet.', array $widths = []): string {
    if (!$rows) return '<div class="d-empty">' . dEsc($empty) . '</div>';
    $h = '<table class="d-tbl"><colgroup>';
    foreach ($cols as $i => $c) $h .= '<col' . (isset($widths[$i]) ? ' style="width:' . (int)$widths[$i] . '%"' : '') . '>';
    $h .= '</colgroup><thead><tr>';
    foreach ($cols as $c) $h .= '<th>' . dEsc($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $h .= '<tr>'; foreach ($r as $cell) $h .= '<td>' . $cell . '</td>'; $h .= '</tr>'; }
    return $h . '</tbody></table>';
}

function dChartBody(string $id): string {
    return '<div class="d-chart"><canvas id="' . dEsc($id) . '"></canvas></div>';
}

const D_PALETTE = ['#CD1E27', '#1A1D26', '#6B7280', '#E8888D', '#9CA3AF', '#A8181F', '#D1D5DB', '#F4B6BA'];

// Donut with legend on the right (legend colours follow the same palette as the chart)
function dDonutBody(string $id, array $labels, array $values): string {
    $h = '<div class="d-donut"><div class="d-chart"><canvas id="' . dEsc($id) . '"></canvas></div><ul class="d-legend">';
    foreach ($labels as $i => $l) {
        $h .= '<li><span class="dot" style="background:' . 'var(--dp-chart-' . (($i % count(D_PALETTE)) + 1) . ')' . '"></span><span class="lb">' . dEsc($l) . '</span><b>' . dEsc($values[$i] ?? 0) . '</b></li>';
    }
    return $h . '</ul></div>';
}

function dQuickActions(array $items): string {
    $h = '<div class="d-qa">';
    foreach ($items as $i => [$icon, $label, $href]) {
        $h .= '<a href="' . dEsc($href) . '" class="' . ($i === 0 ? 'primary' : '') . '"><i class="bx ' . dEsc($icon) . '"></i><span>' . dEsc($label) . '</span><i class="bx bx-chevron-right go"></i></a>';
    }
    return $h . '</div>';
}

function dActivities(array $rows): string {
    if (!$rows) return '<div class="d-empty">No recent activity.</div>';
    $h = '<ul class="d-act">';
    foreach ($rows as [$text, $time]) $h .= '<li><span class="pt"></span><div><div class="tx">' . dEsc($text) . '</div><div class="tm">' . dEsc($time) . '</div></div></li>';
    return $h . '</ul>';
}

function dEvents(array $events): string {
    if (!$events) return '<div class="d-empty">No upcoming events.</div>';
    $h = '<ul class="d-ev">';
    foreach ($events as [$date, $title, $sub]) {
        $ts = strtotime($date);
        $h .= '<li><div class="dt"><span>' . strtoupper(date('M', $ts)) . '</span><b>' . date('j', $ts) . '</b></div><div><div class="tx">' . dEsc($title) . '</div><div class="tm">' . dEsc($sub) . '</div></div></li>';
    }
    return $h . '</ul>';
}

// Upcoming events common to every role: next salary release, next cut-off, next holidays
function dCommonEvents(PDO $db, int $limit = 3): array {
    $ev = [];
    $cut = currentCutoff();
    $ev[] = [$cut['pay_date'], 'Salary release', 'Cut-off ' . $cut['cutoff']];
    $ev[] = [$cut['end'], 'Cut-off ends', $cut['label']];
    try {
        foreach ($db->query("SELECT holiday_date, name, type FROM holidays WHERE holiday_date >= CURDATE() ORDER BY holiday_date LIMIT 2")->fetchAll() as $h) {
            $ev[] = [$h['holiday_date'], $h['name'], ucfirst((string)$h['type']) . ' holiday'];
        }
    } catch (Throwable $e) {}
    usort($ev, fn($a, $b) => strcmp($a[0], $b[0]));
    return array_slice(array_filter($ev, fn($e) => $e[0] >= date('Y-m-d')), 0, $limit);
}

/**
 * $c = [
 *  'title' => 'Good afternoon, Name', 'sub' => '...', 'action' => '<html>',
 *  'kpis' => [dKpi(...) x4],
 *  'c1' => ['icon','title','link','body','chart'=>[...]], 'c2' => [...],
 *  'b1' => ['icon','title','link','body'], 'b2' => [...],
 *  'rail' => ['actions'=>[[icon,label,href]], 'activities'=>[[text,time]], 'events'=>[[date,title,sub]], 'actTitle'=>..., 'actLink'=>..., 'noActivity'=>true to hide the activity card],
 *  'charts' => [ ['id'=>..,'type'=>'bar|line|doughnut','labels'=>[],'datasets'=>[['label'=>..,'data'=>[],'color'=>..]],'money'=>bool,'center'=>['3','Total']] ]
 * ]
 */
function dashRender(array $c): void {
    $base = '../../';
    echo '<link rel="stylesheet" href="' . $base . 'assets/css/dash.css?v=' . @filemtime(__DIR__ . '/../assets/css/dash.css') . '">';
    echo '<div class="page-body dash-wrap"><div class="dash">';
    echo '<div class="d-head"><div><h1>' . dEsc($c['title']) . '</h1><p>' . dEsc($c['sub'] ?? '') . '</p></div><div class="d-head-r">' . ($c['action'] ?? '') . '<span class="d-date"><i class="bx bx-calendar"></i>' . date('D, M j, Y') . '</span></div></div>';
    echo '<div class="d-kpis">' . implode('', $c['kpis']) . '</div>';
    foreach (['c1' => 'd-c1', 'c2' => 'd-c2', 'b1' => 'd-b1', 'b2' => 'd-b2'] as $k => $cls) {
        if (!empty($c[$k])) echo dCard($c[$k]['icon'], $c[$k]['title'], $c[$k]['body'], $c[$k]['link'] ?? '', $c[$k]['linkLabel'] ?? 'View all', $cls);
    }
    $r = $c['rail'];
    echo '<div class="d-rail">';
    echo dCard('bx-bolt-circle', 'Quick actions', dQuickActions($r['actions']), '', '', 'rail-qa');
    if (empty($r['noActivity'])) echo dCard('bx-time-five', $r['actTitle'] ?? 'Recent activity', dActivities($r['activities']), $r['actLink'] ?? '', 'View all', 'rail-act');
    echo dCard('bx-calendar-event', 'Upcoming events', dEvents($r['events']), '', '', 'rail-ev');
    echo '</div></div></div>';
    echo '<script src="' . $base . 'assets/js/dash-charts.js?v=' . @filemtime(__DIR__ . '/../assets/js/dash-charts.js') . '"></script>';
    echo '<script>window.dpDash && window.dpDash(' . json_encode($c['charts'] ?? [], JSON_UNESCAPED_UNICODE) . ');</script>';
}
