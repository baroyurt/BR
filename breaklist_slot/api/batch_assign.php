<?php
require_once '../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek yöntemi']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['assignments']) || !is_array($data['assignments'])) {
        throw new Exception('Geçersiz veri formatı');
    }
    
    $assignments = $data['assignments'];
    $processed = 0;
    $inserted = 0;
    $deleted = 0;
    $errors = [];
    
    $slot_duration = 20 * 60;

    // Hazır statement'lar
    $deleteStmt = $pdo->prepare("
        DELETE FROM work_slots 
        WHERE employee_id = ? AND slot_start = FROM_UNIXTIME(?)
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO work_slots (employee_id, area_id, slot_start, slot_end, shift_date)
        VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)
    ");

    $pdo->beginTransaction();

    foreach ($assignments as $assign) {
        try {
            $employee_id = isset($assign['employee_id']) ? (int)$assign['employee_id'] : null;
            // area_id may be null, empty string or numeric id
            $area_id = array_key_exists('area_id', $assign) ? $assign['area_id'] : null;
            $slot_time = isset($assign['slot_time']) ? (int)$assign['slot_time'] : null;
            
            // employee_id and slot_time are required to identify record
            if (!$employee_id || !$slot_time) {
                // skip invalid
                continue;
            }

            $slot_start = $slot_time;
            $slot_end = $slot_start + $slot_duration;

            // Önce o slottaki mevcut atamayı sil
            $deleteStmt->execute([$employee_id, $slot_start]);
            $delCount = $deleteStmt->rowCount();
            if ($delCount) $deleted += $delCount;

            // Eğer area_id null veya '' ise sadece silme (yani atama kaldırma)
            if ($area_id === null || $area_id === '') {
                // processed olarak say
                $processed++;
                continue;
            }

            // Aksi halde yeni atamayı ekle
            // Güvenlik: area_id numeric olması beklenir, zorunlu cast
            $area_id_int = (int)$area_id;
            $shift_date = date('Y-m-d', $slot_start);
            $insertStmt->execute([$employee_id, $area_id_int, $slot_start, $slot_end, $shift_date]);
            $insCount = $insertStmt->rowCount();
            if ($insCount) $inserted += $insCount;

            $processed++;

        } catch (Exception $e) {
            // Her assignment hatasında hata kaydedilir ama döngü devam eder
            $errors[] = $e->getMessage();
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'inserted' => $inserted,
        'deleted' => $deleted,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>