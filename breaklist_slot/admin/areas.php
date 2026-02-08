<?php
require_once '../config.php';
session_start();

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_area'])) {
        $name = trim($_POST['name']);
        $color = $_POST['color'] ?? '#3498db';
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO areas (name, color) VALUES (?, ?)");
            $stmt->execute([$name, $color]);
            $message = ['type' => 'success', 'text' => 'Bölge eklendi!'];
        }
    } elseif (isset($_POST['update_area'])) {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $color = $_POST['color'] ?? '#3498db';
        if ($name) {
            $stmt = $pdo->prepare("UPDATE areas SET name = ?, color = ? WHERE id = ?");
            $stmt->execute([$name, $color, $id]);
            $message = ['type' => 'success', 'text' => 'Bölge güncellendi!'];
        }
    } elseif (isset($_POST['delete_area'])) {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM areas WHERE id = ?");
        $stmt->execute([$id]);
        $message = ['type' => 'success', 'text' => 'Bölge silindi!'];
    }
}

/*
  Kullanıcının verdiği kesin sıralama:
  'AR 01', 'AR 02', ... , 'TR'
  (aynı sırayla SQL sorgusunda FIELD() ile kullanacağız)
*/
$desiredOrder = [
    'AR 01','AR 02','AR 03','AR 04','AR 05','AR 06','AR 07','AR 08','AR 09','AR 10','AR 11','AR 12','AR SLOT',
    'PK 01','PK 02','PK 03','PK 04','PK 05','PK 06','PK 07','PK 08','PK 09','PK 10','PK 11','PK 12','PK 13','PK 14','PK 15','PK 16','PK 17',
    'BJ 01','BJ 02','BJ 03','BJ 04','BJ 05','BJ 06',
    'PK 18','PK 19','PK 20','PK 20', // note: duplicate 'PK 20' kept as user provided (will be deduped below)
    'INS/AR 01','INS/AR 02','INS/AR 03','INS/AR 04','INS/AR 05','INS/AR 06','INS/AR 07','INS/AR 08','INS/AR 09','INS/AR 10','INS/AR 10','INS/AR 11','INS/AR 12',
    'INS/AR 01','INS/AR 02','INS/AR 03','INS/AR 04','INS/AR 05','INS/AR 06','INS/AR 07','INS/AR 08','INS/AR 09','INS/AR 10','INS/AR 10','INS/AR 11','INS/AR 12',
    'INS/PK 01-02','INS/PK 03-04','INS/PK 05','INS/PK 06-07','INS/PK 08-09','INS/PK 10','INS/PK 11','INS/PK 12-13','INS/PK 14 BJ 04','INS/PK 15 BJ 05','INS/PK 16-17','INS/PK 18 BJ6','INS/PK 19-20',
    'INS/BJ 01','INS/BJ 02','INS/BJ 03','INS/PB 01','INS/PB 02',
    'CHIP 01','CHIP 02','CHIP 03','CHIP 04','CHIP 05','CHIP 06','CHIP 07','CHIP 08','CHIP 09','CHIP 10','CHIP 11','CHIP 12',
    'CRAPS','CRAPS 1','CRAPS 2','CRAPS 2',
    ':)','FIN','COUNT','SORT','TC','LATE','PIT','TR'
];

// Deduplicate while preserving order (to avoid repeated FIELD entries)
$desiredOrder = array_values(array_unique($desiredOrder));

// Quote each value safely for SQL (PDO::quote)
$quoted = array_map(function($v) use ($pdo) {
    // ensure trimming
    return $pdo->quote(trim($v));
}, $desiredOrder);

// Build FIELD list
$fieldList = implode(',', $quoted);

// Use FIELD() to order: put entries that are in the list first (FIELD(...) > 0), keep given order,
// then fallback to alphabetical 'name' for any areas not in the list.
if ($fieldList) {
    $sql = "SELECT * FROM areas
            ORDER BY (FIELD(name, $fieldList) = 0), FIELD(name, $fieldList), name";
    $areas = $pdo->query($sql)->fetchAll();
} else {
    // Fallback simple ordering
    $areas = $pdo->query("SELECT * FROM areas ORDER BY name")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Bölge Yönetimi - Breaklist</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>📍 Bölge Yönetimi</h1>
            <nav>
                <a href="index.php">Atama Yap</a>
                <a href="employees.php">Çalışanlar</a>
                <a href="areas.php" class="active">Bölgeler</a>
                <a href="../display/" target="_blank">Takip Ekranı</a>
            </nav>
        </header>

        <main>
            <?php if (isset($message)): ?>
                <div class="alert alert-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
            <?php endif; ?>

            <div class="card">
                <h2>➕ Yeni Bölge Ekle</h2>
                <form method="POST" class="form-inline">
                    <input type="text" name="name" placeholder="Bölge adı" required>
                    <input type="color" name="color" value="#3498db">
                    <button type="submit" name="add_area" class="btn btn-primary">Ekle</button>
                </form>
            </div>

            <div class="card">
                <h2>📋 Mevcut Bölgeler</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Bölge</th>
                                <th>Renk</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($areas as $area): ?>
                                <tr>
                                    <td><?= $area['id'] ?></td>
                                    <td>
                                        <span style="display:inline-block; width:15px; height:15px; background:<?= $area['color'] ?>; border-radius:3px; margin-right:8px;"></span>
                                        <?= htmlspecialchars($area['name']) ?>
                                    </td>
                                    <td>
                                        <input type="color" value="<?= $area['color'] ?>" disabled style="width:60px; height:30px; border:none;">
                                    </td>
                                    <td>
                                        <button onclick="editArea(<?= $area['id'] ?>, '<?= htmlspecialchars($area['name'], ENT_QUOTES) ?>', '<?= $area['color'] ?>')" class="btn btn-small btn-warning">✏️ Düzenle</button>
                                        <button onclick="deleteArea(<?= $area['id'] ?>, '<?= htmlspecialchars($area['name']) ?>')" class="btn btn-small btn-danger">🗑️ Sil</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Düzenleme Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Bölge Düzenle</h2>
            <form id="editForm" method="POST">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                    <label>Bölge Adı:</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group">
                    <label>Renk:</label>
                    <input type="color" name="color" id="edit_color" value="#3498db">
                </div>
                <button type="submit" name="update_area" class="btn btn-primary">Güncelle</button>
            </form>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        function editArea(id, name, color) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_color').value = color;
            document.getElementById('editModal').style.display = 'block';
        }

        function deleteArea(id, name) {
            if (!confirm(name + ' bölgesini silmek istediğinizden emin misiniz?')) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="delete_area" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        document.querySelector('.close').onclick = () => {
            document.getElementById('editModal').style.display = 'none';
        };
    </script>
</body>
</html>