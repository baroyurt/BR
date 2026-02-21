<?php
require_once '../config.php';
session_start();

// --- Config / input ---
$selected_date = $_GET['date'] ?? date('Y-m-d');
// Validate selected_date to ensure it's a valid date
$selected_timestamp = strtotime($selected_date);
if ($selected_timestamp === false) {
    $selected_date = date('Y-m-d'); // Fallback to today if invalid
    $selected_timestamp = strtotime($selected_date);
}
$previous_date = date('Y-m-d', $selected_timestamp - 86400); // Use timestamp - 1 day in seconds
$selected_employee_id = $_GET['employee_id'] ?? '';
$slot_minutes = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$per_page_raw = $_GET['per_page'] ?? '50';
$per_page = ($per_page_raw === 'all') ? 0 : max(1, intval($per_page_raw));

// --- Fetch employees ---
$employees = $pdo->query("
    SELECT id, name 
    FROM employees 
    WHERE is_active = 1 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// --- Fetch history data for both days ---
$history_data = [];
$history_data_previous = [];
if ($selected_employee_id) {
    // Selected date
    $stmt = $pdo->prepare("
        SELECT e.name as employee_name, e.id as employee_id, a.name as area_name, a.color as area_color,
               ws.slot_start, ws.slot_end
        FROM work_slots ws
        JOIN employees e ON ws.employee_id = e.id
        JOIN areas a ON ws.area_id = a.id
        WHERE ws.employee_id = ? AND DATE(ws.slot_start) = ?
        ORDER BY ws.slot_start ASC
    ");
    $stmt->execute([$selected_employee_id, $selected_date]);
    $history_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Previous date - prepare a new statement for clarity
    $stmt_prev = $pdo->prepare("
        SELECT e.name as employee_name, e.id as employee_id, a.name as area_name, a.color as area_color,
               ws.slot_start, ws.slot_end
        FROM work_slots ws
        JOIN employees e ON ws.employee_id = e.id
        JOIN areas a ON ws.area_id = a.id
        WHERE ws.employee_id = ? AND DATE(ws.slot_start) = ?
        ORDER BY ws.slot_start ASC
    ");
    $stmt_prev->execute([$selected_employee_id, $previous_date]);
    $history_data_previous = $stmt_prev->fetchAll(PDO::FETCH_ASSOC);
    
    $selected_employee = array_values(array_filter($employees, fn($x)=>$x['id']==$selected_employee_id))[0] ?? null;
} else {
    // Selected date
    $stmt = $pdo->prepare("
        SELECT e.name as employee_name, e.id as employee_id, a.name as area_name, a.color as area_color,
               ws.slot_start, ws.slot_end
        FROM work_slots ws
        JOIN employees e ON ws.employee_id = e.id
        JOIN areas a ON ws.area_id = a.id
        WHERE DATE(ws.slot_start) = ?
        ORDER BY e.name ASC, ws.slot_start ASC
    ");
    $stmt->execute([$selected_date]);
    $history_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Previous date - prepare a new statement for clarity
    $stmt_prev = $pdo->prepare("
        SELECT e.name as employee_name, e.id as employee_id, a.name as area_name, a.color as area_color,
               ws.slot_start, ws.slot_end
        FROM work_slots ws
        JOIN employees e ON ws.employee_id = e.id
        JOIN areas a ON ws.area_id = a.id
        WHERE DATE(ws.slot_start) = ?
        ORDER BY e.name ASC, ws.slot_start ASC
    ");
    $stmt_prev->execute([$previous_date]);
    $history_data_previous = $stmt_prev->fetchAll(PDO::FETCH_ASSOC);
}

// --- Group shifts by employee for both dates ---
$shifts_by_employee = [];
$shifts_by_employee_previous = [];
if ($selected_employee_id) {
    if (!empty($selected_employee)) {
        $shifts_by_employee[$selected_employee['id']] = ['name'=>$selected_employee['name'], 'shifts'=>[]];
        $shifts_by_employee_previous[$selected_employee['id']] = ['name'=>$selected_employee['name'], 'shifts'=>[]];
    } else {
        $shifts_by_employee[$selected_employee_id] = ['name'=>'Bilinmeyen Personel','shifts'=>[]];
        $shifts_by_employee_previous[$selected_employee_id] = ['name'=>'Bilinmeyen Personel','shifts'=>[]];
    }
} else {
    foreach ($employees as $e) {
        $shifts_by_employee[$e['id']] = ['name'=>$e['name'],'shifts'=>[]];
        $shifts_by_employee_previous[$e['id']] = ['name'=>$e['name'],'shifts'=>[]];
    }
}

// Fill selected date shifts
foreach ($history_data as $r) {
    $eid = $r['employee_id'];
    if (!isset($shifts_by_employee[$eid])) $shifts_by_employee[$eid] = ['name'=>$r['employee_name'] ?? ('#'.$eid),'shifts'=>[]];
    $shifts_by_employee[$eid]['shifts'][] = [
        'start'=>$r['slot_start'],'end'=>$r['slot_end'],
        'area_name'=>$r['area_name'],'area_color'=>$r['area_color']
    ];
}

// Fill previous date shifts
foreach ($history_data_previous as $r) {
    $eid = $r['employee_id'];
    if (!isset($shifts_by_employee_previous[$eid])) $shifts_by_employee_previous[$eid] = ['name'=>$r['employee_name'] ?? ('#'.$eid),'shifts'=>[]];
    $shifts_by_employee_previous[$eid]['shifts'][] = [
        'start'=>$r['slot_start'],'end'=>$r['slot_end'],
        'area_name'=>$r['area_name'],'area_color'=>$r['area_color']
    ];
}

// --- Sort employees alphabetically by name ---
uasort($shifts_by_employee, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});
uasort($shifts_by_employee_previous, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

// --- Pagination ---
$employee_ids = array_keys($shifts_by_employee);
$total_employees = count($employee_ids);
$total_pages = ($per_page===0) ? 1 : max(1,intval(ceil($total_employees/$per_page)));
if ($per_page>0 && $page>$total_pages) $page=$total_pages;
if ($per_page>0) {
    $offset = ($page-1)*$per_page;
    $paged_ids = array_slice($employee_ids,$offset,$per_page);
} else $paged_ids = $employee_ids;
$paged_shifts_by_employee = [];
foreach ($paged_ids as $id) $paged_shifts_by_employee[$id] = $shifts_by_employee[$id];

// --- slots for both days ---
$slotSec = $slot_minutes * 60;
$slots_count = intval(1440 / $slot_minutes);

// Previous day slots
$startSlot_previous = strtotime($previous_date.' 00:00:00');
$slots_previous = [];
for ($i=0;$i<$slots_count;$i++) $slots_previous[] = $startSlot_previous + $i*$slotSec;

// Selected day slots
$startSlot = strtotime($selected_date.' 00:00:00');
$slots = [];
for ($i=0;$i<$slots_count;$i++) $slots[] = $startSlot + $i*$slotSec;

// Combined slots for display (previous day + selected day)
$slots_combined = array_merge($slots_previous, $slots);

// --- layout sizes (tweakable) ---
$base_person_px = 700;
$person_col_width = intval($base_person_px * 0.42);
$slot_col_width = 44;
$table_min_width = $person_col_width + count($slots_combined) * $slot_col_width;

// --- build only slot colgroup (do NOT include person column here) ---
$colgroup_slots = '';
for ($i=0;$i<count($slots_combined);$i++) {
    $colgroup_slots .= "<col style=\"width:{$slot_col_width}px; min-width:{$slot_col_width}px; max-width:{$slot_col_width}px;\">";
}

// helpers
function sort_areas_alphabetically(&$areas) {
    if (count($areas) > 1) {
        usort($areas, function($a, $b) {
            return strcasecmp($a['area_name'], $b['area_name']);
        });
    }
}

function two_words($str){
    $str = trim(preg_replace('/\s+/',' ', strip_tags($str)));
    if ($str==='') return '';
    $parts = preg_split('/\s+/',$str);
    return implode(' ', array_slice($parts,0,2));
}
function format_minutes($m){
    if ($m<=0) return '0 dk';
    $h=intdiv($m,60); $r=$m%60;
    return $h>0 ? "{$h} saat {$r} dk" : "{$r} dk";
}

/**
 * Try to split a label into up to 2 short lines to improve fit in narrow cells.
 * - If label is short enough, return escaped label.
 * - Otherwise split on a space boundary to balance both lines.
 * - If no spaces or still too long, break the word roughly in half.
 */
function two_line_label($label, $maxCharsPerLine = 8) {
    $s = trim(strip_tags($label));
    if ($s === '') return '';
    // quick short
    if (mb_strlen($s) <= $maxCharsPerLine) return htmlspecialchars($s);
    // split into words
    $words = preg_split('/\s+/', $s);
    if (count($words) > 1) {
        // compute cumulative lengths and choose split point to balance lengths
        $total = 0;
        $lens = [];
        foreach ($words as $w) { $l = mb_strlen($w); $lens[] = $l; $total += $l; }
        $acc = 0; $splitIndex = 0;
        for ($i=0;$i<count($lens);$i++){
            $acc += $lens[$i];
            if ($acc >= $total/2) { $splitIndex = $i; break; }
        }
        // create first and second parts
        $part1 = implode(' ', array_slice($words,0,$splitIndex+1));
        $part2 = implode(' ', array_slice($words,$splitIndex+1));
        // if part2 empty (all words in part1) try different split: put last word to part2
        if ($part2 === '') {
            if (count($words) >= 2) {
                $part1 = implode(' ', array_slice($words,0,-1));
                $part2 = $words[count($words)-1];
            }
        }
        // clamp lengths by trimming if still too long
        if (mb_strlen($part1) > $maxCharsPerLine+3) $part1 = mb_substr($part1,0,$maxCharsPerLine+3) . '…';
        if (mb_strlen($part2) > $maxCharsPerLine+3) $part2 = mb_substr($part2,0,$maxCharsPerLine+3) . '…';
        return htmlspecialchars($part1) . '<br>' . htmlspecialchars($part2);
    } else {
        // no spaces - break the word roughly into two pieces
        $len = mb_strlen($s);
        $half = intval($len/2);
        $p1 = mb_substr($s,0,$half);
        $p2 = mb_substr($s,$half);
        if (mb_strlen($p1) > $maxCharsPerLine+2) $p1 = mb_substr($p1,0,$maxCharsPerLine+2) . '…';
        if (mb_strlen($p2) > $maxCharsPerLine+2) $p2 = mb_substr($p2,0,$maxCharsPerLine+2) . '…';
        return htmlspecialchars($p1) . '<br>' . htmlspecialchars($p2);
    }
}

function format_date_turkish($date) {
    $days_tr = [
        'Monday' => 'Pazartesi',
        'Tuesday' => 'Salı',
        'Wednesday' => 'Çarşamba',
        'Thursday' => 'Perşembe',
        'Friday' => 'Cuma',
        'Saturday' => 'Cumartesi',
        'Sunday' => 'Pazar'
    ];
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        error_log("Invalid date format received in format_date_turkish: " . $date);
        return 'Geçersiz Tarih'; // Return "Invalid Date" in Turkish
    }
    $day_name_en = date('l', $timestamp);
    $day_name_tr = $days_tr[$day_name_en] ?? $day_name_en;
    return date('d.m.Y', $timestamp) . ' (' . $day_name_tr . ')';
}

$total_shifts = count($history_data) + count($history_data_previous);
$merged_area_names = array_merge(array_column($history_data,'area_name'), array_column($history_data_previous,'area_name'));
$unique_areas = $selected_employee_id ? count(array_unique($merged_area_names)) : 0;
$merged_employee_ids = array_merge(array_column($history_data,'employee_id'), array_column($history_data_previous,'employee_id'));
$unique_employees_in_history = count(array_unique($merged_employee_ids));
$total_minutes = 0;
if ($selected_employee_id && (!empty($history_data) || !empty($history_data_previous))){
    $merged_history = array_merge($history_data, $history_data_previous);
    foreach ($merged_history as $r){
        $s=strtotime($r['slot_start']); $e=strtotime($r['slot_end']);
        if ($s && $e && $e>=$s) $total_minutes += intval(($e-$s)/60);
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Geçmiş Vardiya</title>
<link rel="stylesheet" href="../assets/css/admin-grid.css">
<style>
:root{
    --person-w: <?= $person_col_width ?>px;
    --slot-w: <?= $slot_col_width ?>px;
    --row-h: 44px;
    --muted: #6b7280;
    --cell-border: #dfe7ec;
    --accent: #2b94d6;
    --line-w: 2px;
    --label-font-size: 10px;
    --date-separator-width: 4px;
    --previous-day-header-bg: #fff3cd;
    --previous-day-header-border: #ffc107;
    --previous-day-header-color: #856404;
    --previous-day-cell-bg: #fffbf0;
    --selected-day-header-bg: #d1ecf1;
    --selected-day-header-color: #0c5460;
    --selected-day-cell-bg: #e7f6f8;
}
*{box-sizing:border-box;}
html,body{height:100%;}
body{font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial; background:#f7f9fb; color:#0f172a; margin:0;}
.container{max-width:1600px;margin:18px auto;padding:12px;}
.controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;}

/* Home button style */
.home-btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:8px;
  border:1px solid rgba(14,20,30,0.06);
  background:#fff;
  color:#0f172a;
  text-decoration:none;
  font-weight:600;
  font-size:14px;
}
.home-btn:hover{ background:#f3f6f8; }

/* Date header styles */
.date-header-previous {
  background: var(--previous-day-header-bg);
  border-right: var(--date-separator-width) solid var(--previous-day-header-border);
  font-weight: 900;
  color: var(--previous-day-header-color);
  font-size: 13px;
  text-align: center;
}
.date-header-selected {
  background: var(--selected-day-header-bg);
  font-weight: 900;
  color: var(--selected-day-header-color);
  font-size: 13px;
  text-align: center;
}
.time-slot-previous {
  background: var(--previous-day-cell-bg);
}
.time-slot-selected {
  background: var(--selected-day-cell-bg);
}

/* Grid */
.grid { display:grid; grid-template-columns: var(--person-w) 1fr; grid-template-rows: auto 1fr; gap:0; position: relative; }
.corner { grid-column:1/2; grid-row:1/2; background:#fff; border:var(--line-w) solid var(--cell-border); border-right:0; padding:10px 14px; font-weight:700; color:var(--muted); position: sticky; top: 0; z-index: 3; }
.header-scroll { grid-column:2/3; grid-row:1/2; overflow-x:auto; overflow-y:hidden; border:var(--line-w) solid var(--cell-border); border-left:0; background:#fff; position: sticky; top: 0; z-index: 2; }
.header-table{border-collapse:collapse; table-layout:fixed; width:max-content;}
.header-table th{
  height:var(--row-h);
  padding:6px 4px;
  text-align:center;
  border-left:var(--line-w) solid #f3f6f8;
  font-weight:700;
  color:var(--muted);
  font-size:12px;
  width:var(--slot-w);
  min-width:var(--slot-w);
  max-width:var(--slot-w);
  box-sizing:border-box;
}

/* Left names column */
.left-scroll { grid-column:1/2; grid-row:2/3; overflow-y:auto; overflow-x:hidden; border:var(--line-w) solid var(--cell-border); border-top:0; background:#fff; }
.left-table{ border-collapse:collapse; table-layout:fixed; width:100%; }
.left-table tr{ height:var(--row-h); }
.left-table td{ padding:10px 14px; height:var(--row-h); border-top:var(--line-w) solid #f3f6f8; vertical-align:middle; font-weight:700; color:#083047; display:flex; align-items:center; overflow:hidden; }

/* Main matrix */
.main-scroll { grid-column:2/3; grid-row:2/3; overflow:auto; border:var(--line-w) solid var(--cell-border); border-top:0; border-left:0; background:#fff; }
.main-table{ border-collapse:collapse; table-layout:fixed; width:max-content; }
.main-table tr{ height:var(--row-h); }
.main-table td{
  height:var(--row-h);
  border-left:var(--line-w) solid #f3f6f8;
  border-top:var(--line-w) solid #f3f6f8;
  padding:4px 2px; /* tighter padding to gain space */
  text-align:center;
  vertical-align:middle;
  box-sizing:border-box;
  width:var(--slot-w);
  min-width:var(--slot-w);
  max-width:var(--slot-w);
}

/* Plain text inside cell: allow up to 2 lines; smaller font and tighter line-height for fitting */
.shift-text{
  display:block;
  overflow:hidden;
  white-space:normal;
  text-overflow:ellipsis;
  font-weight:600;
  font-size:var(--label-font-size);
  line-height:1.05;
  color:#083047;
  max-height: calc(1.05em * 2 + 2px); /* 2 lines */
  padding:0 2px;
  box-sizing:border-box;
}

/* ensure <br> doesn't create excessive gap */
.shift-text br { line-height:1.05; }

/* minimal overlap indicator */
.multi-dot{
  display:inline-block;
  width:8px;
  height:8px;
  background:rgba(0,0,0,0.08);
  border-radius:50%;
  margin-left:4px;
  vertical-align:middle;
}

/* empty cell subtle */
.empty-cell{ opacity:0.03; height:var(--row-h); }

/* person name */
.person-name{ display:block; font-size:14px; line-height:1.1; word-break:break-word; max-height: calc(var(--row-h) * 1); overflow:hidden; padding-right:6px; }

/* responsiveness */
@media (max-width:900px){ :root{ --person-w: 260px; --slot-w: 40px; } }
</style>
</head>
<body>
<div class="container">
  <h2 style="margin:0 0 10px 0;">🕒 Geçmiş Vardiya</h2>

  <div class="controls">
    <a class="home-btn" href="/breaklist_slot/admin/index.php" aria-label="Anasayfaya dön">Anasayfaya Dön</a>
    <a class="home-btn" href="analytics.php" style="background:#1e88e5;">📈 Analiz</a>

    <select id="perPage" onchange="applyFilters()" style="padding:7px;border-radius:8px;border:1px solid var(--cell-border);">
      <option value="25" <?= ($per_page_raw === '25') ? 'selected' : '' ?>>25 / sayfa</option>
      <option value="50" <?= ($per_page_raw === '50') ? 'selected' : '' ?>>50 / sayfa</option>
      <option value="100" <?= ($per_page_raw === '100') ? 'selected' : '' ?>>100 / sayfa</option>
      <option value="all" <?= ($per_page_raw === 'all') ? 'selected' : '' ?>>Hepsi</option>
    </select>

    <input id="dateFilter" type="date" value="<?= htmlspecialchars($selected_date) ?>" onchange="applyFilters()" style="padding:7px;border-radius:8px;border:1px solid var(--cell-border);">
    <select id="employeeFilter" onchange="applyFilters()" style="padding:7px;border-radius:8px;border:1px solid var(--cell-border);">
      <option value="">Tüm Personeller</option>
      <?php foreach ($employees as $emp): ?>
        <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $selected_employee_id ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="grid" id="gridRoot">
    <div class="corner">Personel</div>

    <div class="header-scroll" id="headerScroll" aria-hidden="true">
      <table id="headerTable" class="header-table" role="presentation">
        <colgroup>
          <?= $colgroup_slots /* only slots here */ ?>
        </colgroup>
        <thead>
          <tr>
            <!-- Previous day header -->
            <th colspan="<?= count($slots_previous) ?>" class="date-header-previous">
              <?= format_date_turkish($previous_date) ?> - Önceki Gün
            </th>
            <!-- Selected day header -->
            <th colspan="<?= count($slots) ?>" class="date-header-selected">
              <?= format_date_turkish($selected_date) ?> - Seçili Gün
            </th>
          </tr>
          <tr>
            <?php foreach ($slots_previous as $slotTs): ?>
              <th class="time-slot-previous"><?= date('H:i', $slotTs) ?></th>
            <?php endforeach; ?>
            <?php foreach ($slots as $slotTs): ?>
              <th class="time-slot-selected"><?= date('H:i', $slotTs) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
      </table>
    </div>

    <div class="left-scroll" id="leftScroll">
      <table class="left-table" role="presentation">
        <tbody id="leftBody">
          <?php foreach ($paged_shifts_by_employee as $edata): ?>
            <tr><td title="<?= htmlspecialchars($edata['name']) ?>"><span class="person-name"><?= htmlspecialchars($edata['name']) ?></span></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="main-scroll" id="mainScroll">
      <table id="mainTable" class="main-table" role="presentation">
        <colgroup>
          <?= $colgroup_slots /* same colgroup for main table */ ?>
        </colgroup>
        <tbody id="mainBody">
          <?php foreach ($paged_shifts_by_employee as $eid => $edata): ?>
            <tr>
              <?php
                // Get shifts for both dates
                $shifts_previous = $shifts_by_employee_previous[$eid]['shifts'] ?? [];
                $shifts = $edata['shifts'];
                
                // First, render previous day slots
                foreach ($slots_previous as $slotStartTs):
                  $slotEndTs = $slotStartTs + $slotSec;
                  $found = [];
                  foreach ($shifts_previous as $s) {
                    $sTs = strtotime($s['start']); $eTs = strtotime($s['end']);
                    if (min($slotEndTs,$eTs) > max($slotStartTs,$sTs)) $found[] = $s;
                  }
                  // Sort areas alphabetically
                  sort_areas_alphabetically($found);
              ?>
                <td class="time-slot-previous">
                  <?php if (!empty($found)):
                    $first = $found[0];
                    $title = htmlspecialchars($first['area_name'].' '.date('H:i',strtotime($first['start'])).' - '.date('H:i',strtotime($first['end'])));
                    $displayHtml = two_line_label($first['area_name'], 8);
                  ?>
                    <div class="shift-text" title="<?= $title ?>"><?= $displayHtml ?></div>
                    <?php if (count($found)>1): ?><span class="multi-dot" title="<?= count($found) ?> örtüşme"></span><?php endif; ?>
                  <?php else: ?>
                    <div class="empty-cell">&nbsp;</div>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              
              <?php
                // Then, render selected day slots
                foreach ($slots as $slotStartTs):
                  $slotEndTs = $slotStartTs + $slotSec;
                  $found = [];
                  foreach ($shifts as $s) {
                    $sTs = strtotime($s['start']); $eTs = strtotime($s['end']);
                    if (min($slotEndTs,$eTs) > max($slotStartTs,$sTs)) $found[] = $s;
                  }
                  // Sort areas alphabetically
                  sort_areas_alphabetically($found);
              ?>
                <td class="time-slot-selected">
                  <?php if (!empty($found)):
                    $first = $found[0];
                    $title = htmlspecialchars($first['area_name'].' '.date('H:i',strtotime($first['start'])).' - '.date('H:i',strtotime($first['end'])));
                    $displayHtml = two_line_label($first['area_name'], 8);
                  ?>
                    <div class="shift-text" title="<?= $title ?>"><?= $displayHtml ?></div>
                    <?php if (count($found)>1): ?><span class="multi-dot" title="<?= count($found) ?> örtüşme"></span><?php endif; ?>
                  <?php else: ?>
                    <div class="empty-cell">&nbsp;</div>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- stats -->
  <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
    <div style="background:#fff;padding:10px;border-radius:8px;border:var(--line-w) solid var(--cell-border);min-width:120px;">
      <div style="font-weight:700;color:#083047;font-size:16px;"><?= $total_shifts ?></div>
      <div style="font-size:12px;color:var(--muted);">Toplam Vardiya</div>
    </div>
    <?php if ($selected_employee_id): ?>
      <div style="background:#fff;padding:10px;border-radius:8px;border:var(--line-w) solid var(--cell-border);">
        <div style="font-weight:700;color:#083047;font-size:16px;"><?= $unique_areas ?></div>
        <div style="font-size:12px;color:var(--muted);">Farklı Bölge</div>
      </div>
      <div style="background:#fff;padding:10px;border-radius:8px;border:var(--line-w) solid var(--cell-border);">
        <div style="font-weight:700;color:#083047;font-size:16px;"><?= format_minutes($total_minutes) ?></div>
        <div style="font-size:12px;color:var(--muted);">Toplam Süre</div>
      </div>
    <?php else: ?>
      <div style="background:#fff;padding:10px;border-radius:8px;border:var(--line-w) solid var(--cell-border);">
        <div style="font-weight:700;color:#083047;font-size:16px;"><?= $unique_employees_in_history ?></div>
        <div style="font-size:12px;color:var(--muted);">Aktif Personel</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
const mainScroll = document.getElementById('mainScroll');
const headerScroll = document.getElementById('headerScroll');
const leftScroll = document.getElementById('leftScroll');
const mainTable = document.getElementById('mainTable');
const leftBody = document.getElementById('leftBody');
const headerTable = document.getElementById('headerTable');

// compensate scrollbars and align header width precisely
function compensateScrollbars(){
    if (mainTable && headerTable) {
        headerTable.style.width = mainTable.clientWidth + 'px';
    }
    headerScroll.scrollLeft = mainScroll.scrollLeft;
    const scrollbarHeight = mainScroll.offsetHeight - mainScroll.clientHeight;
    leftScroll.style.paddingBottom = (scrollbarHeight > 0 ? scrollbarHeight + 'px' : '0px');
}

// synchronize header horizontal and left vertical with main
let syncing = { main:false, header:false, left:false };
mainScroll.addEventListener('scroll', () => {
    if (!syncing.main) {
        syncing.header = syncing.left = true;
        headerScroll.scrollLeft = mainScroll.scrollLeft;
        leftScroll.scrollTop = mainScroll.scrollTop;
        requestAnimationFrame(()=>{ syncing.header = syncing.left = false; });
    }
});
headerScroll.addEventListener('scroll', () => {
    if (!syncing.header) {
        syncing.main = true;
        mainScroll.scrollLeft = headerScroll.scrollLeft;
        requestAnimationFrame(()=>{ syncing.main = false; });
    }
});
leftScroll.addEventListener('scroll', () => {
    if (!syncing.left) {
        syncing.main = true;
        mainScroll.scrollTop = leftScroll.scrollTop;
        requestAnimationFrame(()=>{ syncing.main = false; });
    }
});

// Ensure left row heights equal main row heights (use getBoundingClientRect for fractional pixels)
function syncRowHeights(){
    const mainRows = mainTable.querySelectorAll('tbody tr');
    const leftRows = leftBody.querySelectorAll('tr');
    const len = Math.min(mainRows.length, leftRows.length);
    for (let i=0;i<len;i++){
        const h = Math.round(mainRows[i].getBoundingClientRect().height);
        leftRows[i].style.height = h + 'px';
        const td = leftRows[i].querySelector('td');
        if (td) td.style.height = h + 'px';
    }
}

// initial adjustments
window.addEventListener('load', () => {
    setTimeout(() => {
        // Calculate the scroll position where the two days meet
        const previousDaySlots = <?= count($slots_previous) ?>;
        const slotWidth = <?= $slot_col_width ?>;
        const scrollToPosition = previousDaySlots * slotWidth;
        
        // Set the initial scroll position to the junction of the two days
        mainScroll.scrollLeft = scrollToPosition;
        
        // Compensate scrollbars and sync header (which also syncs headerScroll.scrollLeft)
        compensateScrollbars();
        leftScroll.scrollTop = mainScroll.scrollTop;
        syncRowHeights();
    }, 25);
});

// resize observer for main table to update header width and row heights
if (window.ResizeObserver) {
    const ro = new ResizeObserver(entries => {
        compensateScrollbars();
        requestAnimationFrame(() => {
            syncRowHeights();
        });
    });
    ro.observe(mainTable);
}

// also update on window resize
window.addEventListener('resize', () => {
    requestAnimationFrame(() => {
        compensateScrollbars();
        syncRowHeights();
    });
});

// safety sync after fonts loaded
setTimeout(() => {
    compensateScrollbars();
    syncRowHeights();
}, 600);

// URL helpers remain unchanged
function buildUrl(params={}) {
    const url = new URL(window.location.href.split('?')[0], window.location.origin);
    const date = document.getElementById('dateFilter')?.value;
    const employee = document.getElementById('employeeFilter')?.value;
    const perPage = document.getElementById('perPage')?.value;
    if (date) url.searchParams.set('date', date);
    if (employee) url.searchParams.set('employee_id', employee);
    if (perPage) url.searchParams.set('per_page', perPage);
    for (const k in params) {
        if (params[k] === null) url.searchParams.delete(k);
        else url.searchParams.set(k, params[k]);
    }
    return url.toString();
}
function applyFilters(){ window.location.href = buildUrl({ page: 1 }); }
function goPage(p){ window.location.href = buildUrl({ page: p }); }
</script>
</body>
</html>