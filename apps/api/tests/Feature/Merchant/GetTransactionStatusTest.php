<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$operatorHeaders = [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
];

$merchantA = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants',
    $operatorHeaders,
    [
        'legal_name' => 'Merchant A Inc.',
        'display_name' => 'Merchant A',
        'country' => 'CA',
        'default_currency' => 'CAD',
    ]
));
$merchantAId = $merchantA->body['data']['merchant_id'];
$credentialA = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants/credentials',
    $operatorHeaders,
    ['merchant_id' => $merchantAId]
));

$merchantB = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants',
    $operatorHeaders,
    [
        'legal_name' => 'Merchant B Inc.',
        'display_name' => 'Merchant B',
        'country' => 'CA',
        'default_currency' => 'CAD',
    ]
));
$merchantBId = $merchantB->body['data']['merchant_id'];
$credentialB = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants/credentials',
    $operatorHeaders,
    ['merchant_id' => $merchantBId]
));

$create = $app->handle(new Request(
    'POST',
    '/v1/transactions',
    [
        'X-Merchant-Id' => $merchantAId,
        'X-Merchant-Key-Id' => $credentialA->body['data']['key_id'],
        'X-Merchant-Secret' => $credentialA->body['data']['secret'],
        'Idempotency-Key' => 'idem-status-001',
    ],
    [
        'type' => 'authorization',
        'amount' => '42.00',
        'currency' => 'CAD',
        'payment_method' => [
            'type' => 'card_token',
            'token' => 'tok_status',
        ],
    ]
));

$transactionId = $create->body['data']['transaction_id'];

$get = $app->handle(new Request(
    'GET',
    '/v1/transactions/' . $transactionId,
    [
        'X-Merchant-Id' => $merchantAId,
        'X-Merchant-Key-Id' => $credentialA->body['data']['key_id'],
        'X-Merchant-Secret' => $credentialA->body['data']['secret'],
    ]
));

TestCase::assertSame(200, $get->status);
TestCase::assertSame($transactionId, $get->body['data']['transaction_id']);
TestCase::assertSame('pending', $get->body['data']['status']);
TestCase::assertSame('42.00', $get->body['data']['amount']);

$foreignRead = $app->handle(new Request(
    'GET',
    '/v1/transactions/' . $transactionId,
    [
        'X-Merchant-Id' => $merchantBId,
        'X-Merchant-Key-Id' => $credentialB->body['data']['key_id'],
        'X-Merchant-Secret' => $credentialB->body['data']['secret'],
    ]
));

TestCase::assertSame(404, $foreignRead->status);
TestCase::assertSame('Transaction not found', $foreignRead->body['message']);
