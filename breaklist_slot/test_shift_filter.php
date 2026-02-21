<?php
require_once 'config.php';
require_once 'config_hr.php';

date_default_timezone_set('Europe/Nicosia');

// VARDİYA HESAPLAMA (same as in index.php)
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
    
    if ($base_hour === null) return null;
    
    $start_hour = $base_hour;
    $start_minute = 0;
    $end_hour = $start_hour + $duration_hours;
    $end_minute = 0;
    $wraps = false;
    if ($end_hour >= 24) {
        $wraps = true;
        $end_hour = $end_hour % 24;
    }
    
    return ['start_hour'=>$start_hour,'start_minute'=>$start_minute,'end_hour'=>$end_hour,'end_minute'=>$end_minute,'duration'=>$duration_hours,'is_extended'=>$is_extended,'wraps'=>$wraps];
}

echo "<h1>Shift 24 Filter Test</h1>";

$test_codes = ['8', '22', '24', '24+'];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Shift Code</th><th>start_hour</th><th>Condition Result</th><th>Would be added?</th></tr>";

foreach ($test_codes as $code) {
    $shift_info_today = calculate_shift_hours($code);
    $start_hour = $shift_info_today['start_hour'];
    $condition = $shift_info_today && $shift_info_today['start_hour'] < 24;
    
    echo "<tr>";
    echo "<td>$code</td>";
    echo "<td>$start_hour</td>";
    echo "<td>" . ($shift_info_today['start_hour'] < 24 ? 'TRUE' : 'FALSE') . "</td>";
    echo "<td>" . ($condition ? 'YES' : 'NO') . "</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Expected:</h2>";
echo "<ul>";
echo "<li>Shift 8: YES</li>";
echo "<li>Shift 22: YES</li>";
echo "<li>Shift 24: NO (SHOULD BE FILTERED OUT)</li>";
echo "<li>Shift 24+: NO (SHOULD BE FILTERED OUT)</li>";
echo "</ul>";

echo "<p><strong>If shift 24 shows NO above, the fix is working correctly!</strong></p>";
