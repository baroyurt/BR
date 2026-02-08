<?php
require_once '../config.php';
session_start();

// CSRF token (varsa kullan, yoksa oluştur)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Türkçe ay isimleri
$months_tr = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
    7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

// Mevcut aylık kayıtların listesi (YYYY-MM)
$monthsStmt = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(`date`, '%Y-%m') AS month
    FROM tip_boxes
    ORDER BY month DESC
");
$months = $monthsStmt->fetchAll(PDO::FETCH_COLUMN);

// Eğer ?month=YYYY-MM verildiyse, o aya ait verileri çek
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : null;
$monthData = [];
$totals = 0;
$monthLabel = '';
if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $start = $selectedMonth . '-01';
    $end = (new DateTime($start))->format('Y-m-t'); // last day of month
    $stmt = $pdo->prepare("SELECT `date`, `count` FROM tip_boxes WHERE `date` BETWEEN :start AND :end ORDER BY `date` ASC");
    $stmt->execute([':start' => $start, ':end' => $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $monthData[$r['date']] = (int)$r['count'];
        $totals += (int)$r['count'];
    }
    // Türkçe ay etiketi
    $dtTmp = DateTime::createFromFormat('Y-m-d', $start);
    if ($dtTmp) {
        $mIndex = (int)$dtTmp->format('n');
        $y = $dtTmp->format('Y');
        $monthLabel = (isset($months_tr[$mIndex]) ? $months_tr[$mIndex] : $dtTmp->format('F')) . ' ' . $y;
    } else {
        $monthLabel = (new DateTime($start))->format('F Y');
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tip Kutusu - Geçmiş</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px; }
        h1 { text-align: center; margin-bottom: 20px; color: #2c3e50; }
        .months { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; margin-bottom:20px; align-items:center; }
        .month-select { padding:8px 12px; background:#fff; border:1px solid #d1d5db; border-radius:6px; color:#111827; font-weight:600; }
        .month-label { color:#6b7280; font-size:14px; margin-right:8px; font-weight:700; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-top: 20px; }
        .cell { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:12px; text-align:center; }
        .back { display:inline-block; margin-top:18px; text-decoration:none; color:#1e88e5; }
        .controls { display:flex; justify-content:center; gap:8px; margin-top:12px; }
        .btn { padding:8px 12px; border-radius:6px; background:#e9ecef; color:#2c3e50; text-decoration:none; font-weight:700; border:1px solid transparent; cursor:pointer; }
        .btn.primary { background:#1e88e5; color:#fff; border-color:#1666c4; }
        .status { text-align:center; margin-top:10px; font-weight:700; }
        input[type="number"] { width:80px; padding:6px; border-radius:6px; border:1px solid #cbd5e1; font-weight:700; text-align:center; }
        .save-row { display:flex; justify-content:center; gap:10px; margin-top:14px; align-items:center; }
        .note { color:#6b7280; font-size:13px; text-align:center; margin-top:8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📜 Tip Kutusu - Geçmiş</h1>

        <div class="months">
            <label for="monthSelect" class="month-label">Ay seçin:</label>

            <form id="monthForm" method="get" action="" style="display:inline-block;">
                <select id="monthSelect" name="month" class="month-select" aria-label="Ay seçin">
                    <option value="">-- Ay seçin --</option>
                    <?php foreach ($months as $m):
                        // $m formatı YYYY-MM
                        $dt = DateTime::createFromFormat('Y-m', $m);
                        if ($dt) {
                            $mi = (int)$dt->format('n');
                            $label = (isset($months_tr[$mi]) ? $months_tr[$mi] : $dt->format('F')) . ' ' . $dt->format('Y');
                        } else {
                            $label = $m;
                        }
                    ?>
                        <option value="<?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?>" <?= ($m === $selectedMonth) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript>
                    <button type="submit" class="btn">Göster</button>
                </noscript>
            </form>

            <div class="controls" style="margin-left:12px;">
                <?php if ($selectedMonth): ?>
                    <button id="refreshBtn" class="btn primary" onclick="location.reload()">Yenile</button>
                    <a href="tip_boxes.php" class="btn">Girişe Dön</a>
                <?php else: ?>
                    <a href="tip_boxes.php" class="btn">Girişe Dön</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selectedMonth): ?>
            <h2 style="text-align:center; margin-bottom:6px;"><?= htmlspecialchars($monthLabel ?: $selectedMonth, ENT_QUOTES, 'UTF-8') ?> - Günlük Veriler (Düzenlenebilir)</h2>
            <p style="text-align:center; margin-bottom:6px;">Toplam: <strong id="totalTips"><?= (int)$totals ?></strong></p>
            <p class="note">Değişiklikleri kaydetmek için "Kaydet" butonuna basınız.</p>

            <form id="editForm" onsubmit="return false;">
                <div class="grid" id="daysGrid">
                    <?php
                    $start = new DateTime($selectedMonth . '-01');
                    $daysInMonth = (int)$start->format('t');
                    for ($i = 0; $i < $daysInMonth; $i++):
                        $d = (clone $start)->modify("+{$i} days");
                        $ds = $d->format('Y-m-d');
                        $c = isset($monthData[$ds]) ? $monthData[$ds] : 0;
                    ?>
                    <div class="cell">
                        <div style="font-weight:700"><?= $d->format('j') ?>. Gün</div>
                        <div style="color:#6c757d;font-size:13px;"><?= $d->format('d.m.Y') ?></div>
                        <div style="margin-top:8px;">
                            <input type="number" min="0" step="1" name="counts[<?= htmlspecialchars($ds, ENT_QUOTES, 'UTF-8') ?>]" value="<?= (int)$c ?>" data-date="<?= htmlspecialchars($ds, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <div class="save-row">
                    <button id="saveBtn" class="btn primary" type="button">💾 Kaydet</button>
                    <button id="resetBtn" class="btn" type="button">Sıfırla (girişleri geri al)</button>
                    <div id="status" class="status" aria-live="polite"></div>
                </div>
            </form>
        <?php else: ?>
            <p style="text-align:center;">Görüntülemek için bir ay seçin.</p>
        <?php endif; ?>

        <div style="text-align:center;">
            <a class="back" href="tip_boxes.php">← Giriş ekranına dön</a>
        </div>
    </div>

    <script>
        // Otomatik submit (JS varsa)
        document.getElementById('monthSelect').addEventListener('change', function() {
            document.getElementById('monthForm').submit();
        });

        // Sadece eğer ay seçilmişse düzenleme JS'i etkinleşir
        (function(){
            const saveBtn = document.getElementById('saveBtn');
            const resetBtn = document.getElementById('resetBtn');
            const statusEl = document.getElementById('status');
            const totalEl = document.getElementById('totalTips');
            const csrfToken = "<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>";

            if (!saveBtn) return;

            // Orijinal değerleri sakla (reset için)
            const inputs = Array.from(document.querySelectorAll('#daysGrid input[type="number"]'));
            const original = inputs.map(i => ({ date: i.dataset.date, value: i.value }));

            function showStatus(msg, ok = true) {
                statusEl.textContent = msg;
                statusEl.style.color = ok ? '#166534' : '#7f1d1d';
                setTimeout(() => {
                    if (statusEl.textContent === msg) statusEl.textContent = '';
                }, 4000);
            }

            function computeTotal() {
                const sum = inputs.reduce((acc, el) => acc + (parseInt(el.value) || 0), 0);
                if (totalEl) totalEl.textContent = sum;
            }

            inputs.forEach(i => {
                i.addEventListener('input', () => {
                    if (i.value === '') i.value = 0;
                    i.value = Math.max(0, Math.floor(Number(i.value) || 0));
                    computeTotal();
                });
            });

            resetBtn.addEventListener('click', () => {
                original.forEach(o => {
                    const el = document.querySelector('input[data-date="' + o.date + '"]');
                    if (el) el.value = o.value;
                });
                computeTotal();
                showStatus('Girişler geri yüklendi', true);
            });

            saveBtn.addEventListener('click', () => {
                const tips = [];
                let hasError = false;

                inputs.forEach(i => {
                    const date = i.dataset.date;
                    const count = Math.max(0, Math.floor(Number(i.value) || 0));
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                        hasError = true;
                        return;
                    }
                    tips.push({ date, count });
                });

                if (hasError) {
                    showStatus('❌ Geçersiz veriler algılandı', false);
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.textContent = 'Kaydediliyor...';

                fetch('../api/save_tip_boxes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ tips })
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        showStatus('✅ Değişiklikler kaydedildi', true);
                        // Orijinali güncelle
                        inputs.forEach(i => i.setAttribute('data-original', i.value));
                        // Güncel toplamı tekrar isteğe bağlı olarak güncelle (şimdilik client-side)
                        computeTotal();
                    } else {
                        showStatus('❌ Kaydetme hatası: ' + (data.message || 'Bilinmeyen hata'), false);
                    }
                })
                .catch(err => {
                    showStatus('❌ Sunucu hatası: ' + (err.message || err), false);
                })
                .finally(() => {
                    saveBtn.disabled = false;
                    saveBtn.textContent = '💾 Kaydet';
                });
            });

            // İlk toplam hesapla
            computeTotal();
        })();
    </script>
</body>
</html>