<?php
require_once '../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
    exit;
}

try {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    if (!$id) {
        throw new Exception('Atama ID bulunamadı!');
    }

    $stmt = $pdo->prepare("DELETE FROM work_slots WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'Atama silindi!'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>