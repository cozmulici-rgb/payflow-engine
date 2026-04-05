<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$merchant = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
], [
    'legal_name' => 'Webhook Merchant Legal',
    'display_name' => 'Webhook Merchant',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));

$merchantId = $merchant->body['data']['merchant_id'];
$credential = $app->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
], ['merchant_id' => $merchantId]));

$headers = [
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $credential->body['data']['key_id'],
    'X-Merchant-Secret' => $credential->body['data']['secret'],
];

$registered = $app->handle(new Request('POST', '/v1/webhook-endpoints', $headers, [
    'url' => 'https://merchant.example/webhooks/payments',
    'event_types' => ['transaction.authorized', 'transaction.captured'],
]));
TestCase::assertSame(201, $registered->status);

$app->handle(new Request('POST', '/v1/webhook-endpoints', $headers, [
    'url' => 'https://merchant.example/failover/webhooks/payments',
    'event_types' => ['transaction.captured'],
]));

$create = $app->handle(new Request('POST', '/v1/transactions', $headers + [
    'Idempotency-Key' => 'idem-webhook-001',
    'X-Correlation-Id' => 'corr-webhook-create',
], [
    'type' => 'authorization',
    'amount' => '80.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_webhook'],
    'capture_mode' => 'manual',
]));

$transactionId = $create->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $transactionId . '/capture', $headers, ['amount' => '80.00']));
$processed = $app->processWebhookEvents();

$deliveries = $app->readWebhookDeliveries();
TestCase::assertSame(2, $processed);
TestCase::assertSame(3, count($deliveries));
TestCase::assertSame('transaction.authorized', $deliveries[0]['event_type']);
TestCase::assertSame('delivered', $deliveries[0]['status']);
TestCase::assertTrue($deliveries[0]['signature'] !== '');

foreach ($deliveries as $delivery) {
    if (($delivery['url'] ?? null) === 'https://merchant.example/failover/webhooks/payments') {
        TestCase::assertSame('delivered', $delivery['status']);
        TestCase::assertSame(1, $delivery['attempt']);
    }
}

$customBasePath = sys_get_temp_dir() . '/payflow-webhook-topic-' . bin2hex(random_bytes(4));
mkdir($customBasePath . '/config', 0777, true);
copy(__DIR__ . '/../../../config/payflow.php', $customBasePath . '/config/payflow.php');

$config = file_get_contents($customBasePath . '/config/payflow.php');
if ($config === false) {
    throw new RuntimeException('Failed to read temporary config file');
}

$updatedConfig = str_replace("'transaction.events'", "'merchant.transaction.events'", $config);
if ($updatedConfig === $config) {
    throw new RuntimeException('Failed to override transaction event topic for test');
}

file_put_contents($customBasePath . '/config/payflow.php', $updatedConfig);

$customApp = bootstrap_app($customBasePath);
$customApp->resetStorage();

$customMerchant = $customApp->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
], [
    'legal_name' => 'Webhook Topic Merchant Legal',
    'display_name' => 'Webhook Topic Merchant',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));

$customMerchantId = $customMerchant->body['data']['merchant_id'];
$customCredential = $customApp->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
], ['merchant_id' => $customMerchantId]));

$customHeaders = [
    'X-Merchant-Id' => $customMerchantId,
    'X-Merchant-Key-Id' => $customCredential->body['data']['key_id'],
    'X-Merchant-Secret' => $customCredential->body['data']['secret'],
];

$customApp->handle(new Request('POST', '/v1/webhook-endpoints', $customHeaders, [
    'url' => 'https://merchant.example/custom-topic/webhooks/payments',
    'event_types' => ['transaction.authorized', 'transaction.captured'],
]));

$customCreate = $customApp->handle(new Request('POST', '/v1/transactions', $customHeaders + [
    'Idempotency-Key' => 'idem-webhook-custom-topic-001',
    'X-Correlation-Id' => 'corr-webhook-custom-topic-create',
], [
    'type' => 'authorization',
    'amount' => '80.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_webhook_custom_topic'],
    'capture_mode' => 'manual',
]));

$customTransactionId = $customCreate->body['data']['transaction_id'];
$customApp->processPendingTransactionCommands();
$customApp->handle(new Request('POST', '/v1/transactions/' . $customTransactionId . '/capture', $customHeaders, ['amount' => '80.00']));

TestCase::assertSame('merchant.transaction.events', $customApp->readCommandBus()[1]['topic']);
TestCase::assertSame(2, $customApp->processWebhookEvents());
TestCase::assertSame(2, count($customApp->readWebhookDeliveries()));
