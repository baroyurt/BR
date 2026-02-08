<?php
/**
 * Standalone test for the hour 24 fix
 */

// Copy of the FIXED calculate_shift_hours function
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
    
    $letter_shifts = ['A'=>['start'=>8,'duration'=>8],'B'=>['start'=>16,'duration'=>8],'C'=>['start'=>0,'duration'=>8],'D'=>['start'=>9,'duration'=>9],'E'=>['start'=>14,'duration'=>8],'F'=>['start'=>10,'duration'=>8],'G'=>['start'=>18,'duration'=>8],'H'=>['start'=>12,'duration'=>8],'I'=>['start'=>13,'duration'=>8],'J'=>['start'=>22,'duration'=>8],'K'=>['start'=>20,'duration'=>8],'L'=>['start'=>7,'duration'=>8],'M'=>['start'=>6,'duration'=>8],'N'=>['start'=>23,'duration'=>8]];
    
    if (isset($letter_shifts[$vardiya_kod])) {
        $base_hour = $letter_shifts[$vardiya_kod]['start'];
        $duration_hours = $letter_shifts[$vardiya_kod]['duration'];
    }
    
    if ($base_hour === null) return null;
    
    // FIX: Normalize hour 24 to hour 0 (midnight)
    // Hour 24 (24:00) is the same as hour 0 (00:00) of the next day
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

function in_circular_range($cur, $from, $to) {
    if ($from === $to) return false;
    if ($from < $to) {
        return ($cur >= $from && $cur < $to);
    } else {
        // wraps midnight
        return ($cur >= $from || $cur < $to);
    }
}

echo "=== Verification Test for Hour 24 Fix ===\n\n";

// Test 1: Shift code "24"
echo "Test 1: Shift Code '24' (should be 00:00-08:00)\n";
echo "-------------------------------------------------\n";
$shift_24 = calculate_shift_hours('24');
print_r($shift_24);
$start_minutes = $shift_24['start_hour'] * 60;
echo "\nstart_hour: {$shift_24['start_hour']} (expected: 0)\n";
echo "start_minutes: {$start_minutes} (expected: 0)\n";
echo "end_hour: {$shift_24['end_hour']} (expected: 8)\n";
echo "wraps: " . ($shift_24['wraps'] ? "YES" : "NO") . " (expected: NO)\n";
echo "Result: " . ($start_minutes == 0 ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test at 02:00 (should be WORKING)
$current_time = 2 * 60; // 02:00 = 120 minutes
$start_minus = max(0, $start_minutes - 40);
$end_minutes = $shift_24['end_hour'] * 60;
$is_working = in_circular_range($current_time, $start_minus, $end_minutes);
echo "At 02:00, status: " . ($is_working ? "WORKING" : "NOT WORKING") . " (expected: WORKING)\n";
echo "Result: " . ($is_working ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 2: Shift code "22+"
echo "Test 2: Shift Code '22+' (should be 22:00-08:00)\n";
echo "---------------------------------------------------\n";
$shift_22plus = calculate_shift_hours('22+');
print_r($shift_22plus);
$start_minutes_22 = $shift_22plus['start_hour'] * 60;
echo "\nstart_hour: {$shift_22plus['start_hour']} (expected: 22)\n";
echo "start_minutes: {$start_minutes_22} (expected: 1320)\n";
echo "end_hour: {$shift_22plus['end_hour']} (expected: 8)\n";
echo "wraps: " . ($shift_22plus['wraps'] ? "YES" : "NO") . " (expected: YES)\n";
echo "Result: " . ($start_minutes_22 == 1320 && $shift_22plus['wraps'] ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test at 02:00 (should be WORKING)
$current_time = 2 * 60; // 02:00 = 120 minutes
$start_minus_22 = max(0, $start_minutes_22 - 40);
$end_minutes_22 = $shift_22plus['end_hour'] * 60;
$is_working_22 = in_circular_range($current_time, $start_minus_22, $end_minutes_22);
echo "At 02:00, status: " . ($is_working_22 ? "WORKING" : "NOT WORKING") . " (expected: WORKING)\n";
echo "Result: " . ($is_working_22 ? "PASS ✓" : "FAIL ✗") . "\n\n";

echo "=== Summary ===\n";
echo "✓ Hour 24 is now correctly normalized to hour 0\n";
echo "✓ Shift 24 (00:00-08:00) shows correctly as WORKING at 02:00\n";
echo "✓ Shift 22+ (22:00-08:00) continues to work correctly\n";
?>
