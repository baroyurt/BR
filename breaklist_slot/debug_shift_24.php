<?php
/**
 * Debug tool to test shift 24 filtering logic
 * Access via: http://yourserver/breaklist_slot/debug_shift_24.php
 */

require_once 'config.php';
require_once 'config_hr.php';

echo "<h1>Shift 24 Filter Debug</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";

// Test regex
echo "<h2>1. Regex Test</h2>";
$test_codes = ['24', '24+', '22', '22+', '8', '16'];
echo "<table border='1' cellpadding='5'><tr><th>Code</th><th>Matches /^24\\+?\$/</th><th>Will be filtered?</th></tr>";
foreach ($test_codes as $code) {
    $matches = preg_match('/^24\+?$/', $code);
    echo "<tr><td>$code</td><td>" . ($matches ? 'YES' : 'NO') . "</td><td>" . ($matches ? '<strong>YES (FILTERED)</strong>' : 'NO') . "</td></tr>";
}
echo "</table>";

// Test actual data
echo "<h2>2. Actual Employee Data</h2>";
$view_date = new DateTime('now', new DateTimeZone('Europe/Nicosia'));
echo "<p>View date: " . $view_date->format('Y-m-d') . "</p>";

try {
    $employees = $pdo->query("SELECT id, name, external_id FROM employees WHERE is_active = 1 AND external_id IS NOT NULL ORDER BY name LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Name</th><th>External ID</th><th>Shift Today</th><th>Matches Filter?</th><th>Action</th></tr>";
    
    foreach ($employees as $emp) {
        $vardiya_kod_today = get_vardiya_kod_for_day($emp['external_id'], $view_date->format('Y-m-d'));
        $matches = preg_match('/^24\+?$/', $vardiya_kod_today);
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($emp['name']) . "</td>";
        echo "<td>" . htmlspecialchars($emp['external_id']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($vardiya_kod_today) . "</strong></td>";
        echo "<td>" . ($matches ? 'YES' : 'NO') . "</td>";
        echo "<td style='background:" . ($matches ? '#ffcccc' : '#ccffcc') . "'>";
        echo $matches ? '<strong>FILTERED (will NOT show)</strong>' : 'SHOWN';
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>3. Check Code in index.php</h2>";
$index_file = __DIR__ . '/admin/index.php';
if (file_exists($index_file)) {
    $content = file_get_contents($index_file);
    if (strpos($content, "preg_match('/^24\+?\$/', \$vardiya_kod_today)") !== false) {
        echo "<p style='color:green'>✓ Filter code EXISTS in admin/index.php</p>";
    } else {
        echo "<p style='color:red'>✗ Filter code NOT FOUND in admin/index.php</p>";
    }
} else {
    echo "<p style='color:red'>✗ admin/index.php not found</p>";
}

echo "<h2>4. Recommendations</h2>";
echo "<ul>";
echo "<li>If filter code exists but employees with '24' still show: <strong>Clear PHP OpCache</strong> (restart PHP-FPM)</li>";
echo "<li>If employees have shift '24' assigned to YESTERDAY: They SHOULD show with '(önceki gün)' label</li>";
echo "<li>If employees have shift '24' assigned to TODAY: They should NOT show at all</li>";
echo "<li>Check browser cache: Press Ctrl+Shift+R to hard refresh</li>";
echo "</ul>";

echo "<hr>";
echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . "</small></p>";
?>
