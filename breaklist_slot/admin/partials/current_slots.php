<?php require_once '../../config.php'; ?>

<?php
$slot_duration = 20 * 60;
$now = time();
$slot_start = floor($now / $slot_duration) * $slot_duration;
$slot_end = $slot_start + $slot_duration;

$stmt = $pdo->prepare("
    SELECT ws.id, a.name AS area_name, a.color, e.name AS employee_name, 
           ws.slot_start, ws.slot_end
    FROM work_slots ws
    JOIN areas a ON ws.area_id = a.id
    JOIN employees e ON ws.employee_id = e.id
    WHERE ws.slot_start = FROM_UNIXTIME(?)
    ORDER BY a.name
");
$stmt->execute([$slot_start]);
$current_slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bir sonraki slot
$next_slot_start = $slot_end;
$next_slot_end = $next_slot_start + $slot_duration;

$stmt = $pdo->prepare("
    SELECT ws.id, a.name AS area_name, a.color, e.name AS employee_name,
           ws.slot_start, ws.slot_end
    FROM work_slots ws
    JOIN areas a ON ws.area_id = a.id
    JOIN employees e ON ws.employee_id = e.id
    WHERE ws.slot_start = FROM_UNIXTIME(?)
    ORDER BY a.name
");
$stmt->execute([$next_slot_start]);
$next_slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="slot-section">
    <h3>⏳ Şu Anki Slot (<?= date('H:i', $slot_start) ?> - <?= date('H:i', $slot_end) ?>)</h3>
    <?php if (empty($current_slots)): ?>
        <p class="empty-message">Henüz atama yok.</p>
    <?php else: ?>
        <div class="slot-grid">
            <?php foreach ($current_slots as $slot): ?>
                <div class="slot-card" style="border-left-color: <?= $slot['color'] ?>;">
                    <div class="slot-header">
                        <strong><?= htmlspecialchars($slot['area_name']) ?></strong>
                        <span class="time-badge"><?= date('H:i', strtotime($slot['slot_start'])) ?> - <?= date('H:i', strtotime($slot['slot_end'])) ?></span>
                    </div>
                    <div class="slot-body">
                        <span class="employee-name"><?= htmlspecialchars($slot['employee_name']) ?></span>
                    </div>
                    <div class="slot-footer">
                        <button onclick="deleteAssignment(<?= $slot['id'] ?>)" class="btn btn-small btn-danger">🗑️ Sil</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="slot-section">
    <h3>⏭️ Bir Sonraki Slot (<?= date('H:i', $next_slot_start) ?> - <?= date('H:i', $next_slot_end) ?>)</h3>
    <?php if (empty($next_slots)): ?>
        <p class="empty-message">Henüz atama yok.</p>
    <?php else: ?>
        <div class="slot-grid">
            <?php foreach ($next_slots as $slot): ?>
                <div class="slot-card" style="border-left-color: <?= $slot['color'] ?>;">
                    <div class="slot-header">
                        <strong><?= htmlspecialchars($slot['area_name']) ?></strong>
                        <span class="time-badge"><?= date('H:i', strtotime($slot['slot_start'])) ?> - <?= date('H:i', strtotime($slot['slot_end'])) ?></span>
                    </div>
                    <div class="slot-body">
                        <span class="employee-name"><?= htmlspecialchars($slot['employee_name']) ?></span>
                    </div>
                    <div class="slot-footer">
                        <button onclick="deleteAssignment(<?= $slot['id'] ?>)" class="btn btn-small btn-danger">🗑️ Sil</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.slot-section { margin-bottom: 30px; }
.slot-section h3 { margin-bottom: 15px; color: #2c3e50; }
.slot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; }
.slot-card { 
    background: white; border-radius: 8px; padding: 15px; 
    border-left: 5px solid #3498db; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.slot-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.slot-header strong { font-size: 16px; }
.time-badge { background: #f0f0f0; padding: 3px 10px; border-radius: 15px; font-size: 12px; }
.slot-body { margin-bottom: 10px; }
.employee-name { font-size: 18px; font-weight: bold; color: #2c3e50; }
.slot-footer { display: flex; justify-content: flex-end; }
.btn-small { padding: 5px 12px; font-size: 12px; }
.empty-message { color: #7f8c8d; font-style: italic; }
</style>

<script>
function deleteAssignment(id) {
    if (!confirm('Bu atamayı silmek istediğinizden emin misiniz?')) return;
    
    fetch('../api/delete_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            location.reload();
        } else {
            alert(result.message || 'Silme işlemi başarısız!');
        }
    })
    .catch(error => {
        console.error('Hata:', error);
        alert('Bir hata oluştu!');
    });
}
</script>