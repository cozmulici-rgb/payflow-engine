<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$createResponse = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants',
    [
        'X-Operator-Id' => 'op-123',
        'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
        'X-Correlation-Id' => 'corr-create',
    ],
    [
        'legal_name' => 'Acme Payments Canada Inc.',
        'display_name' => 'Acme Payments',
        'country' => 'CA',
        'default_currency' => 'CAD',
    ]
));

$merchantId = $createResponse->body['data']['merchant_id'];
$credentialResponse = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants/credentials',
    [
        'X-Operator-Id' => 'op-123',
        'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
        'X-Correlation-Id' => 'corr-credential',
    ],
    [
        'merchant_id' => $merchantId,
    ]
));

TestCase::assertSame(201, $credentialResponse->status);
TestCase::assertArrayHasKey('data', $credentialResponse->body);
TestCase::assertArrayHasKey('secret', $credentialResponse->body['data']);

$merchants = $app->readMerchants();
TestCase::assertSame(1, count($merchants[0]['credentials']));
TestCase::assertTrue(str_starts_with($merchants[0]['credentials'][0]['key_id'], 'pk_'));
TestCase::assertTrue(!str_contains($merchants[0]['credentials'][0]['secret_hash'], $credentialResponse->body['data']['secret']), 'Secret should be hashed at rest');

$audit = $app->readAuditLog();
TestCase::assertSame('merchant.api_credential_issued', $audit[1]['event_type']);
TestCase::assertSame('corr-credential', $audit[1]['correlation_id']);
