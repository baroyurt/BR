<?php
require_once '../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek yöntemi']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['employee_id']) || !isset($data['area_id']) || !isset($data['slot_time'])) {
        throw new Exception('Eksik parametre');
    }
    
    $employee_id = $data['employee_id'];
    $area_id = $data['area_id'];
    $slot_time = (int)$data['slot_time'];
    
    $slot_duration = 20 * 60;
    $slot_start = $slot_time;
    $slot_end = $slot_start + $slot_duration;
    
    // Önce bu time'da varsa sil
    $stmt = $pdo->prepare("
        DELETE FROM work_slots 
        WHERE employee_id = ? AND slot_start = FROM_UNIXTIME(?)
    ");
    $stmt->execute([$employee_id, $slot_start]);
    
    // Eğer area_id boş değilse yeni atamayı ekle
    if ($area_id !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO work_slots (employee_id, area_id, slot_start, slot_end)
            VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))
        ");
        $stmt->execute([$employee_id, $area_id, $slot_start, $slot_end]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Atama kaydedildi'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>