<?php
require_once '../config.php';
session_start();

// --- BEGIN: background sync of automatic employees (silent, non-blocking) ---
// Calls your internal sync_employees.php in background so employees.php doesn't block or show output.
function background_request_fire_and_forget($url) {
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host'])) return false;

    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
    $host = $parts['host'];
    $port = isset($parts['port']) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $path = (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $errno = 0; $errstr = '';
    $transport = ($scheme === 'https') ? 'ssl' : 'tcp';
    // short timeout to avoid hanging
    $fp = @fsockopen($transport . '://' . $host, $port, $errno, $errstr, 1);
    if ($fp) {
        stream_set_blocking($fp, 0); // non-blocking
        $out  = "GET " . $path . " HTTP/1.1\r\n";
        $out .= "Host: " . $host . "\r\n";
        $out .= "User-Agent: BackgroundSync/1.0\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        // give the server a tiny moment to accept, then close
        usleep(50000); // 50ms
        fclose($fp);
        return true;
    }

    // fallback to curl with strict timeouts
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800); // 0.8s max
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 400);
        curl_exec($ch);
        curl_close($ch);
        return true;
    }

    return false;
}

// fire and forget - silent
@background_request_fire_and_forget('http://172.18.0.36/breaklist/sync_employees.php');
// --- END background sync ---


/* Rest of employees.php (merged final version with PRG, AJAX toggles, searches, Extra naming) */

// ensure manual_vardiya column exists (attempt auto-migration if permitted)
try {
    $colVardiya = $pdo->query("SHOW COLUMNS FROM employees LIKE 'manual_vardiya'")->fetch(PDO::FETCH_ASSOC);
    if (!$colVardiya) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN manual_vardiya VARCHAR(16) NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    // ignore if cannot modify schema
}

// helper: set flash message and redirect to avoid double-submit (PRG)
function flash_and_redirect($type, $text) {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// retrieve flash message (if any) before output
if (isset($_SESSION['flash'])) {
    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Determine whether request is AJAX (fetch will send X-Requested-With)
function is_ajax() {
    return (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

// Prepare slot times (kept for compatibility)
$slot_duration = 20 * 60;
$now = time();
$current_slot_start = floor($now / $slot_duration) * $slot_duration;
$time_slots = 9;
$current_index = 7;
$slotOptions = [];
for ($i = 0; $i < $time_slots; $i++) {
    $offset = $i - $current_index;
    $slot_start = $current_slot_start + ($offset * $slot_duration);
    $slotOptions[] = date('H:i', $slot_start);
}

// Build vardiya options
$vardiyaOptions = [];
for ($h = 0; $h <= 24; $h++) {
    $label = (string)$h;
    $vardiyaOptions[] = $label;
    $vardiyaOptions[] = $label . '+';
}
foreach (range('A','N') as $lt) $vardiyaOptions[] = $lt;
$vardiyaOptions = array_values(array_unique($vardiyaOptions, SORT_REGULAR));

// Fetch distinct birim options
$birimRows = $pdo->query("SELECT DISTINCT TRIM(birim) AS birim FROM employees WHERE birim IS NOT NULL AND TRIM(birim) <> '' ORDER BY birim")->fetchAll(PDO::FETCH_ASSOC);
$birimOptions = array_map(function($r){ return $r['birim']; }, $birimRows);

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normalize birim input possibility
    $postedBirim = isset($_POST['birim']) ? trim($_POST['birim']) : null;

    // --- ADD ---
    if (isset($_POST['add_employee'])) {
        $name = trim($_POST['name']);
        if (empty($postedBirim)) {
            if (!empty($_POST['birim_select'])) $postedBirim = trim($_POST['birim_select']);
            if (!empty($_POST['birim_custom'])) $postedBirim = trim($_POST['birim_custom']);
        }
        $birim = $postedBirim ?: '';
        $manual_vardiya = trim($_POST['manual_vardiya'] ?? '') ?: null;
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO employees (name, is_active, birim, manual_vardiya) VALUES (?, 1, ?, ?)");
            $stmt->execute([$name, $birim, $manual_vardiya]);
            // PRG to avoid duplicate on refresh
            flash_and_redirect('success', 'Çalışan eklendi!');
        } else {
            flash_and_redirect('error', 'Ad alanı boş olamaz.');
        }
    }

    // --- UPDATE ---
    if (isset($_POST['update_employee'])) {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        if (empty($postedBirim)) {
            if (!empty($_POST['birim_select'])) $postedBirim = trim($_POST['birim_select']);
            if (!empty($_POST['birim_custom'])) $postedBirim = trim($_POST['birim_custom']);
        }
        $birim = $postedBirim ?: '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $manual_vardiya = trim($_POST['manual_vardiya'] ?? '') ?: null;
        if ($name) {
            $stmt = $pdo->prepare("UPDATE employees SET name = ?, is_active = ?, birim = ?, manual_vardiya = ? WHERE id = ?");
            $stmt->execute([$name, $is_active, $birim, $manual_vardiya, $id]);
            flash_and_redirect('success', 'Çalışan güncellendi!');
        } else {
            flash_and_redirect('error', 'Ad alanı boş olamaz.');
        }
    }

    // --- DELETE ---
    if (isset($_POST['delete_employee'])) {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        flash_and_redirect('success', 'Çalışan silindi!');
    }

    // --- TOGGLE ACTIVE (single) ---
    if (isset($_POST['toggle_active'])) {
        $id = $_POST['id'];
        $new = isset($_POST['new_active']) ? (int)$_POST['new_active'] : null;
        if ($new !== null) {
            $stmt = $pdo->prepare("UPDATE employees SET is_active = ? WHERE id = ?");
            $stmt->execute([$new, $id]);

            if (is_ajax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'id' => (int)$id, 'new_active' => (int)$new]);
                exit;
            } else {
                flash_and_redirect('success', 'Durum güncellendi!');
            }
        } else {
            if (is_ajax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
                exit;
            } else {
                flash_and_redirect('error', 'Geçersiz durum isteği.');
            }
        }
    }

    // --- BULK: set_all_manual_action ---
    if (isset($_POST['set_all_manual_action'])) {
        $action = $_POST['set_all_manual_action']; // 'activate' or 'deactivate'
        $val = ($action === 'activate') ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE employees SET is_active = ? WHERE external_id IS NULL OR external_id = ''");
        $stmt->execute([$val]);

        if (is_ajax()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'action' => $action, 'new_active' => $val]);
            exit;
        } else {
            flash_and_redirect('success', 'Tüm Extra çalışanların durumu güncellendi!');
        }
    }
}

// fetch employees split into automatic (external_id present) and manual (external_id null/empty)
$autoEmployees = $pdo->query("SELECT id, name, external_id, is_active, birim, manual_vardiya FROM employees WHERE external_id IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$manualEmployees = $pdo->query("SELECT id, name, external_id, is_active, birim, manual_vardiya FROM employees WHERE external_id IS NULL OR external_id = '' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Çalışan Yönetimi - Breaklist</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root { --gap:16px; --muted:#6b7280; --accent:#0ea5e9; --success:#10b981; --danger:#ef4444; --card:#ffffff; }
        body { background:#f5f7fa; color:#14202b; font-family:Inter, "Segoe UI", Tahoma, Arial, sans-serif; margin:0; padding:0; }
        .container { max-width:1200px; margin:18px auto; padding:0 12px; }

        .topbar { position: sticky; top: 0; z-index: 1200; background: rgba(255,255,255,0.98); padding: 12px; border-radius: 10px; box-shadow: 0 2px 8px rgba(2,6,23,0.06); display:flex; gap:var(--gap); align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; }
        header h1 { font-size:18px; margin:0; }
        .add-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .content { display:flex; gap:var(--gap); align-items:flex-start; }
        .column { flex:1; min-width:300px; }
        .card { background:var(--card); border-radius:10px; padding:12px; box-shadow:0 1px 6px rgba(2,6,23,0.04); }
        .table-responsive { overflow:auto; max-height:560px; }
        table { width:100%; border-collapse:collapse; font-size:14px; margin-top:8px; }
        thead th { text-align:left; font-weight:700; padding:10px 8px; border-bottom:1px solid #eef2f7; color:#374151; position:sticky; top:0; background:var(--card); z-index:2; }
        tbody td { padding:10px 8px; border-bottom:1px solid #f3f4f6; color:#334155; vertical-align:middle; }
        .badge { display:inline-block; padding:6px 10px; border-radius:999px; font-size:13px; font-weight:700; }
        .badge-success { background:rgba(16,185,129,0.12); color:var(--success); border:1px solid rgba(16,185,129,0.18); }
        .badge-danger { background:rgba(239,68,68,0.06); color:var(--danger); border:1px solid rgba(239,68,68,0.12); }

        /* Buttons */
        .btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; border:1px solid transparent; cursor:pointer; font-weight:700; font-size:13px; text-decoration:none; }
        .btn:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(2,6,23,0.06); }
        .btn-primary { background:linear-gradient(135deg,#10b981 0%,#0ca678 100%); color:white; border-color:rgba(12,166,120,0.12); }
        .btn-success { background:linear-gradient(90deg,var(--success),#059669); color:white; border-color:rgba(5,150,105,0.12); }
        .btn-danger { background:linear-gradient(90deg,var(--danger),#dc2626); color:white; border-color:rgba(220,38,38,0.12); }
        .btn-ghost { background:transparent; color:#374151; border:1px solid #e6eef6; }
        .btn-small { padding:6px 8px; border-radius:8px; font-size:13px; cursor:pointer; border:1px solid #e2e8f0; background:#fff; }

        /* Search inputs */
        .card-header { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px; }
        .header-left { display:flex; gap:8px; align-items:center; }
        .search-input { height:36px; border-radius:8px; border:1px solid #e5e7eb; padding:6px 10px; min-width:180px; background:#fff; }
        .search-input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px rgba(14,165,233,0.06); }

        /* small ajax status */
        #ajaxStatus { position:fixed; right:18px; bottom:18px; z-index:2000; padding:10px 14px; border-radius:10px; display:none; font-weight:700; box-shadow:0 6px 20px rgba(2,6,23,0.12); }

        @media (max-width: 980px) { .content { flex-direction:column; } .card-header { flex-direction:column; align-items:flex-start } .search-input { width:100%; } }
    </style>
</head>
<body>
    <div class="container">

        <!-- TOPBAR -->
        <div class="topbar" role="region" aria-label="Üst Kontroller - Çalışan Ekle">
            <div>
                <header>
                    <h1>👥 Çalışan Yönetimi</h1>
                </header>
            </div>

            <div style="display:flex;align-items:center;gap:8px;">
                <!-- Back to main -->
                <a href="index.php" class="btn btn-ghost" title="Ana sayfaya dön">🏠 Ana Sayfaya Dön</a>

                <form id="addForm" method="POST" class="add-form" style="margin:0;" onsubmit="return syncBirimBeforeSubmit('addForm')">
                    <input type="text" id="add_name" name="name" placeholder="Çalışan adı" required aria-label="Çalışan adı" style="height:36px;border-radius:8px;padding:6px 10px;border:1px solid #e5e7eb;">
                    <!-- birim select + other -->
                    <select id="add_birim_select" class="birim-select" aria-label="Birim seç" onchange="onBirimSelectChange('add')" style="height:36px;border-radius:8px;">
                        <option value="">Birim (opsiyonel)</option>
                        <?php foreach ($birimOptions as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                        <option value="__other__">Diğer...</option>
                    </select>
                    <input id="add_birim_custom" class="birim-custom" type="text" placeholder="Birim yazın" aria-label="Birim (özel)" style="height:36px;border-radius:8px;padding:6px 10px;border:1px solid #e5e7eb;display:none;">
                    <input type="hidden" name="birim" id="add_birim_hidden" value="">
                    <select name="manual_vardiya" aria-label="Vardiya seç" style="height:36px;border-radius:8px;border:1px solid #e5e7eb;padding:6px 10px;font-size:13px;">
                        <option value="">Vardiya (opsiyonel)</option>
                        <?php foreach ($vardiyaOptions as $v): ?>
                            <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="add_employee" class="btn btn-primary">Ekle</button>
                </form>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div style="margin-bottom:12px;">
                <div class="card" style="background:#e6fffa;border-left:4px solid var(--success);">
                    <?= htmlspecialchars($message['text']) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="content">
            <!-- LEFT: Automatic -->
            <div class="column">
                <div class="card">
                    <div class="card-header">
                        <div class="header-left">
                            <h2 style="margin:0 8px 0 0;">🔁 Otomatik Çekilen Personeller</h2>
                        </div>

                        <div style="display:flex;gap:8px;align-items:center;">
                            <input id="search_auto" class="search-input" type="search" placeholder="Otomatik personelde ara (isim / birim / vardiya)" aria-label="Otomatik personel ara">
                        </div>
                    </div>

                    <div class="table-responsive" role="table" aria-label="Otomatik çekilen personeller">
                        <table id="table_auto">
                            <thead>
                                <tr>
                                    <th style="width:48px">ID</th>
                                    <th>Ad</th>
                                    <th>Birim</th>
                                    <th>Vardiya</th>
                                    <th style="width:110px">Durum</th>
                                    <th style="width:160px">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($autoEmployees)): ?>
                                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px;">Otomatik personel bulunamadı.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($autoEmployees as $emp): ?>
                                    <tr data-employee-id="<?= $emp['id'] ?>">
                                        <td class="col-id"><?= $emp['id'] ?></td>
                                        <td class="col-name"><?= htmlspecialchars($emp['name']) ?></td>
                                        <td class="col-birim"><?= htmlspecialchars($emp['birim'] ?? '') ?></td>
                                        <td class="col-vardiya"><?= htmlspecialchars($emp['manual_vardiya'] ?? '') ?></td>
                                        <td class="col-status">
                                            <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                                <?= $emp['is_active'] ? 'Aktif' : 'Pasif' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <!-- For automatic employees: no edit, only an active/passive toggle button -->
                                            <form method="POST" class="ajax-toggle-form" data-employee-id="<?= $emp['id'] ?>" style="display:inline;">
                                                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                                <input type="hidden" name="new_active" value="<?= $emp['is_active'] ? 0 : 1 ?>">
                                                <button type="submit" name="toggle_active" class="btn-small btn-ghost" title="Durum değiştir"><?= $emp['is_active'] ? 'Pasif Yap' : 'Aktifleştir' ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Extra (previously Manual) -->
            <div class="column">
                <div class="card">
                    <div class="card-header">
                        <div class="header-left">
                            <h2 style="margin:0 8px 0 0;">🧾 Extra Personeller</h2>
                        </div>

                        <div style="display:flex;gap:8px;align-items:center;">
                            <input id="search_manual" class="search-input" type="search" placeholder="Extra personelde ara (isim / birim / vardiya)" aria-label="Extra personel ara">
                            <form method="POST" class="ajax-bulk-manual-form" style="display:inline;">
                                <input type="hidden" name="set_all_manual_action" value="activate">
                                <button type="submit" class="btn btn-success" title="Tüm extra personelleri aktif yap">Tümünü Aktif</button>
                            </form>
                            <form method="POST" class="ajax-bulk-manual-form" style="display:inline;">
                                <input type="hidden" name="set_all_manual_action" value="deactivate">
                                <button type="submit" class="btn btn-danger" title="Tüm extra personelleri pasif yap">Tümünü Pasif</button>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive" role="table" aria-label="Extra eklenen personeller">
                        <table id="table_manual">
                            <thead>
                                <tr>
                                    <th style="width:48px">ID</th>
                                    <th>Ad</th>
                                    <th>Birim</th>
                                    <th>Vardiya</th>
                                    <th style="width:110px">Durum</th>
                                    <th style="width:160px">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($manualEmployees)): ?>
                                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px;">Extra personel yok.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($manualEmployees as $emp): ?>
                                    <tr data-employee-id="<?= $emp['id'] ?>">
                                        <td class="col-id"><?= $emp['id'] ?></td>
                                        <td class="col-name"><?= htmlspecialchars($emp['name']) ?></td>
                                        <td class="col-birim"><?= htmlspecialchars($emp['birim'] ?? '') ?></td>
                                        <td class="col-vardiya"><?= htmlspecialchars($emp['manual_vardiya'] ?? '') ?></td>
                                        <td class="col-status">
                                            <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                                <?= $emp['is_active'] ? 'Aktif' : 'Pasif' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <!-- For extra employees: edit & delete, plus inline active toggle (ajax) -->
                                            <button onclick="editEmployee(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($emp['birim'] ?? '', ENT_QUOTES) ?>', <?= $emp['is_active'] ?>, '<?= htmlspecialchars($emp['manual_vardiya'] ?? '', ENT_QUOTES) ?>')" class="btn-small btn-ghost" title="Düzenle">✏️</button>

                                            <form method="POST" class="ajax-toggle-form" data-employee-id="<?= $emp['id'] ?>" style="display:inline;">
                                                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                                <input type="hidden" name="new_active" value="<?= $emp['is_active'] ? 0 : 1 ?>">
                                                <!-- Button shows action (opposite of current status): if active => "Pasif", if inactive => "Aktif" -->
                                                <button type="submit" name="toggle_active" class="btn-small btn-ghost" title="Durum değiştir"><?= $emp['is_active'] ? 'Pasif' : 'Aktif' ?></button>
                                            </form>

                                            <button onclick="deleteEmployee(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['name'], ENT_QUOTES) ?>')" class="btn-small" style="background:linear-gradient(90deg,#f8d7da,#fff);border-color:rgba(239,68,68,0.12);" title="Sil">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div> <!-- /.content -->

    </div> <!-- /.container -->

    <!-- Düzenleme Modal -->
    <div id="editModal" class="modal" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="editTitle">
            <span class="close" style="float:right;cursor:pointer;font-size:20px;padding:6px 8px;">&times;</span>
            <h3 id="editTitle" style="margin-top:0;">Çalışan Düzenle</h3>
            <form id="editForm" method="POST" onsubmit="return syncBirimBeforeSubmit('editForm')">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                    <label for="edit_name">Ad:</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group">
                    <label for="edit_birim_select">Birim:</label>
                    <select id="edit_birim_select" class="birim-select" aria-label="Birim seç" onchange="onBirimSelectChange('edit')">
                        <option value="">(Seçiniz)</option>
                        <?php foreach ($birimOptions as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                        <option value="__other__">Diğer...</option>
                    </select>
                    <input id="edit_birim_custom" class="birim-custom" type="text" name="birim_custom" placeholder="Birim yazın" aria-label="Birim (özel)">
                    <input type="hidden" name="birim" id="edit_birim_hidden" value="">
                </div>

                <div class="form-group">
                    <label for="edit_manual_vardiya">Vardiya Kodu:</label>
                    <select name="manual_vardiya" id="edit_manual_vardiya" class="time-select">
                        <option value="">(Seçiniz)</option>
                        <?php foreach ($vardiyaOptions as $v): ?>
                            <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><input type="checkbox" name="is_active" id="edit_active" value="1"> Aktif</label>
                </div>
                <div style="text-align:right;">
                    <button type="submit" name="update_employee" class="btn btn-primary" style="padding:8px 14px;border-radius:8px;">Güncelle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- small ajax status -->
    <div id="ajaxStatus"></div>

    <script>
        // Helper and JS code (ajax toggle handling, search filter, modal handling)
        function onBirimSelectChange(prefix) {
            const select = document.getElementById(prefix + '_birim_select');
            const custom = document.getElementById(prefix + '_birim_custom');
            if (!select || !custom) return;
            if (select.value === '__other__') {
                custom.style.display = 'inline-block';
                custom.focus();
            } else {
                custom.style.display = 'none';
                custom.value = '';
            }
        }

        function syncBirimBeforeSubmit(formId) {
            try {
                const form = document.getElementById(formId);
                if (!form) return true;
                const prefix = formId === 'addForm' ? 'add' : 'edit';
                const select = document.getElementById(prefix + '_birim_select');
                const custom = document.getElementById(prefix + '_birim_custom');
                const hidden = document.getElementById(prefix + '_birim_hidden');
                if (!hidden) return true;
                let value = '';
                if (select && select.value && select.value !== '__other__') value = select.value;
                if (select && select.value === '__other__' && custom && custom.value.trim() !== '') value = custom.value.trim();
                hidden.value = value;
                return true;
            } catch (e) {
                console.error('syncBirimBeforeSubmit error', e);
                return true;
            }
        }

        function showAjaxStatus(text, type = 'info', timeout = 2000) {
            const el = document.getElementById('ajaxStatus');
            el.textContent = text;
            el.style.display = 'block';
            if (type === 'success') {
                el.style.background = '#d4edda';
                el.style.color = '#155724';
                el.style.border = '1px solid #c3e6cb';
            } else if (type === 'error') {
                el.style.background = '#f8d7da';
                el.style.color = '#721c24';
                el.style.border = '1px solid #f5c6cb';
            } else {
                el.style.background = '#d1ecf1';
                el.style.color = '#0c5460';
                el.style.border = '1px solid #bee5eb';
            }
            clearTimeout(el._t);
            el._t = setTimeout(() => { el.style.display = 'none'; }, timeout);
        }

        function updateRowActive(id, newActive) {
            const row = document.querySelector(`tr[data-employee-id="${id}"]`);
            if (!row) return;
            const badgeWrap = row.querySelector('.col-status');
            if (badgeWrap) {
                const badge = badgeWrap.querySelector('.badge');
                if (badge) {
                    if (newActive == 1) {
                        badge.classList.remove('badge-danger');
                        badge.classList.add('badge-success');
                        badge.textContent = 'Aktif';
                    } else {
                        badge.classList.remove('badge-success');
                        badge.classList.add('badge-danger');
                        badge.textContent = 'Pasif';
                    }
                }
            }

            const form = row.querySelector('form.ajax-toggle-form');
            if (form) {
                const hidden = form.querySelector('input[name="new_active"]');
                const btn = form.querySelector('button[type="submit"]');
                if (hidden) hidden.value = newActive == 1 ? 0 : 1;

                if (row.closest('#table_manual')) {
                    if (btn) btn.textContent = newActive == 1 ? 'Pasif' : 'Aktif';
                } else {
                    if (btn) btn.textContent = newActive == 1 ? 'Pasif Yap' : 'Aktifleştir';
                }
            }
        }

        function updateAllManualRows(newActive) {
            document.querySelectorAll('#table_manual tbody tr[data-employee-id]').forEach(r => {
                const id = r.getAttribute('data-employee-id');
                updateRowActive(id, newActive);
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const addCustom = document.getElementById('add_birim_custom');
            if (addCustom) addCustom.style.display = 'none';
            const editCustom = document.getElementById('edit_birim_custom');
            if (editCustom) editCustom.style.display = 'none';

            document.querySelectorAll('form.ajax-toggle-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const fd = new FormData(form);
                    if (!fd.has('toggle_active')) fd.append('toggle_active', '1');

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd
                    }).then(resp => resp.json())
                    .then(data => {
                        if (data && data.success) {
                            updateRowActive(data.id, data.new_active);
                            showAjaxStatus('Durum güncellendi', 'success', 1200);
                        } else {
                            showAjaxStatus(data && data.message ? data.message : 'Güncelleme başarısız', 'error', 2500);
                        }
                    })
                    .catch(err => {
                        console.error('toggle ajax error', err);
                        showAjaxStatus('Sunucu hatası', 'error', 2500);
                    });
                });
            });

            document.querySelectorAll('form.ajax-bulk-manual-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const fd = new FormData(form);
                    if (!fd.has('set_all_manual_action')) fd.append('set_all_manual_action', 'activate');

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: fd
                    }).then(resp => resp.json())
                    .then(data => {
                        if (data && data.success) {
                            updateAllManualRows(data.new_active);
                            showAjaxStatus('Tüm Extra çalışanların durumu güncellendi', 'success', 1400);
                        } else {
                            showAjaxStatus(data && data.message ? data.message : 'Toplu güncelleme başarısız', 'error', 2500);
                        }
                    })
                    .catch(err => {
                        console.error('bulk ajax error', err);
                        showAjaxStatus('Sunucu hatası', 'error', 2500);
                    });
                });
            });

            const searchAuto = document.getElementById('search_auto');
            const searchManual = document.getElementById('search_manual');
            const debounce = (fn, wait) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), wait); }; };
            if (searchAuto) searchAuto.addEventListener('input', debounce(function(e) { filterTable('#table_auto', e.target.value); }, 200));
            if (searchManual) searchManual.addEventListener('input', debounce(function(e) { filterTable('#table_manual', e.target.value); }, 200));
        });

        function filterTable(tableSelector, query) {
            const q = (query || '').trim().toLowerCase();
            const table = document.querySelector(tableSelector);
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            if (!q) { rows.forEach(r => r.style.display = ''); return; }
            rows.forEach(r => {
                if (r.querySelectorAll('td').length <= 1) { r.style.display = 'none'; return; }
                const name = (r.querySelector('.col-name') ? r.querySelector('.col-name').textContent : '').toLowerCase();
                const birim = (r.querySelector('.col-birim') ? r.querySelector('.col-birim').textContent : '').toLowerCase();
                const vardiya = (r.querySelector('.col-vardiya') ? r.querySelector('.col-vardiya').textContent : '').toLowerCase();
                const id = (r.querySelector('.col-id') ? r.querySelector('.col-id').textContent : '').toLowerCase();
                const haystack = `${id} ${name} ${birim} ${vardiya}`;
                r.style.display = haystack.indexOf(q) !== -1 ? '' : 'none';
            });
        }

        function editEmployee(id, name, birim, isActive, manualVardiya) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            const birimSelect = document.getElementById('edit_birim_select');
            const birimCustom = document.getElementById('edit_birim_custom');
            const birimHidden = document.getElementById('edit_birim_hidden');
            if (birimSelect) {
                let found = false;
                for (let i=0;i<birimSelect.options.length;i++) {
                    if (birimSelect.options[i].value === birim) { birimSelect.selectedIndex = i; found = true; break; }
                }
                if (!found && birim) {
                    birimSelect.value = '__other__';
                    birimCustom.style.display = 'inline-block';
                    birimCustom.value = birim;
                } else {
                    if (birimSelect.value !== '__other__') { birimCustom.style.display = 'none'; birimCustom.value = ''; } else { birimCustom.style.display = 'inline-block'; }
                }
            }
            if (birimHidden) birimHidden.value = birim || '';
            const mv = document.getElementById('edit_manual_vardiya');
            if (mv) mv.value = manualVardiya || '';
            document.getElementById('edit_active').checked = isActive == 1;
            document.getElementById('editModal').style.display = 'flex';
            document.getElementById('editModal').setAttribute('aria-hidden', 'false');
            document.getElementById('edit_name').focus();
        }

        function deleteEmployee(id, name) {
            if (!confirm(name + ' adlı çalışanı silmek istediğinizden emin misiniz?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `<input type="hidden" name="id" value="${id}"><input type="hidden" name="delete_employee" value="1">`;
            document.body.appendChild(form);
            form.submit();
        }

        // modal close handlers
        document.querySelectorAll('.modal .close').forEach(el => {
            el.onclick = () => {
                document.getElementById('editModal').style.display = 'none';
                document.getElementById('editModal').setAttribute('aria-hidden', 'true');
            };
        });
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('editModal');
            if (e.target === modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
        });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { const modal = document.getElementById('editModal'); if (modal.style.display === 'flex') { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); } } });
    </script>
</body>
</html>