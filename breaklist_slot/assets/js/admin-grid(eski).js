let changedCells = new Set();

// Değişiklik işaretleri
function markAsChanged(selectElement) {
    const cellKey = `${selectElement.dataset.employeeId}-${selectElement.dataset.slotTime}`;
    
    if (selectElement.value !== '') {
        selectElement.classList.add('changed');
        changedCells.add(cellKey);
    } else {
        selectElement.classList.remove('changed');
        changedCells.delete(cellKey);
    }
    
    // Renk değiştir
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const color = selectedOption.dataset.color || '#fff';
    selectElement.style.backgroundColor = color;
}

// Tüm atamaları kaydet
async function saveAssignments() {
    const saveBtn = document.querySelector('.btn-primary');
    const statusEl = document.getElementById('saveStatus');
    
    if (changedCells.size === 0) {
        showStatus('Değişiklik yok!', 'error');
        return;
    }
    
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading">💾</span> Kaydediliyor...';
    statusEl.className = '';
    statusEl.textContent = '';
    
    const assignments = [];
    
    // Tüm değişen hücreleri topla
    document.querySelectorAll('.area-select.changed').forEach(select => {
        if (select.value !== '') {
            assignments.push({
                employee_id: select.dataset.employeeId,
                area_id: select.value,
                slot_time: select.dataset.slotTime
            });
        }
    });
    
    try {
        const response = await fetch('../api/batch_assign.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ assignments })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showStatus(`✅ ${result.saved} atama başarıyla kaydedildi!`, 'success');
            changedCells.clear();
            
            // Değişiklik işaretlerini temizle
            document.querySelectorAll('.area-select.changed').forEach(select => {
                select.classList.remove('changed');
            });
        } else {
            showStatus(`❌ Hata: ${result.message || 'Bilinmeyen hata'}`, 'error');
        }
    } catch (error) {
        console.error('Hata:', error);
        showStatus('❌ Sunucu hatası!', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '💾 Tüm Atamaları Kaydet';
    }
}

// Tümünü temizle
function clearAll() {
    if (!confirm('Tüm atamaları temizlemek istediğinizden emin misiniz?')) {
        return;
    }
    
    document.querySelectorAll('.area-select').forEach(select => {
        select.value = '';
        select.classList.remove('changed');
        select.style.backgroundColor = '#fff';
    });
    
    changedCells.clear();
    showStatus('✅ Tüm atamalar temizlendi!', 'success');
}

// Status mesajı göster
function showStatus(message, type) {
    const statusEl = document.getElementById('saveStatus');
    statusEl.textContent = message;
    statusEl.className = type;
    
    setTimeout(() => {
        if (statusEl.className === type) {
            statusEl.className = '';
            statusEl.textContent = '';
        }
    }, 5000);
}

// Sayfa yüklendiğinde
document.addEventListener('DOMContentLoaded', function() {
    console.log('Grid arayüzü yüklendi!');
    console.log('Toplam personel:', document.querySelectorAll('.grid-row').length);
});