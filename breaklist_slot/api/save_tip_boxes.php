<?php
require_once '../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['tips']) || !is_array($data['tips'])) {
        throw new Exception('Geçersiz veri formatı');
    }
    
    $saved = 0;
    $stmt = $pdo->prepare("
        INSERT INTO tip_boxes (date, count) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE count = VALUES(count), updated_at = NOW()
    ");
    
    foreach ($data['tips'] as $tip) {
        if (isset($tip['date']) && isset($tip['count'])) {
            $stmt->execute([$tip['date'], $tip['count']]);
            $saved++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'saved' => $saved,
        'message' => "{$saved} günün tip kutusu verisi kaydedildi"
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>