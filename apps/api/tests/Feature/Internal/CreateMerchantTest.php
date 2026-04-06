<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$response = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants',
    [
        'X-Operator-Id' => 'op-123',
        'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
    ],
    [
        'legal_name' => 'Acme Payments Canada Inc.',
        'display_name' => 'Acme Payments',
        'country' => 'CA',
        'default_currency' => 'CAD',
    ]
));

TestCase::assertSame(201, $response->status);
TestCase::assertArrayHasKey('data', $response->body);
TestCase::assertArrayHasKey('merchant_id', $response->body['data']);

$merchants = $app->readMerchants();
TestCase::assertSame(1, count($merchants));
TestCase::assertSame('Acme Payments', $merchants[0]['display_name']);

$audit = $app->readAuditLog();
TestCase::assertSame(1, count($audit));
TestCase::assertSame('merchant.created', $audit[0]['event_type']);
TestCase::assertTrue(isset($audit[0]['correlation_id']) && $audit[0]['correlation_id'] !== '', 'Expected generated correlation ID');
