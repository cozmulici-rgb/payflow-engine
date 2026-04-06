<?php

declare(strict_types=1);

use App\Support\TestCase;

// Test: float addition gives wrong result; BCMath gives correct result
TestCase::assertTrue(
    0.1 + 0.2 !== 0.3,
    'Float 0.1+0.2 should NOT equal 0.3 (IEEE 754 rounding)'
);
TestCase::assertSame('0.3000', bcadd('0.1', '0.2', 4), 'BCMath 0.1+0.2 should equal 0.3000');

// Test: normalizeAmount via BCMath truncates at scale 4
TestCase::assertSame('1.2345', bcadd('1.23456789', '0', 4), 'BCMath truncates (not rounds) at scale 4');

// Test: large batch sum via BCMath is exact
$total = '0';
for ($i = 0; $i < 1000; $i++) {
    $total = bcadd($total, '9.9999', 4);
}
TestCase::assertSame('9999.9000', $total, '1000 * 9.9999 should be exactly 9999.9000');

// Test: zero guard using bccomp
TestCase::assertSame(0, bccomp('0.00', '0', 4), 'bccomp 0.00 vs 0 should be equal');
TestCase::assertTrue(bccomp('0.00', '0', 4) <= 0, 'Zero amount should fail isPositive guard');
TestCase::assertTrue(bccomp('0.01', '0', 4) > 0, 'Positive amount should pass isPositive guard');

// Test: FX settlement ratio calculation matches expected refund amount
// CAD 125.50 authorized → USD 92.87 settlement at rate 0.74
// Refunding CAD 20.00 → settlement ratio = 92.87 / 125.50 = 0.74000000
// Settlement refund = 20.00 * 0.74000000 = 14.8000
$settlementAmount = '92.87';
$transactionAmount = '125.50';
$refundAmount = '20.00';
$ratio = bcdiv($settlementAmount, $transactionAmount, 8);
$refundSettlement = bcadd(bcmul($refundAmount, $ratio, 8), '0', 4);
TestCase::assertSame('14.8000', $refundSettlement, 'FX refund settlement amount should be 14.8000');
