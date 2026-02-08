<?php
// Basit endpoint: personel_photo.php?sicil=XXXX
// Eğer sicil boş veya resim bulunamazsa default_image.jpg döner.

$serverName1 = "172.18.0.33"; 
$databaseName1 = "IK_Chamada";
$username = "sa";
$password = "5a?4458059";

$sicilNo = isset($_GET['sicil']) ? trim($_GET['sicil']) : '';

$defaultPath = __DIR__ . '/default_image.jpg';

// Eğer sicil boşsa doğrudan default resmi dön
if ($sicilNo === '') {
    if (file_exists($defaultPath)) {
        header("Content-Type: image/jpeg");
        readfile($defaultPath);
        exit;
    } else {
        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        exit;
    }
}

$conn1 = sqlsrv_connect($serverName1, array(
    "Database" => $databaseName1,
    "Uid" => $username,
    "PWD" => $password,
    "CharacterSet" => "UTF-8",
    "TrustServerCertificate" => true
));

if ($conn1 === false) {
    // fallback olarak default göster
    if (file_exists($defaultPath)) {
        header("Content-Type: image/jpeg");
        readfile($defaultPath);
        exit;
    }
    die(print_r(sqlsrv_errors(), true));
}

$sql1 = "SELECT b.Icerik
        FROM [IK_Chamada].[dbo].[Personel] p
        LEFT JOIN [IK_Binary].[dbo].[Belgeler] b ON p.ID = b.PersonelID
        WHERE p.SicilNo = ?";

$params = array($sicilNo);
$stmt1 = sqlsrv_prepare($conn1, $sql1, $params);

if ($stmt1 === false) {
    if (file_exists($defaultPath)) {
        header("Content-Type: image/jpeg");
        readfile($defaultPath);
        exit;
    }
    die(print_r(sqlsrv_errors(), true));
}

if (sqlsrv_execute($stmt1)) {
    $row = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_ASSOC);
    if ($row && $row['Icerik']) {
        header("Content-Type: image/jpeg");
        echo $row['Icerik'];
        sqlsrv_free_stmt($stmt1);
        sqlsrv_close($conn1);
        exit;
    } else {
        if (file_exists($defaultPath)) {
            header("Content-Type: image/jpeg");
            readfile($defaultPath);
            sqlsrv_free_stmt($stmt1);
            sqlsrv_close($conn1);
            exit;
        } else {
            header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
            sqlsrv_free_stmt($stmt1);
            sqlsrv_close($conn1);
            exit;
        }
    }
} else {
    if (file_exists($defaultPath)) {
        header("Content-Type: image/jpeg");
        readfile($defaultPath);
        sqlsrv_free_stmt($stmt1);
        sqlsrv_close($conn1);
        exit;
    }
    die(print_r(sqlsrv_errors(), true));
}
?>