# Deployment Guide - Shift Completion Fix

## 🚀 Quick Start Deployment

### Adım 1: Backup (Yedekleme)
```bash
# Veritabanı yedeği alın
mysqldump -u root -p breaklistslot > backup_before_shift_fix_$(date +%Y%m%d).sql

# Kod yedeği alın
tar -czf code_backup_$(date +%Y%m%d).tar.gz /path/to/breaklist_slot/
```

### Adım 2: Database Migration
```bash
cd /path/to/breaklist_slot
php db_migration_add_shift_date.php
```

**Beklenen Çıktı:**
```
Starting database migration...
Adding shift_date column...
✓ shift_date column added
Adding completed_at column...
✓ completed_at column added
Updating existing records with shift_date...
✓ Existing records updated
Adding index on shift_date...
✓ Index created

✅ Migration completed successfully!
```

### Adım 3: Deploy Code
Güncellenmiş dosyaları production'a kopyalayın:
```bash
# Ana dosya
cp admin/index.php /production/path/breaklist_slot/admin/

# API dosyaları
cp api/assign.php /production/path/breaklist_slot/api/
cp api/save_single_assignment.php /production/path/breaklist_slot/api/
cp api/batch_assign.php /production/path/breaklist_slot/api/
```

### Adım 4: Doğrulama (Verification)
```bash
# Test scriptini çalıştırın
php test_shift_completion_logic.php

# Tüm testler "PASS ✅" göstermeli
```

## 🧪 Functional Testing

### Test 1: Geçmiş Gün Görüntüleme
1. Tarayıcıda admin/index.php sayfasını açın
2. URL'e `?day_offset=-1` ekleyin (dünü görmek için)
3. Kontrol edin:
   - ✅ Tüm çalışanlar "Mesai Bitmiş" bölümünde
   - ✅ Hiçbir çalışan "Şu An Çalışanlar" bölümünde değil

### Test 2: Bugün Görüntüleme
1. URL'i `?day_offset=0` yapın (bugün)
2. Kontrol edin:
   - ✅ Çalışanlar saat bazlı doğru kategorizasyon
   - ✅ Şu an çalışanlar doğru gösteriliyor

### Test 3: Manuel Gün Geçişi
1. Gün sonunda işlemlerinizi tamamlayın
2. Yeni güne geçiş yapın (day_offset'i değiştirerek)
3. Önceki günün sayfasına geri dönün
4. Kontrol edin:
   - ✅ Tamamlanmış çalışanlar tekrar "çalışıyor" görünmüyor

## 🔍 Database Verification

### Verify Schema Changes
```sql
-- shift_date ve completed_at sütunlarının eklendiğini kontrol edin
DESCRIBE work_slots;

-- Beklenen çıktı:
-- shift_date     | date     | YES  | | NULL    |
-- completed_at   | datetime | YES  | | NULL    |
```

### Verify Data
```sql
-- Mevcut kayıtlarda shift_date'in dolu olduğunu kontrol edin
SELECT COUNT(*) as total,
       SUM(CASE WHEN shift_date IS NOT NULL THEN 1 ELSE 0 END) as with_shift_date,
       SUM(CASE WHEN shift_date IS NULL THEN 1 ELSE 0 END) as without_shift_date
FROM work_slots;

-- with_shift_date = total olmalı (tüm kayıtlar shift_date'e sahip)
```

### Check Completed Shifts
```sql
-- Tamamlanmış slotları kontrol edin
SELECT COUNT(*) as completed_slots
FROM work_slots
WHERE completed_at IS NOT NULL;

-- slot_end < NOW() olan slotlar otomatik tamamlanmalı
SELECT COUNT(*) as should_be_completed
FROM work_slots
WHERE slot_end < NOW() AND completed_at IS NULL;

-- should_be_completed = 0 olmalı (tümü işaretlenmiş olmalı)
```

## 📊 Monitoring

### Performance Check
```sql
-- Index kullanımını kontrol edin
EXPLAIN SELECT * FROM work_slots WHERE shift_date = '2026-02-08';
-- "Using index" veya "key: idx_shift_date" görmeli

-- Sorgu performansı
SELECT shift_date, COUNT(*) as slot_count
FROM work_slots
GROUP BY shift_date
ORDER BY shift_date DESC
LIMIT 10;
```

### Daily Checks (Günlük Kontroller)
```sql
-- Bugün için atamalar
SELECT COUNT(*) FROM work_slots WHERE shift_date = CURDATE();

-- Tamamlanmamış geçmiş slotlar (olmamalı)
SELECT COUNT(*) FROM work_slots 
WHERE shift_date < CURDATE() AND completed_at IS NULL;

-- Bugün tamamlanan slotlar
SELECT COUNT(*) FROM work_slots 
WHERE shift_date = CURDATE() AND completed_at IS NOT NULL;
```

## 🐛 Troubleshooting

### Problem: Migration başarısız
**Hata:** "Column 'shift_date' already exists"
**Çözüm:** Sütunlar zaten eklenmiş, migration scriptini tekrar çalıştırmaya gerek yok.

### Problem: shift_date NULL kalıyor
**Çözüm:**
```sql
UPDATE work_slots 
SET shift_date = DATE(slot_start) 
WHERE shift_date IS NULL;
```

### Problem: completed_at işaretlenmiyor
**Çözüm:** 
- `mark_completed_shifts()` fonksiyonunun her sayfa yüklemede çağrıldığından emin olun
- Exception catch ediliyor olabilir, PHP error log'larına bakın:
```bash
tail -f /var/log/apache2/error.log
# veya
tail -f /var/log/php-fpm/error.log
```

### Problem: Geçmiş günlerde hala "çalışıyor" görünüyor
**Kontrol:**
1. Admin/index.php dosyasının son versiyonu deploy edilmiş mi?
2. Browser cache'i temizleyin (Ctrl+F5)
3. PHP cache temizleyin (opcache varsa):
```bash
# Apache
sudo service apache2 restart

# PHP-FPM
sudo service php-fpm restart
```

## 🔄 Rollback Plan

Eğer sorun çıkarsa geri alma adımları:

### Step 1: Kodu geri al
```bash
# Yedekten geri yükle
tar -xzf code_backup_YYYYMMDD.tar.gz -C /
```

### Step 2: Database'i geri al (opsiyonel - VERİ KAYBI!)
```bash
# Sadece sütunları kaldır (veriyi korur)
mysql -u root -p breaklistslot <<EOF
ALTER TABLE work_slots DROP COLUMN completed_at;
ALTER TABLE work_slots DROP COLUMN shift_date;
DROP INDEX idx_shift_date ON work_slots;
EOF

# VEYA: Tam backup'tan geri yükle (DİKKAT: Yeni veriler kaybolur!)
mysql -u root -p breaklistslot < backup_before_shift_fix_YYYYMMDD.sql
```

## ✅ Post-Deployment Checklist

- [ ] Database migration başarıyla çalıştı
- [ ] Yeni sütunlar mevcut: shift_date, completed_at
- [ ] Mevcut veriler shift_date aldı
- [ ] Index oluşturuldu
- [ ] Kod dosyaları deploy edildi
- [ ] Test script'i başarılı
- [ ] Geçmiş gün testi başarılı
- [ ] Bugün testi başarılı
- [ ] Manuel gün geçişi testi başarılı
- [ ] Performance metrikleri normal
- [ ] Error log'larda yeni hata yok
- [ ] Kullanıcı testleri başarılı

## 📞 Support

Sorun yaşarsanız:
1. Bu dokümandaki Troubleshooting bölümüne bakın
2. Error log'ları kontrol edin
3. Test script'ini çalıştırın
4. GitHub Issues'da soru sorun

## 📝 Notes

- Migration **idempotent** (tekrar çalıştırılabilir)
- Kod değişiklikleri **backward compatible**
- Mevcut veriler **korunur**
- Performance **etkilenmez** (index var)
- Rollback **mümkün** (ancak önerilmez)
