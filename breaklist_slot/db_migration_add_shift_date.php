<?php
/**
 * Database Migration: Add shift_date and completed_at columns to work_slots
 * 
 * This migration adds:
 * - shift_date: DATE field to lock each assignment to a specific calendar day
 * - completed_at: DATETIME field to track when shift was marked complete
 */

require_once 'config.php';

try {
    echo "Starting database migration...\n";
    
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM work_slots LIKE 'shift_date'");
    $shift_date_exists = $stmt->rowCount() > 0;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM work_slots LIKE 'completed_at'");
    $completed_at_exists = $stmt->rowCount() > 0;
    
    if ($shift_date_exists && $completed_at_exists) {
        echo "✓ Columns already exist. No migration needed.\n";
        exit(0);
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Add shift_date column
    if (!$shift_date_exists) {
        echo "Adding shift_date column...\n";
        $pdo->exec("ALTER TABLE work_slots ADD COLUMN shift_date DATE NULL AFTER slot_end");
        echo "✓ shift_date column added\n";
    }
    
    // Add completed_at column
    if (!$completed_at_exists) {
        echo "Adding completed_at column...\n";
        $pdo->exec("ALTER TABLE work_slots ADD COLUMN completed_at DATETIME NULL AFTER shift_date");
        echo "✓ completed_at column added\n";
    }
    
    // Update existing records to set shift_date based on slot_start
    echo "Updating existing records with shift_date...\n";
    $pdo->exec("
        UPDATE work_slots 
        SET shift_date = DATE(slot_start) 
        WHERE shift_date IS NULL
    ");
    echo "✓ Existing records updated\n";
    
    // Add index for better performance on shift_date queries
    echo "Adding index on shift_date...\n";
    try {
        $pdo->exec("CREATE INDEX idx_shift_date ON work_slots(shift_date)");
        echo "✓ Index created\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "✓ Index already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Update API endpoints to use shift_date\n";
    echo "2. Add shift completion logic in admin/index.php\n";
    echo "3. Test day transition scenarios\n";
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
