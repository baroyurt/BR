<?php
require_once '../config.php';
session_start();

date_default_timezone_set('Europe/Nicosia');

// --- Date range filter ---
$range = $_GET['range'] ?? '7';
$validRanges = ['1', '7', '30', '90'];
if (!in_array($range, $validRanges)) $range = '7';
$rangeDays = (int)$range;

$endDate   = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-{$rangeDays} days"));

// --- 1. Summary stats ---
$totalEmployees = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE is_active = 1")->fetchColumn();
$totalAreas     = (int)$pdo->query("SELECT COUNT(*) FROM areas")->fetchColumn();

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM work_slots WHERE DATE(slot_start) BETWEEN :s AND :e");
$stmtTotal->execute([':s' => $startDate, ':e' => $endDate]);
$totalSlots = (int)$stmtTotal->fetchColumn();

// --- 2. Top areas by assignment count ---
$stmtAreas = $pdo->prepare("
    SELECT a.name, a.color, COUNT(ws.id) AS cnt
    FROM work_slots ws
    JOIN areas a ON ws.area_id = a.id
    WHERE DATE(ws.slot_start) BETWEEN :s AND :e
    GROUP BY a.id, a.name, a.color
    ORDER BY cnt DESC
    LIMIT 15
");
$stmtAreas->execute([':s' => $startDate, ':e' => $endDate]);
$topAreas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);

// --- 3. Top employees by assignment count ---
$stmtEmp = $pdo->prepare("
    SELECT e.name, COUNT(ws.id) AS cnt
    FROM work_slots ws
    JOIN employees e ON ws.employee_id = e.id
    WHERE DATE(ws.slot_start) BETWEEN :s AND :e
    GROUP BY e.id, e.name
    ORDER BY cnt DESC
    LIMIT 15
");
$stmtEmp->execute([':s' => $startDate, ':e' => $endDate]);
$topEmployees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

// --- 4. Daily slot counts ---
$stmtDaily = $pdo->prepare("
    SELECT DATE(slot_start) AS day, COUNT(*) AS cnt
    FROM work_slots
    WHERE DATE(slot_start) BETWEEN :s AND :e
    GROUP BY DATE(slot_start)
    ORDER BY day ASC
");
$stmtDaily->execute([':s' => $startDate, ':e' => $endDate]);
$dailyCounts = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);

// --- 5. Hourly distribution ---
$stmtHour = $pdo->prepare("
    SELECT HOUR(slot_start) AS hr, COUNT(*) AS cnt
    FROM work_slots
    WHERE DATE(slot_start) BETWEEN :s AND :e
    GROUP BY HOUR(slot_start)
    ORDER BY hr ASC
");
$stmtHour->execute([':s' => $startDate, ':e' => $endDate]);
$hourlyRows = $stmtHour->fetchAll(PDO::FETCH_ASSOC);
$hourlyCounts = array_fill(0, 24, 0);
foreach ($hourlyRows as $row) {
    $hourlyCounts[(int)$row['hr']] = (int)$row['cnt'];
}

// max values for bar scaling
$maxAreaCnt = $topAreas ? max(array_column($topAreas, 'cnt')) : 1;
$maxEmpCnt  = $topEmployees ? max(array_column($topEmployees, 'cnt')) : 1;
$maxHour    = $hourlyCounts ? max($hourlyCounts) : 1;
$maxDaily   = $dailyCounts  ? max(array_column($dailyCounts, 'cnt')) : 1;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Veri Analizi - Breaklist</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; color: #2c3e50; }
.container { max-width: 1400px; margin: 0 auto; padding: 20px; }
header { background: white; padding: 16px 24px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
header h1 { font-size: 22px; color: #2c3e50; flex: 1; }
.btn-nav { background: linear-gradient(135deg,#4b5563 0%,#374151 100%); padding: 7px 14px; font-size: 12px; color: white; text-decoration: none; border-radius: 5px; font-weight: 600; }
.btn-nav:hover { background: linear-gradient(135deg,#374151 0%,#1f2937 100%); }
.btn-nav.active { background: linear-gradient(135deg,#1e88e5 0%,#0d47a1 100%); }
.range-form { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; }
.range-form select { padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; background: white; cursor: pointer; }
.range-form button { padding: 6px 14px; border-radius: 6px; border: none; background: #1e88e5; color: white; font-weight: 600; cursor: pointer; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-card { background: white; border-radius: 10px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); text-align: center; }
.stat-card .value { font-size: 32px; font-weight: 700; color: #1e88e5; }
.stat-card .label { font-size: 13px; color: #6b7280; margin-top: 4px; font-weight: 500; }
.section { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); padding: 20px; margin-bottom: 20px; }
.section h2 { font-size: 16px; margin-bottom: 14px; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
.bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 13px; }
.bar-row .label { width: 160px; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #374151; flex-shrink: 0; }
.bar-track { flex: 1; background: #f1f5f9; border-radius: 4px; height: 20px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 4px; background: #1e88e5; transition: width 0.4s; }
.bar-row .count { width: 40px; text-align: left; font-weight: 700; color: #374151; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 720px) { .two-col { grid-template-columns: 1fr; } }
.hour-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 4px; margin-top: 6px; }
.hour-cell { text-align: center; }
.hour-bar-wrap { height: 60px; display: flex; align-items: flex-end; justify-content: center; }
.hour-bar { width: 80%; background: #1e88e5; border-radius: 3px 3px 0 0; transition: height 0.4s; }
.hour-label { font-size: 10px; color: #6b7280; margin-top: 2px; }
.hour-cnt { font-size: 10px; font-weight: 700; color: #374151; }
.daily-chart { display: flex; align-items: flex-end; gap: 3px; height: 100px; margin-top: 10px; overflow-x: auto; padding-bottom: 4px; }
.day-col { display: flex; flex-direction: column; align-items: center; gap: 2px; flex-shrink: 0; }
.day-bar { width: 28px; background: #1e88e5; border-radius: 3px 3px 0 0; }
.day-lbl { font-size: 9px; color: #9ca3af; white-space: nowrap; }
.day-cnt-lbl { font-size: 9px; color: #374151; font-weight: 700; }
.empty-note { color: #9ca3af; font-size: 13px; text-align: center; padding: 20px; }
</style>
</head>
<body>
<div class="container">

  <header>
    <h1>📊 Veri Analizi</h1>
    <a href="index.php" class="btn-nav">🔄 Yönetim</a>
    <a href="employees.php" class="btn-nav">👥 Çalışanlar</a>
    <a href="employee_history.php" class="btn-nav">📅 Vardiya Geçmişi</a>
    <a href="analytics.php" class="btn-nav active">📊 Analiz</a>
    <form method="get" class="range-form">
      <label for="rangeSelect">Son:</label>
      <select id="rangeSelect" name="range">
        <option value="1"  <?= $range==='1'  ? 'selected':'' ?>>1 gün</option>
        <option value="7"  <?= $range==='7'  ? 'selected':'' ?>>7 gün</option>
        <option value="30" <?= $range==='30' ? 'selected':'' ?>>30 gün</option>
        <option value="90" <?= $range==='90' ? 'selected':'' ?>>90 gün</option>
      </select>
      <button type="submit">Filtrele</button>
    </form>
  </header>

  <!-- Summary Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="value"><?= $totalEmployees ?></div>
      <div class="label">Aktif Çalışan</div>
    </div>
    <div class="stat-card">
      <div class="value"><?= $totalAreas ?></div>
      <div class="label">Bölge</div>
    </div>
    <div class="stat-card">
      <div class="value"><?= number_format($totalSlots) ?></div>
      <div class="label">Toplam Atama (son <?= $rangeDays ?> gün)</div>
    </div>
    <div class="stat-card">
      <div class="value"><?= $rangeDays > 0 ? number_format(round($totalSlots / $rangeDays)) : 0 ?></div>
      <div class="label">Günlük Ort. Atama</div>
    </div>
  </div>

  <div class="two-col">

    <!-- Top Areas -->
    <div class="section">
      <h2>🏆 En Çok Atanan Bölgeler</h2>
      <?php if (empty($topAreas)): ?>
        <div class="empty-note">Bu dönemde veri yok.</div>
      <?php else: ?>
        <?php foreach ($topAreas as $row): ?>
        <div class="bar-row">
          <div class="label" title="<?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="bar-track">
            <div class="bar-fill" style="width:<?= round($row['cnt'] / $maxAreaCnt * 100) ?>%;background:<?= htmlspecialchars($row['color'], ENT_QUOTES, 'UTF-8') ?>;"></div>
          </div>
          <div class="count"><?= (int)$row['cnt'] ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Top Employees -->
    <div class="section">
      <h2>👤 En Çok Atanan Çalışanlar</h2>
      <?php if (empty($topEmployees)): ?>
        <div class="empty-note">Bu dönemde veri yok.</div>
      <?php else: ?>
        <?php foreach ($topEmployees as $row): ?>
        <div class="bar-row">
          <div class="label" title="<?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="bar-track">
            <div class="bar-fill" style="width:<?= round($row['cnt'] / $maxEmpCnt * 100) ?>%;"></div>
          </div>
          <div class="count"><?= (int)$row['cnt'] ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <!-- Daily Distribution -->
  <div class="section">
    <h2>📅 Günlük Atama Dağılımı (son <?= $rangeDays ?> gün)</h2>
    <?php if (empty($dailyCounts)): ?>
      <div class="empty-note">Bu dönemde veri yok.</div>
    <?php else: ?>
    <div class="daily-chart">
      <?php foreach ($dailyCounts as $d):
        $pct = $maxDaily > 0 ? round($d['cnt'] / $maxDaily * 90) : 0;
        $lbl = date('d.m', strtotime($d['day']));
      ?>
      <div class="day-col">
        <div class="day-cnt-lbl"><?= (int)$d['cnt'] ?></div>
        <div class="day-bar" style="height:<?= max(2,$pct) ?>px;" title="<?= htmlspecialchars($d['day'], ENT_QUOTES, 'UTF-8') ?>: <?= (int)$d['cnt'] ?> atama"></div>
        <div class="day-lbl"><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Hourly Distribution -->
  <div class="section">
    <h2>🕐 Saatlik Atama Dağılımı (son <?= $rangeDays ?> gün)</h2>
    <div class="hour-grid">
      <?php for ($h = 0; $h < 24; $h++):
        $cnt = $hourlyCounts[$h];
        $pct = $maxHour > 0 ? round($cnt / $maxHour * 56) : 0;
      ?>
      <div class="hour-cell">
        <div class="hour-bar-wrap">
          <div class="hour-bar" style="height:<?= max(2,$pct) ?>px;" title="<?= $h ?>:00 — <?= $cnt ?> atama"></div>
        </div>
        <div class="hour-cnt"><?= $cnt ?></div>
        <div class="hour-label"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</div>
      </div>
      <?php endfor; ?>
    </div>
  </div>

</div>
</body>
</html>
