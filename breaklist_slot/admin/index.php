<?php
require_once '../config.php';
require_once '../config_hr.php';
session_start();

// ---------------------------
// MANUEL GÜN GEÇİŞİ (day_offset)
// - Sayfayı ?day_offset=N ile çağırarak N gün ileri/geri bakabilirsiniz.
// - GET parametresi varsa session güncellenir; aksi halde session'daki değer kullanılır.
// ---------------------------
if (isset($_GET['day_offset'])) {
    $_SESSION['day_offset'] = (int)$_GET['day_offset'];
}
if (!isset($_SESSION['day_offset'])) $_SESSION['day_offset'] = 0;
$day_offset = (int)$_SESSION['day_offset'];

// wrapper: vardiya kodu çekme - mümkünse tarih parametreli fonksiyonu kullanmaya çalışır
function get_vardiya_kod_for_day($external_id, $dateString) {
    // dateString: 'YYYY-MM-DD'
    if (function_exists('get_vardiya_kod_for_date')) {
        try {
            return get_vardiya_kod_for_date($external_id, $dateString);
        } catch (Exception $e) {
            // fallback
        }
    }
    if (function_exists('get_today_vardiya_kod')) {
        try {
            $rf = new ReflectionFunction('get_today_vardiya_kod');
            $params = $rf->getNumberOfParameters();
            if ($params >= 2) {
                return get_today_vardiya_kod($external_id, $dateString);
            } elseif ($params === 1) {
                return get_today_vardiya_kod($external_id);
            } else {
                return get_today_vardiya_kod();
            }
        } catch (ReflectionException $e) {
            try {
                return get_today_vardiya_kod($external_id);
            } catch (Exception $e2) {
                return null;
            }
        }
    }
    return null;
}

// --- BEGIN: background sync of automatic employees (silent, non-blocking) ---
// Calls your internal sync_employees.php in background so index.php doesn't block or show output.
function background_request_fire_and_forget($url) {
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host'])) return false;

    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
    $host = $parts['host'];
    $port = isset($parts['port']) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $path = (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $errno = 0; $errstr = '';
    $transport = ($scheme === 'https') ? 'ssl' : 'tcp';
    // short timeout to avoid hanging
    $fp = @fsockopen($transport . '://' . $host, $port, $errno, $errstr, 1);
    if ($fp) {
        stream_set_blocking($fp, 0); // non-blocking
        $out  = "GET " . $path . " HTTP/1.1\r\n";
        $out .= "Host: " . $host . "\r\n";
        $out .= "User-Agent: BackgroundSync/1.0\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        // give the server a tiny moment to accept, then close
        usleep(50000); // 50ms
        fclose($fp);
        return true;
    }

    // fallback to curl with strict timeouts
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800); // 0.8s max
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 400);
        curl_exec($ch);
        curl_close($ch);
        return true;
    }

    return false;
}

// NOTE: Background sync disabled by request — the following call was commented out.
// If you want to re-enable it, uncomment the line below and ensure the URL is correct.
// @background_request_fire_and_forget('http://172.18.0.36/breaklist_slot/sync_employees.php');
// --- END background sync ---

// -------------------------
// HR photo helper + AJAX endpoint (integrated from show_hr_query_result.php)
// -------------------------
function get_personel_photo($personel_id) {
    $conn = get_hr_connection();
    $photo = null;

    // Tam veritabanı yolları ile cross-database join
    // NOT: PersonelID GUID olduğu için = ? kullanıyoruz
    $sql = "SELECT TOP 1 
                b.Icerik,
                p.Adi,
                p.Soyadi,
                bolum.Tanim AS BolumTanimi,
                birim.Tanim AS BirimTanimi
            FROM [IK_Chamada].[dbo].[Personel] p
            LEFT JOIN [IK_Binary].[dbo].[Belgeler] b ON p.ID = b.PersonelID
            LEFT JOIN [IK_Chamada].[dbo].[Bolum] bolum ON p.BolumID = bolum.ID
            LEFT JOIN [IK_Chamada].[dbo].[Birim] birim ON p.BirimID = birim.ID
            WHERE p.ID = ? 
            AND b.Icerik IS NOT NULL 
            AND b.Icerik != ''
            AND b.BelgeTuru = 'PersonelResmi'
            ORDER BY b.CreateDate DESC, b.ID DESC";

    $stmt = @sqlsrv_query($conn, $sql, [$personel_id]);

    if ($stmt !== false) {
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['Icerik'] && strlen($row['Icerik']) > 0) {
                $content = $row['Icerik'];
                $photo = [
                    'data' => base64_encode($content),
                    'adi' => $row['Adi'] ?? '',
                    'soyadi' => $row['Soyadi'] ?? '',
                    'bolum' => $row['BolumTanimi'] ?? '',
                    'birim' => $row['BirimTanimi'] ?? ''
                ];
                error_log("Fotoğraf bulundu - PersonelID: $personel_id, Boyut: " . strlen($content));
            }
        }
        sqlsrv_free_stmt($stmt);
    } else {
        error_log("SQL hatası (get_personel_photo): " . print_r(sqlsrv_errors(), true));
    }

    sqlsrv_close($conn);
    return $photo;
}

// AJAX isteği için fotoğraf getirme - aynı dosyaya POST yapıldığında çalışacak
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_photo'])) {
    // GUID olduğu için intval YOK! String olarak al
    $personelID = trim($_POST['get_photo']);

    // GUID formatını kontrol et
    if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $personelID)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Geçersiz Personel ID formatı',
            'personelID' => $personelID
        ]);
        exit;
    }

    $photo = get_personel_photo($personelID);

    header('Content-Type: application/json; charset=utf-8');
    if ($photo && $photo['data']) {
        echo json_encode([
            'success' => true,
            'photoData' => $photo['data'],
            'bolum' => $photo['bolum'],
            'birim' => $photo['birim'],
            'adi' => $photo['adi'],
            'soyadi' => $photo['soyadi']
        ]);
    } else {
        // Alternatif olarak sadece personel bilgilerini getir
        $conn = get_hr_connection();
        $sql_info = "SELECT p.Adi, p.Soyadi, bolum.Tanim AS BolumTanimi, birim.Tanim AS BirimTanimi
                    FROM [IK_Chamada].[dbo].[Personel] p
                    LEFT JOIN [IK_Chamada].[dbo].[Bolum] bolum ON p.BolumID = bolum.ID
                    LEFT JOIN [IK_Chamada].[dbo].[Birim] birim ON p.BirimID = birim.ID
                    WHERE p.ID = ?";
        $stmt = @sqlsrv_query($conn, $sql_info, [$personelID]);
        $personel_info = null;
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $personel_info = $row;
        }
        @sqlsrv_free_stmt($stmt);
        @sqlsrv_close($conn);

        echo json_encode([
            'success' => false,
            'message' => 'Fotoğraf bulunamadı',
            'bolum' => $personel_info['BolumTanimi'] ?? '',
            'birim' => $personel_info['BirimTanimi'] ?? ''
        ]);
    }
    exit;
}

date_default_timezone_set('Europe/Nicosia');

// ---------------------------
// LATE-NIGHT SHIFT THRESHOLD
// ---------------------------
// Shifts starting at this hour or later are considered to belong to the NEXT day
// for display purposes. This handles the case where night shifts (22:00-06:00, etc.)
// should appear on the day they end, not the day they start.
define('LATE_NIGHT_SHIFT_THRESHOLD', 22);

// ---------------------------
// current / view time adjustments (day_offset kullanılıyor)
// ---------------------------
$now_real = new DateTime('now', new DateTimeZone('Europe/Nicosia'));

// $day_offset session üzerinden alındı daha üstte; create view_date
$view_date = clone $now_real;
if ($day_offset !== 0) {
    if ($day_offset > 0) $view_date->modify('+' . $day_offset . ' days');
    else $view_date->modify($day_offset . ' days'); // negative handled
}

// current_time used in template is based on view_date but keeps current time-of-day
$current_time = clone $view_date;
$current_hour = (int)$current_time->format('H');
$current_minute = (int)$current_time->format('i');
$current_total_minutes = $current_hour * 60 + $current_minute;

// NOTU ÇEK
$display_note = '';
try {
    $note_stmt = $pdo->query("SELECT note_text FROM display_notes ORDER BY updated_at DESC LIMIT 1");
    $note_row = $note_stmt->fetch(PDO::FETCH_ASSOC);
    $display_note = $note_row && !empty(trim($note_row['note_text'])) ? trim($note_row['note_text']) : '';
} catch (Exception $e) {
    $display_note = '';
}

// helper: circular range check (handles intervals that wrap past midnight)
function in_circular_range($cur, $from, $to) {
    // cur, from, to are minutes since midnight (0..1439)
    if ($from === $to) return false; // empty interval
    if ($from < $to) {
        return ($cur >= $from && $cur < $to);
    } else {
        // wraps midnight
        return ($cur >= $from || $cur < $to);
    }
}

// NEW helper: görünürlük başlangıcını doğru güne sabitle
function get_visible_start_minute($start_total) {
    // 40 dk önce göster, ama 00:00'dan önceye taşırma
    return max(0, $start_total - 40);
}

// VARDİYA HESAPLAMA
function calculate_shift_hours($vardiya_kod) {
    if (!$vardiya_kod || in_array($vardiya_kod, ['OFF', 'RT'])) return null;
    
    $base_hour = null;
    $is_extended = false;
    $duration_hours = 8;
    
    if (preg_match('/^(\d{1,2})\+?$/', $vardiya_kod, $matches)) {
        $base_hour = (int)$matches[1];
        $is_extended = strpos($vardiya_kod, '+') !== false;
        $duration_hours = $is_extended ? 10 : 8;
    }
    
    $letter_shifts = ['A'=>['start'=>8,'duration'=>8],'B'=>['start'=>16,'duration'=>8],'C'=>['start'=>0,'duration'=>8],'D'=>['start'=>9,'duration'=>9],'E'=>['start'=>14,'duration'=>8],'F'=>['start'=>10,'duration'=>8],'G'=>['start'=>18,'duration'=>8],'H'=>['start'=>12,'duration'=>8],'I'=>['start'=>13,'duration'=>8],'J'=>['start'=>22,'duration'=>8],'K'=>['start'=>20,'duration'=>8],'L'=>['start'=>7,'duration'=>8],'M'=>['start'=>6,'duration'=>8],'N'=>['start'=>23,'duration'=>8]];
    
    if (isset($letter_shifts[$vardiya_kod])) {
        $base_hour = $letter_shifts[$vardiya_kod]['start'];
        $duration_hours = $letter_shifts[$vardiya_kod]['duration'];
    }
    
    if ($base_hour === null) return null;
    
    $start_hour = $base_hour;
    $start_minute = 0;
    $end_hour = $start_hour + $duration_hours;
    $end_minute = 0;
    // detect wrap (shift continues into next day)
    $wraps = false;
    if ($end_hour >= 24) {
        $wraps = true;
        $end_hour = $end_hour % 24;
    }
    
    return ['start_hour'=>$start_hour,'start_minute'=>$start_minute,'end_hour'=>$end_hour,'end_minute'=>$end_minute,'duration'=>$duration_hours,'is_extended'=>$is_extended,'wraps'=>$wraps];
}

// PERSONEL ÇEKME
// -> buraya 'birim' ve 'external_id' sütunu eklendi, böylece UI'da isim altına gösterilebilsin
$employees = $pdo->query("SELECT id, name, external_id, birim FROM employees WHERE is_active = 1 AND external_id IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$working_now = $not_started_yet = $finished = [];

// Track which employee IDs have been added for the view_date (so we avoid duplicates)
$added_employee_ids = [];

foreach ($employees as $emp) {
    // use wrapper to attempt to fetch vardiya kod for the selected view_date
    $vardiya_kod_today = get_vardiya_kod_for_day($emp['external_id'], $view_date->format('Y-m-d'));
    $shift_info_today = calculate_shift_hours($vardiya_kod_today);

    // Primary: if today has a shift and it is not OFF/RT -> show per normal logic
    if ($shift_info_today) {
        // IMPORTANT: Late-night shifts (starting at LATE_NIGHT_SHIFT_THRESHOLD or later) belong to the NEXT day
        // These shifts are skipped here and will be picked up by the SECONDARY check below
        // when viewing the next day. This coordination ensures night shifts appear on the
        // correct day (the day they END, not the day they START).
        if ($shift_info_today['start_hour'] >= LATE_NIGHT_SHIFT_THRESHOLD) {
            // This late-night shift will be displayed on the next day instead
            // Skip it and don't add to any list for today
            continue;
        }

        // compute start/end in minutes since midnight
        $start_total = $shift_info_today['start_hour'] * 60 + $shift_info_today['start_minute'];
        $end_total = $shift_info_today['end_hour'] * 60 + $shift_info_today['end_minute'];

        // NEW: görünürlük başlangıcı (00:00'dan önceye taşmaz)
        $start_minus = get_visible_start_minute($start_total);

        // determine if current time falls into the visible/active window
        $is_visible_and_working = in_circular_range($current_total_minutes, $start_minus, $end_total);

        $data = [
            'id'=>$emp['id'],
            'name'=>$emp['name'],
            'birim'=> $emp['birim'] ?? '',
            'vardiya_kod'=>$vardiya_kod_today,
            'shift_info'=>$shift_info_today,
            'visible_from_minus20'=>$start_minus,
            'external_id' => $emp['external_id']
        ];
        if ($is_visible_and_working) $working_now[] = $data;
        elseif ($current_total_minutes < $start_total) $not_started_yet[] = $data;
        else $finished[] = $data;

        $added_employee_ids[$emp['id']] = true;
        continue; // bugünün vardiyası varsa burada bitir
    }

    // SECONDARY: check previous day's shift for late-night shifts (>=LATE_NIGHT_SHIFT_THRESHOLD) that belong to today
    // This section works in coordination with the PRIMARY check above, which skips late-night
    // shifts on their original day. Here we pick up those same shifts and display them on the
    // day they actually belong to (the next day).
    $prev_date = (clone $view_date)->modify('-1 day');
    $vardiya_kod_prev = get_vardiya_kod_for_day($emp['external_id'], $prev_date->format('Y-m-d'));
    $shift_info_prev = calculate_shift_hours($vardiya_kod_prev);

    // Handle shifts starting at LATE_NIGHT_SHIFT_THRESHOLD or later from the previous day
    if ($shift_info_prev && $shift_info_prev['start_hour'] >= LATE_NIGHT_SHIFT_THRESHOLD) {
        // This late-night shift from the previous day belongs to today
        $start_total = $shift_info_prev['start_hour'] * 60 + $shift_info_prev['start_minute'];
        $end_total = $shift_info_prev['end_hour'] * 60 + $shift_info_prev['end_minute'];
        
        // görünürlük başlangıcı
        $start_minus = get_visible_start_minute($start_total);
        
        $is_visible_and_working = in_circular_range($current_total_minutes, $start_minus, $end_total);
        
        $data = [
            'id'=>$emp['id'],
            'name'=>$emp['name'],
            'birim'=> $emp['birim'] ?? '',
            'vardiya_kod'=> $vardiya_kod_prev,
            'shift_info'=>$shift_info_prev,
            'visible_from_minus20'=>$start_minus,
            'external_id' => $emp['external_id'],
            'from_prev_day' => true
        ];
        
        if ($is_visible_and_working) $working_now[] = $data;
        elseif ($current_total_minutes < $start_total) $not_started_yet[] = $data;
        else $finished[] = $data;
        
        $added_employee_ids[$emp['id']] = true;
    }
    // Also handle wrapping shifts that start before LATE_NIGHT_SHIFT_THRESHOLD but wrap past midnight
    // (e.g., shift K: 20:00-04:00). These shifts are NOT considered late-night shifts and
    // remain on their original day, but we still need to show the overflow portion on the next day.
    elseif ($shift_info_prev && !empty($shift_info_prev['wraps'])) {
        $end_total_prev = $shift_info_prev['end_hour'] * 60 + $shift_info_prev['end_minute'];
        if ($end_total_prev > 0) {
            $start_total = 0;
            $end_total = $end_total_prev;

            // 00:00 başlangıcında geriye taşma yok
            $start_minus = 0;
            $is_visible_and_working = in_circular_range($current_total_minutes, $start_minus, $end_total);

            $shift_info_for_today = [
                'start_hour' => 0,
                'start_minute' => 0,
                'end_hour' => $shift_info_prev['end_hour'],
                'end_minute' => $shift_info_prev['end_minute'],
                'duration' => ($end_total - $start_total) / 60,
                'is_extended' => $shift_info_prev['is_extended'],
                'wraps' => false
            ];

            $data = [
                'id'=>$emp['id'],
                'name'=>$emp['name'],
                'birim'=> $emp['birim'] ?? '',
                'vardiya_kod'=> $vardiya_kod_prev . ' (prev-day overflow)',
                'shift_info'=>$shift_info_for_today,
                'visible_from_minus20'=>$start_minus,
                'external_id' => $emp['external_id'],
                'from_prev_day' => true
            ];

            if ($is_visible_and_working) $working_now[] = $data;
            elseif ($current_total_minutes < $start_total) $not_started_yet[] = $data;
            else $finished[] = $data;

            $added_employee_ids[$emp['id']] = true;
        }
    }
}

// SIRALAMA

// Yeni: birim önceliklendirme fonksiyonu (istediğiniz sıra)
function birim_priority($birim) {
    $map = [
        'top inspector' => 1,
        'inspector' => 2,
        'dealer-inspector-1' => 3,
        'dealer-inspector-2' => 4,
        'dealer' => 5,
        'dealer-1' => 6,
        'training dealer' => 7
    ];

    $b = strtolower(trim((string)$birim));
    // normalize bazı ayraçları ve fazla boşlukları
    $b = str_replace(['_', '–', '—', '/', '\\'], '-', $b);
    $b = preg_replace('/\s+/', ' ', $b);

    // yaygın yazım varyantları / hataları eşleştirme (lowercase keys)
    $variants = [
        'dealer instpector 1' => 'dealer-inspector-1',
        'dealer instpector 2' => 'dealer-inspector-2',
        'dealer-instcriptor 1' => 'dealer-inspector-1',
        'dealer-instcriptor 2' => 'dealer-inspector-2',
        'dealer inspector 1' => 'dealer-inspector-1',
        'dealer inspector 2' => 'dealer-inspector-2',
        'dealer1' => 'dealer-1',
        'dealer-1' => 'dealer-1',
        'top-inspector' => 'top inspector',
        'top inspector ' => 'top inspector'
    ];

    if (isset($variants[$b])) $b = $variants[$b];

    return $map[$b] ?? 999; // bilinmeyen birimler en sona
}

// Yeni yardımcı: birim değerinden CSS sınıfı üret (ör. "ATTENDANT-1" -> "unit-attendant-1")
function birim_css_class($birim) {
    $b = strtolower(trim((string)$birim));
    if ($b === '') return '';
    // replace various separators with hyphen and remove invalid chars
    $b = str_replace([' ', '_', '–', '—', '/', '\\'], '-', $b);
    $b = preg_replace('/[^a-z0-9\-]/', '', $b);
    $b = preg_replace('/-+/', '-', $b);
    $b = trim($b, '-');
    if ($b === '') return '';
    return 'unit-' . $b;
}

usort($not_started_yet, function($a, $b) {
    $pa = birim_priority($a['birim'] ?? '');
    $pb = birim_priority($b['birim'] ?? '');
    if ($pa !== $pb) return $pa - $pb;

    // öncelik aynıysa mevcut davranış: shift start time
    $sa = ($a['shift_info']['start_hour']*60 + $a['shift_info']['start_minute']);
    $sb = ($b['shift_info']['start_hour']*60 + $b['shift_info']['start_minute']);
    return $sa - $sb;
});

usort($finished, function($a, $b) {
    $pa = birim_priority($a['birim'] ?? '');
    $pb = birim_priority($b['birim'] ?? '');
    if ($pa !== $pb) return $pa - $pb;

    $sa = ($a['shift_info']['start_hour']*60 + $a['shift_info']['start_minute']);
    $sb = ($b['shift_info']['start_hour']*60 + $b['shift_info']['start_minute']);
    return $sa - $sb;
});

usort($working_now, function($a, $b) {
    $pa = birim_priority($a['birim'] ?? '');
    $pb = birim_priority($b['birim'] ?? '');
    if ($pa !== $pb) return $pa - $pb;

    // aynı öncelikse önce vardiya başlangıcı sonra isim
    $startA = $a['shift_info']['start_hour']*60 + $a['shift_info']['start_minute'];
    $startB = $b['shift_info']['start_hour']*60 + $b['shift_info']['start_minute'];
    if ($startA !== $startB) return $startA - $startB;
    return strcmp($a['name'], $b['name']);
});

// Manuel personeller için de 'birim' çekilsin
$manual_employees = $pdo->query("SELECT id, name, birim FROM employees WHERE is_active = 1 AND (external_id IS NULL OR external_id = '') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$areas = $pdo->query("SELECT id, name, color FROM areas ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Manuel personelleri de aynı öncelik ile sırala
usort($manual_employees, function($a, $b) {
    $pa = birim_priority($a['birim'] ?? '');
    $pb = birim_priority($b['birim'] ?? '');
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['name'], $b['name']);
});
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Yönetim Paneli - Breaklist</title>
<style>
/* =============== TAM 25 SATIR + RENK ŞERİDİ DÜZELTİLMİŞ CSS =============== */
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:#f5f7fa; color:#2c3e50; }
.container { max-width:2500px; margin:0 auto; padding:15px 10px; }

.topbar {
    position: -webkit-sticky;
    position: sticky;
    top: 0;
    z-index: 1200;
    background: rgba(255,255,255,0.98);
    backdrop-filter: blur(4px);
    padding: 8px 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.topbar .inner {
    padding: 6px 0;
}

header { background:white; padding:15px 20px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1); margin-bottom:10px; }
header h1 { margin-bottom:8px; color:#2c3e50; font-size:24px; }

nav { display:flex; gap:12px; flex-wrap:wrap; }
nav a { text-decoration:none; color:#7f8c8d; padding:6px 12px; border-radius:5px; transition:all 0.3s; font-weight:500; font-size:12.5px; }
nav a:hover, nav a.active { background:#3498db; color:white; }

main { background:white; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); padding:12px; }

.controls { margin-bottom:10px; display:flex; flex-direction:column; gap:8px; align-items:stretch; padding:8px; background:white; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,0.08); }

.controls-top, .controls-bottom {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}
.controls-bottom { margin-top:4px; }

.hidden-sync { display:none !important; }

.btn { padding:7px 14px; border:none; border-radius:5px; cursor:pointer; font-size:12.5px; font-weight:600; transition:all 0.2s; display:inline-flex; align-items:center; gap:6px; }
.btn-primary { background:#28a745; color:white; }
.btn-primary:hover { background:#218838; transform:translateY(-1px); box-shadow:0 3px 6px rgba(40,167,69,0.3); }
.btn-danger { background:#dc3545; color:white; }
.btn-danger:hover { background:#c82333; transform:translateY(-1px); box-shadow:0 3px 6px rgba(220,53,69,0.3); }

.grid-container { 
    border:1px solid #dee2e6; 
    border-radius:8px; 
    margin-top:10px; 
    padding-bottom:12px;
}

/* added start column (--start-col) between unit and time slots */
.grid-header { 
    display:grid; 
    grid-template-columns: var(--employee-col,172px) var(--unit-col,148px) var(--start-col,96px) repeat(var(--slots,13), minmax(52px,1fr)); 
    background:#e9ecef; 
    border-bottom:2px solid #dee2e6; 
    position:sticky; 
    top: var(--topbar-height, 120px);
    z-index:10; 
    min-height: 44px;
}
.header-cell { 
    padding:4px 5px; 
    font-weight:700; 
    color:#212529; 
    text-align:center; 
    border-right:1px solid #ced4da; 
    font-size:10.5px; 
    line-height:1.1;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
}
.employee-header { 
    text-align:left; 
    background:#f8f9fa; 
    border-right:2px solid #dee2e6; 
    position:sticky; 
    left:0; 
    z-index:12; 
    box-shadow:2px 0 5px rgba(0,0,0,0.05); 
    padding:6px 8px; 
    min-height: 44px;
    font-size:12px;
    cursor: pointer;
}
.unit-header {
    text-align:left;
    background:#fbfbfb;
    border-right:2px solid #dee2e6;
    position:sticky;
    left:var(--employee-col,172px);
    z-index:11;
    padding:6px 8px;
    min-height:44px;
    font-size:12px;
    cursor: pointer;
}
/* start header (shift start) - sticky next to unit */
.start-header {
    text-align:left;
    background:#fbfbfb;
    border-right:2px solid #dee2e6;
    position:sticky;
    left:calc(var(--employee-col,172px) + var(--unit-col,148px));
    z-index:11;
    padding:6px 8px;
    min-height:44px;
    font-size:12px;
    cursor: pointer;
}

/* grid rows updated too */
.grid-body { display:flex; flex-direction:column; gap:1px; }
.grid-row { 
    display:grid; 
    grid-template-columns: var(--employee-col,172px) var(--unit-col,148px) var(--start-col,96px) repeat(var(--slots,13), minmax(52px,1fr)); 
    background:white; 
    transition:background-color 0.15s; 
    min-height: 32px; 
    height: 32px; 
}
.grid-row:hover { background:#f8f9fa; }

/* STRONG VISIBLE ROW HIGHLIGHT
   The selected row will have a clear full-row background color that overrides per-cell gradients.
   Use !important to ensure the highlight is visible even for specially colored unit rows.
*/
.grid-row.selected {
    background: #bfeaff !important; /* visible blue tint */
    color: #023045 !important;
    box-shadow: inset 6px 0 0 0 #0ea5e9 !important; /* left accent */
}
.grid-row.selected .employee-cell,
.grid-row.selected .unit-cell,
.grid-row.selected .start-cell,
.grid-row.selected .time-cell {
    background: #bfeaff !important;
    color: #023045 !important;
    /* Remove subtle inner shadows that might conflict */
    box-shadow: none !important;
}

/* Keep selects' own background (area color) visible, but make sure text is readable on the selected row */
.grid-row.selected .area-select {
    color: #023045 !important;
    /* do not override background-color of the select which indicates area; keep it */
}

/* employee-cell */
.employee-cell { 
    padding:2px 6px; 
    font-weight:600; 
    color:#2c3e50; 
    background:linear-gradient(90deg,#f8f9fa 0%,#e9ecef 100%); 
    border-right:2px solid #dee2e6; 
    position:sticky; 
    left:0; 
    z-index:13; 
    box-shadow:2px 0 5px rgba(0,0,0,0.03); 
    display:flex; 
    flex-direction:column; 
    justify-content:center; 
    min-height: 32px;
    height: 32px;
    font-size:11px;
    overflow:hidden;
}
.employee-cell div:first-child {
    font-weight:700 !important;
    font-size:10.5px !important; 
    line-height:1.05 !important; 
    margin-bottom:1px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-height: 14px;
}
.employee-cell div.vardiya {
    font-size:8.8px !important; 
    margin-top:0 !important; 
    letter-spacing:0.2px !important;
    font-weight:600 !important;
    line-height:1 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-height: 12px;
}

/* unit column */
.unit-cell {
    padding:2px 8px;
    font-weight:600;
    color:#495057;
    background:linear-gradient(90deg,#ffffff 0%,#f8f9fa 100%);
    border-right:2px solid #dee2e6;
    position:sticky;
    left:var(--employee-col,172px);
    z-index:12;
    display:flex;
    align-items:center;
    min-height:32px;
    height:32px;
    font-size:11px;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}

/* new start column cell - sticky to the right of unit column */
.start-cell {
    padding:2px 8px;
    font-weight:600;
    color:#495057;
    background:linear-gradient(90deg,#ffffff 0%,#fbfbfb 100%);
    border-right:2px solid #dee2e6;
    position:sticky;
    left:calc(var(--employee-col,172px) + var(--unit-col,148px));
    z-index:11;
    display:flex;
    align-items:center;
    min-height:32px;
    height:32px;
    font-size:11px;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}

/* time cell and others unchanged */
.time-cell { 
    padding:1px 2px; 
    border-right:1px solid #e9ecef; 
    display:flex; 
    align-items:center; 
    justify-content:center; 
    position:relative; 
    min-height: 32px;
    height: 32px;
    overflow:hidden;
    flex-direction:column;
}
.time-cell.current-slot-cell { 
    background:linear-gradient(90deg, rgba(14,165,233,0.06), rgba(3,105,161,0.03)); 
}

.area-select { 
    width:100%; 
    padding:1px 3px !important;
    border:1px solid #dee2e6; 
    border-radius:3px; 
    font-size:9.5px !important; 
    background:white; 
    color:#2c3e50; 
    cursor:pointer; 
    transition:all 0.15s; 
    height: 28px !important; 
    line-height: 1 !important;
    -webkit-appearance: none; 
    -moz-appearance: none; 
    appearance: none; 
    margin:0;
    box-sizing:border-box;
}
.area-select[disabled] { opacity:0.6; cursor:not-allowed; }
.area-select:hover { border-color:#3498db; }
.area-select:focus { 
    outline:none; 
    border-color:#3498db; 
    box-shadow:0 0 0 2px rgba(52,152,219,0.2); 
}
.area-select.current-select { 
    border:2px solid #0ea5e9; 
    box-shadow:0 0 0 3px rgba(14,165,233,0.12); 
}
.area-select.changed { 
    outline: 2px dashed rgba(255,193,7,0.5); 
}
.area-select.editing { box-shadow: 0 0 0 3px rgba(59,130,246,0.12); border-color:#3b82f6; }

.edit-column-btn {
    margin-top:6px;
    font-size:11px;
    padding:4px 8px;
    border-radius:6px;
    border:1px solid #cbd5e0;
    background:white;
    cursor:pointer;
    color:#2d3748;
    transition:all .12s;
}
.edit-column-btn:hover { background:#f8fafc; border-color:#94a3b8; transform:translateY(-1px); }
.edit-column-btn.active { background:#3b82f6; color:white; border-color:#3b82f6; }

.clear-column-btn {
    margin-top:6px;
    font-size:11px;
    padding:4px 8px;
    border-radius:6px;
    border:1px solid #cbd5e0;
    background:linear-gradient(135deg,#ffffff 0%,#f1f5f9 100%);
    cursor:pointer;
    color:#2d3748;
    transition:all .12s;
}
.clear-column-btn:hover { background:#fff1f0; border-color:#f5c6cb; transform:translateY(-1px); color:#721c24; box-shadow:0 4px 10px rgba(220,53,69,0.08); }

.select-wrap { width:100%; display:flex; flex-direction:column; align-items:stretch; }

.name-link { color:#007bff; text-decoration:none; cursor:pointer; }
.name-link:hover { text-decoration:underline; }
.modal { display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); }
.modal-content { background-color:#fff; margin:5% auto; padding:20px; border-radius:8px; width:90%; max-width:420px; box-shadow:0 4px 20px rgba(0,0,0,0.15); position:relative; }
.close { color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer; }
.close:hover { color:#000; }
.personel-img { width:100%; max-width:340px; height:auto; border-radius:4px; margin-top:10px; border:1px solid #ddd; }
.photo-info { margin-top:12px; padding:10px; background:#f8f9fa; border-radius:4px; font-size:13px; }
.photo-placeholder { width:300px; height:300px; background:#f0f0f0; display:flex; align-items:center; justify-content:center; color:#666; border-radius:4px; }
.loading { color:#007bff; }
.error-msg { color:#dc3545; }

.time-header .badge { 
    font-size:7.5px; 
    padding:1px 4px; 
    border-radius:8px; 
    line-height:1;
}
.time-header.current-slot .badge { 
    font-size:7px; 
    padding:0 3px; 
}

/* button styles / dropdowns / modals still unchanged */
.btn-success { background:linear-gradient(135deg,#28a745 0%,#218838 100%); color:white; }
.btn-success:hover { background:linear-gradient(135deg,#218838 0%,#1e7e34 100%); transform:translateY(-1px); box-shadow:0 4px 8px rgba(40,167,69,0.4); }
.btn-info { background:linear-gradient(135deg,#1e88e5 0%,#0d47a1 100%); color:white; }
.btn-info:hover { background:linear-gradient(135deg,#1565c0 0%,#0a2463 100%); transform:translateY(-1px); box-shadow:0 4px 8px rgba(23,105,223,0.4); }
.btn-note-edit { background:linear-gradient(135deg,#8b5cf6 0%,#7c3aed 100%); color:white; }
.btn-note-edit:hover { background:linear-gradient(135deg,#7c3aed 0%,#6d28d9 100%); transform:translateY(-1px); box-shadow:0 4px 8px rgba(124,58,237,0.4); }
.btn-nav { background:linear-gradient(135deg,#4b5563 0%,#374151 100%); padding:7px 12px; font-size:12px; color:white; text-decoration:none; border-radius:5px; }
.btn-nav:hover { background:linear-gradient(135deg,#374151 0%,#1f2937 100%); }
.btn-nav.active { background:linear-gradient(135deg,#1e88e5 0%,#0d47a1 100%); box-shadow:0 0 0 2px rgba(30,136,229,0.4); }

.stat-box { position:relative; cursor:default; transition:all .2s; overflow:visible; background:white; border-radius:8px; padding:8px; box-shadow:0 2px 6px rgba(0,0,0,0.08); }
.stat-box:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:1000; }
.dropdown-arrow { font-size:9px; margin-left:3px; opacity:.7; transition:transform .3s; }
.stat-box:hover .dropdown-arrow { transform:rotate(180deg); opacity:1; }
.dropdown-list { display:none; position:absolute; top:100%; left:0; right:0; background:white; border-radius:0 0 8px 8px; box-shadow:0 8px 25px rgba(0,0,0,0.25); z-index:2000; margin-top:-2px; padding:5px; max-height:260px; min-width:200px; overflow-y:auto; animation:slideDown .2s; font-size:11.5px; line-height:1.25; border:1px solid #e2e8f0; border-top:none; }
.stat-box:hover .dropdown-list { display:block; }
@keyframes slideDown { from { opacity:0; transform:translateY(-5px); } to { opacity:1; transform:translateY(0); } }
.employee-item { padding:3px 6px; border-bottom:1px solid #f1f3f5; font-size:11px; min-height:18px; display:flex; flex-direction:column; justify-content:center; }
.employee-item:last-child { border-bottom:none; padding-bottom:3px; }
.employee-item strong { display:block; color:#2d3748; font-weight:600; font-size:11px; line-height:1.15; margin-bottom:1px; }
.employee-item span { color:#4a5568; font-size:10px !important; font-family:'Segoe UI',Arial,sans-serif !important; letter-spacing:-.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dropdown-footer { padding:5px 7px 3px 7px; text-align:center; font-weight:700; color:#4a5568; border-top:2px solid #e2e8f0; margin-top:3px; font-size:11px; background:#f8fafc; border-radius:0 0 6px 6px; }
.dropdown-list::-webkit-scrollbar { width:4px; }
.dropdown-list::-webkit-scrollbar-track { background:#f7fafc; border-radius:10px; }
.dropdown-list::-webkit-scrollbar-thumb { background:#cbd5e0; border-radius:10px; }
.dropdown-list::-webkit-scrollbar-thumb:hover { background:#a0aec0; }

#noteModal { display:none; position:fixed; z-index:3000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,.7); backdrop-filter:blur(5px); }
#noteModalContent { background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); margin:8% auto; padding:20px; border-radius:12px; width:90%; max-width:600px; box-shadow:0 10px 40px rgba(0,0,0,.5); border:1px solid #334155; position:relative; animation:modalSlideIn .3s; }
@keyframes modalSlideIn { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
#noteModal .close { position:absolute; right:12px; top:12px; font-size:24px; color:#94a5568; cursor:pointer; transition:color .2s; }
#noteModal .close:hover { color:#f87171; }
#noteModal h2 { color:#60a5fa; margin-bottom:12px; font-size:20px; display:flex; align-items:center; gap:8px; }
#noteModal h2::before { content:'📝'; font-size:20px; }
#noteTextarea { width:100%; padding:10px 12px; border:2px solid #334155; border-radius:8px; background:rgba(30,41,59,.8); color:#e2e8f0; font-size:14px; line-height:1.4; resize:vertical; min-height:90px; max-height:280px; font-family:'Segoe UI',Arial,sans-serif; transition:border-color .3s; }
#noteTextarea:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.2); }
.note-modal-buttons { display:flex; gap:10px; margin-top:16px; justify-content:flex-end; }
.note-modal-buttons button { padding:8px 16px; border:none; border-radius:6px; font-weight:600; cursor:pointer; font-size:14px; display:flex; align-items:center; gap:5px; transition:all .2s; }
.note-modal-buttons .save-btn { background:linear-gradient(135deg,#10b981 0%,#0ca678 100%); color:white; }
.note-modal-buttons .save-btn:hover { background:linear-gradient(135deg,#0da271 0%,#0a8a66 100%); transform:translateY(-1px); box-shadow:0 4px 12px rgba(16,185,129,.4); }
.note-modal-buttons .cancel-btn { background:#4b5563; color:white; }
.note-modal-buttons .cancel-btn:hover { background:#374151; transform:translateY(-1px); }
.note-modal-buttons .delete-btn { background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%); color:white; margin-right:auto; }
.note-modal-buttons .delete-btn:hover { background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%); transform:translateY(-1px); box-shadow:0 4px 12px rgba(239,68,68,.4); }

#saveStatus { margin:0; padding:6px 12px; border-radius:6px; font-weight:600; min-width:160px; text-align:center; font-size:13px; }
.current-time-display { margin-left:auto !important; padding:6px 12px; background:#e9ecef; border-radius:8px; font-weight:700; font-size:14px; box-shadow:0 2px 6px rgba(0,0,0,.1); }

...
/* (CSS and the rest of HTML/JS unchanged from original file for brevity) */
</style>
</head>
<body>
<div class="container">

  <!-- TOPBAR: header + note + controls + stats (sticky) -->
  <div class="topbar" role="region" aria-label="Üst Kontroller">
    <div class="inner">
      <header>
        <h1>🎯 Breaklist Yönetim Paneli</h1>
      </header>

      <?php if (!empty($display_note)): ?>
      <div style="background:rgba(59,130,246,.12);color:#cbd5e1;padding:4px 10px 4px 30px;border-radius:6px;margin:6px 0 6px 0;font-size:12.5px;line-height:1.35;border-left:3px solid #3b82f6;box-shadow:0 1px 4px rgba(0,0,0,0.08);word-wrap:break-word;max-width:100%;position:relative;">
        <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);font-size:13px;opacity:.9;">📢</span>
        <?= nl2br(htmlspecialchars($display_note)) ?>
      </div>
      <?php endif; ?>

      <div class="controls" style="align-items:stretch;">
        <div class="controls-top">
          <a href="sync_employees.php" class="btn btn-success hidden-sync" aria-hidden="true">🔄 Senkronize Et</a>

          <a href="employees.php" class="btn btn-nav">👥 Çalışanlar</a>
          <a href="employee_history.php" class="btn btn-nav">📊 Vardiya Geçmişi</a>
          <a href="../display/" target="_blank" class="btn btn-nav">📺 Takip Ekranı</a>

          <span id="saveStatus"></span>

          <!-- GÖRÜNTÜLENEN GÜN VE GEÇİŞ BUTONLARI -->
          <div style="display:flex; gap:6px; align-items:center;">
            <form method="get" style="display:inline;">
              <input type="hidden" name="day_offset" value="<?= htmlspecialchars($day_offset - 1, ENT_QUOTES) ?>">
              <button type="submit" class="btn btn-nav" title="Önceki gün">⬅️ Geri</button>
            </form>
            <form method="get" style="display:inline;">
              <input type="hidden" name="day_offset" value="0">
              <button type="submit" class="btn btn-primary" title="Bugünü göster">📅 Bugün</button>
            </form>
            <form method="get" style="display:inline;">
              <input type="hidden" name="day_offset" value="<?= htmlspecialchars($day_offset + 1, ENT_QUOTES) ?>">
              <button type="submit" class="btn btn-nav" title="Sonraki gün">➡️ İleri</button>
            </form>
            <div style="padding:6px 10px;background:#f1f5f9;border-radius:6px;margin-left:8px;font-weight:700;">
                Görüntülenen Gün: <?= $view_date->format('Y-m-d') ?>
            </div>
          </div>

          <div class="current-time-display">🕐 <strong id="currentTimeDisplay"><?= $current_time->format('H:i:s') ?></strong></div>
        </div>

        <div class="controls-bottom" aria-label="Alt Kontroller">
          <button onclick="clearAll()" class="btn btn-danger" title="+20 dakikadaki atamaları temizler">🗑️ +20'yi Temizle</button>
          <button id="assignSmileBtn" class="btn btn-info" title="+20 dakikadaki boşlara ':)' atar">🙂 Break +20</button>

          <a href="index.php" class="btn btn-nav active">🔄 Yenile</a>

          <button onclick="openNoteModal()" class="btn btn-note-edit">📝 Not Düzenle</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:8px 0 6px 0;">
        <div class="stat-box" style="padding:8px;background:#d4edda;border-left:3px solid #28a745;position:relative;">
          <div>
            <div style="font-size:18px;font-weight:700;color:#155724;"><?= count($working_now) ?></div>
            <div style="color:#155724;font-size:12px;">
              Şu An Çalışan
              <?php if (!empty($working_now)): ?><span class="dropdown-arrow">▼</span><?php endif; ?>
            </div>
          </div>

          <?php if (!empty($working_now)): ?>
          <div class="dropdown-list" aria-label="Şu An Çalışanlar">
            <?php foreach ($working_now as $emp): ?>
            <div class="employee-item">
              <strong><?= htmlspecialchars($emp['name']) ?></strong>
              <span><?= htmlspecialchars($emp['vardiya_kod']) ?> • <?= sprintf('%02d:%02d', $emp['shift_info']['start_hour'], $emp['shift_info']['start_minute']) ?>-<?= sprintf('%02d:%02d', $emp['shift_info']['end_hour'], $emp['shift_info']['end_minute']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="dropdown-footer">Toplam: <?= count($working_now) ?> kişi</div>
          </div>
          <?php endif; ?>
        </div>

        <div class="stat-box" style="background:#fff3cd;border-left:3px solid #ffc107;position:relative;padding:8px;">
          <div>
            <div style="font-size:18px;font-weight:700;color:#856404;"><?= count($not_started_yet) ?></div>
            <div style="color:#856404;font-size:12px;">
              Mesai Başlayacak
              <?php if (!empty($not_started_yet)): ?><span class="dropdown-arrow">▼</span><?php endif; ?>
            </div>
          </div>
          <?php if (!empty($not_started_yet)): ?>
          <div class="dropdown-list">
            <?php foreach ($not_started_yet as $emp): ?>
            <div class="employee-item">
              <strong><?= htmlspecialchars($emp['name']) ?></strong>
              <span><?= htmlspecialchars($emp['vardiya_kod']) ?> • <?= sprintf('%02d:%02d', $emp['shift_info']['start_hour'], $emp['shift_info']['start_minute']) ?>-
              <?= sprintf('%02d:%02d', $emp['shift_info']['end_hour'], $emp['shift_info']['end_minute']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="dropdown-footer">Toplam: <?= count($not_started_yet) ?> kişi</div>
          </div>
          <?php endif; ?>
        </div>

        <div class="stat-box" style="background:#f8d7da;border-left:3px solid #dc3545;position:relative;padding:8px;">
          <div>
            <div style="font-size:18px;font-weight:700;color:#721c24;"><?= count($finished) ?></div>
            <div style="color:#721c24;font-size:12px;">
              Mesai Bitmiş
              <?php if (!empty($finished)): ?><span class="dropdown-arrow">▼</span><?php endif; ?>
            </div>
          </div>
          <?php if (!empty($finished)): ?>
          <div class="dropdown-list">
            <?php foreach ($finished as $emp): ?>
            <div class="employee-item">
              <strong><?= htmlspecialchars($emp['name']) ?></strong>
              <span><?= htmlspecialchars($emp['vardiya_kod']) ?> • <?= sprintf('%02d:%02d', $emp['shift_info']['start_hour'], $emp['shift_info']['start_minute']) ?>-
              <?= sprintf('%02d:%02d', $emp['shift_info']['end_hour'], $emp['shift_info']['end_minute']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="dropdown-footer">Toplam: <?= count($finished) ?> kişi</div>
          </div>
          <?php endif; ?>
        </div>

      </div>

    </div>
  </div>
  <!-- /TOPBAR -->

<main>
<?php
$slot_duration = 20 * 60;
// NOTE: burada $now değeri artık seçilen view_date zamanına göre alınır
$now = $current_time->getTimestamp();
$current_slot_start = floor($now / $slot_duration) * $slot_duration;
$time_slots = 9;
$current_index = 2;
?>
<!-- set CSS vars for employee, unit and start column widths -->
<div class="grid-container" style="--slots:<?= $time_slots ?>; --employee-col:172px; --unit-col:148px; --start-col:96px;">
  <div class="grid-header">
    <div class="header-cell employee-header" id="hdrPersonel">PERSONEL</div>
    <div class="header-cell unit-header" id="hdrBirim">BİRİM</div>
    <div class="header-cell start-header" id="hdrBaslama">BAŞ. SAAT</div>
    <?php
    for ($i = 0; $i < $time_slots; $i++):
        $offset = $i - $current_index;
        $slot_start = $current_slot_start + ($offset * $slot_duration);
        $slot_end = $slot_start + $slot_duration;
        $start_time = date('H:i', $slot_start);
        $end_time = date('H:i', $slot_end);
        $is_current = ($i === $current_index);
        $is_past = ($slot_start < $now && !$is_current);
    ?>
    <div class="header-cell time-header<?= $is_current ? ' current-slot' : '' ?>" data-slot="<?= $slot_start ?>">
        <span><?= $start_time ?>-<?= $end_time ?></span>
        <?php if ($is_current): ?>
            <span class="badge">🔵</span>
        <?php endif; ?>

        <?php if ($is_past): ?>
            <button class="edit-column-btn" type="button" data-slot="<?= $slot_start ?>">✏️ Düzenle</button>
        <?php else: ?>
            <button class="clear-column-btn" type="button" data-slot="<?= $slot_start ?>">🧹 Sütunu Temizle</button>
        <?php endif; ?>
    </div>
    <?php endfor; ?>
  </div>

  <div class="grid-body" id="gridBody">
    <?php foreach ($working_now as $employee):
        $start_minutes = ($employee['shift_info']['start_hour']*60 + $employee['shift_info']['start_minute']);
        $row_unit_class = birim_css_class($employee['birim'] ?? '');
    ?>
    <div class="grid-row <?= htmlspecialchars($row_unit_class) ?>" data-employee-id="<?= htmlspecialchars($employee['id'], ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($employee['name'], ENT_QUOTES) ?>" data-start="<?= $start_minutes ?>" data-birim="<?= htmlspecialchars($employee['birim'] ?? '', ENT_QUOTES) ?>" tabindex="0" role="row" aria-selected="false">
      <!-- İsim sütunu -->
      <div class="employee-cell" style="background:linear-gradient(90deg,#d4edda 0%,#e9ecef 100%);">
          <div>
            <?php if (!empty($employee['external_id'])): ?>
                <a href="#" class="name-link" data-personel-id="<?= htmlspecialchars($employee['external_id'], ENT_QUOTES) ?>" data-ad-soyad="<?= htmlspecialchars($employee['name'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($employee['name']) ?>
                </a>
            <?php else: ?>
                <?= htmlspecialchars($employee['name']) ?>
            <?php endif; ?>
          </div>
          <div class="vardiya"><?= htmlspecialchars($employee['vardiya_kod']) ?> • <?= sprintf('%02d:%02d', $employee['shift_info']['start_hour'], $employee['shift_info']['start_minute']) ?>-<?= sprintf('%02d:%02d', $employee['shift_info']['end_hour'], $employee['shift_info']['end_minute']) ?></div>
      </div>

      <!-- Birim sütunu -->
      <div class="unit-cell"><?= htmlspecialchars($employee['birim'] ?? '') ?></div>

      <!-- Başlangıç saati sütunu (yeni) -->
      <div class="start-cell"><?= sprintf('%02d:%02d', $employee['shift_info']['start_hour'], $employee['shift_info']['start_minute']) ?></div>

      <?php for ($i = 0; $i < $time_slots; $i++):
          $offset = $i - $current_index;
          $slot_start_time = $current_slot_start + ($offset * $slot_duration);
          $stmt = $pdo->prepare("SELECT a.id AS area_id, a.color FROM work_slots ws JOIN areas a ON ws.area_id = a.id WHERE ws.employee_id = ? AND ws.slot_start = FROM_UNIXTIME(?)");
          $stmt->execute([$employee['id'], $slot_start_time]);
          $current_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
          $current_area_id = $current_assignment['area_id'] ?? '';
          $current_color = $current_assignment['color'] ?? '#e9ecef';
          $is_current = ($i === $current_index);

          $is_past_slot = ($slot_start_time < $now && !$is_current);
      ?>
      <div class="time-cell<?= $is_current ? ' current-slot-cell' : '' ?>">
          <div class="select-wrap">
              <select class="area-select auto-save<?= $is_current ? ' current-select' : '' ?>" 
                      data-employee-id="<?= $employee['id'] ?>" 
                      data-slot-time="<?= $slot_start_time ?>" 
                      data-current-area-id="<?= $current_area_id ?>" 
                      onchange="handleAreaChange(this)" 
                      aria-label="Atama <?= htmlspecialchars($employee['name']) ?> <?= date('H:i', $slot_start_time) ?>"
                      style="background-color: <?= $current_area_id ? $current_color : '#fff' ?>;"
                      <?= $is_past_slot ? 'disabled' : '' ?>>
                  <option value="">-</option>
                  <?php foreach ($areas as $area): ?>
                  <option value="<?= $area['id'] ?>" data-color="<?= $area['color'] ?>" <?= $area['id'] == $current_area_id ? 'selected' : '' ?>><?= htmlspecialchars($area['name']) ?></option>
                  <?php endforeach; ?>
              </select>
          </div>
      </div>
      <?php endfor; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($manual_employees)): ?>
    <div id="manualSeparator" style="grid-column:1/-1;padding:6px;background:#f8f9fa;font-weight:600;color:#495057;border-top:2px solid #dee2e6;border-bottom:2px solid #dee2e6;font-size:11.5px;">📝 Ekstra Eklenen Personeller</div>
    <?php foreach ($manual_employees as $employee): 
        $row_unit_class = birim_css_class($employee['birim'] ?? '');
    ?>
    <div class="grid-row <?= htmlspecialchars($row_unit_class) ?>" data-employee-id="<?= htmlspecialchars($employee['id'], ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($employee['name'], ENT_QUOTES) ?>" data-start="99999" data-birim="<?= htmlspecialchars($employee['birim'] ?? '', ENT_QUOTES) ?>" tabindex="0" role="row" aria-selected="false">
      <!-- Manuel: isim sütunu -->
      <div class="employee-cell" style="background:linear-gradient(90deg,#d1ecf1 0%,#f8f9fa 100%);">
          <div><?= htmlspecialchars($employee['name']) ?></div>
          <div class="vardiya">Ekstra</div>
      </div>

      <!-- Manuel: birim sütunu -->
      <div class="unit-cell"><?= htmlspecialchars($employee['birim'] ?? 'Manuel') ?></div>

      <!-- Manuel: Başlangıç saati bilinmiyorsa '-' -->
      <div class="start-cell">-</div>

      <?php for ($i = 0; $i < $time_slots; $i++): 
          $offset = $i - $current_index;
          $slot_start_time = $current_slot_start + ($offset * $slot_duration);
          $stmt = $pdo->prepare("SELECT a.id AS area_id, a.color FROM work_slots ws JOIN areas a ON ws.area_id = a.id WHERE ws.employee_id = ? AND ws.slot_start = FROM_UNIXTIME(?)");
          $stmt->execute([$employee['id'], $slot_start_time]);
          $current_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
          $current_area_id = $current_assignment['area_id'] ?? '';
          $current_color = $current_assignment['color'] ?? '#e9ecef';
          $is_current = ($i === $current_index);
          $is_past_slot = ($slot_start_time < $now && !$is_current);
      ?>
      <div class="time-cell<?= $is_current ? ' current-slot-cell' : '' ?>">
          <div class="select-wrap">
              <select class="area-select auto-save<?= $is_current ? ' current-select' : '' ?>" 
                      data-employee-id="<?= $employee['id'] ?>" 
                      data-slot-time="<?= $slot_start_time ?>" 
                      data-current-area-id="<?= $current_area_id ?>" 
                      onchange="handleAreaChange(this)" 
                      aria-label="Atama <?= htmlspecialchars($employee['name']) ?> <?= date('H:i', $slot_start_time) ?>"
                      style="background-color: <?= $current_area_id ? $current_color : '#fff' ?>;"
                      <?= $is_past_slot ? 'disabled' : '' ?>>
                  <option value="">-</option>
                  <?php foreach ($areas as $area): ?>
                  <option value="<?= $area['id'] ?>" data-color="<?= $area['color'] ?>" <?= $area['id'] == $current_area_id ? 'selected' : '' ?>><?= htmlspecialchars($area['name']) ?></option>
                  <?php endforeach; ?>
              </select>
          </div>
      </div>
      <?php endfor; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($working_now) && empty($manual_employees)): ?>
    <div style="padding:20px;text-align:center;color:#6c757d;font-size:15px;grid-column:1/-1;background:#f8d7da;">⚠️ Şu anda İK sistemine göre aktif personel bulunmamaktadır.</div>
    <?php endif; ?>
  </div>
</div>
</main>

</div>

<!-- FOTOĞRAF MODAL (isimler link olduğunda açılacak) -->
<div id="photoModal" class="modal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <span class="close" id="photoModalClose">&times;</span>
        <h3 id="modalTitle">Personel Fotoğrafı</h3>
        <div id="photoContainer">
            <div class="photo-placeholder">
                <div class="loading">Fotoğraf yükleniyor...</div>
            </div>
        </div>
        <div id="personelInfo" class="photo-info"></div>
    </div>
</div>

<!-- NOT MODAL -->
<div id="noteModal">
<div id="noteModalContent">
<span class="close" onclick="closeNoteModal()">&times;</span>
<h2>Ekran Notu Düzenle</h2>
<textarea id="noteTextarea" placeholder="Buraya notunuzu yazın..."><?= htmlspecialchars($display_note) ?></textarea>
<div class="note-modal-buttons">
<?php if (!empty($display_note)): ?><button class="delete-btn" onclick="deleteNote()">🗑️ Notu Sil</button><?php endif; ?>
<button class="cancel-btn" onclick="closeNoteModal()">❌ İptal</button>
<button class="save-btn" onclick="saveNote()">💾 Kaydet</button>
</div>
</div>
</div>

<script>
// ==================== RENK ŞERİDİ + OTOMATİK KAYIT ====================
let changedCells = new Set();
// slot duration from server-side (seconds)
const SLOT_DURATION = <?= $slot_duration ?>;

function applyAreaColor(sel, color) {
    try {
        sel.style.boxShadow = 'none';
        sel.style.borderLeft = 'none';
        sel.style.paddingLeft = '3px';
        if (color && color.trim() !== '' && color !== '#fff' && color !== 'white' && color !== 'transparent') {
            sel.style.boxShadow = `inset 4px 0 0 0 ${color}`;
            sel.style.paddingLeft = '8px';
        }
    } catch (e) {
        sel.style.boxShadow = 'none';
        sel.style.paddingLeft = '3px';
        console.error('applyAreaColor error', e);
    }
}

function markAsChanged(selectElement) {
    const cellKey = `${selectElement.dataset.employeeId}-${selectElement.dataset.slotTime}`;
    if (selectElement.value !== '') {
        selectElement.classList.add('changed');
        changedCells.add(cellKey);
    } else {
        selectElement.classList.remove('changed');
        changedCells.delete(cellKey);
    }
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const color = (selectedOption && selectedOption.dataset && selectedOption.dataset.color) ? selectedOption.dataset.color : '';
    applyAreaColor(selectElement, color);
}

/* NEW: handleAreaChange
   - Checks per-employee assignments for the chosen area.
   - If after this change the employee would have 4 or more slots assigned to the same area,
     prompts the user "Emin misiniz?".
*/
function handleAreaChange(sel) {
    // get new value
    const newArea = sel.value;
    const employeeId = sel.dataset.employeeId;
    const prevArea = sel.dataset.currentAreaId ? String(sel.dataset.currentAreaId) : '';

    // empty selection -> proceed normally (this clears)
    if (!newArea) {
        markAsChanged(sel);
        autoSaveAssignment(sel);
        // update slot options (so others see the change)
        updateOptionsForSlot(sel.dataset.slotTime);
        return;
    }

    // if selecting same as already current, no prompt (but still process)
    if (String(newArea) === String(prevArea)) {
        markAsChanged(sel);
        autoSaveAssignment(sel);
        updateOptionsForSlot(sel.dataset.slotTime);
        return;
    }

    // count how many assignments of this area exist for this employee (across all visible selects)
    const employeeSelects = Array.from(document.querySelectorAll(`.area-select[data-employee-id="${employeeId}"]`));
    let count = 0;
    employeeSelects.forEach(s => {
        // Determine effective value for that select (if user already changed some other selects, consider current DOM value)
        const val = (s === sel) ? newArea : (s.value && s.value !== '' ? String(s.value) : (s.dataset.currentAreaId ? String(s.dataset.currentAreaId) : ''));
        if (val && String(val) === String(newArea)) count++;
    });

    // If count is 4 or more, require confirmation
    if (count >= 4) {
        const confirmMsg = `Bu personele aynı alan ${count} kez atanmış olacak. Devam etmek istediğinize emin misiniz?`;
        if (!confirm(confirmMsg)) {
            // revert selection to previous (currentAreaId) and restore visuals
            sel.value = prevArea || '';
            const opt = Array.from(sel.options).find(o => String(o.value) === prevArea);
            const color = (opt && opt.dataset && opt.dataset.color) ? opt.dataset.color : '';
            applyAreaColor(sel, color);
            sel.classList.remove('changed');
            // ensure options/hiding are consistent
            updateSlotsAfterBatch([]); // will update all slots now
            updateOptionsForSlot(sel.dataset.slotTime);
            return;
        }
    }

    // proceed normally
    markAsChanged(sel);
    autoSaveAssignment(sel);
    updateOptionsForSlot(sel.dataset.slotTime);
}

/* ================== AREA SIRALAMASI ================== */
function normalizeForOrder(text) {
    if (!text) return '';
    let t = String(text).toUpperCase().trim();
    t = t.replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ş/g,'S').replace(/İ/g,'I').replace(/İ/g,'I').replace(/Ö/g,'O').replace(/Ç/g,'C');
    t = t.replace(/[_–—\/\\]+/g, '-');
    t = t.replace(/\s+/g, '-');
    t = t.replace(/[^A-Z0-9-]/g, '');
    t = t.replace(/-+/g, '-');
    t = t.replace(/^-|-$/g, '');
    return t;
}

const desiredAreaOrder = [
 'SALON 1','SALON 2','ALT SALON','VIP','VIP 2','CARD DESK 1','CARD DESK 2','CARD DESK 3', ':)','FIN','COUNT','LATE','PIT','TR'
];
const areaRankMap = (function() {
    const m = {};
    desiredAreaOrder.forEach((label, idx) => {
        const key = normalizeForOrder(label);
        if (!(key in m)) m[key] = idx;
    });
    return m;
})();

const multiAllowedLabels = ['SALON 1','SALON 2','ALT SALON','VIP','VIP 2','CARD DESK 1','CARD DESK 2','CARD DESK 3', ':)', 'FIN', 'COUNT', 'SORT', 'TC', 'LATE', 'PIT', 'TR'];
const multiAllowedRawLabels = new Set([':)']);
const multiAllowedKeys = (function() {
    const s = new Set();
    multiAllowedLabels.forEach(l => {
        const k = normalizeForOrder(l);
        if (k) s.add(k);
    });
    return s;
})();

function reorderOptionsForSelect(sel) {
    if (!sel) return;
    const placeholder = Array.from(sel.options).find(o => !o.value);
    const selectedValue = sel.value ? String(sel.value) : '';

    const opts = Array.from(sel.options).filter(o => o.value);

    const mapped = opts.map(o => {
        const label = (o.textContent || o.innerText || o.label || '').toString();
        const key = normalizeForOrder(label);
        const rank = (typeof areaRankMap[key] !== 'undefined') ? areaRankMap[key] : 9999;
        return { opt: o, rank, label };
    });

    mapped.sort((a,b) => {
        if (a.rank !== b.rank) return a.rank - b.rank;
        return a.label.localeCompare(b.label, 'tr');
    });

    while (sel.options.length > 0) sel.remove(0);
    if (placeholder) sel.appendChild(placeholder.cloneNode(true));
    mapped.forEach(m => sel.appendChild(m.opt.cloneNode(true)));

    if (selectedValue) {
        const optToSelect = Array.from(sel.options).find(o => String(o.value) === selectedValue);
        if (optToSelect) sel.value = selectedValue;
        else sel.value = '';
    } else {
        sel.value = '';
    }
}

function reorderAllSelects() {
    document.querySelectorAll('.area-select').forEach(sel => reorderOptionsForSelect(sel));
}

/* UNIQUE OPTIONS PER SLOT */
function initUniqueOptions() {
    reorderAllSelects();
    const slots = new Set(Array.from(document.querySelectorAll('.area-select')).map(s => s.dataset.slotTime));
    slots.forEach(slot => updateOptionsForSlot(slot));
}

function getSelectsBySlot(slot) {
    return Array.from(document.querySelectorAll(`.area-select[data-slot-time="${slot}"]`));
}

function updateOptionsForSlot(slot) {
    const selects = getSelectsBySlot(slot);
    if (!selects.length) return;

    const selected = new Set();
    selects.forEach(s => {
        const v = s.value;
        if (!v) return;
        const opt = Array.from(s.options).find(o => String(o.value) === String(v));
        const label = opt ? (opt.textContent || opt.innerText || '') : '';
        const key = normalizeForOrder(label);
        if (multiAllowedKeys.has(key) || multiAllowedRawLabels.has(label.trim())) {
            return;
        }
        selected.add(String(v));
    });

    selects.forEach(s => {
        const currentVal = s.value ? String(s.value) : '';
        Array.from(s.options).forEach(opt => {
            if (!opt.value) {
                opt.hidden = false;
                opt.style.display = '';
                return;
            }
            const optVal = String(opt.value);
            const label = (opt.textContent || opt.innerText || '');
            const key = normalizeForOrder(label);

            if (multiAllowedKeys.has(key) || multiAllowedRawLabels.has(label.trim())) {
                opt.hidden = false;
                opt.style.display = '';
                return;
            }

            if (selected.has(optVal) && optVal !== currentVal) {
                opt.hidden = true;
                opt.style.display = 'none';
            } else {
                opt.hidden = false;
                opt.style.display = '';
            }
        });
    });
}

/* UPDATED: updateSlotsAfterBatch updates given slots or all slots if assignments empty */
function updateSlotsAfterBatch(assignments) {
    const slots = new Set();

    if (Array.isArray(assignments) && assignments.length > 0) {
        assignments.forEach(a => {
            if (a && a.slot_time) slots.add(String(a.slot_time));
        });
    } else {
        document.querySelectorAll('.area-select').forEach(s => {
            if (s.dataset && s.dataset.slotTime) slots.add(String(s.dataset.slotTime));
        });
    }

    reorderAllSelects();

    slots.forEach(s => {
        const selects = getSelectsBySlot(s);
        selects.forEach(sel => reorderOptionsForSelect(sel));
        updateOptionsForSlot(s);
    });
}

/* helpers for +20 slot and batch actions */
function getPlus20TargetSelect() {
    const currentSel = document.querySelector('.area-select.current-select');
    if (!currentSel) return null;
    const nextSlot = String(Number(currentSel.dataset.slotTime) + SLOT_DURATION);
    return document.querySelector(`.area-select[data-slot-time="${nextSlot}"]`);
}

function assignSmileToUnassignedInCurrentSlot() {
    const targetSel = getPlus20TargetSelect();
    if (!targetSel) {
        showStatus('⚠️ +20 dakikadaki slot bulunamadı.', 'error');
        return;
    }
    const slot = targetSel.dataset.slotTime;
    let smileOption = null;
    Array.from(targetSel.options).forEach(opt => {
        if (opt.textContent.trim() === ':)') smileOption = opt;
    });
    if (!smileOption) {
        const anySel = document.querySelectorAll('.area-select');
        for (let s of anySel) {
            for (let opt of s.options) {
                if (opt.textContent.trim() === ':)') { smileOption = opt; break; }
            }
            if (smileOption) break;
        }
    }
    if (!smileOption) {
        showStatus("❌ ':)' alanı bulunamadı. Lütfen 'areas' tablosunda ':)' olarak tanımlı bir alan ekleyin.", 'error');
        return;
    }
    const areaId = smileOption.value;
    const color = smileOption.dataset.color || '';

    const selects = getSelectsBySlot(slot).filter(s => !s.disabled && (!s.value || s.value === ''));

    if (selects.length === 0) {
        showStatus('ℹ️ Bu +20 slotta atanmamış personel yok.', 'info');
        return;
    }

    const assignments = [];
    selects.forEach(sel => {
        sel.value = areaId;
        sel.dataset.currentAreaId = areaId;
        applyAreaColor(sel, color);
        const key = `${sel.dataset.employeeId}-${sel.dataset.slotTime}`;
        sel.classList.add('changed');
        changedCells.add(key);
        assignments.push({ employee_id: sel.dataset.employeeId, area_id: areaId, slot_time: sel.dataset.slotTime });
    });

    fetch('../api/batch_assign.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ assignments })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            selects.forEach(sel => {
                sel.dataset.currentAreaId = areaId;
                sel.classList.remove('changed');
                const key = `${sel.dataset.employeeId}-${sel.dataset.slotTime}`;
                changedCells.delete(key);
                const opt = Array.from(sel.options).find(o => String(o.value) === String(areaId));
                const optColor = (opt && opt.dataset && opt.dataset.color) ? opt.dataset.color : color;
                applyAreaColor(sel, optColor);
            });

            updateSlotsAfterBatch(assignments);

            showStatus(`✅ ${result.saved || assignments.length} kişi ':)' alanına atandı (+20).`, 'success');
        } else {
            showStatus(`❌ Hata: ${result.message || 'Toplu kaydetme başarısız'}`, 'error');
        }
    })
    .catch(err => {
        console.error('assignSmile error', err);
        showStatus('❌ Sunucu hatası!', 'error');
    });
}

/* SAVE / AUTO-SAVE */
function autoSaveAssignment(selectElement) {
    const employeeId = selectElement.dataset.employeeId;
    const slotTime = selectElement.dataset.slotTime;
    const areaId = selectElement.value;
    const currentAreaId = selectElement.dataset.currentAreaId;

    if (areaId === currentAreaId) return;

    selectElement.style.borderColor = '#ffc107';
    selectElement.style.boxShadow = '0 0 0 2px rgba(255, 193, 7, 0.3)';

    fetch('../api/save_single_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: employeeId, area_id: areaId, slot_time: slotTime })
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(result => {
        if (result.success) {
            selectElement.style.borderColor = '#28a745';
            selectElement.style.boxShadow = '0 0 0 2px rgba(40, 167, 69, 0.3)';

            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const color = (selectedOption && selectedOption.dataset && selectedOption.dataset.color) ? selectedOption.dataset.color : '';
            applyAreaColor(selectElement, color);

            selectElement.dataset.currentAreaId = areaId;
            const cellKey = `${employeeId}-${slotTime}`;
            selectElement.classList.remove('changed');
            changedCells.delete(cellKey);

            getSelectsBySlot(slotTime).forEach(sel => reorderOptionsForSelect(sel));
            updateOptionsForSlot(slotTime);

            showStatus('✅ Kaydedildi!', 'success');
            setTimeout(() => {
                selectElement.style.borderColor = '#dee2e6';
                selectElement.style.boxShadow = 'none';
            }, 1000);
        } else {
            selectElement.style.borderColor = '#dc3545';
            selectElement.style.boxShadow = '0 0 0 2px rgba(220, 53, 69, 0.3)';
            showStatus(`❌ ${result.message || 'Kaydetme hatası!'}`, 'error');
            setTimeout(() => {
                selectElement.style.borderColor = '#dee2e6';
                selectElement.style.boxShadow = 'none';
            }, 2000);
        }
    })
    .catch(error => {
        console.error('Otomatik kaydetme hatası:', error);
        selectElement.style.borderColor = '#dc3545';
        selectElement.style.boxShadow = '0 0 0 2px rgba(220, 53, 69, 0.3)';
        showStatus('❌ Sunucu hatası!', 'error');
        setTimeout(() => {
            selectElement.style.borderColor = '#dee2e6';
            selectElement.style.boxShadow = 'none';
        }, 2000);
    });
}

function saveAssignments() {
    const saveBtn = document.querySelector('.btn-primary');
    const statusEl = document.getElementById('saveStatus');

    if (changedCells.size === 0) {
        showStatus('ℹ️ Değişiklik yok!', 'info');
        return;
    }

    saveBtn.disabled = true;
    const originalHtml = saveBtn.innerHTML;
    saveBtn.innerHTML = '<span class="loading">💾</span> Kaydediliyor...';

    const assignments = [];
    document.querySelectorAll('.area-select.changed').forEach(select => {
        if (select.value !== '') {
            assignments.push({
                employee_id: select.dataset.employeeId,
                area_id: select.value,
                slot_time: select.dataset.slotTime
            });
        }
    });

    fetch('../api/batch_assign.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ assignments })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            document.querySelectorAll('.area-select.changed').forEach(select => {
                const areaId = select.value;
                select.dataset.currentAreaId = areaId;
                select.classList.remove('changed');

                const opt = select.options[select.selectedIndex];
                const color = (opt && opt.dataset && opt.dataset.color) ? opt.dataset.color : '';
                applyAreaColor(select, color);

                const key = `${select.dataset.employeeId}-${select.dataset.slotTime}`;
                changedCells.delete(key);
            });

            updateSlotsAfterBatch(assignments);

            showStatus(`✅ ${result.saved || assignments.length} atama kaydedildi!`, 'success');
        } else {
            showStatus(`❌ Hata: ${result.message || 'Bilinmeyen hata'}`, 'error');
        }
    })
    .catch(error => {
        console.error('Toplu kaydetme hatası:', error);
        showStatus('❌ Sunucu hatası!', 'error');
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalHtml;
    });
}

/* ================== clearAll (only +20 slot) ================== */
function clearAll() {
    const targetSel = getPlus20TargetSelect();
    if (!targetSel) {
        showStatus('⚠️ +20 dakikadaki slot bulunamadı. Lütfen önce sütunlardan birini düzenleme moduna alınız veya mevcut slotu kontrol ediniz.', 'error');
        return;
    }

    const slot = targetSel.dataset.slotTime;
    const start = new Date(Number(slot) * 1000);
    const end = new Date((Number(slot) + SLOT_DURATION) * 1000);
    const startStr = start.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', hour12: false });
    const endStr = end.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', hour12: false });

    if (!confirm(`Bu +20 slottaki (${startStr} - ${endStr}) tüm atamaları temizlemek istediğinizden emin misiniz?`)) return;

    const selects = getSelectsBySlot(slot);
    if (!selects.length) {
        showStatus('ℹ️ Bu slotta seçilebilir alan bulunamadı.', 'info');
        return;
    }

    const toClear = [];
    const prevStates = [];
    selects.forEach(select => {
        if (select.disabled) return;
        const prevValue = (select.value && select.value !== '') ? String(select.value) : (select.dataset.currentAreaId ? String(select.dataset.currentAreaId) : '');
        if (!prevValue) return;

        const prevOption = Array.from(select.options).find(o => String(o.value) === prevValue);
        const prevColor = prevOption && prevOption.dataset ? (prevOption.dataset.color || '') : '';
        prevStates.push({ sel: select, prevValue, prevColor });

        toClear.push({ employee_id: select.dataset.employeeId, area_id: null, slot_time: select.dataset.slotTime });

        const key = `${select.dataset.employeeId}-${select.dataset.slotTime}`;
        select.value = '';
        select.dataset.currentAreaId = '';
        select.classList.remove('changed');
        applyAreaColor(select, '');
        changedCells.delete(key);
    });

    if (toClear.length === 0) {
        showStatus('ℹ️ Bu +20 slotta temizlenecek atama yok.', 'info');
        return;
    }

    fetch('../api/batch_assign.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ assignments: toClear })
    })
    .then(response => {
        if (!response.ok) throw new Error('Ağ hatası');
        return response.json();
    })
    .then(result => {
        if (result && result.success) {
            updateSlotsAfterBatch(toClear);
            showStatus(`✅ ${result.processed || toClear.length} atama temizlendi ve kaydedildi (+20).`, 'success');
        } else {
            prevStates.forEach(({ sel, prevValue, prevColor }) => {
                const opt = Array.from(sel.options).find(o => String(o.value) === prevValue);
                if (opt) sel.value = prevValue;
                else sel.value = '';
                sel.dataset.currentAreaId = prevValue;
                applyAreaColor(sel, prevColor);
            });
            getSelectsBySlot(slot).forEach(s => reorderOptionsForSelect(s));
            updateOptionsForSlot(slot);

            showStatus(`❌ Sunucu hatası: ${result && result.message ? result.message : 'Toplu kaydetme başarısız'} — değişiklik geri alındı.`, 'error');
        }
    })
    .catch(err => {
        console.error('clearAll batch error:', err);
        prevStates.forEach(({ sel, prevValue, prevColor }) => {
            const opt = Array.from(sel.options).find(o => String(o.value) === prevValue);
            if (opt) sel.value = prevValue;
            else sel.value = '';
            sel.dataset.currentAreaId = prevValue;
            applyAreaColor(sel, prevColor);
        });
        getSelectsBySlot(slot).forEach(s => reorderOptionsForSelect(s));
        updateOptionsForSlot(slot);

        showStatus('❌ Sunucuya kaydetme başarısız — değişiklik geri alındı.', 'error');
    });
}

/* ================== clearColumn (current & future columns) ================== */
function clearColumn(slot) {
    const nowSec = Math.floor(Date.now() / 1000);
    const slotNum = Number(slot);

    if (slotNum + SLOT_DURATION <= nowSec && slotNum < (nowSec - SLOT_DURATION)) {
        showStatus('⚠️ Bu sütun geçmişe ait ve temizlenemez.', 'error');
        return;
    }

    const selects = getSelectsBySlot(slot);
    if (!selects.length) {
        showStatus('ℹ️ Bu sütunda seçilebilir hücre bulunamadı.', 'info');
        return;
    }

    const start = new Date(slotNum * 1000);
    const end = new Date((slotNum + SLOT_DURATION) * 1000);
    const startStr = start.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', hour12: false });
    const endStr = end.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', hour12: false });

    if (!confirm(`Bu sütundaki (${startStr} - ${endStr}) tüm atamaları temizlemek istediğinizden emin misiniz? (Sadece mevcut ve gelecekteki hücreler etkilenecektir)`)) return;

    const toClear = [];
    const prevStates = [];
    selects.forEach(select => {
        if (select.disabled) return;
        const prevValue = (select.value && select.value !== '') ? String(select.value) : (select.dataset.currentAreaId ? String(select.dataset.currentAreaId) : '');
        if (!prevValue) return;

        const prevOption = Array.from(select.options).find(o => String(o.value) === prevValue);
        const prevColor = prevOption && prevOption.dataset ? (prevOption.dataset.color || '') : '';
        prevStates.push({ sel: select, prevValue, prevColor });

        toClear.push({ employee_id: select.dataset.employeeId, area_id: null, slot_time: select.dataset.slotTime });

        const key = `${select.dataset.employeeId}-${select.dataset.slotTime}`;
        select.value = '';
        select.dataset.currentAreaId = '';
        select.classList.remove('changed');
        applyAreaColor(select, '');
        changedCells.delete(key);
    });

    if (toClear.length === 0) {
        showStatus('ℹ️ Bu sütunda temizlenecek atama bulunamadı veya hücreler kilitli.', 'info');
        return;
    }

    fetch('../api/batch_assign.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ assignments: toClear })
    })
    .then(response => {
        if (!response.ok) throw new Error('Ağ hatası');
        return response.json();
    })
    .then(result => {
        if (result && result.success) {
            updateSlotsAfterBatch(toClear);
            showStatus(`✅ ${result.processed || toClear.length} atama temizlendi ve kaydedildi.`, 'success');
        } else {
            prevStates.forEach(({ sel, prevValue, prevColor }) => {
                const opt = Array.from(sel.options).find(o => String(o.value) === prevValue);
                if (opt) sel.value = prevValue;
                else sel.value = '';
                sel.dataset.currentAreaId = prevValue;
                applyAreaColor(sel, prevColor);
            });
            getSelectsBySlot(slot).forEach(s => reorderOptionsForSelect(s));
            updateOptionsForSlot(slot);
            showStatus(`❌ Sunucu hatası: ${result && result.message ? result.message : 'Toplu kaydetme başarısız'} — değişiklik geri alındı.`, 'error');
        }
    })
    .catch(err => {
        console.error('clearColumn batch error:', err);
        prevStates.forEach(({ sel, prevValue, prevColor }) => {
            const opt = Array.from(sel.options).find(o => String(o.value) === prevValue);
            if (opt) sel.value = prevValue;
            else sel.value = '';
            sel.dataset.currentAreaId = prevValue;
            applyAreaColor(sel, prevColor);
        });
        getSelectsBySlot(slot).forEach(s => reorderOptionsForSelect(s));
        updateOptionsForSlot(slot);
        showStatus('❌ Sunucuya kaydetme başarısız — değişiklik geri alındı.', 'error');
    });
}

function showStatus(message, type) {
    const el = document.getElementById('saveStatus');
    el.textContent = message;
    el.className = type;
    el.style.background = type === 'success' ? '#d4edda' : (type === 'info' ? '#d1ecf1' : '#f8d7da');
    el.style.color = type === 'success' ? '#155724' : (type === 'info' ? '#0c5460' : '#721c24');
    el.style.border = type === 'success' ? '1px solid #c3e6cb' : (type === 'info' ? '1px solid #bee5eb' : '1px solid #f5c6cb');

    setTimeout(() => {
        if (el.textContent === message) {
            el.className = '';
            el.textContent = '';
            el.style.background = '';
            el.style.color = '';
            el.style.border = '';
        }
    }, 3000);
}

/* init buttons */
function initColumnEditButtons() {
    document.querySelectorAll('.edit-column-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const slot = btn.dataset.slot;
            const isActive = btn.classList.contains('active');

            if (isActive) {
                document.querySelectorAll(`.area-select[data-slot-time="${slot}"]`).forEach(sel => {
                    sel.disabled = true;
                    sel.classList.remove('editing');
                });
                getSelectsBySlot(slot).forEach(sel => reorderOptionsForSelect(sel));
                updateOptionsForSlot(slot);

                btn.classList.remove('active');
                btn.textContent = '✏️ Düzenle';
                return;
            }

            document.querySelectorAll('.edit-column-btn.active').forEach(activeBtn => {
                const s = activeBtn.dataset.slot;
                activeBtn.classList.remove('active');
                activeBtn.textContent = '✏️ Düzenle';
                document.querySelectorAll(`.area-select[data-slot-time="${s}"]`).forEach(sel => {
                    sel.disabled = true;
                    sel.classList.remove('editing');
                });
                getSelectsBySlot(s).forEach(sel => reorderOptionsForSelect(sel));
                updateOptionsForSlot(s);
            });

            document.querySelectorAll(`.area-select[data-slot-time="${slot}"]`).forEach(sel => {
                sel.disabled = false;
                sel.classList.add('editing');
            });

            getSelectsBySlot(slot).forEach(sel => reorderOptionsForSelect(sel));
            updateOptionsForSlot(slot);

            btn.classList.add('active');
            btn.textContent = '✖ Kapat';
        });
    });
}

function initClearButtons() {
    document.querySelectorAll('.clear-column-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const slot = btn.dataset.slot;
            clearColumn(slot);
        });
    });
}

// NOT modal functions
function openNoteModal() {
    document.getElementById('noteModal').style.display = 'block';
    document.getElementById('noteTextarea').focus();
    document.getElementById('noteTextarea').select();
}
function closeNoteModal() { document.getElementById('noteModal').style.display = 'none'; }

function saveNote() {
    const text = document.getElementById('noteTextarea').value.trim();
    const statusEl = document.getElementById('saveStatus');

    fetch('../api/save_display_note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note_text: text })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusEl.textContent = '✅ ' + data.message;
            statusEl.className = 'success';
            statusEl.style.background = '#d4edda';
            statusEl.style.color = '#155724';
            statusEl.style.border = '1px solid #c3e6cb';
            setTimeout(() => location.reload(), 1200);
        } else {
            statusEl.textContent = '❌ ' + data.message;
            statusEl.className = 'error';
            statusEl.style.background = '#f8d7da';
            statusEl.style.color = '#721c24';
            statusEl.style.border = '1px solid #f5c6cb';
        }
    })
    .catch(error => {
        statusEl.textContent = '❌ Sunucu hatası: ' + error.message;
        statusEl.className = 'error';
        statusEl.style.background = '#f8d7da';
        statusEl.style.color = '#721c24';
        statusEl.style.border = '1px solid #f5c6cb';
    });
}

function deleteNote() {
    if (!confirm('Notu silmek istediğinizden emin misiniz?')) return;
    fetch('../api/save_display_note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note_text: '' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('saveStatus').textContent = '✅ ' + data.message;
            document.getElementById('saveStatus').className = 'success';
            document.getElementById('saveStatus').style.background = '#d4edda';
            document.getElementById('saveStatus').style.color = '#155724';
            document.getElementById('saveStatus').style.border = '1px solid #c3e6cb';
            setTimeout(() => location.reload(), 1200);
        }
    });
}

window.onclick = function(event) {
    const modal = document.getElementById('noteModal');
    if (event.target === modal) closeNoteModal();
};
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') closeNoteModal();
});

/* PHOTO MODAL */
const photoModal = document.getElementById('photoModal');
const photoModalClose = document.getElementById('photoModalClose');
const photoContainer = document.getElementById('photoContainer');
const personelInfoDiv = document.getElementById('personelInfo');
const modalTitle = document.getElementById('modalTitle');

function openPhotoModal() {
    photoModal.style.display = 'block';
    photoModal.setAttribute('aria-hidden', 'false');
}
function closePhotoModal() {
    photoModal.style.display = 'none';
    photoModal.setAttribute('aria-hidden', 'true');
}
if (photoModalClose) {
    photoModalClose.addEventListener('click', closePhotoModal);
}
window.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closePhotoModal();
});
window.addEventListener('click', function(e){
    if (e.target === photoModal) closePhotoModal();
});

function showPersonelPhoto(personelID, adSoyad) {
    if (!personelID) {
        console.warn('PersonelID yok');
        return;
    }
    openPhotoModal();
    modalTitle.textContent = adSoyad || 'Personel Fotoğrafı';
    photoContainer.innerHTML = '<div class="photo-placeholder"><div class="loading">Fotoğraf yükleniyor...</div></div>';
    personelInfoDiv.innerHTML = '';

    const payload = new URLSearchParams();
    payload.append('get_photo', personelID);

    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
    })
    .then(r => r.text())
    .then(text => {
        try {
            const resp = JSON.parse(text);
            if (resp.success && resp.photoData) {
                photoContainer.innerHTML = '<img src="data:image/jpeg;base64,' + resp.photoData + '" alt="Personel Fotoğrafı" class="personel-img" onerror="this.onerror=null; this.src=\\\'https://via.placeholder.com/300x300?text=Fotoğraf+Gösterilemedi\\\'">';
                personelInfoDiv.innerHTML = '<strong>Personel:</strong> ' + (resp.adi ? (resp.adi + ' ' + (resp.soyadi||'')) : adSoyad) + '<br>' +
                                            '<strong>Bölüm:</strong> ' + (resp.bolum || '-') + '<br>' +
                                            '<strong>Birim:</strong> ' + (resp.birim || '-');
            } else {
                photoContainer.innerHTML = '<div class="photo-placeholder"><div class="error-msg">' + (resp.message || 'Fotoğraf bulunamadı') + '</div></div>';
                personelInfoDiv.innerHTML = '<strong>Personel:</strong> ' + adSoyad + '<br>' +
                                            '<strong>Bölüm:</strong> ' + (resp.bolum || '-') + '<br>' +
                                            '<strong>Birim:</strong> ' + (resp.birim || '-');
            }
        } catch (e) {
            console.error('Foto parse hatası', e);
            photoContainer.innerHTML = '<div class="photo-placeholder"><div class="error-msg">Beklenmeyen cevap: ' + e.message + '</div></div>';
            personelInfoDiv.innerHTML = '';
        }
    })
    .catch(err => {
        console.error('Foto fetch error', err);
        photoContainer.innerHTML = '<div class="photo-placeholder"><div class="error-msg">Bağlantı hatası</div></div>';
        personelInfoDiv.innerHTML = '';
    });
}

document.addEventListener('click', function(e){
    const target = e.target;
    if (target.matches('.name-link')) {
        e.preventDefault();
        const pid = target.dataset.personelId;
        const ad = target.dataset.adSoyad || target.textContent.trim();
        showPersonelPhoto(pid, ad);
        return;
    }
});

/* ========== GRID SORTING (PERSONEL / BİRİM / BAŞ. SAAT / SAAT SÜTUNLARI) ========== */
/*
 - Personele basınca personele göre a-z sırala
 - Başlama zamanına basınca küçükten büyüğe sıralayacak
 - Birime basınca özel sıra kullanacak:
   ["ATTENDANT-1","ATTENDANT-2","TRAINING ATTENDANT", "CARD DESK","CARD DESK-1","CARD DESK-2"]
 - Saat sütunlarına tıklanınca o sütundaki seçili/alınan alan adına göre alfabetik sırala (A→Z / Z→A toggle)
 - Separator (Ekstra Eklenen Personeller) korunacak, her iki bölüm ayrı ayrı sıralanır.
*/

document.addEventListener('DOMContentLoaded', function() {
    function updateTopbarHeightVar() {
        const topbar = document.querySelector('.topbar');
        if (!topbar) return;
        document.documentElement.style.setProperty('--topbar-height', topbar.offsetHeight + 'px');
    }
    updateTopbarHeightVar();
    window.addEventListener('resize', updateTopbarHeightVar);

    // Helper to select the row that contains a given element (visual-only)
    function selectRowOf(element) {
        const row = element.closest('.grid-row');
        if (!row) return;
        document.querySelectorAll('.grid-row.selected').forEach(r => {
            r.classList.remove('selected');
            r.setAttribute('aria-selected', 'false');
        });
        row.classList.add('selected');
        row.setAttribute('aria-selected', 'true');
    }

    // Apply area colors and make selects also trigger row selection when focused/changed/clicked.
    document.querySelectorAll('.area-select').forEach(function(sel) {
        if (sel.value && sel.value !== '') {
            const opt = sel.options[sel.selectedIndex];
            const color = (opt && opt.dataset && opt.dataset.color) ? opt.dataset.color : '';
            applyAreaColor(sel, color);
        }

        // When user focuses, mouses down or changes a select, highlight the whole row.
        sel.addEventListener('focus', function() { selectRowOf(sel); });
        sel.addEventListener('mousedown', function() { selectRowOf(sel); });
        sel.addEventListener('change', function() { selectRowOf(sel); });
    });

    initColumnEditButtons();
    initClearButtons();
    initUniqueOptions();

    const assignBtn = document.getElementById('assignSmileBtn');
    if (assignBtn) assignBtn.addEventListener('click', function() {
        if (!confirm("Gelecek +20 slottaki atanmamış personellere ':)' atamak istediğinize emin misiniz?")) return;
        assignSmileToUnassignedInCurrentSlot();
    });

    function updateCurrentTime() {
        const now = new Date();
        document.getElementById('currentTimeDisplay').textContent = now.toLocaleTimeString('tr-TR', {
            timeZone: 'Europe/Nicosia',
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }
    updateCurrentTime();
    setInterval(updateCurrentTime, 1000);

    // ---------- Sorting logic ----------
    const gridBody = document.getElementById('gridBody');
    const hdrPersonel = document.getElementById('hdrPersonel');
    const hdrBirim = document.getElementById('hdrBirim');
    const hdrBaslama = document.getElementById('hdrBaslama');

    // Custom birim order requested by user
    const customUnitOrder = [
        "ATTENDANT-1","ATTENDANT-2","TRAINING ATTENDANT","CARD DESK","CARD DESK-1","CARD DESK-2"
    ];
    const unitRankMap = (function() {
        const m = {};
        customUnitOrder.forEach((label, idx) => {
            const key = normalizeForOrder(label);
            if (!(key in m)) m[key] = idx;
        });
        return m;
    })();

    let lastSort = { field: null, dir: 1 }; // dir: 1 asc, -1 desc

    function getRowsParts() {
        const rows = Array.from(gridBody.children);
        const sepIndex = rows.findIndex(r => r.id === 'manualSeparator');
        if (sepIndex === -1) {
            return { top: rows.slice(), separator: null, bottom: [] };
        } else {
            return { top: rows.slice(0, sepIndex), separator: rows[sepIndex], bottom: rows.slice(sepIndex+1) };
        }
    }

    function compareByName(a, b) {
        const na = (a.dataset.name || '').trim();
        const nb = (b.dataset.name || '').trim();
        return na.localeCompare(nb, 'tr', { sensitivity: 'base' });
    }
    function compareByStart(a, b) {
        const sa = parseInt(a.dataset.start || '99999', 10);
        const sb = parseInt(b.dataset.start || '99999', 10);
        return sa - sb;
    }
    function compareByBirim(a, b) {
        const ba = normalizeForOrder(a.dataset.birim || '');
        const bb = normalizeForOrder(b.dataset.birim || '');
        const ra = (typeof unitRankMap[ba] !== 'undefined') ? unitRankMap[ba] : 9999;
        const rb = (typeof unitRankMap[bb] !== 'undefined') ? unitRankMap[bb] : 9999;
        if (ra !== rb) return ra - rb;
        // fallback to natural compare of birim text
        return (a.dataset.birim || '').localeCompare(b.dataset.birim || '', 'tr', { sensitivity: 'base' });
    }

    function sortSection(sectionRows, field, dir, slot) {
        const rowsCopy = sectionRows.slice();
        let cmp;
        if (field === 'name') cmp = compareByName;
        else if (field === 'start') cmp = compareByStart;
        else if (field === 'birim') cmp = compareByBirim;
        else if (field === 'slot' && slot) {
            cmp = function(a, b) {
                // find select in row for that slot
                const selA = a.querySelector(`.area-select[data-slot-time="${slot}"]`);
                const selB = b.querySelector(`.area-select[data-slot-time="${slot}"]`);
                const tA = selA ? (selA.options[selA.selectedIndex]?.textContent || '') : '';
                const tB = selB ? (selB.options[selB.selectedIndex]?.textContent || '') : '';
                return tA.localeCompare(tB, 'tr', { sensitivity: 'base' });
            };
        } else cmp = compareByName;
        rowsCopy.sort((r1, r2) => {
            const v = cmp(r1, r2);
            return dir * v;
        });
        return rowsCopy;
    }

    function applySortedRows(field, dir, slot) {
        const parts = getRowsParts();
        const sortedTop = sortSection(parts.top, field, dir, slot);
        const sortedBottom = sortSection(parts.bottom, field, dir, slot);

        // Clear gridBody and append in order
        gridBody.innerHTML = '';
        sortedTop.forEach(r => gridBody.appendChild(r));
        if (parts.separator) gridBody.appendChild(parts.separator);
        sortedBottom.forEach(r => gridBody.appendChild(r));

        // Update header indicators
        document.querySelectorAll('.header-cell').forEach(h => {
            h.classList.remove('sorted-asc', 'sorted-desc');
            h.removeAttribute('aria-sort');
        });
        let hdr = null;
        if (field === 'name') hdr = hdrPersonel;
        else if (field === 'start') hdr = hdrBaslama;
        else if (field === 'birim') hdr = hdrBirim;
        else if (field === 'slot') hdr = document.querySelector(`.time-header[data-slot="${slot}"]`);
        if (hdr) {
            hdr.classList.add(dir === 1 ? 'sorted-asc' : 'sorted-desc');
            hdr.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
        }

        // After reorder, re-init unique options on visible selects to ensure option hiding works correctly
        setTimeout(() => {
            initUniqueOptions();
        }, 40);
    }

    hdrPersonel.addEventListener('click', function() {
        let dir = 1;
        if (lastSort.field === 'name') dir = -lastSort.dir;
        lastSort = { field: 'name', dir: dir };
        applySortedRows('name', dir);
        showStatus(`Sırala: Personel (${dir === 1 ? 'A→Z' : 'Z→A'})`, 'info');
    });

    hdrBaslama.addEventListener('click', function() {
        let dir = 1;
        if (lastSort.field === 'start') dir = -lastSort.dir;
        lastSort = { field: 'start', dir: dir };
        applySortedRows('start', dir);
        showStatus(`Sırala: Baş. Saat (${dir === 1 ? 'Küçükten büyüğe' : 'Büyükten küçüğe'})`, 'info');
    });

    hdrBirim.addEventListener('click', function() {
        let dir = 1;
        if (lastSort.field === 'birim') dir = -lastSort.dir;
        lastSort = { field: 'birim', dir: dir };
        applySortedRows('birim', dir);
        showStatus(`Sırala: Birim (${dir === 1 ? 'Özel sıra (↑)' : 'Özel sıra (↓)'})`, 'info');
    });

    // Time column sorting: alphabetical by selected option label in that column (slot)
    document.querySelectorAll('.time-header').forEach(hdr => {
        const slot = hdr.dataset.slot;
        if (!slot) return;
        hdr.style.cursor = 'pointer';
        hdr.addEventListener('click', function() {
            const fieldName = 'slot:' + slot;
            let dir = 1;
            if (lastSort.field === fieldName) dir = -lastSort.dir;
            lastSort = { field: fieldName, dir: dir };
            applySortedRows('slot', dir, slot);
            // Human-readable time for status message
            const t = new Date(Number(slot) * 1000);
            const timeStr = t.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', hour12: false });
            showStatus(`Sırala: Sütun ${timeStr} (${dir === 1 ? 'A→Z' : 'Z→A'})`, 'info');
        });
    });

    // ---------- ROW SELECTION (satıra tıklayınca tüm satırın renginin değişmesi) ----------
    // Single visual selection: clicking a row highlights it visually (entire row).
    // Clicking interactive child elements (except selects — selects are handled above) will NOT change selection.
    function initRowSelection() {
        const body = document.getElementById('gridBody');
        if (!body) return;

        body.addEventListener('click', function(e) {
            const row = e.target.closest('.grid-row');
            if (!row) return;

            // If click happened inside a link/button/input/textarea, ignore (selects are handled separately)
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input') || e.target.closest('textarea')) {
                return;
            }

            // Remove previous selection and set on clicked row (visual only)
            document.querySelectorAll('.grid-row.selected').forEach(r => {
                r.classList.remove('selected');
                r.setAttribute('aria-selected', 'false');
            });
            row.classList.add('selected');
            row.setAttribute('aria-selected', 'true');
        });

        // Allow keyboard activation (Enter or Space) when a row has focus.
        // Focusable rows have tabindex="0".
        body.addEventListener('keydown', function(e) {
            const row = e.target.closest && e.target.closest('.grid-row') ? e.target.closest('.grid-row') : null;
            if (!row) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                document.querySelectorAll('.grid-row.selected').forEach(r => {
                    r.classList.remove('selected');
                    r.setAttribute('aria-selected', 'false');
                });
                row.classList.add('selected');
                row.setAttribute('aria-selected', 'true');
            }
        });
    }

    initRowSelection();

    // Optionally, initialize default ordering state (no sorting) - keep server order.
});
</script>
</body>
</html>