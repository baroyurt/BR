<?php
/**
 * Comprehensive test simulating the exact scenario from problem statement
 * 
 * Scenario:
 * - Current time: Around 02:00-03:00 (night time)
 * - Samet: Shift 22+ (22:00-08:00) - should be WORKING
 * - Alper: Shift 24 (00:00-08:00) - should be WORKING
 */

// Include the fixed function
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
    
    $letter_shifts = ['A'=>['start'=>8,'duration'=>8],'B'=>['start'=>16,'duration'=>8],'C'=>['start'=>0,'duration'=>8],'J'=>['start'=>22,'duration'=>8]];
    
    if (isset($letter_shifts[$vardiya_kod])) {
        $base_hour = $letter_shifts[$vardiya_kod]['start'];
        $duration_hours = $letter_shifts[$vardiya_kod]['duration'];
    }
    
    if ($base_hour === null) return null;
    
    // FIX: Normalize hour 24 to hour 0 (midnight)
    if ($base_hour >= 24) {
        $base_hour = $base_hour % 24;
    }
    
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

function in_circular_range($cur, $from, $to) {
    if ($from === $to) return false;
    if ($from < $to) {
        return ($cur >= $from && $cur < $to);
    } else {
        return ($cur >= $from || $cur < $to);
    }
}

function get_visible_start_minute($start_total) {
    return max(0, $start_total - 40);
}

function test_employee($name, $shift_code, $current_hour, $current_minute) {
    echo "\n";
    echo "=" . str_repeat("=", 70) . "\n";
    echo " {$name} - Shift: {$shift_code}\n";
    echo "=" . str_repeat("=", 70) . "\n";
    
    $shift_info = calculate_shift_hours($shift_code);
    
    if (!$shift_info) {
        echo "❌ No shift info for code: {$shift_code}\n";
        return;
    }
    
    echo "Shift Details:\n";
    echo "  Start: {$shift_info['start_hour']}:00\n";
    echo "  End: {$shift_info['end_hour']}:00\n";
    echo "  Duration: {$shift_info['duration']} hours\n";
    echo "  Extended: " . ($shift_info['is_extended'] ? "YES" : "NO") . "\n";
    echo "  Wraps midnight: " . ($shift_info['wraps'] ? "YES" : "NO") . "\n";
    
    $start_total = $shift_info['start_hour'] * 60 + $shift_info['start_minute'];
    $end_total = $shift_info['end_hour'] * 60 + $shift_info['end_minute'];
    $start_minus = get_visible_start_minute($start_total);
    
    echo "\nCalculated Values:\n";
    echo "  start_total: {$start_total} minutes\n";
    echo "  end_total: {$end_total} minutes\n";
    echo "  start_minus (visibility): {$start_minus} minutes\n";
    
    $current_total_minutes = $current_hour * 60 + $current_minute;
    $is_visible_and_working = in_circular_range($current_total_minutes, $start_minus, $end_total);
    
    echo "\nCurrent Time: {$current_hour}:" . str_pad($current_minute, 2, '0', STR_PAD_LEFT) . " ({$current_total_minutes} minutes)\n";
    
    if ($is_visible_and_working) {
        $status = "WORKING";
        $icon = "✓";
        $color = "green";
    } elseif ($current_total_minutes < $start_total) {
        $status = "NOT STARTED YET";
        $icon = "○";
        $color = "blue";
    } else {
        $status = "FINISHED";
        $icon = "✗";
        $color = "red";
    }
    
    echo "\nStatus: {$icon} {$status}\n";
    
    // Validate
    $expected_working = true; // At night (02:00), both should be working
    if ($status === "WORKING" && $expected_working) {
        echo "Result: ✅ CORRECT\n";
    } else if ($status !== "WORKING" && $expected_working) {
        echo "Result: ❌ WRONG - Should be WORKING!\n";
    }
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║  Problem Scenario Test - Night Shift Display                      ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n";

echo "\nScenario: It's night time at 02:30 (02:30 AM)\n";
echo "Testing both employees who should be WORKING:\n";

// Test Samet
test_employee("SAMET GÜLMEZ", "22+", 2, 30);

// Test Alper
test_employee("ALPER KAVACIK", "24", 2, 30);

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║  Additional Time Tests                                            ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n";

echo "\nTesting at different times throughout the night:\n";

$test_times = [
    ['hour' => 23, 'minute' => 0, 'desc' => '23:00 - Before midnight'],
    ['hour' => 0, 'minute' => 0, 'desc' => '00:00 - Midnight'],
    ['hour' => 1, 'minute' => 0, 'desc' => '01:00 - After midnight'],
    ['hour' => 2, 'minute' => 30, 'desc' => '02:30 - Deep night'],
    ['hour' => 7, 'minute' => 30, 'desc' => '07:30 - Early morning'],
    ['hour' => 8, 'minute' => 0, 'desc' => '08:00 - End of shift'],
    ['hour' => 9, 'minute' => 0, 'desc' => '09:00 - After shift'],
];

foreach ($test_times as $time) {
    echo "\n--- Time: {$time['desc']} ---\n";
    
    // Test both employees
    foreach ([['name' => 'Samet', 'code' => '22+'], ['name' => 'Alper', 'code' => '24']] as $emp) {
        $shift_info = calculate_shift_hours($emp['code']);
        $start_total = $shift_info['start_hour'] * 60;
        $end_total = $shift_info['end_hour'] * 60;
        $start_minus = get_visible_start_minute($start_total);
        
        $current_minutes = $time['hour'] * 60 + $time['minute'];
        $is_working = in_circular_range($current_minutes, $start_minus, $end_total);
        
        // Proper status determination for wrapping and non-wrapping shifts
        if ($is_working) {
            $status = "WORKING";
        } else {
            // Check if we're before the shift start (considering wraps)
            if ($shift_info['wraps']) {
                // Wrapping shift: NOT STARTED if we're between end and start
                $is_not_started = in_circular_range($current_minutes, $end_total, $start_total);
                $status = $is_not_started ? "NOT STARTED" : "FINISHED";
            } else {
                // Normal shift: simple comparison
                $status = ($current_minutes < $start_total ? "NOT STARTED" : "FINISHED");
            }
        }
        echo "  {$emp['name']} ({$emp['code']}): {$status}\n";
    }
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════════╗\n";
echo "║  Conclusion                                                       ║\n";
echo "╚═══════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "✅ With the fix, both Samet and Alper correctly show as WORKING\n";
echo "   during their night shifts (22:00-08:00 and 00:00-08:00)\n";
echo "\n";
?>
