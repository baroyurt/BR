<?php
require_once '../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $note_text = trim($data['note_text'] ?? '');
    
    // Tabloyu kontrol et/oluştur
    $pdo->exec("CREATE TABLE IF NOT EXISTS display_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        note_text TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    if (empty($note_text)) {
        // Notu sil
        $pdo->exec("DELETE FROM display_notes");
        echo json_encode(['success' => true, 'message' => 'Not silindi']);
    } else {
        // Notu kaydet veya güncelle
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM display_notes");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        
        if ($count > 0) {
            $pdo->exec("UPDATE display_notes SET note_text = " . $pdo->quote($note_text));
        } else {
            $pdo->exec("INSERT INTO display_notes (note_text) VALUES (" . $pdo->quote($note_text) . ")");
        }
        
        echo json_encode(['success' => true, 'message' => 'Not kaydedildi']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>