<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Database;
use App\Services\MembershipOrderService;
use App\Services\MembershipService;

function simulateProRataDelayTest()
{
    $pdo = Database::connection();
    echo "Starting Pro-Rata Webhook Delay Edge Case Test...\n";

    // 1. Create a mock user and member
    $email = 'prorata_test_' . time() . '@example.com';
    $stmt = $pdo->prepare('INSERT INTO users (email, name, password_hash, created_at) VALUES (:email, "Test User", "dummyhash", NOW())');
    $stmt->execute(['email' => $email]);
    $userId = (int) $pdo->lastInsertId();

    $memberBase = (int) substr(time(), -5);
    $stmt = $pdo->prepare('INSERT INTO members (user_id, email, first_name, last_name, status, member_type, member_number_base, member_number_suffix, created_at) VALUES (:user_id, :email, "Test", "User", "PENDING", "FULL", :base, 0, NOW())');
    $stmt->execute(['user_id' => $userId, 'email' => $email, 'base' => $memberBase]);
    $memberId = (int) $pdo->lastInsertId();

    // 2. Base scenario: It is July 31st 2026, user purchases 1 Year membership.
    // Cut-off Date: August 1st. If purchased on July 31st 2026, expiry = 2026-07-31.
    // If webhook delays to Aug 1st, they might wrongfully get 2027-07-31 without our fix!
    
    $simulatedOrderDate = '2026-07-31 23:30:00'; // Right before cutoff

    // Create membership period
    $stmt = $pdo->prepare('INSERT INTO membership_periods (member_id, term, start_date, status, created_at) VALUES (:member_id, "1Y", :start_date, "PENDING_PAYMENT", NOW())');
    $stmt->execute(['member_id' => $memberId, 'start_date' => '2026-07-31']);
    $periodId = (int) $pdo->lastInsertId();

    // Create order with explicitly manipulated created_at = July 31
    $stmt = $pdo->prepare('INSERT INTO orders (user_id, status, order_type, currency, subtotal, tax_total, shipping_total, total, channel_id, created_at) VALUES (:user_id, "pending", "membership", "AUD", 0, 0, 0, 0, 1, :created_at)');
    $stmt->execute([
        'user_id' => $userId,
        'created_at' => $simulatedOrderDate
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // Fetch the stored order
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);

    echo "Initial Order Created: {$order['created_at']} \n";

    // 3. System time is currently (or simulated to be) August 1st or beyond for Webhook reception.
    // Note: Since we cannot override system `new DateTimeImmutable('today')` without monkey patching, 
    // the code fix uses \$order['created_at']. If the fix works, it will yield `2026-07-31`!
    
    // Trigger the actual backend logic
    $activated = MembershipOrderService::activateMembershipForOrder($order, [
        'payment_intent' => 'pi_simulated_delay_' . time(),
        'period_id' => $periodId,
        'member_id' => $memberId
    ]);

    if (!$activated) {
        throw new \Exception("Failed to activate membership.");
    }

    // 4. Assertions on the applied period
    $stmt = $pdo->prepare('SELECT * FROM membership_periods WHERE id = :id');
    $stmt->execute(['id' => $periodId]);
    $updatedPeriod = $stmt->fetch(\PDO::FETCH_ASSOC);

    echo "Calculated Start Date: {$updatedPeriod['start_date']}\n";
    echo "Calculated End Date: {$updatedPeriod['end_date']}\n";

    if ($updatedPeriod['start_date'] !== '2026-07-31') {
        throw new \Exception("Start date shifted incorrectly! Expected 2026-07-31 but got {$updatedPeriod['start_date']}");
    }

    // If purchased prior to Aug 1 2026 with 1-year term, expiry MUST be 2026-07-31.
    if ($updatedPeriod['end_date'] !== '2026-07-31') {
         throw new \Exception("Pro-rata boundary violation! Expiry calculated as {$updatedPeriod['end_date']} but should be 2026-07-31. They gained a free year.");
    }

    echo "✅ TEST PASSED: Webhook delay did not breach pro-rata boundary!\n";

    // Cleanup
    $pdo->exec("DELETE FROM orders WHERE id = $orderId");
    $pdo->exec("DELETE FROM membership_periods WHERE id = $periodId");
    $pdo->exec("DELETE FROM members WHERE id = $memberId");
    $pdo->exec("DELETE FROM users WHERE id = $userId");
}

try {
    simulateProRataDelayTest();
} catch (\Exception $e) {
    echo "❌ TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
