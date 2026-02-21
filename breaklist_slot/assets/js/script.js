// Genel JavaScript fonksiyonları
document.addEventListener('DOMContentLoaded', function() {
    console.log('Breaklist sistemi yüklendi!');
});

// Toast bildirim sistemi
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 25px;
        background: ${type === 'success' ? '#28a745' : '#dc3545'};
        color: white;
        border-radius: 5px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        animation: toastSlideIn 0.3s, toastFadeOut 0.5s 2.5s forwards;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}