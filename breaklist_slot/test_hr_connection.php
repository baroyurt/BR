<?php
// Bu dosyayı tarayıcıda açarak bağlantıyı test edin
echo "<h2>İK Sistemi Bağlantı Testi</h2>";
echo "<pre>";

// SQL Server driver kontrolü
if (!extension_loaded('sqlsrv')) {
    die("❌ HATA: sqlsrv extension kurulu değil!\n\nÇözüm: php.ini dosyasına şu satırı ekleyin:\nextension=php_sqlsrv.dll\n\nveya XAMPP/WAMP'te 'php_sqlsrv' extension'ını aktif edin.");
}

echo "✅ sqlsrv extension kurulu\n\n";

// Bağlantı testi
$server = "172.18.0.33";
$connectionInfo = [
    "Database" => "IK_Chamada",
    "Uid" => "sa",
    "PWD" => "5a?4458059",
    "TrustServerCertificate" => true,
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($server, $connectionInfo);

if ($conn === false) {
    echo "❌ Bağlantı HATASI:\n";
    die(print_r(sqlsrv_errors(), true));
}

echo "✅ İK sistemine bağlantı başarılı\n\n";

// Basit sorgu testi
$stmt = sqlsrv_query($conn, "SELECT TOP 5 ID, Adi, Soyadi FROM dbo.Personel");

if ($stmt === false) {
    echo "❌ Sorgu HATASI:\n";
    die(print_r(sqlsrv_errors(), true));
}

echo "✅ Sorgu başarılı, örnek personeller:\n";
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    echo "- {$row['Adi']} {$row['Soyadi']} (ID: {$row['ID']})\n";
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

echo "\n✅ TÜM TESTLER BAŞARILI! Şimdi sync_employees.php'yi çalıştırabilirsiniz.";
echo "</pre>";
?>