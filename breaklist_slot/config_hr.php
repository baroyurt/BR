<?php
// İK Sistemi (IK_Chamada) Bağlantı Bilgileri - SQL Server
$hr_config = [
    'server' => '172.18.0.33',
    'database' => 'IK_Chamada',
    'username' => 'sa',
    'password' => '5a?4458059'
];

// SQL Server bağlantısı fonksiyonu
function get_hr_connection() {
    global $hr_config;
    
    $connectionInfo = [
        "Database" => $hr_config['database'],
        "Uid" => $hr_config['username'],
        "PWD" => $hr_config['password'],
        "TrustServerCertificate" => true,
        "CharacterSet" => "UTF-8",
        "ConnectionPooling" => 0,
        "ReturnDatesAsStrings" => true
    ];
    
    $conn = sqlsrv_connect($hr_config['server'], $connectionInfo);
    
    if ($conn === false) {
        die("İK Sistemi bağlantı hatası: " . print_r(sqlsrv_errors(), true));
    }
    
    return $conn;
}

// İK'den bu haftanın personel vardiya bilgilerini çek
function get_weekly_employees_from_hr() {
    $conn = get_hr_connection();
    
    $sql = "
        SET DATEFIRST 1; -- Haftayı Pazartesi başlat

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

        FROM dbo.Personel p
        INNER JOIN dbo.Bolum bolum 
            ON p.BolumID = bolum.ID
        LEFT JOIN dbo.Birim birim 
            ON p.BirimID = birim.ID
        LEFT JOIN dbo.PersonelVardiya pv 
            ON p.ID = pv.PersonelID
           AND pv.Tarih BETWEEN @HaftaBaslangic AND @HaftaBitis
        LEFT JOIN dbo.IstenAyrilisNedenleri ian 
            ON p.IstenAyrilisNedenleriID = ian.ID

        WHERE ian.ID IS NULL
          AND UPPER(bolum.Tanim) = 'SLOT GAME'
          AND (
                birim.Tanim IS NULL
                OR UPPER(birim.Tanim) NOT IN (
                    'ASST. SLOT MANAGER',
                    'JUNIOR SLOT SUPERVISOR',
                    'SENIOR SUPERVISOR',
                    'SLOT MANAGER',
                    'SLOT SUPERVISOR',
					'SLOT PR-1',
					'SLOT PR-2',
					'TRAINING SLOT PR'
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
    
    // SET DATEFIRST komutunu ayrı çalıştır
    $stmt = sqlsrv_query($conn, "SET DATEFIRST 1");
    if ($stmt === false) {
        die("SET DATEFIRST hatası: " . print_r(sqlsrv_errors(), true));
    }
    
    // Ana sorguyu çalıştır
    $stmt = sqlsrv_query($conn, $sql);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $error_msg = "İK sorgu hatası: ";
        foreach ($errors as $error) {
            $error_msg .= "\n" . $error['message'];
        }
        die($error_msg);
    }
    
    $employees = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $employees[] = [
            'id' => $row['PersonelID'],
            'name' => trim($row['AdSoyad']),
            'bolum' => $row['BolumTanimi'],
            'birim' => $row['BirimTanimi'] ?? '',
            'vardiya' => [
                'pazartesi' => $row['Pazartesi'] ?? null,
                'sali' => $row['Sali'] ?? null,
                'carsamba' => $row['Carsamba'] ?? null,
                'persembe' => $row['Persembe'] ?? null,
                'cuma' => $row['Cuma'] ?? null,
                'cumartesi' => $row['Cumartesi'] ?? null,
                'pazar' => $row['Pazar'] ?? null
            ]
        ];
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    
    return $employees;
}

/**
 * İK'den belirli bir tarihteki vardiya kodunu getirir.
 * $date parametresi 'YYYY-MM-DD' veya strtotime ile parse edilebilecek bir string olmalı.
 * Dönen değer: vardiya kodu string'i veya null.
 */
function get_vardiya_kod_for_date($personel_id, $date) {
    $conn = get_hr_connection();

    // Normalize date to YYYY-MM-DD
    $d = null;
    try {
        $dt = new DateTime($date, new DateTimeZone('Europe/Nicosia'));
        $d = $dt->format('Y-m-d');
    } catch (Exception $e) {
        // fallback: try as-is
        $d = $date;
    }

    $sql = "
        SELECT TOP 1 pv.VardiyaKod
        FROM dbo.PersonelVardiya pv
        WHERE pv.PersonelID = ?
        AND CAST(pv.Tarih AS DATE) = CAST(? AS DATE)
        ORDER BY pv.Tarih DESC
    ";

    $params = [$personel_id, $d];
    $stmt = @sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        // isteğe bağlı: log yaz
        // error_log("get_vardiya_kod_for_date sql error: " . print_r(sqlsrv_errors(), true));
        sqlsrv_close($conn);
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return $row ? $row['VardiyaKod'] : null;
}

// İK'den bugünün vardiya kodunu getir (mevcut fonksiyonla uyumlu kalır, ancak yukarıdaki fonksiyonu kullanır)
function get_today_vardiya_kod($personel_id) {
    // Kolaylık olsun diye bugün tarihli fonksiyonu çağırıyoruz
    $today = (new DateTime('now', new DateTimeZone('Europe/Nicosia')))->format('Y-m-d');
    return get_vardiya_kod_for_date($personel_id, $today);
}
?>