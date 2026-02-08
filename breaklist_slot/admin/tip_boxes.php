<?php 
require_once '../config.php';
session_start();

// Bugün ve içinde bulunduğumuz ay
$today = new DateTime();
$tip_data = [];

// Ayın ilk ve son günü
$start = new DateTime($today->format('Y-m-01'));
$end = new DateTime($today->format('Y-m-t'));
$daysInMonth = (int)$end->format('d');

// Veritabanından bu ayın verilerini çek
$stmt = $pdo->prepare("
    SELECT date, count 
    FROM tip_boxes 
    WHERE date BETWEEN :start AND :end
    ORDER BY date ASC
");
$stmt->execute([
    ':start' => $start->format('Y-m-d'),
    ':end' => $end->format('Y-m-d'),
]);
$db_tips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Veriyi diziye dönüştür
foreach ($db_tips as $row) {
    $tip_data[$row['date']] = $row['count'];
}

// Bu ayın toplamını hesapla (ayın yanında gösterilecek)
$total_tips = 0;
foreach ($tip_data as $v) {
    $total_tips += (int)$v;
}

// Bu ay için tarihleri oluştur
$dates = [];
for ($i = 0; $i < $daysInMonth; $i++) {
    $date = clone $start;
    $date->modify("+$i days");
    $dates[] = $date->format('Y-m-d');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tip Kutusu Girişi - Breaklist</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px; }
        h1 { text-align: center; margin-bottom: 30px; color: #2c3e50; }
        .controls { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: #28a745; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-info { background: #1e88e5; color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .tip-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }
        
        .tip-day {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        
        .tip-day label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #495057;
            font-size: 13px;
        }
        
        .tip-day input {
            width: 100%;
            padding: 6px;
            border: 2px solid #dee2e6;
            border-radius: 5px;
            font-size: 15px;
            text-align: center;
            font-weight: 700;
        }
        
        .tip-day input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .today {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-color: #28a745;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }
        
        .today label {
            color: #155724;
            font-weight: 800;
        }
        
        .status {
            padding: 12px;
            border-radius: 5px;
            margin: 15px 0;
            text-align: center;
            font-weight: 600;
        }
        
        .status.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .month-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
            padding: 10px;
            background: #e9ecef;
            border-radius: 8px;
        }

        .month-total {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 14px;
            box-shadow: 0 0 6px rgba(34,197,94,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>💰 Tip Kutusu Girişi</h1>
        
        <div class="month-title">
            <div>Bu Ay - <?= htmlspecialchars($start->format('F Y'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="month-total">Toplam: <?= htmlspecialchars(number_format($total_tips, 0, ',', '.'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        
        <div class="controls">
            <button onclick="saveTips()" class="btn btn-primary">💾 Kaydet</button>
            <!-- <button onclick="fillToday()" class="btn btn-secondary">🎯 Bugün İçin Doldur</button> -->
            <button onclick="fillZeros()" class="btn btn-secondary">🧹 Sıfırla</button>
            <button onclick="window.location.href='history_tip_boxes.php'" class="btn btn-info">📜 Geçmiş</button>
            <!-- Yeni: /breaklist/admin/ dizinine geri dönme butonu -->
            <button onclick="window.location.href='/breaklist_slot/admin/'" class="btn btn-info" style="margin-left: auto;" type="button">← Ana Sayfaya Dön</button>
        </div>
        
        <div id="status"></div>
        
        <div class="tip-grid">
            <?php foreach ($dates as $index => $date): 
                // day number is the day of month
                $day_num = (new DateTime($date))->format('j');
                $is_today = ($date == $today->format('Y-m-d'));
                $count = isset($tip_data[$date]) ? $tip_data[$date] : 0;
            ?>
                <div class="tip-day <?= $is_today ? 'today' : '' ?>">
                    <label>
                        <?= $day_num ?>. Gün<br>
                        <small style="font-weight: 400; color: #6c757d;"><?= (new DateTime($date))->format('d.m.Y') ?></small>
                    </label>
                    <input 
                        type="number" 
                        min="0" 
                        data-date="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>" 
                        value="<?= htmlspecialchars((string)$count, ENT_QUOTES, 'UTF-8') ?>" 
                        oninput="validateInput(this)"
                    >
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function validateInput(input) {
            if (input.value < 0) input.value = 0;
            input.value = Math.floor(input.value);
        }
        
        function fillToday() {
            const todayInput = document.querySelector('.today input');
            if (todayInput) {
                const currentValue = parseInt(todayInput.value) || 0;
                todayInput.value = currentValue + 1;
                showStatus('✅ Bugün için +1 eklendi', 'success');
            }
        }
        
        function fillZeros() {
            if (!confirm('Tüm değerleri sıfırlamak istediğinizden emin misiniz?')) return;
            
            document.querySelectorAll('.tip-day input').forEach(input => {
                input.value = 0;
            });
            showStatus('🧹 Tüm değerler sıfırlandı', 'success');
        }
        
        function saveTips() {
            const tips = [];
            let hasError = false;
            
            document.querySelectorAll('.tip-day input').forEach(input => {
                const date = input.dataset.date;
                const count = parseInt(input.value) || 0;
                
                if (count < 0) {
                    hasError = true;
                    return;
                }
                
                tips.push({ date, count });
            });
            
            if (hasError) {
                showStatus('❌ Lütfen geçerli değerler girin', 'error');
                return;
            }
            
            fetch('../api/save_tip_boxes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tips })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatus('✅ Tip kutusu verileri kaydedildi!', 'success');
                } else {
                    showStatus('❌ Kaydetme hatası: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showStatus('❌ Sunucu hatası: ' + error.message, 'error');
            });
        }
        
        function showStatus(message, type) {
            const statusDiv = document.getElementById('status');
            statusDiv.className = 'status ' + type;
            statusDiv.textContent = message;
            
            setTimeout(() => {
                if (statusDiv.textContent === message) {
                    statusDiv.className = '';
                    statusDiv.textContent = '';
                }
            }, 5000);
        }
    </script>
</body>
</html>