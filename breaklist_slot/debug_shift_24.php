<?php
/**
 * Debug tool to test shift 24 filtering and pre-show logic
 * Access via: http://yourserver/breaklist_slot/debug_shift_24.php
 */

require_once 'config.php';
require_once 'config_hr.php';

// Wrapper function to get shift code for a specific day
function get_vardiya_kod_for_day($external_id, $dateString) {
    // dateString: 'YYYY-MM-DD'
    // Try the date-specific function first
    if (function_exists('get_vardiya_kod_for_date')) {
        try {
            return get_vardiya_kod_for_date($external_id, $dateString);
        } catch (Exception $e) {
            // Fallback to today function
        }
    }
    
    // Fallback to today function
    if (function_exists('get_today_vardiya_kod')) {
        try {
            return get_today_vardiya_kod($external_id);
        } catch (Exception $e) {
            return null;
        }
    }
    
    return null;
}

echo "<h1>Shift 24 Filter & Pre-Show Debug</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";

// Current time
$now = new DateTime('now', new DateTimeZone('Europe/Nicosia'));
$current_hour = (int)$now->format('H');
$current_minute = (int)$now->format('i');
$current_total_minutes = $current_hour * 60 + $current_minute;

echo "<h2>1. Pre-Show Check</h2>";
echo "<p>Current time: " . sprintf("%02d:%02d", $current_hour, $current_minute) . " (" . $current_total_minutes . " minutes)</p>";
echo "<p>Pre-show threshold: 23:20 (1400 minutes)</p>";

if ($current_total_minutes >= 1400) {
    echo "<p style='color:green; font-weight:bold'>✅ PRE-SHOW ACTIVE: Current time >= 23:20</p>";
    echo "<p>Tomorrow's shift 24 assignments SHOULD be shown</p>";
} else {
    echo "<p style='color:orange; font-weight:bold'>⏰ PRE-SHOW INACTIVE: Current time < 23:20</p>";
    echo "<p>Need to wait until 23:20 for pre-show to activate</p>";
    echo "<p>Time until 23:20: " . (1400 - $current_total_minutes) . " minutes</p>";
}

// Test regex
echo "<h2>2. Regex Test</h2>";
$test_codes = ['24', '24+', '22', '22+', '8', '16'];
echo "<table border='1' cellpadding='5'><tr><th>Code</th><th>Matches /^24\\+?\$/</th><th>Will be shown in pre-show?</th></tr>";
foreach ($test_codes as $code) {
    $matches = preg_match('/^24\+?$/', $code);
    echo "<tr><td>$code</td><td>" . ($matches ? 'YES' : 'NO') . "</td><td>" . ($matches ? '<strong>YES</strong>' : 'NO') . "</td></tr>";
}
echo "</table>";

// Test actual data
echo "<h2>3. Tomorrow's Shift Assignments</h2>";
$view_date = new DateTime('now', new DateTimeZone('Europe/Nicosia'));
$tomorrow_date = (clone $view_date)->modify('+1 day');
echo "<p>Today: " . $view_date->format('Y-m-d') . "</p>";
echo "<p>Tomorrow: " . $tomorrow_date->format('Y-m-d') . "</p>";

try {
    $employees = $pdo->query("SELECT id, name, external_id FROM employees WHERE is_active = 1 AND external_id IS NOT NULL ORDER BY name LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Name</th><th>External ID</th><th>Shift Tomorrow</th><th>Pre-Show?</th></tr>";
    
    $preshow_candidates = 0;
    foreach ($employees as $emp) {
        $vardiya_kod_tomorrow = get_vardiya_kod_for_day($emp['external_id'], $tomorrow_date->format('Y-m-d'));
        $matches = preg_match('/^24\+?$/', $vardiya_kod_tomorrow);
        
        if ($matches || preg_match('/^24/', $vardiya_kod_tomorrow)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($emp['name']) . "</td>";
            echo "<td>" . htmlspecialchars($emp['external_id']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($vardiya_kod_tomorrow) . "</strong></td>";
            echo "<td style='background:" . ($matches ? '#ccffcc' : '#ffcccc') . "'>";
            if ($matches) {
                echo '<strong>✅ YES (if time >= 23:20)</strong>';
                $preshow_candidates++;
            } else {
                echo '❌ NO (not 24 or 24+)';
            }
            echo "</td>";
            echo "</tr>";
        }
    }
    
    echo "</table>";
    
    echo "<p><strong>Total employees with shift 24/24+ tomorrow: " . $preshow_candidates . "</strong></p>";
    
    if ($preshow_candidates == 0) {
        echo "<p style='color:red; font-weight:bold'>⚠️ NO EMPLOYEES have shift 24/24+ assigned to tomorrow!</p>";
        echo "<p>This is why pre-show doesn't show anyone. Check shift assignments in HR system.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>4. Debug Mode</h2>";
echo "<p>To see detailed logs, access admin page with debug mode:</p>";
echo "<p><code>http://yourserver/breaklist_slot/admin/?debug=1</code></p>";
echo "<p>Then check PHP error log:</p>";
echo "<pre>tail -f /var/log/php-fpm/error.log</pre>";

echo "<h2>5. Troubleshooting</h2>";
echo "<ul>";
echo "<li>If current time < 23:20: Pre-show is not active yet, wait until 23:20</li>";
echo "<li>If no employees with shift 24 tomorrow: Nothing to show, assign shifts in HR system</li>";
echo "<li>If employees exist but not showing: Clear PHP OpCache and browser cache</li>";
echo "<li>Check server time matches expected time (Cyprus timezone)</li>";
echo "</ul>";

echo "<hr>";
echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . " (Server time)</small></p>";
?>
