<?php
require_once 'config.php';
require_once 'config_hr.php';

echo "<!DOCTYPE html>
<html lang='tr'>
<head>
    <meta charset='UTF-8'>
    <title>Personel Senkronizasyonu</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f7fa; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .status { padding: 15px; border-radius: 5px; margin: 15px 0; }
        .success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .info { background: #d1ecf1; color: #0c5460; border-left: 4px solid #17a2b8; }
        .warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn:hover { background: #2980b9; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔄 Personel Senkronizasyonu</h1>
";

try {
    // 1. SQL Server driver kontrolü
    if (!extension_loaded('sqlsrv')) {
        throw new Exception("sqlsrv extension kurulu değil! php.ini'de 'extension=php_sqlsrv.dll' ekleyin.");
    }
    
    echo "<div class='status info'>✅ sqlsrv extension kurulu</div>";
    
    // 2. İK'den personel çek
    echo "<div class='status info'>🔄 İK sisteminden personel bilgileri çekiliyor...</div>";
    $hr_employees = get_weekly_employees_from_hr();
    echo "<div class='status success'>✅ Toplam " . count($hr_employees) . " personel bulundu</div>";
    
    if (empty($hr_employees)) {
        throw new Exception("İK sisteminden personel çekilemedi. Sorguyu kontrol edin.");
    }
    
    // 3. Senkronizasyon
    $added = 0;
    $updated = 0;
    $skipped = 0;
    
    foreach ($hr_employees as $emp) {
        $personel_id = $emp['id'];
        $name = $emp['name'];
        $birim = $emp['birim'] ?? '';
        
        // Local'de var mı?
        $stmt = $pdo->prepare("SELECT id, name, birim FROM employees WHERE external_id = ?");
        $stmt->execute([$personel_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Güncelleme: isim veya birim değiştiyse güncelle
            if ($existing['name'] != $name || ($existing['birim'] ?? '') != $birim) {
                $stmt = $pdo->prepare("UPDATE employees SET name = ?, birim = ? WHERE external_id = ?");
                $stmt->execute([$name, $birim, $personel_id]);
                echo "<div class='status info'>🔄 <strong>" . htmlspecialchars($name) . "</strong> güncellendi (Birim: " . htmlspecialchars($birim) . ").</div>";
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            // Yeni ekleme
            $stmt = $pdo->prepare("
                INSERT INTO employees (name, external_id, is_active, birim)
                VALUES (?, ?, 1, ?)
            ");
            $stmt->execute([$name, $personel_id, $birim]);
            echo "<div class='status success'>➕ <strong>" . htmlspecialchars($name) . "</strong> eklendi (Birim: " . htmlspecialchars($birim) . ").</div>";
            $added++;
        }
    }
    
    // 4. İstatistikler
    echo "<div class='status success'>
        <h3>📊 Senkronizasyon Sonucu:</h3>
        <ul>
            <li>✅ Yeni Eklenen: <strong>{$added}</strong> personel</li>
            <li>🔄 Güncellenen: <strong>{$updated}</strong> personel</li>
            <li>⏭️ Değişiklik Olmayan: <strong>{$skipped}</strong> personel</li>
            <li>🎯 Toplam: <strong>" . ($added + $updated + $skipped) . "</strong> personel</li>
        </ul>
    </div>";
    
    // 5. Pasif personeller
    $all_hr_ids = array_column($hr_employees, 'id');
    
    if (!empty($all_hr_ids)) {
        $placeholders = implode(',', array_fill(0, count($all_hr_ids), '?'));
        $stmt = $pdo->prepare("
            UPDATE employees 
            SET is_active = 0
            WHERE external_id IS NOT NULL 
            AND external_id NOT IN ($placeholders)
            AND is_active = 1
        ");
        $stmt->execute($all_hr_ids);
        $deactivated = $stmt->rowCount();
        
        if ($deactivated > 0) {
            echo "<div class='status warning'>
                ⚠️ <strong>{$deactivated}</strong> personel İK'de olmadığı için pasif yapıldı.
            </div>";
        } else {
            echo "<div class='status info'>✅ Tüm personeller İK ile senkron.</div>";
        }
    }
    
    echo "<a href='admin/index.php' class='btn'>← Admin Paneline Dön</a>";
    
} catch (Exception $e) {
    echo "<div class='status error'>
        ❌ HATA: " . htmlspecialchars($e->getMessage()) . "
    </div>";
    
    // Detaylı hata için geliştirici modu
    if (isset($_GET['debug'])) {
        echo "<pre>" . htmlspecialchars(print_r($e, true)) . "</pre>";
    }
    
    echo "<div class='status info'>
        🔧 Çözüm Önerileri:
        <ul>
            <li>1. <strong>test_hr_connection.php</strong> dosyasını çalıştırarak bağlantıyı test edin</li>
            <li>2. PHP'ye <strong>sqlsrv extension</strong>'ını kurun</li>
            <li>3. Sunucu IP'si (172.18.0.33) ve şifreyi kontrol edin</li>
            <li>4. Tablo isimlerini (Personel, PersonelVardiya) kontrol edin</li>
        </ul>
    </div>";
}

echo "
</div>
</body>
</html>
";
?>