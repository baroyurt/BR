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
    echo "<p>Shift 24 assignments are shown as TODAY's personnel</p>";
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
echo "<h2>3. Shift 24 Assignments</h2>";
$today_date = new DateTime('now', new DateTimeZone('Europe/Nicosia'));
$yesterday_date = (clone $today_date)->modify('-1 day');

echo "<p><strong>Current time:</strong> " . $today_date->format('Y-m-d H:i:s') . "</p>";
echo "<p><strong>Today:</strong> " . $today_date->format('Y-m-d') . "</p>";
echo "<p><strong>Yesterday:</strong> " . $yesterday_date->format('Y-m-d') . "</p>";

echo "<h3>Understanding Shift 24 Assignment:</h3>";
echo "<ul>";
echo "<li>Shift 24 starts at 00:00 (midnight)</li>";
echo "<li>In this HR system, it's assigned to the WORKING DAY (the day before midnight)</li>";
echo "<li>Example: Shift 24 starting at 00:00 on Feb 12 → assigned to Feb 11 in HR</li>";
echo "<li><strong>At 00:24 on Feb 12: Currently working shift 24 is assigned to Feb 11</strong></li>";
echo "</ul>";

try {
    $employees = $pdo->query("SELECT id, name, external_id FROM employees WHERE is_active = 1 AND external_id IS NOT NULL ORDER BY name LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    
    // Check YESTERDAY (currently working shift 24)
    echo "<h3>A. Yesterday's Shift 24 (Currently Working at " . $today_date->format('H:i') . ")</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Name</th><th>External ID</th><th>Shift on " . $yesterday_date->format('Y-m-d') . "</th><th>Currently Working?</th></tr>";
    
    $currently_working = 0;
    foreach ($employees as $emp) {
        $vardiya_kod = get_vardiya_kod_for_day($emp['external_id'], $yesterday_date->format('Y-m-d'));
        $matches = preg_match('/^24\+?$/', $vardiya_kod);
        
        if ($matches || preg_match('/^24/', $vardiya_kod)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($emp['name']) . "</td>";
            echo "<td>" . htmlspecialchars($emp['external_id']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($vardiya_kod) . "</strong></td>";
            echo "<td style='background:" . ($matches ? '#ccffcc' : '#ffcccc') . "'>";
            if ($matches) {
                // Check if currently within shift time (00:00 - 08:00 typically)
                $current_hour = (int)$today_date->format('H');
                $in_shift_time = ($current_hour >= 0 && $current_hour < 8);
                echo $in_shift_time ? '<strong>✅ YES (working now)</strong>' : '⏰ Shift ended';
                if ($in_shift_time) $currently_working++;
            } else {
                echo '❌ NO (not 24 or 24+)';
            }
            echo "</td>";
            echo "</tr>";
        }
    }
    
    echo "</table>";
    echo "<p><strong>Total shift 24/24+ from yesterday currently working: " . $currently_working . "</strong></p>";
    
    if ($currently_working == 0) {
        echo "<p style='color:red; font-weight:bold'>⚠️ NO shift 24 personnel from yesterday found!</p>";
    }
    
    // Check TODAY (for pre-show / future shift)
    echo "<h3>B. Today's Shift 24 (Pre-show at 23:20, starts at 00:00 tomorrow)</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Name</th><th>External ID</th><th>Shift on " . $today_date->format('Y-m-d') . "</th><th>Status</th></tr>";
    
    $preshow_candidates = 0;
    foreach ($employees as $emp) {
        $vardiya_kod = get_vardiya_kod_for_day($emp['external_id'], $today_date->format('Y-m-d'));
        $matches = preg_match('/^24\+?$/', $vardiya_kod);
        
        if ($matches || preg_match('/^24/', $vardiya_kod)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($emp['name']) . "</td>";
            echo "<td>" . htmlspecialchars($emp['external_id']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($vardiya_kod) . "</strong></td>";
            echo "<td style='background:" . ($matches ? '#ffffcc' : '#ffcccc') . "'>";
            if ($matches) {
                $current_hour = (int)$today_date->format('H');
                $current_minute = (int)$today_date->format('i');
                $current_total_minutes = $current_hour * 60 + $current_minute;
                
                if ($current_total_minutes >= 1400) {
                    echo '🔔 Pre-show active (visible in admin)';
                } else {
                    echo '⏰ Waiting for 23:20 pre-show';
                }
                $preshow_candidates++;
            } else {
                echo '❌ NO (not 24 or 24+)';
            }
            echo "</td>";
            echo "</tr>";
        }
    }
    
    echo "</table>";
    
    echo "<p><strong>Total employees with shift 24/24+ on " . $today_date->format('Y-m-d') . ": " . $preshow_candidates . "</strong></p>";
    
    if ($preshow_candidates == 0) {
        echo "<p style='color:orange; font-weight:bold'>⚠️ NO EMPLOYEES have shift 24/24+ assigned to " . $today_date->format('Y-m-d') . " in HR system!</p>";
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

echo "<h2>6. What Admin Should Be Doing Right Now</h2>";
$current_hour = (int)$today_date->format('H');
$current_minute = (int)$today_date->format('i');
$current_total_minutes = $current_hour * 60 + $current_minute;

echo "<p><strong>Current time:</strong> " . sprintf("%02d:%02d", $current_hour, $current_minute) . " (" . $current_total_minutes . " minutes)</p>";

if ($current_total_minutes >= 1400) {
    echo "<div style='background:#e7f3ff; padding:15px; border-left:4px solid #2196F3;'>";
    echo "<h3>Pre-show Logic Should Be Active</h3>";
    echo "<p>✓ Time >= 23:20 (" . $current_total_minutes . " >= 1400)</p>";
    echo "<p>✓ Admin should query: <strong>" . $today_date->format('Y-m-d') . "</strong> for shift 24</p>";
    echo "<p>✓ Personnel with shift 24 on " . $today_date->format('Y-m-d') . " should appear in admin</p>";
    echo "</div>";
} elseif ($current_total_minutes < 480) { // Before 08:00
    echo "<div style='background:#fff3cd; padding:15px; border-left:4px solid #ffc107;'>";
    echo "<h3>Previous Day Wrapping Logic Should Be Active</h3>";
    echo "<p>✓ Time is after midnight but before 08:00</p>";
    echo "<p>✓ Admin should check: <strong>" . $yesterday_date->format('Y-m-d') . "</strong> for wrapping shifts</p>";
    echo "<p>✓ Shift 24 from yesterday (if exists) should be caught by 'previous day wrapping' logic</p>";
    echo "<p>✓ This is handled by lines 342-437 in admin/index.php</p>";
    echo "<p><strong>Check:</strong> Does shift have 'wraps' property = true?</p>";
    echo "</div>";
} else {
    echo "<div style='background:#f0f0f0; padding:15px; border-left:4px solid #666;'>";
    echo "<h3>Normal Day Logic</h3>";
    echo "<p>No special shift 24 logic active at this time</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . " (Server time)</small></p>";
?>
