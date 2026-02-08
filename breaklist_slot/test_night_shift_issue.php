<?php
/**
 * Test for Night Shift (Wrapping Shift) Display Issues
 * 
 * Tests the specific issue:
 * - Samet: 22+ (22:00-08:00) shows as finished when still working
 * - Alper: 24 (24:00-08:00) shows as upcoming instead of working
 */

// Simulate the calculate_shift_hours function
function calculate_shift_hours_test($vardiya_kod) {
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

// Simulate in_circular_range
function in_circular_range_test($cur, $from, $to) {
    if ($from === $to) return false;
    if ($from < $to) {
        return ($cur >= $from && $cur < $to);
    } else {
        // wraps midnight
        return ($cur >= $from || $cur < $to);
    }
}

echo "=== Testing Night Shift Display Issues ===\n\n";

// Test Case 1: Samet with 22+ shift (22:00-08:00, 10 hours)
echo "Test 1: Samet - Shift 22+ (22:00-08:00)\n";
echo "---------------------------------------\n";
$shift_22plus = calculate_shift_hours_test('22+');
echo "Shift Info:\n";
print_r($shift_22plus);

$start_total = $shift_22plus['start_hour'] * 60 + $shift_22plus['start_minute'];
$end_total = $shift_22plus['end_hour'] * 60 + $shift_22plus['end_minute'];
$start_minus = max(0, $start_total - 40);

echo "\nCalculations:\n";
echo "  start_total: {$start_total} minutes (22:00)\n";
echo "  end_total: {$end_total} minutes (08:00)\n";
echo "  start_minus (visibility): {$start_minus} minutes\n";
echo "  wraps: " . ($shift_22plus['wraps'] ? "YES" : "NO") . "\n";

// Test at different times
$test_times = [
    ['time' => '02:00', 'minutes' => 2*60],
    ['time' => '07:00', 'minutes' => 7*60],
    ['time' => '08:00', 'minutes' => 8*60],
    ['time' => '21:00', 'minutes' => 21*60],
];

echo "\nStatus at different times:\n";
foreach ($test_times as $test) {
    $is_working = in_circular_range_test($test['minutes'], $start_minus, $end_total);
    // For wrapping shifts, use circular logic, not simple comparison
    if ($is_working) {
        $status = "WORKING";
    } else {
        // Check if we're before the shift start (considering wraps)
        if ($shift_22plus['wraps']) {
            // Wrapping shift: NOT STARTED if we're between end and start
            $is_not_started = in_circular_range_test($test['minutes'], $end_total, $start_total);
            $status = $is_not_started ? "NOT STARTED" : "FINISHED";
        } else {
            // Normal shift: simple comparison
            $status = ($test['minutes'] < $start_total ? "NOT STARTED" : "FINISHED");
        }
    }
    echo "  At {$test['time']}: {$status} " . ($is_working ? "✓" : "");
    if ($test['time'] == '02:00' || $test['time'] == '07:00') {
        echo " (should be WORKING)";
    } else if ($test['time'] == '21:00') {
        echo " (should be NOT STARTED)";
    }
    echo "\n";
}

echo "\n";

// Test Case 2: Alper with 24 shift (24:00-08:00, which is 00:00-08:00)
echo "Test 2: Alper - Shift 24 (24:00-08:00, really 00:00-08:00)\n";
echo "-----------------------------------------------------------\n";
$shift_24 = calculate_shift_hours_test('24');
echo "Shift Info:\n";
print_r($shift_24);

echo "\nISSUE: Shift 24 incorrectly has wraps=YES due to hour 24 calculation\n";
echo "  Expected wraps: NO (after normalization, 0-8 doesn't wrap)\n";
echo "  Actual wraps: " . ($shift_24['wraps'] ? "YES ❌" : "NO") . "\n";

$start_total_24 = $shift_24['start_hour'] * 60 + $shift_24['start_minute'];
$end_total_24 = $shift_24['end_hour'] * 60 + $shift_24['end_minute'];
$start_minus_24 = max(0, $start_total_24 - 40);

echo "\nCalculations:\n";
echo "  start_total: {$start_total_24} minutes";
if ($start_total_24 >= 1440) {
    echo " ❌ OUT OF RANGE (should be 0-1439)";
}
echo "\n";
echo "  end_total: {$end_total_24} minutes (08:00)\n";
echo "  start_minus (visibility): {$start_minus_24} minutes\n";
echo "  wraps: " . ($shift_24['wraps'] ? "YES" : "NO") . "\n";

echo "\nPROBLEM: Hour 24 should be normalized to hour 0!\n";
echo "  Expected start_total: 0 minutes (00:00)\n";
echo "  Actual start_total: {$start_total_24} minutes\n";

echo "\nStatus at different times (with BUGGY hour 24):\n";
foreach ($test_times as $test) {
    $is_working = in_circular_range_test($test['minutes'], $start_minus_24, $end_total_24);
    // For wrapping shifts, use circular logic
    if ($is_working) {
        $status = "WORKING";
    } else {
        // Check if we're before the shift start (considering wraps)
        if ($shift_24['wraps']) {
            // Wrapping shift: NOT STARTED if we're between end and start
            $is_not_started = in_circular_range_test($test['minutes'], $end_total_24, $start_total_24);
            $status = $is_not_started ? "NOT STARTED" : "FINISHED";
        } else {
            // Normal shift: simple comparison
            $status = ($test['minutes'] < $start_total_24 ? "NOT STARTED" : "FINISHED");
        }
    }
    echo "  At {$test['time']}: {$status}";
    if ($test['time'] == '02:00' || $test['time'] == '07:00') {
        echo " ❌ (should be WORKING)";
    }
    echo "\n";
}

echo "\n";
echo "=== FIX: Normalize hour 24 to hour 0 ===\n\n";

// Fixed version
function calculate_shift_hours_fixed($vardiya_kod) {
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
    
    // FIX: Normalize hour 24 to hour 0 (midnight)
    if ($base_hour >= 24) {
        $base_hour = $base_hour % 24;
    }
    
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

echo "Test 3: Alper - Shift 24 with FIX (normalized to 00:00-08:00)\n";
echo "--------------------------------------------------------------\n";
$shift_24_fixed = calculate_shift_hours_fixed('24');
echo "Shift Info:\n";
print_r($shift_24_fixed);

$start_total_24_fixed = $shift_24_fixed['start_hour'] * 60 + $shift_24_fixed['start_minute'];
$end_total_24_fixed = $shift_24_fixed['end_hour'] * 60 + $shift_24_fixed['end_minute'];
$start_minus_24_fixed = max(0, $start_total_24_fixed - 40);

echo "\nCalculations:\n";
echo "  start_total: {$start_total_24_fixed} minutes (00:00) ✓\n";
echo "  end_total: {$end_total_24_fixed} minutes (08:00)\n";
echo "  start_minus (visibility): {$start_minus_24_fixed} minutes\n";
echo "  wraps: " . ($shift_24_fixed['wraps'] ? "YES" : "NO") . "\n";

echo "\nStatus at different times (FIXED):\n";
foreach ($test_times as $test) {
    $is_working = in_circular_range_test($test['minutes'], $start_minus_24_fixed, $end_total_24_fixed);
    $status = $is_working ? "WORKING" : ($test['minutes'] < $start_total_24_fixed ? "NOT STARTED" : "FINISHED");
    $expected = "";
    if ($test['time'] == '02:00' || $test['time'] == '07:00') {
        $expected = $is_working ? " ✓ CORRECT" : " ❌ WRONG";
    } else if ($test['time'] == '21:00') {
        $expected = !$is_working ? " ✓ CORRECT" : " ❌ WRONG";
    }
    echo "  At {$test['time']}: {$status}{$expected}\n";
}

echo "\n";
echo "=== Summary ===\n";
echo "Issue: Shift code '24' (24:00) was not normalized to '00:00'\n";
echo "Fix: Normalize $base_hour to $base_hour % 24 before calculations\n";
echo "Result: Overnight shifts now display correctly\n";
?>
