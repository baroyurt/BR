<?php
require_once '../config.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek yöntemi']);
    exit;
}

try {
    $employee_id = $_POST['employee_id'] ?? null;
    $area_id = $_POST['area_id'] ?? null;
    $slot_time = $_POST['slot_time'] ?? 'now';

    if (!$employee_id || !$area_id) {
        throw new Exception('Çalışan ve bölge seçilmelidir!');
    }

    // Slot zamanını hesapla
    $slot_duration = 20 * 60; // 20 dakika
    $now = time();
    
    if ($slot_time === 'now') {
        $slot_start = floor($now / $slot_duration) * $slot_duration;
    } else {
        $current_slot = floor($now / $slot_duration) * $slot_duration;
        $slot_start = $current_slot + $slot_duration;
    }
    
    $slot_end = $slot_start + $slot_duration;

    // Aynı bölgede çakışma kontrolü
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM work_slots 
        WHERE area_id = ? AND slot_start = FROM_UNIXTIME(?)
    ");
    $stmt->execute([$area_id, $slot_start]);
    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($conflict['count'] > 0) {
        throw new Exception('Bu bölge için bu slot zaten dolu!');
    }

    // Atamayı kaydet - shift_date'i slot_start'ın tarihine göre belirle
    $shift_date = date('Y-m-d', $slot_start);
    $stmt = $pdo->prepare("
        INSERT INTO work_slots (employee_id, area_id, slot_start, slot_end, shift_date)
        VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)
    ");
    $stmt->execute([$employee_id, $area_id, $slot_start, $slot_end, $shift_date]);

    echo json_encode([
        'success' => true,
        'message' => 'Başarıyla atandı! ' . date('H:i', $slot_start) . ' - ' . date('H:i', $slot_end) . ' arası'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>