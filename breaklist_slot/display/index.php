<?php 
require_once '../config.php';
date_default_timezone_set('Europe/Nicosia');

// Türkçe ay isimleri
$months_tr = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
    7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

// Vardiya saatlerini hesaplayan fonksiyon
function calculate_shift_hours($vardiya_kod) {
    if (!$vardiya_kod || in_array($vardiya_kod, ['OFF', 'RT'])) {
        return null;
    }
    
    $base_hour = null;
    $is_extended = false;
    $duration_hours = 8;
    
    if (preg_match('/^(\d{1,2})\+?$/', $vardiya_kod, $matches)) {
        $base_hour = (int)$matches[1];
        $is_extended = strpos($vardiya_kod, '+') !== false;
        $duration_hours = $is_extended ? 10 : 8;
    }
    
    $letter_shifts = [
        'A' => ['start' => 8, 'duration' => 8],
        'B' => ['start' => 16, 'duration' => 8],
        'C' => ['start' => 0, 'duration' => 8],
        'D' => ['start' => 9, 'duration' => 9],
        'E' => ['start' => 14, 'duration' => 8],
        'F' => ['start' => 10, 'duration' => 8],
        'G' => ['start' => 18, 'duration' => 8],
        'H' => ['start' => 12, 'duration' => 8],
        'I' => ['start' => 13, 'duration' => 8],
        'J' => ['start' => 22, 'duration' => 8],
        'K' => ['start' => 20, 'duration' => 8],
        'L' => ['start' => 7, 'duration' => 8],
        'M' => ['start' => 6, 'duration' => 8],
        'N' => ['start' => 23, 'duration' => 8],
    ];
    
    if (isset($letter_shifts[$vardiya_kod])) {
        $base_hour = $letter_shifts[$vardiya_kod]['start'];
        $duration_hours = $letter_shifts[$vardiya_kod]['duration'];
    }
    
    if ($base_hour === null) {
        return null;
    }
    
    $start_hour = $base_hour;
    $start_minute = 0;
    $end_hour = $start_hour + $duration_hours;
    $end_minute = 0;
    
    // Gece yarısını geçen mesai kontrolü
    $wraps = false;
    if ($end_hour >= 24) {
        $wraps = true;
        $end_hour = $end_hour % 24;
    }
    
    return [
        'start_hour' => $start_hour,
        'start_minute' => $start_minute,
        'end_hour' => $end_hour,
        'end_minute' => $end_minute,
        'duration' => $duration_hours,
        'is_extended' => $is_extended,
        'wraps' => $wraps
    ];
}

// Şu anki saati hesapla
$current_time = new DateTime();
$current_hour = (int)$current_time->format('H');
$current_minute = (int)$current_time->format('i');
$current_total_minutes = $current_hour * 60 + $current_minute;

// Mevcut ay adını al
$current_month_index = (int)$current_time->format('n'); // 1-12 arası
$current_month_name = $months_tr[$current_month_index] . ' ' . $current_time->format('Y');

// NOTU VERİTABANINDAN ÇEK (YENİ!)
$display_note = '';
try {
    $note_stmt = $pdo->query("
        SELECT note_text 
        FROM display_notes 
        ORDER BY updated_at DESC 
        LIMIT 1
    ");
    $note_row = $note_stmt->fetch(PDO::FETCH_ASSOC);
    if ($note_row && !empty(trim($note_row['note_text']))) {
        $display_note = trim($note_row['note_text']);
    }
} catch (Exception $e) {
    // Tablo yoksa veya hata varsa not gösterilmez
    $display_note = '';
}

// HR yapılandırması varsa yükle (vardiya kodu almak için)
$use_hr = false;
if (file_exists('../config_hr.php')) {
    require_once '../config_hr.php';
    $use_hr = true;
}

/*
  Manuel vardiya sorgulama / tespit fonksiyonlarına küçük ama kritik eklemeler:
  - employees tablosundaki sütun adı 'manual_vardiya' ise artık doğrudan kullanılır.
  - get_manual_vardiya() aramalarına 'manual_vardiya' eklendi.
*/

function column_exists_in_table(PDO $pdo, $table, $column) {
    try {
        $q = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $q->execute([$column]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function table_exists(PDO $pdo, $table) {
    try {
        $q = $pdo->prepare("SHOW TABLES LIKE ?");
        $q->execute([$table]);
        return (bool)$q->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {
        return false;
    }
}

function get_manual_vardiya(PDO $pdo, $employee_id) {
    // 1) employees tablosunda olası sütun isimleri (manual_vardiya dahil edildi)
    $employee_columns = ['manual_vardiya', 'shift_code', 'vardiya_kod', 'vardiya', 'shift', 'shiftcode', 'code'];
    foreach ($employee_columns as $col) {
        if (column_exists_in_table($pdo, 'employees', $col)) {
            try {
                $s = $pdo->prepare("SELECT `$col` AS sc FROM employees WHERE id = ? LIMIT 1");
                $s->execute([$employee_id]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty(trim((string)$r['sc']))) {
                    return trim((string)$r['sc']);
                }
            } catch (Exception $e) {
                // ignore and try next
            }
        }
    }

    // 2) Olası manuel vardiya tabloları ve olası sütun adları (manual_vardiya da dahil)
    $candidate_tables = ['manual_shifts', 'employee_shifts', 'manual_vardiyas', 'employee_shift_assignments', 'shift_assignments'];
    $candidate_columns = ['manual_vardiya','vardiya_kod', 'shift_code', 'shift', 'code'];

    foreach ($candidate_tables as $tbl) {
        if (!table_exists($pdo, $tbl)) continue;
        foreach ($candidate_columns as $col) {
            // Sütun var mı?
            if (!column_exists_in_table($pdo, $tbl, $col)) continue;

            // Tahmini tarih sütunu isimleri
            $date_cols = ['date', 'day', 'assigned_date', 'shift_date'];

            // Deneme: önce bugünün kaydını seç
            foreach ($date_cols as $dcol) {
                if (!column_exists_in_table($pdo, $tbl, $dcol)) continue;
                try {
                    $q = $pdo->prepare("SELECT `$col` AS sc FROM `$tbl` WHERE employee_id = ? AND DATE(`$dcol`) = CURDATE() LIMIT 1");
                    $q->execute([$employee_id]);
                    $row = $q->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty(trim((string)$row['sc']))) {
                        return trim((string)$row['sc']);
                    }
                } catch (Exception $e) {
                    // ignore and try next
                }
            }

            // Eğer tarih yoksa, belki sadece en son kayıt var
            try {
                $q = $pdo->prepare("SELECT `$col` AS sc FROM `$tbl` WHERE employee_id = ? ORDER BY id DESC LIMIT 1");
                $q->execute([$employee_id]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty(trim((string)$row['sc']))) {
                    return trim((string)$row['sc']);
                }
            } catch (Exception $e) {
                // ignore
            }
        }
    }

    // Bulunamadı
    return '';
}

// Helper: genel vardiya bulucu (HR veya manuel kaynaklar)
// ÖNEMLİ: eğer $employee['manual_vardiya'] varsa onu da doğrudan dikkate alıyoruz
function get_vardiya_for_employee(PDO $pdo, array $employee, $use_hr) {
    // Eğer HR ve external_id varsa, önce HR kaynağını kullan
    if ($use_hr && !empty($employee['external_id'])) {
        try {
            // get_today_vardiya_kod fonksiyonu config_hr.php içinde tanımlı olmalı
            $kod = get_today_vardiya_kod($employee['external_id']);
            if ($kod) return $kod;
        } catch (Exception $e) {
            // fallback to manual
        }
    }

    // Eğer SELECT ile manual_vardiya alanı geldiyse, doğrudan kullan
    if (isset($employee['manual_vardiya']) && trim((string)$employee['manual_vardiya']) !== '') {
        return trim((string)$employee['manual_vardiya']);
    }

    // Manuel olarak diğer tablolara bak
    return get_manual_vardiya($pdo, $employee['id']);
}

// Aktif personel ID'lerini topla
$active_employee_ids = [];

// 1. Manuel personelleri ekle (eski davranış korunuyor)
$manual_employees = $pdo->query("
    SELECT id FROM employees 
    WHERE is_active = 1 
    AND (external_id IS NULL OR external_id = '')
")->fetchAll(PDO::FETCH_COLUMN);
$active_employee_ids = array_merge($active_employee_ids, $manual_employees);

// 2. İK personellerini kontrol et (vardiyalarına göre)
try {
    if ($use_hr) {
        $hr_employees = $pdo->query("
            SELECT id, external_id 
            FROM employees 
            WHERE is_active = 1 
            AND external_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // Ön gösterim süresi (dakika) - vardiya başlamadan önce personelin listede gözükmesini istediğimiz dakika
        $pre_show_minutes = 20;
        $minutes_in_day = 24 * 60;

        foreach ($hr_employees as $emp) {
            // ÖNCE: Önceki günden taşan mesaiyi kontrol et
            try {
                // Önceki gün tarihi hesapla
                $prev_date = (clone $current_time)->modify('-1 day');
                $vardiya_kod_prev = null;
                
                // get_vardiya_kod_for_date fonksiyonu varsa kullan, yoksa sadece bugünkü vardiyayı kontrol et
                if (function_exists('get_vardiya_kod_for_date')) {
                    $vardiya_kod_prev = get_vardiya_kod_for_date($emp['external_id'], $prev_date->format('Y-m-d'));
                }
                
                // Önceki gün vardiyası varsa ve gece yarısını geçiyorsa kontrol et
                // ANCAK: Vardiya 24:00 veya sonra başlıyorsa, önceki günde başlamıyor
                // (start_hour < 24 kontrolü ile sadece önceki günde başlayanları seçiyoruz)
                if ($vardiya_kod_prev && !in_array($vardiya_kod_prev, ['OFF', 'RT'])) {
                    $shift_info_prev = calculate_shift_hours($vardiya_kod_prev);
                    
                    if ($shift_info_prev && !empty($shift_info_prev['wraps']) && $shift_info_prev['start_hour'] < 24) {
                        // Önceki günün mesaisi bugüne taşıyor
                        $end_total_prev = $shift_info_prev['end_hour'] * 60 + $shift_info_prev['end_minute'];
                        
                        if ($end_total_prev > 0 && $current_total_minutes < $end_total_prev) {
                            // Çalışan hala önceki günün mesaisinde
                            $active_employee_ids[] = $emp['id'];
                            continue; // Bugünün mesaisını kontrol etme
                        }
                    }
                }
            } catch (Exception $e) {
                // Önceki gün kontrolü başarısız, bugünkü mesaiyi kontrol et
            }
            
            // SONRA: Bugünün vardiyasını kontrol et
            try {
                $vardiya_kod = get_today_vardiya_kod($emp['external_id']);
            } catch (Exception $e) {
                $vardiya_kod = null;
            }
            
            if (!$vardiya_kod || in_array($vardiya_kod, ['OFF', 'RT'])) {
                continue;
            }
            
            $shift_info = calculate_shift_hours($vardiya_kod);
            if (!$shift_info) continue;
            
            // Eğer vardiya yarın başlıyorsa (start_hour >= 24), bugün gösterilmemeli
            if ($shift_info['start_hour'] >= 24) {
                continue;
            }
            
            $start_total = $shift_info['start_hour'] * 60 + $shift_info['start_minute']; // 0..1439
            $end_total = $shift_info['end_hour'] * 60 + $shift_info['end_minute']; // 0..1439 (may be < start_total for night shift)

            // adjusted start = start - pre_show_minutes, normalized into 0..1439
            $adj_start = ($start_total - $pre_show_minutes) % $minutes_in_day;
            if ($adj_start < 0) $adj_start += $minutes_in_day;

            // normalize end_total into 0..1439 (should already be, but keep consistent)
            $end_total = $end_total % $minutes_in_day;
            if ($end_total < 0) $end_total += $minutes_in_day;

            // check whether current_total_minutes is inside interval [adj_start, end_total)
            $is_working = false;
            if ($adj_start <= $end_total) {
                // straightforward interval within the same day
                if ($current_total_minutes >= $adj_start && $current_total_minutes < $end_total) {
                    $is_working = true;
                }
            } else {
                // interval wraps over midnight: true if current >= adj_start OR current < end_total
                if ($current_total_minutes >= $adj_start || $current_total_minutes < $end_total) {
                    $is_working = true;
                }
            }
            
            if ($is_working) {
                $active_employee_ids[] = $emp['id'];
            }
        }
    }
} catch (Exception $e) {
    error_log("HR bağlantı hatası (display): " . $e->getMessage());
}

$active_employee_ids = array_unique($active_employee_ids);
sort($active_employee_ids);

if (!empty($active_employee_ids)) {
    $placeholders = implode(',', array_fill(0, count($active_employee_ids), '?'));
    // Burada manual_vardiya sütununu da SELECT'e ekliyoruz ki manuel eklenen vardiya doğrudan gelsin
    $stmt = $pdo->prepare("
        SELECT id, name, external_id, COALESCE(manual_vardiya, '') AS manual_vardiya
        FROM employees 
        WHERE is_active = 1 
        AND id IN ($placeholders)
        ORDER BY name
    ");
    $stmt->execute($active_employee_ids);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $employees = [];
}

/*
    DEĞİŞİKLİK: Burada artık "son 31 gün" yerine içinde bulunduğunuz AY'ın verileri çekiliyor.
    - current month's start/end belirleniyor
    - aylık toplam hesaplanıyor
    - ayın günleri için dizi oluşturuluyor
*/

// Mevcut ayın ilk ve son günü
$month_start_dt = new DateTime($current_time->format('Y-m-01'));
$month_end_dt = new DateTime($current_time->format('Y-m-t'));
$month_start = $month_start_dt->format('Y-m-d');
$month_end = $month_end_dt->format('Y-m-d');
$days_in_month = (int)$month_start_dt->format('t');

// Bu ayın tip kutusu verilerini çek
$stmt = $pdo->prepare("
    SELECT DATE(`date`) as day, `count` 
    FROM tip_boxes 
    WHERE `date` BETWEEN :start AND :end
    ORDER BY `date` ASC
");
$stmt->execute([':start' => $month_start, ':end' => $month_end]);
$tip_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ayın günleri için başlangıç dizi
$month_days = [];
for ($i = 0; $i < $days_in_month; $i++) {
    $d = (clone $month_start_dt)->modify("+{$i} days");
    $month_days[$d->format('Y-m-d')] = 0;
}

// Veritabanından gelen verileri doldur ve toplamı hesapla
$total_tips = 0;
foreach ($tip_rows as $row) {
    if (isset($month_days[$row['day']])) {
        $month_days[$row['day']] = (int)$row['count'];
        $total_tips += (int)$row['count'];
    }
}

// Bugünün tarihi string
$today_dt = new DateTime();
$today_str = $today_dt->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="15">
    <title>Çalışma Takip</title>

    <!-- Çok erken: preload sınıfını ekle (head içinde, body henüz yokken çalışır) -->
    <script>document.documentElement.classList.add('preload');</script>

    <style>
        /* Kullanımı kolay değişkenler: burayı değiştirerek tüm ölçüleri hızlıca ayarlayabilirsiniz */
        :root{
            --employee-col-width: 400px; /* önce 320px idi, 2cm civarı eklemek için büyütüldü */
            --shift-col-width: 44px;    /* önce 38px */
            --time-slot-min-width: 150px; /* önce 110px */
            /* DİKEY BÜYÜTME: satır yüksekliğini ve fontu yaklaşık %40 artırdım */
            --row-min-height: 40px;     /* önce 28px (≈ +40%) */
            --row-max-height: 90px;     /* önce 64px (≈ +40%) */
            --base-font-size: 18px;     /* önce 13px (≈ +40%) - yazılar dahil */
            --badge-font-size: 14px;    /* önce 10px (≈ +40%) */

            /* ÜST SAAT ETİKETLERİ İÇİN (aynı oranda büyütüldü) */
            --time-label-height: 44px;
            --time-label-font-size: 18px;
            --time-subtext-scale: 0.65; /* alt etiketin sub-font ölçeği (ör. ŞU AN / +20 DK) */
        }

        /* PRELOAD - tüm animasyon/transition'ları kapat ve container'ı gizle (html.preload fallback ile) */
        html.preload *, html.preload *::before, html.preload *::after,
        body.preload *, body.preload *::before, body.preload *::after {
            transition: none !important;
            animation: none !important;
        }
        html.preload .container, body.preload .container {
            visibility: hidden;
        }

        /* Sadece renk/arka plan/visibility değişiklikleri; font aileleri ve temel yatay düzen korunmuştur */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Açık mavi arka plan, daha belirgin koyu metin */
        body {
            font-family: 'Segoe UI', 'SF Pro Display', 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(180deg, #e9f8ff 0%, #d6eefc 100%); /* daha açık mavi tonlar */
            color: #042b3a; /* koyu mavi/mor ton — yüksek kontrast */
            min-height: 100vh;
            padding-bottom: 10vh; /* footer (tip kutuları) için güvenli boşluk */
            overflow: auto; /* TV'lerde scroll gerekiyorsa erişilebilir olsun */
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 10% 20%, rgba(59,130,246,0.02) 0%, transparent 18%),
                radial-gradient(circle at 90% 80%, rgba(6,182,212,0.02) 0%, transparent 18%);
            z-index: -1;
        }

        .container {
            max-width: 3840px;
            margin: 0 auto;
            padding: 4px 10px;
            height: calc(100vh - 10vh); /* footer yüksekliğini çıkardık, içeriğin görünmesini garanti eder */
            display: flex;
            flex-direction: column;
        }

        .header {
            text-align: center;
            padding: 3px 0;
            margin-bottom: 5px;
            border-bottom: 2px solid rgba(4,43,58,0.06);
            background: rgba(255, 255, 255, 0.92); /* açık */
            border-radius: 6px;
            box-shadow: 0 1px 8px rgba(4,43,58,0.03);
            position: relative;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 20px;
        }

        .header::after {
            content: '✅ AKTİF: ' attr(data-count) ' / 100';
            position: absolute;
            top: -6px;
            right: 10px;
            background: #0b6fb8; /* belirgin mavi badge */
            color: white;
            padding: 0 6px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(11,111,184,0.12);
        }

        .header .date-time {
            font-size: 15px;
            color: #044b7a; /* koyu mavi */
            font-weight: 700;
            letter-spacing: 0.8px;
        }

        /* NOT BÖLÜMÜ STİLİ (açık, okunaklı) */
        .note-section {
            background: rgba(255, 249, 230, 0.95); /* hafif sıcak ton, okunaklı koyu metin */
            color: #042b3a;
            padding: 8px 16px 8px 36px; /* Sol padding ikon için */
            border-radius: 6px;
            margin: 6px 0 10px 0;
            font-size: 14px;
            line-height: 1.45;
            border-left: 4px solid #0ea5e9; /* mavi vurgusu */
            box-shadow: 0 1px 6px rgba(4,43,58,0.03);
            word-wrap: break-word;
            max-width: 100%;
            position: relative;
            animation: fadeInNote 0.3s ease-out;
        }
        
        .note-section::before {
            content: '📢';
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            opacity: 0.95;
        }
        
        @keyframes fadeInNote {
            from { opacity: 0; transform: translateY(-3px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .time-labels {
            display: flex;
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(245,252,255,0.95) 100%);
            padding: 3px 8px;
            font-weight: 800;
            color: #044b7a;
            border-bottom: 2px solid rgba(4,43,58,0.04);
            text-align: center;
            border-radius: 4px 4px 0 0;
            box-shadow: 0 2px 6px rgba(4,43,58,0.02);
            flex-shrink: 0;

            /* Üst saatlerin yüksekliğini ayarladık (satırlarla uyumlu olsun diye) */
            height: var(--time-label-height);
            align-items: center;
        }

        .time-labels .employee-header {
            width: var(--employee-col-width);
            text-align: left;
            padding-left: 10px;
            background: rgba(255,255,255,0.95);
            border-right: 2px solid rgba(4,43,58,0.04);
            flex-shrink: 0;
            font-size: calc(var(--time-label-font-size) * 0.9);
            color: #475569;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            display:flex;
            align-items:center;
        }

        /* SHIFT HEADER hizasını shift-code ile eşitlemek için eklendi */
        .time-labels .shift-header {
            width: var(--shift-col-width);
            min-width: var(--shift-col-width);
            max-width: var(--shift-col-width);
            text-align: center;
            padding: 2px 6px;
            background: rgba(255,255,255,0.95);
            border-right: 1px solid rgba(4,43,58,0.04);
            flex-shrink: 0;
            font-size: calc(var(--time-label-font-size) * 0.85);
            color: #475569;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:800;
        }

        .time-label {
            flex: 1;
            min-width: 135px;
            padding: 6px 8px;
            border-left: 1px solid rgba(4,43,58,0.02);
            position: relative;
            font-weight: 700;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            line-height: 1.05;
            font-size: var(--time-label-font-size);
        }

        .time-label .subtext {
            display:block;
            font-size: calc(var(--time-label-font-size) * var(--time-subtext-scale));
            font-weight: 700;
            color: #6b7280;
            margin-top: 2px;
            letter-spacing: 0.2px;
        }

        .time-label::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50%;
            height: 1px;
            background: linear-gradient(90deg, transparent, #0ea5e9, transparent);
        }

        /* Liste ve satırlar (font boyutları orijinalle aynı) */
        .list-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0;
            overflow: hidden;
            background: transparent;
            border-radius: 0 0 6px 6px;
            box-shadow: 0 4px 16px rgba(4,43,58,0.02);
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        .list-item {
            display: flex;
            align-items: center;
            font-weight: 700;
            transition: all 0.15s ease;
            position: relative;
            min-height: var(--row-min-height);
            max-height: var(--row-max-height);
            flex: 1;
            overflow: hidden;
            border-bottom: 1px solid rgba(4,43,58,0.03);
            /* Başlangıç yükleme animasyonu dikkat dağıttığı için iptal edildi */
            animation: none !important;
        }

        /* ZEBRA: satırlar arası belirginlik (koyu - açık) */
        .list-item:nth-child(odd) {
            background: linear-gradient(90deg, rgba(221,235,246,0.98), rgba(219,233,244,0.98));
        }

        .list-item:nth-child(even) {
            background: linear-gradient(90deg, rgba(249,253,255,0.98), rgba(246,251,255,0.98));
        }

        .list-item:hover {
            background: rgba(229,246,255,0.98) !important;
            transform: translateX(2px);
            border-left: 4px solid #06b6d4;
            z-index: 10;
        }

        .employee-name {
            width: var(--employee-col-width);
            padding: 0 12px;
            font-weight: 800;
            color: #042b3a;
            border-right: 2px solid rgba(4,43,58,0.04);
            flex-shrink: 0;
            font-size: var(--base-font-size);
            letter-spacing: 0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            line-height: 1.2;
        }

        .employee-name::after {
            content: attr(data-badge);
            margin-left: 8px;
            background: #0ea5e9;
            color: white;
            font-size: var(--badge-font-size);
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 6px;
            flex-shrink: 0;
        }

        .employee-name[data-badge="M"]::after {
            background: #ca8a04;
        }

        .shift-code {
            width: var(--shift-col-width);
            min-width: var(--shift-col-width);
            max-width: var(--shift-col-width);
            text-align: center;
            padding: 6px 6px;
            color: #fff;
            background: #0b6fb8;
            font-weight: 900;
            border-right: 1px solid rgba(4,43,58,0.04);
            flex-shrink: 0;
            font-size: 12px;
            line-height: 1;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .shift-code.empty { background: rgba(11,111,184,0.12); color: #044b7a; font-weight: 700; }

        .time-slot {
            flex: 1;
            text-align: center;
            padding: 6px 8px;
            min-width: var(--time-slot-min-width);
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.2;
            font-size: var(--base-font-size);
            color: #042b3a;
            background: rgba(255,255,255,0.6);
        }

        .time-slot.current { background: rgba(14,165,233,0.12); color: #033b4a; border-left: 2px solid #0ea5e9; }
        .time-slot.next { background: rgba(245,158,11,0.08); color: #7a3910; border-left: 1.5px solid #f59e0b; }
        .time-slot.future { background: rgba(59,130,246,0.06); color: #08385a; border-left: 1px solid #3b82f6; }
        .time-slot.empty { background: rgba(220,38,38,0.04); color: #991b1b; font-style: normal; font-weight: 500; opacity: 0.9; border-left: 1px solid #dc2626; }

        .no-employees {
            text-align: center;
            padding: 30px 20px;
            color: #475569;
            font-size: 20px;
            font-weight: 700;
            background: rgba(255,255,255,0.9);
            border-radius: 6px;
            margin: auto;
            box-shadow: 0 2px 10px rgba(4,43,58,0.02);
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .no-employees::before {
            content: '⚠️';
            font-size: 32px;
            display: block;
            margin-bottom: 8px;
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(4,43,58,0.08);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(4,43,58,0.14);
        }

        /* TİP KUTUSU FOOTER (sabit) */
        .tip-boxes-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 10vh;
            background: linear-gradient(180deg, #f7fdff 0%, #eef9ff 100%);
            border-top: 1px solid rgba(4,43,58,0.03);
            padding: 8px 12px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .tip-boxes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3px;
        }

        .tip-total-display {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            color: white;
            font-weight: 900;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(16,163,113,0.08);
        }

        .tip-days-grid { display: grid; gap: 4px 6px; height: calc(100% - 34px); overflow: hidden; align-items: center; }
        .tip-day-item { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:4px; border-radius:4px; font-size:9px; font-weight:800; color:#042b3a; background: rgba(255,255,255,0.9); border:1px solid rgba(4,43,58,0.03); }
        .tip-day-item.today { background: rgba(14,165,233,0.12); border-color: rgba(14,165,233,0.22); color:#044b7a; box-shadow: 0 0 4px rgba(14,165,233,0.04); }
        .tip-day-number { font-size:10px; opacity:0.7; margin-bottom:2px; }
        .tip-day-count { font-size:11px; font-weight:900; color:#10b981; }

        /* COMPACT MODE (otomatik etkinleştirilecek) */
        body.compact .header { height: 28px; padding: 4px 6px; }
        body.compact .header .date-time { font-size: 13px; }
        body.compact .time-labels { height: calc(var(--time-label-height) * 0.85); padding: 4px 6px; font-size: 11px; }
        body.compact .time-labels .employee-header { width: calc(var(--employee-col-width) - 140px); padding-left: 10px; } /* orantılı küçültme */
        body.compact .employee-name { width: calc(var(--employee-col-width) - 140px); padding: 0 8px; font-size: 13px; white-space: nowrap; } /* compact için font biraz daha büyük tutuldu */
        body.compact .employee-name::after { display: none; } /* badge gizle */
        body.compact .shift-code { width: calc(var(--shift-col-width) - 6px); padding: 4px 4px; font-size: 11px; }
        body.compact .time-slot { padding: 4px 6px; font-size: 12px; min-width: 90px; }
        body.compact .list-item { min-height: 22px; max-height: 28px; } /* compact modu için orantılı büyütme */
        body.compact .tip-boxes-footer { height: 8vh; padding: 6px 10px; }

        @media (max-height: 1200px) {
            .list-item { min-height: 28px !important; max-height: 56px !important; }
            .employee-name, .time-slot { font-size: 14px !important; }
            .header { padding: 4px 0; height: 28px; }
            .header .date-time { font-size: 14px; }
            .time-labels { padding: 4px 8px; height: calc(var(--time-label-height) * 0.9); font-size: 12px; }
            .note-section {
                font-size: 13px;
                padding: 6px 12px 6px 34px;
            }
            .note-section::before {
                font-size: 14px;
                left: 10px;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(3px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Başlangıç animasyonu iptal edildi */
        /* .list-item { animation: fadeIn 0.25s ease-out forwards; } */
    </style>
</head>
<body>
    <div class="container">
        <div class="header" data-count="<?= (int) count($employees) ?>">
            <div class="date-time" id="dateTime"></div>
        </div>
        
        <!-- YENİ: NOT BÖLÜMÜ - SADECE NOT VARSA GÖRÜNÜR -->
        <?php if (!empty($display_note)): ?>
            <div class="note-section">
                <?= nl2br(htmlspecialchars($display_note, ENT_QUOTES, 'UTF-8')) ?>
            </div>
        <?php endif; ?>

        <?php
        $slot_duration = 20 * 60;
        $now = time();
        $current_slot_start = floor($now / $slot_duration) * $slot_duration;
        ?>

        <div class="time-labels">
            <div class="employee-header">PERSONEL</div>
            <div class="shift-header">V</div> <!-- Vardiya sütunu (kısa) -->
            <?php
            for ($i = 0; $i < 4; $i++):
                $slot_start = $current_slot_start + ($i * $slot_duration);
                $start_time = date('H:i', $slot_start);
            ?>
                <div class="time-label">
                    <?= $start_time ?>
                    <span class="subtext"><?= $i===0 ? 'ŞU AN' : '+'.($i*20).' DK' ?></span>
                </div>
            <?php endfor; ?>
        </div>

        <div class="list-container">
            <?php if (empty($employees)): ?>
                <div class="no-employees">
                    Şu anda aktif personel bulunmamaktadır
                </div>
            <?php else: ?>
                <?php
                $manual_ids = array_flip($manual_employees);
                ?>
                
                <?php foreach ($employees as $employee): ?>
                    <div class="list-item">
                        <div class="employee-name" data-badge="<?= isset($manual_ids[$employee['id']]) ? 'M' : 'İK' ?>">
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        
                        <?php
                        // çalışan için vardiya kodunu al (önce HR, sonra employees.manual_vardiya, sonra diğer manuel kaynaklar)
                        $vardiya_kod_display = get_vardiya_for_employee($pdo, $employee, $use_hr);
                        $shift_class = $vardiya_kod_display ? '' : 'empty';
                        ?>
                        <div class="shift-code <?= $shift_class ?>"><?= $vardiya_kod_display ? htmlspecialchars($vardiya_kod_display, ENT_QUOTES, 'UTF-8') : '-' ?></div>
                        
                        <?php
                        for ($i = 0; $i < 4; $i++):
                            $slot_timestamp = $current_slot_start + ($i * $slot_duration);
                            
                            $stmt = $pdo->prepare("
                                SELECT a.name AS area_name 
                                FROM work_slots ws
                                JOIN areas a ON ws.area_id = a.id
                                WHERE ws.employee_id = ? 
                                AND ws.slot_start = FROM_UNIXTIME(?)
                            ");
                            $stmt->execute([$employee['id'], $slot_timestamp]);
                            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            $area_name = $assignment['area_name'] ?? '-';
                            $is_empty = ($area_name === '-');
                            
                            if ($i === 0) {
                                $time_class = 'current';
                            } elseif ($i === 1) {
                                $time_class = 'next';
                            } else {
                                $time_class = 'future';
                            }
                            
                            if ($is_empty) $time_class = 'empty';
                        ?>
                            <div class="time-slot <?= $time_class ?>">
                                <?= htmlspecialchars($area_name, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
        // Fallback: eğer head'deki erken script çalışmadıysa body'de preload ekle
        (function(){
            if (!document.documentElement.classList.contains('preload')) {
                document.documentElement.classList.add('preload');
            }
            if (document.body && !document.body.classList.contains('preload')) {
                document.body.classList.add('preload');
            }
        })();

        // Gerçek zamanlı saat
        function updateDateTime() {
            const now = new Date();
            const days = ['PAZAR', 'PAZARTESİ', 'SALI', 'ÇARŞAMBA', 'PERŞEMBE', 'CUMA', 'CUMARTESİ'];
            const dayName = days[now.getDay()];
            const dateStr = now.toLocaleDateString('tr-TR', { 
                day: '2-digit', 
                month: '2-digit', 
                year: 'numeric' 
            });
            const timeStr = now.toLocaleTimeString('tr-TR', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: false
            });
            
            document.getElementById('dateTime').textContent = `${dayName} • ${dateStr} • ${timeStr}`;
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        // KRİTİK: 100 kişi optimizasyonu (compact modu otomatik etkinleştirir)
        function optimizeDisplay() {
            const container = document.querySelector('.list-container');
            const items = document.querySelectorAll('.list-item');
            const employeeCount = items.length;
            if (!container) return;

            // Eşikler - isterseniz bu değerleri sunucu tarafında değiştirebilirsiniz
            const compactThresholdCount = 80; // çalışan sayısına göre compact mod
            const compactHeightThreshold = 2000; // px cinsinden, ekran yüksekliği düşükse compact

            // Karar: compact mode
            const compactByCount = employeeCount >= compactThresholdCount;
            const compactByHeight = window.innerHeight < compactHeightThreshold;

            if (compactByCount || compactByHeight) {
                document.body.classList.add('compact');
            } else {
                document.body.classList.remove('compact');
            }

            // Satır başına düşen yükseklik hesapla
            const containerHeight = container.offsetHeight;
            const rowHeight = employeeCount ? (containerHeight / employeeCount) : 0;

            // Yazı boyutlarını satır yüksekliğine göre ayarla (biraz daha büyük başlangıç değerleri)
            let fontSize = '18px', nameFontSize = '18px';

            if (rowHeight < 16) {
                fontSize = '9px';
                nameFontSize = '9px';
            } else if (rowHeight < 18) {
                fontSize = '10px';
                nameFontSize = '10px';
            } else if (rowHeight < 22) {
                fontSize = '11px';
                nameFontSize = '11px';
            } else if (rowHeight < 28) {
                fontSize = '12px';
                nameFontSize = '12px';
            } else {
                fontSize = '18px';
                nameFontSize = '18px';
            }

            document.querySelectorAll('.time-slot').forEach(el => el.style.fontSize = fontSize);
            document.querySelectorAll('.employee-name').forEach(el => el.style.fontSize = nameFontSize);

            // Scroll kontrolü: çok fazla kişi varsa kaydırma aç
            if (employeeCount > 110) { // çok yoğun listelerde scroll açık olsun
                container.style.overflowY = 'auto';
            } else {
                container.style.overflowY = 'hidden';
            }

            // Header aktif sayıyı güncelle
            const header = document.querySelector('.header');
            if (header) header.setAttribute('data-count', employeeCount);
        }

        // initAndOptimize: fonts.ready bekler, optimizeDisplay çalıştırır, sonra preload'u kaldırır ve container'ı görünür yapar
        async function initAndOptimize() {
            // Webfont'lerden kaynaklı kaymaları önlemek için bekle
            if (document.fonts && document.fonts.ready) {
                try { await document.fonts.ready; } catch(e) { /* ignore */ }
            }

            try {
                optimizeDisplay();
            } catch (e) {
                console.error('optimizeDisplay error', e);
            }

            // Bir frame bekleyip görünür yap
            requestAnimationFrame(() => {
                const c = document.querySelector('.container');
                if (c) c.style.removeProperty('visibility');

                // Hem html hem body preload sınıfını kaldır
                document.documentElement.classList.remove('preload');
                if (document.body) document.body.classList.remove('preload');
            });
        }

        // Başlat
        window.addEventListener('load', initAndOptimize);
        // DOM hazır olunca da tetikle (yük tamamlanmazsa bile)
        document.addEventListener('DOMContentLoaded', function(){
            // küçük bir gecikme ile çağırmak çakışmaları azaltır
            setTimeout(initAndOptimize, 40);
        });

        // Periyodik optimizasyon (orijinal davranış)
        setInterval(function(){
            try { optimizeDisplay(); } catch(e){/*ignore*/ }
        }, 15000);
    </script>
</body>
</html>