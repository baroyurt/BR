<?php
// show_hr_query_result.php

require_once 'config_hr.php';

header('Content-Type: text/html; charset=utf-8');

// Fotoğraf getirme fonksiyonu - DÜZELTİLDİ
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
    
    $stmt = sqlsrv_query($conn, $sql, [$personel_id]);
    
    if ($stmt !== false) {
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['Icerik'] && strlen($row['Icerik']) > 0) {
                // Binary veriyi al
                $content = $row['Icerik'];
                
                $photo = [
                    'data' => base64_encode($content),
                    'adi' => $row['Adi'] ?? '',
                    'soyadi' => $row['Soyadi'] ?? '',
                    'bolum' => $row['BolumTanimi'] ?? '',
                    'birim' => $row['BirimTanimi'] ?? ''
                ];
                
                // Debug için
                error_log("Fotoğraf bulundu - PersonelID: $personel_id, Boyut: " . strlen($content));
            }
        }
        sqlsrv_free_stmt($stmt);
    } else {
        error_log("SQL hatası: " . print_r(sqlsrv_errors(), true));
    }
    
    sqlsrv_close($conn);
    return $photo;
}

// AJAX isteği için fotoğraf getirme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_photo'])) {
    // GUID olduğu için intval YOK! String olarak al
    $personelID = $_POST['get_photo'];
    
    // GUID formatını kontrol et
    if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $personelID)) {
        echo json_encode([
            'success' => false,
            'message' => 'Geçersiz Personel ID formatı',
            'personelID' => $personelID
        ]);
        exit;
    }
    
    $photo = get_personel_photo($personelID);
    
    if ($photo && $photo['data']) {
        echo json_encode([
            'success' => true,
            'photoData' => $photo['data'],
            'bolum' => $photo['bolum'],
            'birim' => $photo['birim']
        ]);
    } else {
        // Alternatif olarak sadece personel bilgilerini getir
        $conn = get_hr_connection();
        $sql_info = "SELECT p.Adi, p.Soyadi, bolum.Tanim AS BolumTanimi, birim.Tanim AS BirimTanimi
                    FROM [IK_Chamada].[dbo].[Personel] p
                    LEFT JOIN [IK_Chamada].[dbo].[Bolum] bolum ON p.BolumID = bolum.ID
                    LEFT JOIN [IK_Binary].[dbo].[Birim] birim ON p.BirimID = birim.ID
                    WHERE p.ID = ?";
        
        $stmt = sqlsrv_query($conn, $sql_info, [$personelID]);
        $personel_info = null;
        
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $personel_info = $row;
        }
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        echo json_encode([
            'success' => false,
            'message' => 'Fotoğraf bulunamadı',
            'bolum' => $personel_info['BolumTanimi'] ?? '',
            'birim' => $personel_info['BirimTanimi'] ?? ''
        ]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>İK Sorgu Sonuçları</title>
<style>
body { font-family: Arial, sans-serif; background:#f5f7fa; padding:20px; }
.container { max-width:1200px; margin:0 auto; background:#fff; padding:18px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
h1 { margin:0 0 12px 0; font-size:20px; }
table { width:100%; border-collapse:collapse; margin-top:12px; }
th, td { padding:8px 10px; border:1px solid #e6e9ee; text-align:left; font-size:13px; }
th { background:#f1f3f5; }
.pre { background:#eef6fb; padding:10px; border-radius:6px; }
.info { margin-top:12px; padding:10px; background:#d1ecf1; color:#0c5460; border-left:4px solid #17a2b8; border-radius:4px; }
.error { margin-top:12px; padding:10px; background:#f8d7da; color:#721c24; border-left:4px solid #dc3545; border-radius:4px; }

/* Fotoğraf Modal Stilleri */
.name-link { color:#007bff; text-decoration:none; cursor:pointer; }
.name-link:hover { text-decoration:underline; }
.modal { display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); }
.modal-content { background-color:#fff; margin:5% auto; padding:20px; border-radius:8px; width:90%; max-width:400px; box-shadow:0 4px 20px rgba(0,0,0,0.15); position:relative; }
.close { color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer; }
.close:hover { color:#000; }
.personel-img { width:100%; max-width:300px; height:auto; border-radius:4px; margin-top:10px; border:1px solid #ddd; }
.photo-info { margin-top:15px; padding:10px; background:#f8f9fa; border-radius:4px; }
.photo-placeholder { width:300px; height:300px; background:#f0f0f0; display:flex; align-items:center; justify-content:center; color:#666; border-radius:4px; }
.loading { color:#007bff; }
.error-msg { color:#dc3545; }
</style>
</head>
<body>
<div class="container">
  <h1>🔎 İK Sorgu Sonuçları (Haftalık Personel Vardiya)</h1>
<?php

try {
    if (function_exists('get_weekly_employees_from_hr')) {
        $employees = get_weekly_employees_from_hr();
    } else {
        if (!function_exists('get_hr_connection')) {
            throw new Exception('get_weekly_employees_from_hr veya get_hr_connection fonksiyonu bulunamadı.');
        }

        $conn = get_hr_connection();

        $sql = "
            SET DATEFIRST 1;

            DECLARE @HaftaBaslangic DATE = DATEADD(DAY, 1 - DATEPART(WEEKDAY, GETDATE()), CAST(GETDATE() AS DATE));
            DECLARE @HaftaBitis     DATE = DATEADD(DAY, 6, @HaftaBaslangic);

            SELECT
                p.ID AS PersonelID,
                p.Adi + ' ' + p.Soyadi AS AdSoyad,
                bolum.Tanim AS BolumTanimi,
                birim.Tanim AS BirimTanimi,

                MAX(CASE WHEN pv.Tarih = @HaftaBaslangic                  THEN pv.VardiyaKod END) AS Pazartesi,
                MAX(CASE WHEN pv.Tarih = DATEADD(DAY, 1, @HaftaBaslangic) THEN pv.VardiyaKod END) AS Sali,
                MAX(CASE WHEN pv.Tarih = DATEADD(DAY, 2, @HaftaBaslangic) THEN pv.VardiyaKod END) AS Carsamba,
                MAX(CASE WHEN pv.Tarih = DATEADD(DAY, 3, @HaftaBaslangic) THEN pv.VardiyaKod END) AS Persembe,
                MAX(CASE WHEN pv.Tarih = DATEADD(DAY, 4, @HaftaBaslangic) THEN pv.VardiyaKod END) AS Cuma,
                MAX(CASE WHEN pv.Tarih = DATEADD(DAY, 5, @HaftaBaslangic) THEN pv.VardiyaKod END) AS Cumartesi,
                MAX(CASE WHEN pv.Tarih = DATEADD(DAY, 6, @HaftaBaslangic) THEN pv.VardiyaKod END) AS Pazar

            FROM [IK_Chamada].[dbo].[Personel] p
            INNER JOIN [IK_Chamada].[dbo].[Bolum] bolum 
                ON p.BolumID = bolum.ID
            LEFT JOIN [IK_Chamada].[dbo].[Birim] birim 
                ON p.BirimID = birim.ID
            LEFT JOIN [IK_Chamada].[dbo].[PersonelVardiya] pv 
                ON p.ID = pv.PersonelID
               AND pv.Tarih BETWEEN @HaftaBaslangic AND @HaftaBitis
            LEFT JOIN [IK_Chamada].[dbo].[IstenAyrilisNedenleri] ian 
                ON p.IstenAyrilisNedenleriID = ian.ID

            WHERE ian.ID IS NULL
              AND UPPER(bolum.Tanim) = 'LIVE GAME'
              AND (
                    birim.Tanim IS NULL
                    OR UPPER(birim.Tanim) NOT IN (
                        'PIT MANAGER',
                        'PITBOSS',
                        'RUNNER',
                        'SENIOR PITBOSS',
                        'CRAPS SUPERVISOR'
                    )
                  )

            GROUP BY 
                p.ID,
                p.Adi, 
                p.Soyadi, 
                bolum.Tanim, 
                birim.Tanim

            ORDER BY AdSoyad;
        ";

        $s1 = sqlsrv_query($conn, "SET DATEFIRST 1");
        if ($s1 === false) {
            throw new Exception("SET DATEFIRST hatası: " . print_r(sqlsrv_errors(), true));
        }

        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new Exception("İK sorgu hatası: " . print_r($err, true));
        }

        $employees = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $employees[] = $row;
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
    }

    if (empty($employees)) {
        echo '<div class="info">Sorgudan dönen kayıt yok.</div>';
    } else {
        echo '<table aria-describedby="results"><thead><tr>
                <th>PersonelID</th>
                <th>Ad Soyad</th>
                <th>Fotoğraf</th>
                <th>Bölüm</th>
                <th>Birim</th>
                <th>Pazartesi</th>
                <th>Salı</th>
                <th>Çarşamba</th>
                <th>Perşembe</th>
                <th>Cuma</th>
                <th>Cumartesi</th>
                <th>Pazar</th>
              </tr></thead><tbody>';

        foreach ($employees as $r) {
            $personelID = $r['PersonelID'] ?? $r['id'] ?? '';
            $adSoyad = htmlspecialchars($r['AdSoyad'] ?? $r['name'] ?? '', ENT_QUOTES, 'UTF-8');
            
            // PersonelID'nin GUID olduğundan emin ol
            $personelID = trim($personelID);
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($personelID) . '</td>';
            echo '<td><a href="#" class="name-link" data-personel-id="' . $personelID . '" data-ad-soyad="' . $adSoyad . '">' . $adSoyad . '</a></td>';
            echo '<td><button class="photo-btn" data-personel-id="' . $personelID . '" data-ad-soyad="' . $adSoyad . '" style="padding:4px 8px; background:#007bff; color:white; border:none; border-radius:4px; cursor:pointer;">Fotoğraf</button></td>';
            echo '<td>' . htmlspecialchars($r['BolumTanimi'] ?? $r['bolum'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['BirimTanimi'] ?? $r['birim'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Pazartesi'] ?? $r['vardiya']['pazartesi'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Sali'] ?? $r['vardiya']['sali'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Carsamba'] ?? $r['vardiya']['carsamba'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Persembe'] ?? $r['vardiya']['persembe'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Cuma'] ?? $r['vardiya']['cuma'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Cumartesi'] ?? $r['vardiya']['cumartesi'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($r['Pazar'] ?? $r['vardiya']['pazar'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        // Modal HTML
        echo '
        <div id="photoModal" class="modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h3 id="modalTitle">Personel Fotoğrafı</h3>
                <div id="photoContainer">
                    <div class="photo-placeholder">
                        <div class="loading">Fotoğraf yükleniyor...</div>
                    </div>
                </div>
                <div id="personelInfo" class="photo-info"></div>
            </div>
        </div>';
        
        // JavaScript
        echo '
        <script>
        var modal = document.getElementById("photoModal");
        var closeBtn = document.querySelector(".close");
        
        // Event listeners
        document.querySelectorAll(".name-link, .photo-btn").forEach(function(element) {
            element.addEventListener("click", function(e) {
                e.preventDefault();
                var personelID = this.getAttribute("data-personel-id");
                var adSoyad = this.getAttribute("data-ad-soyad");
                console.log("Tıklandı - PersonelID:", personelID, "Ad Soyad:", adSoyad);
                showPersonelPhoto(personelID, adSoyad);
            });
        });
        
        closeBtn.onclick = function() {
            modal.style.display = "none";
        };
        
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        };
        
        document.addEventListener("keydown", function(event) {
            if (event.key === "Escape") {
                modal.style.display = "none";
            }
        });
        
        function showPersonelPhoto(personelID, adSoyad) {
            console.log("Fotoğraf göster - PersonelID:", personelID);
            modal.style.display = "block";
            document.getElementById("modalTitle").textContent = adSoyad;
            document.getElementById("photoContainer").innerHTML = \'<div class="photo-placeholder"><div class="loading">Fotoğraf yükleniyor...</div></div>\';
            document.getElementById("personelInfo").innerHTML = "";
            
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            
            xhr.onload = function() {
                console.log("AJAX cevabı - Status:", xhr.status, "Response:", xhr.responseText.substring(0, 200));
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        var container = document.getElementById("photoContainer");
                        var infoDiv = document.getElementById("personelInfo");
                        
                        if (response.success && response.photoData) {
                            // Base64 image göster
                            container.innerHTML = \'<img src="data:image/jpeg;base64,\' + response.photoData + \'" alt="Personel Fotoğrafı" class="personel-img" onerror="this.onerror=null; this.src=\\\'https://via.placeholder.com/300x300?text=Fotoğraf+Gösterilemedi\\\';">\';
                            
                            infoDiv.innerHTML = \'<strong>Personel ID:</strong> \' + personelID + \'<br>\' +
                                              \'<strong>Ad Soyad:</strong> \' + adSoyad + \'<br>\' +
                                              \'<strong>Bölüm:</strong> \' + (response.bolum || "-") + \'<br>\' +
                                              \'<strong>Birim:</strong> \' + (response.birim || "-");
                        } else {
                            container.innerHTML = \'<div class="photo-placeholder"><div class="error-msg">\' + (response.message || "Fotoğraf bulunamadı") + \'</div></div>\';
                            infoDiv.innerHTML = \'<strong>Personel ID:</strong> \' + personelID + \'<br>\' +
                                              \'<strong>Ad Soyad:</strong> \' + adSoyad + \'<br>\' +
                                              \'<strong>Bölüm:</strong> \' + (response.bolum || "-") + \'<br>\' +
                                              \'<strong>Birim:</strong> \' + (response.birim || "-");
                        }
                    } catch(e) {
                        console.error("JSON parse hatası:", e);
                        container.innerHTML = \'<div class="photo-placeholder"><div class="error-msg">Hata oluştu: \' + e.message + \'</div></div>\';
                    }
                } else {
                    container.innerHTML = \'<div class="photo-placeholder"><div class="error-msg">Sunucu hatası: \' + xhr.status + \'</div></div>\';
                }
            };
            
            xhr.onerror = function() {
                console.error("AJAX bağlantı hatası");
                document.getElementById("photoContainer").innerHTML = \'<div class="photo-placeholder"><div class="error-msg">Bağlantı hatası</div></div>\';
            };
            
            xhr.send("get_photo=" + encodeURIComponent(personelID));
        }
        </script>';

        if (isset($_GET['raw'])) {
            echo '<h3>Ham veri</h3><pre class="pre">' . htmlspecialchars(print_r($employees, true)) . '</pre>';
        }
    }

} catch (Exception $e) {
    echo '<div class="error"><strong>HATA:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>
</body>
</html>';