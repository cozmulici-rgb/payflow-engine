<?php

declare(strict_types=1);

namespace Tests\Behat;

use App\Http\Request;
use App\Http\Response;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Modules\Audit\Application\WriteAuditRecord;
use Modules\Audit\Infrastructure\Persistence\FileAuditLogWriter;
use Modules\FXCrossBorder\Application\LockRate\FxRateLockService;
use Modules\FXCrossBorder\Infrastructure\Persistence\RateLockRepository;
use Modules\Ledger\Application\PostAuthorizationLedgerEntries;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;
use Modules\PaymentProcessing\Application\AuthorizeTransaction\AuthorizeTransactionHandler;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Providers\Fraud\FraudScreeningService;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;
use RuntimeException;

require_once __DIR__ . '/../../../bootstrap/app.php';

final class FeatureContext implements Context
{
    private \App\Support\Application $app;
    private ?Response $response = null;
    private ?string $merchantId = null;
    private ?array $merchantCredential = null;
    private ?string $foreignMerchantId = null;
    private ?array $foreignMerchantCredential = null;
    /** @var array<string,string> */
    private array $transactionAliases = [];
    private ?string $currentTransactionId = null;
    private ?int $lastProcessedCount = null;
    private ?\Throwable $caughtException = null;

    /** @BeforeScenario */
    public function beforeScenario(): void
    {
        $basePath = dirname(__DIR__, 3);
        $this->app = bootstrap_app($basePath);
        $this->app->resetStorage();
        $this->response = null;
        $this->merchantId = null;
        $this->merchantCredential = null;
        $this->foreignMerchantId = null;
        $this->foreignMerchantCredential = null;
        $this->transactionAliases = [];
        $this->currentTransactionId = null;
        $this->lastProcessedCount = null;
        $this->caughtException = null;
    }

    /** @Given the API is bootstrapped */
    public function theApiIsBootstrapped(): void
    {
    }

    /** @Given an operator with role :role */
    public function anOperatorWithRole(string $role): void
    {
        if ($role === '') {
            throw new RuntimeException('Operator role must not be empty');
        }
    }

    /** @When an internal caller requests :requestLine */
    public function anInternalCallerRequests(string $requestLine): void
    {
        [$method, $path] = $this->splitRequestLine($requestLine);
        $this->response = $this->app->handle(new Request($method, $path));
    }

    /** @When the operator creates a merchant named :displayName */
    public function theOperatorCreatesAMerchantNamed(string $displayName): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/internal/v1/merchants',
            $this->operatorHeaders(),
            [
                'legal_name' => $displayName . ' Legal',
                'display_name' => $displayName,
                'country' => 'CA',
                'default_currency' => 'CAD',
            ]
        ));

        $this->merchantId = (string) ($this->response->body['data']['merchant_id'] ?? '');
    }

    /** @Given an existing merchant :displayName */
    public function anExistingMerchant(string $displayName): void
    {
        $this->theOperatorCreatesAMerchantNamed($displayName);
    }

    /** @When the operator issues API credentials for that merchant */
    public function theOperatorIssuesApiCredentialsForThatMerchant(): void
    {
        $this->assertNotEmpty($this->merchantId, 'Expected merchant to exist before issuing credentials');

        $this->response = $this->app->handle(new Request(
            'POST',
            '/internal/v1/merchants/credentials',
            $this->operatorHeaders('corr-credential'),
            ['merchant_id' => $this->merchantId]
        ));

        $this->merchantCredential = $this->response->body['data'] ?? null;
    }

    /** @Given an active merchant with valid API credentials */
    public function anActiveMerchantWithValidApiCredentials(): void
    {
        $name = 'Merchant ' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->theOperatorCreatesAMerchantNamed($name);
        $this->theOperatorIssuesApiCredentialsForThatMerchant();
    }

    /** @When the merchant submits a new authorization request with idempotency key :idempotencyKey */
    public function theMerchantSubmitsANewAuthorizationRequestWithIdempotencyKey(string $idempotencyKey): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders($idempotencyKey, 'corr-transaction'),
            $this->defaultTransactionBody()
        ));

        $this->currentTransactionId = (string) ($this->response->body['data']['transaction_id'] ?? '');
    }

    /** @Given a previously accepted transaction for idempotency key :idempotencyKey */
    public function aPreviouslyAcceptedTransactionForIdempotencyKey(string $idempotencyKey): void
    {
        $this->theMerchantSubmitsANewAuthorizationRequestWithIdempotencyKey($idempotencyKey);
    }

    /** @When the merchant resubmits the same authorization payload with idempotency key :idempotencyKey */
    public function theMerchantResubmitsTheSameAuthorizationPayloadWithIdempotencyKey(string $idempotencyKey): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders($idempotencyKey, 'corr-transaction-replay'),
            $this->defaultTransactionBody()
        ));
    }

    /** @Given the merchant created transaction :alias in status :status */
    public function theMerchantCreatedTransactionInStatus(string $alias, string $status): void
    {
        $this->theMerchantSubmitsANewAuthorizationRequestWithIdempotencyKey('idem-' . strtolower($alias));
        $this->setTransactionAlias($alias, $this->currentTransactionId);

        if ($status === 'authorized') {
            $this->thePaymentWorkerProcessesPendingTransactionCommands();
        }
    }

    /** @When the merchant requests :requestLine */
    public function theMerchantRequests(string $requestLine): void
    {
        [$method, $path] = $this->splitRequestLine($requestLine);
        $this->response = $this->app->handle(new Request($method, $this->resolvePathAliases($path), $this->merchantHeaders()));
    }

    /** @Given merchant A created transaction :alias */
    public function merchantACreatedTransaction(string $alias): void
    {
        $this->theMerchantCreatedTransactionInStatus($alias, 'pending');
    }

    /** @Given merchant B has separate API credentials */
    public function merchantBHasSeparateApiCredentials(): void
    {
        $response = $this->app->handle(new Request(
            'POST',
            '/internal/v1/merchants',
            $this->operatorHeaders(),
            [
                'legal_name' => 'Merchant B Legal',
                'display_name' => 'Merchant B',
                'country' => 'CA',
                'default_currency' => 'CAD',
            ]
        ));

        $this->foreignMerchantId = (string) ($response->body['data']['merchant_id'] ?? '');
        $credential = $this->app->handle(new Request(
            'POST',
            '/internal/v1/merchants/credentials',
            $this->operatorHeaders(),
            ['merchant_id' => $this->foreignMerchantId]
        ));

        $this->foreignMerchantCredential = $credential->body['data'] ?? null;
    }

    /** @When merchant B requests :requestLine */
    public function merchantBRequests(string $requestLine): void
    {
        [$method, $path] = $this->splitRequestLine($requestLine);
        $this->response = $this->app->handle(new Request(
            $method,
            $this->resolvePathAliases($path),
            $this->merchantHeadersFor($this->foreignMerchantId, $this->foreignMerchantCredential)
        ));
    }

    /** @When the merchant submits an authorization request in currency :currency for a :merchantCurrency merchant */
    public function theMerchantSubmitsAnAuthorizationRequestInCurrencyForAMerchant(string $currency, string $merchantCurrency): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders('idem-invalid-currency'),
            [
                'type' => 'authorization',
                'amount' => '10.00',
                'currency' => $currency,
                'payment_method' => [
                    'type' => 'card_token',
                    'token' => 'tok_456',
                ],
                'metadata' => [
                    'merchant_currency' => $merchantCurrency,
                ],
            ]
        ));
    }

    /** @Given a pending authorization transaction for amount :amount in currency :currency */
    public function aPendingAuthorizationTransactionForAmountInCurrency(string $amount, string $currency): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders('idem-pending-' . preg_replace('/\W+/', '-', $amount)),
            [
                'type' => 'authorization',
                'amount' => $amount,
                'currency' => $currency,
                'payment_method' => ['type' => 'card_token', 'token' => 'tok-approved'],
                'capture_mode' => 'manual',
                'metadata' => ['channel' => 'web'],
            ]
        ));

        $this->currentTransactionId = (string) ($this->response->body['data']['transaction_id'] ?? '');
    }

    /** @Given a pending authorization transaction flagged for fraud rejection */
    public function aPendingAuthorizationTransactionFlaggedForFraudRejection(): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders('idem-worker-fraud'),
            [
                'type' => 'authorization',
                'amount' => '99.00',
                'currency' => 'CAD',
                'payment_method' => ['type' => 'card_token', 'token' => 'tok-fraud'],
                'metadata' => ['channel' => 'fraud'],
            ]
        ));

        $this->currentTransactionId = (string) ($this->response->body['data']['transaction_id'] ?? '');
    }

    /** @Given a pending cross-border authorization transaction from :baseCurrency to :quoteCurrency */
    public function aPendingCrossBorderAuthorizationTransactionFromTo(string $baseCurrency, string $quoteCurrency): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders('idem-worker-timeout'),
            [
                'type' => 'authorization',
                'amount' => '50.00',
                'currency' => $baseCurrency,
                'settlement_currency' => $quoteCurrency,
                'payment_method' => ['type' => 'card_token', 'token' => 'tok-timeout'],
                'metadata' => ['channel' => 'timeout-confirm'],
            ]
        ));

        $this->currentTransactionId = (string) ($this->response->body['data']['transaction_id'] ?? '');
    }

    /** @Given the processor is configured to timeout before inquiry confirms approval */
    public function theProcessorIsConfiguredToTimeoutBeforeInquiryConfirmsApproval(): void
    {
    }

    /**
     * @When the payment worker processes pending transaction commands
     * @When the payment worker processes pending transaction commands again
     */
    public function thePaymentWorkerProcessesPendingTransactionCommands(): void
    {
        $this->caughtException = null;
        $this->lastProcessedCount = $this->app->processPendingTransactionCommands();
    }

    /** @Given a transaction is in status :status */
    public function aTransactionIsInStatus(string $status): void
    {
        $this->aPendingAuthorizationTransactionForAmountInCurrency('30.00', 'CAD');
        if ($status === 'failed') {
            $this->response = $this->app->handle(new Request(
                'POST',
                '/v1/transactions',
                $this->merchantHeaders('idem-state-failed'),
                [
                    'type' => 'authorization',
                    'amount' => '30.00',
                    'currency' => 'CAD',
                    'payment_method' => ['type' => 'card_token', 'token' => 'tok-failed'],
                    'metadata' => ['channel' => 'fraud'],
                ]
            ));
            $this->currentTransactionId = (string) ($this->response->body['data']['transaction_id'] ?? '');
            $this->app->processPendingTransactionCommands();
        }
    }

    /** @When the state machine evaluates a transition to :status */
    public function theStateMachineEvaluatesATransitionTo(string $status): void
    {
        $transaction = $this->requireCurrentTransaction();
        $allowed = \Modules\PaymentProcessing\Domain\TransactionStateMachine::canTransition(
            $transaction->status,
            \Modules\PaymentProcessing\Domain\TransactionStatus::from($status)
        );
        $this->response = new Response($allowed ? 200 : 422, ['allowed' => $allowed]);
    }

    /** @Then the transition should be rejected */
    public function theTransitionShouldBeRejected(): void
    {
        $this->assertSame(422, $this->requireResponse()->status);
        $this->assertSame(false, $this->requireResponse()->body['allowed'] ?? null);
    }

    /** @Given the authorization ledger accounts are unavailable */
    public function theAuthorizationLedgerAccountsAreUnavailable(): void
    {
    }

    /** @When the payment worker processes the authorization command */
    public function thePaymentWorkerProcessesTheAuthorizationCommand(): void
    {
        $this->caughtException = null;

        $basePath = dirname(__DIR__, 3);
        $storage = $basePath . '/storage';
        $handler = new AuthorizeTransactionHandler(
            $this->app->transactionRepository(),
            new ProcessorRouter(),
            new FraudScreeningService(),
            new FxRateLockService(new RateLockRepository($storage . '/rate_locks.json'), $basePath . '/config/payflow.php'),
            new KafkaCommandPublisher($storage . '/command_bus.json', 'transaction.events'),
            new WriteAuditRecord(new FileAuditLogWriter($storage . '/audit_log.json')),
            new PostAuthorizationLedgerEntries(new LedgerRepository(
                $storage . '/missing_accounts.json',
                $storage . '/journal_entries.json',
                $storage . '/ledger_entries.json'
            )),
            $storage . '/processed_events.json',
            $basePath . '/config/payflow.php'
        );

        try {
            $messages = $this->app->readCommandBus();
            $handler->handle($messages[0]['payload']);
        } catch (\Throwable $throwable) {
            $this->caughtException = $throwable;
        }
    }

    /** @Then authorization should fail with a runtime error */
    public function authorizationShouldFailWithARuntimeError(): void
    {
        $this->assertTrue($this->caughtException instanceof RuntimeException, 'Expected runtime exception during authorization');
    }

    /** @Given the merchant has an authorized transaction for amount :amount in currency :currency */
    public function theMerchantHasAnAuthorizedTransactionForAmountInCurrency(string $amount, string $currency): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions',
            $this->merchantHeaders('idem-authorized-' . preg_replace('/\W+/', '-', $amount), 'corr-create-authorized'),
            [
                'type' => 'authorization',
                'amount' => $amount,
                'currency' => $currency,
                'settlement_currency' => 'USD',
                'payment_method' => ['type' => 'card_token', 'token' => 'tok_capture'],
                'capture_mode' => 'manual',
            ]
        ));

        $this->currentTransactionId = (string) ($this->response->body['data']['transaction_id'] ?? '');
        $this->app->processPendingTransactionCommands();
    }

    /** @When the merchant captures the transaction for amount :amount */
    public function theMerchantCapturesTheTransactionForAmount(string $amount): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions/' . $this->requireCurrentTransaction()->id . '/capture',
            $this->merchantHeaders(null, 'corr-capture'),
            ['amount' => $amount]
        ));
    }

    /** @Given the merchant already captured the transaction for amount :amount */
    public function theMerchantAlreadyCapturedTheTransactionForAmount(string $amount): void
    {
        $this->theMerchantCapturesTheTransactionForAmount($amount);
        $this->assertSame(202, $this->requireResponse()->status);
    }

    /** @When the merchant refunds the transaction for amount :amount */
    public function theMerchantRefundsTheTransactionForAmount(string $amount): void
    {
        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/transactions/' . $this->requireCurrentTransaction()->id . '/refund',
            $this->merchantHeaders(null, 'corr-refund'),
            ['amount' => $amount]
        ));
    }

    /** @Given the merchant registered webhook endpoint :url for: */
    public function theMerchantRegisteredWebhookEndpointFor(string $url, TableNode $table): void
    {
        $eventTypes = [];
        foreach ($table->getRows() as $row) {
            $eventTypes[] = (string) ($row[0] ?? '');
        }

        $this->response = $this->app->handle(new Request(
            'POST',
            '/v1/webhook-endpoints',
            $this->merchantHeaders(),
            [
                'url' => $url,
                'event_types' => $eventTypes,
            ]
        ));

        $this->assertSame(201, $this->response->status);
    }

    /** @When the webhook worker processes transaction lifecycle events */
    public function theWebhookWorkerProcessesTransactionLifecycleEvents(): void
    {
        $this->lastProcessedCount = $this->app->processWebhookEvents();
    }

    /** @Then the response status should be :status */
    public function theResponseStatusShouldBe(int $status): void
    {
        $this->assertSame($status, $this->requireResponse()->status);
    }

    /** @Then the response body should contain :key = :value */
    public function theResponseBodyShouldContain(string $key, string $value): void
    {
        $actual = $this->bodyValue($this->requireResponse()->body, $key);
        $expected = $this->transactionAliases[$value] ?? $value;
        $this->assertSame($expected, $this->normalizeScalar($actual));
    }

    /** @Then the response body should contain a non-empty :key */
    public function theResponseBodyShouldContainANonEmpty(string $key): void
    {
        $value = $this->bodyValue($this->requireResponse()->body, $key);
        $this->assertTrue(is_string($value) && $value !== '', sprintf('Expected non-empty body value for [%s]', $key));
    }

    /** @Then the readiness checks should report :key = :value */
    public function theReadinessChecksShouldReport(string $key, string $value): void
    {
        $checks = $this->requireResponse()->body['checks'] ?? [];
        $this->assertSame($value, $checks[$key] ?? null);
    }

    /** @Then a merchant record should be stored for :displayName */
    public function aMerchantRecordShouldBeStoredFor(string $displayName): void
    {
        $merchants = $this->app->readMerchants();
        $this->assertSame($displayName, $merchants[0]['display_name'] ?? null);
    }

    /** @Then an audit event :eventType should be recorded */
    public function anAuditEventShouldBeRecorded(string $eventType): void
    {
        foreach ($this->app->readAuditLog() as $event) {
            if (($event['event_type'] ?? null) === $eventType) {
                return;
            }
        }

        throw new RuntimeException(sprintf('Audit event [%s] not found', $eventType));
    }

    /** @Then the audit event should contain a correlation id */
    public function theAuditEventShouldContainACorrelationId(): void
    {
        $events = $this->app->readAuditLog();
        $last = end($events);
        $this->assertTrue(is_array($last) && (($last['correlation_id'] ?? '') !== ''), 'Expected audit event correlation id');
    }

    /** @Then the response should include a generated API secret */
    public function theResponseShouldIncludeAGeneratedApiSecret(): void
    {
        $secret = $this->bodyValue($this->requireResponse()->body, 'secret');
        $this->assertTrue(is_string($secret) && $secret !== '', 'Expected generated secret');
    }

    /** @Then the stored credential key id should start with :prefix */
    public function theStoredCredentialKeyIdShouldStartWith(string $prefix): void
    {
        $merchants = $this->app->readMerchants();
        $keyId = $merchants[0]['credentials'][0]['key_id'] ?? null;
        $this->assertTrue(is_string($keyId) && str_starts_with($keyId, $prefix), sprintf('Expected key id prefix [%s]', $prefix));
    }

    /** @Then the stored secret should be hashed at rest */
    public function theStoredSecretShouldBeHashedAtRest(): void
    {
        $merchants = $this->app->readMerchants();
        $storedHash = $merchants[0]['credentials'][0]['secret_hash'] ?? '';
        $secret = $this->bodyValue($this->requireResponse()->body, 'secret');
        $this->assertTrue(is_string($storedHash) && is_string($secret) && !str_contains($storedHash, $secret), 'Secret should be hashed at rest');
    }

    /** @Then exactly :count transaction should be persisted for the merchant */
    public function exactlyTransactionShouldBePersistedForTheMerchant(int $count): void
    {
        $transactions = array_values(array_filter(
            $this->app->readTransactions(),
            fn (array $transaction): bool => ($transaction['merchant_id'] ?? null) === $this->merchantId
        ));
        $this->assertSame($count, count($transactions));
    }

    /** @Then the transaction status history should contain :count :status entry */
    public function theTransactionStatusHistoryShouldContainEntry(int $count, string $status): void
    {
        $history = array_values(array_filter(
            $this->app->readTransactionStatusHistory(),
            fn (array $row): bool => ($row['status'] ?? null) === $status
        ));
        $this->assertSame($count, count($history));
    }

    /** @Then exactly :count processing command should be published to topic :topic */
    public function exactlyProcessingCommandShouldBePublishedToTopic(int $count, string $topic): void
    {
        $commands = array_values(array_filter(
            $this->app->readCommandBus(),
            fn (array $message): bool => ($message['topic'] ?? null) === $topic
        ));
        $this->assertSame($count, count($commands));
    }

    /** @Then the original transaction id should be returned */
    public function theOriginalTransactionIdShouldBeReturned(): void
    {
        $transactions = $this->app->readTransactions();
        $this->assertSame($transactions[0]['id'] ?? null, $this->bodyValue($this->requireResponse()->body, 'transaction_id'));
    }

    /** @Then only :count transaction should exist for that idempotency key */
    public function onlyTransactionShouldExistForThatIdempotencyKey(int $count): void
    {
        $rows = array_values(array_filter(
            $this->app->readTransactions(),
            fn (array $row): bool => ($row['idempotency_key'] ?? null) === 'idem-transaction-001'
        ));
        $this->assertSame($count, count($rows));
    }

    /** @Then only :count processing command should exist for that idempotency key */
    public function onlyProcessingCommandShouldExistForThatIdempotencyKey(int $count): void
    {
        $messages = array_values(array_filter(
            $this->app->readCommandBus(),
            fn (array $row): bool => (($row['payload']['transaction_id'] ?? null) === ($this->app->readTransactions()[0]['id'] ?? null))
        ));
        $this->assertSame($count, count($messages));
    }

    /** @Then only :count idempotency record should exist for that key */
    public function onlyIdempotencyRecordShouldExistForThatKey(int $count): void
    {
        $this->assertSame($count, count($this->app->readIdempotencyRecords()));
    }

    /** @Then the validation errors should include :key */
    public function theValidationErrorsShouldInclude(string $key): void
    {
        $errors = $this->requireResponse()->body['errors'] ?? [];
        $this->assertTrue(array_key_exists($key, $errors), sprintf('Expected validation error for [%s]', $key));
    }

    /** @Then no additional transaction should be persisted */
    public function noAdditionalTransactionShouldBePersisted(): void
    {
        $this->assertSame(0, count($this->app->readTransactions()));
    }

    /** @Then exactly :count command should be processed */
    public function exactlyCommandShouldBeProcessed(int $count): void
    {
        $this->assertSame($count, $this->lastProcessedCount);
    }

    /** @Then the transaction status should become :status */
    public function theTransactionStatusShouldBecome(string $status): void
    {
        $transaction = $this->requireCurrentTransaction();
        $this->assertSame($status, $transaction->status->value);
    }

    /** @Then the transaction processor id should be :processorId */
    public function theTransactionProcessorIdShouldBe(string $processorId): void
    {
        $transaction = $this->requireCurrentTransaction();
        $this->assertSame($processorId, $transaction->processorId);
    }

    /** @Then exactly :count processed event should be recorded for the payment worker */
    public function exactlyProcessedEventShouldBeRecordedForThePaymentWorker(int $count): void
    {
        $events = array_values(array_filter(
            $this->app->readProcessedEvents(),
            fn (array $event): bool => ($event['consumer_group'] ?? null) === 'payment-worker'
        ));
        $this->assertSame($count, count($events));
    }

    /** @Then a :eventType event should be published */
    public function aEventShouldBePublished(string $eventType): void
    {
        foreach ($this->app->readCommandBus() as $message) {
            if (($message['payload']['event_type'] ?? null) === $eventType) {
                return;
            }
        }

        throw new RuntimeException(sprintf('Published event [%s] not found', $eventType));
    }

    /** @Then the transaction error code should be :errorCode */
    public function theTransactionErrorCodeShouldBe(string $errorCode): void
    {
        $transaction = $this->requireCurrentTransaction();
        $this->assertSame($errorCode, $transaction->errorCode);
    }

    /** @Then exactly :count FX rate lock should be stored */
    public function exactlyFxRateLockShouldBeStored(int $count): void
    {
        $this->assertSame($count, count($this->app->readRateLocks()));
    }

    /** @Then the FX rate lock should be marked as used */
    public function theFxRateLockShouldBeMarkedAsUsed(): void
    {
        $locks = $this->app->readRateLocks();
        $this->assertTrue(($locks[0]['used_at'] ?? null) !== null, 'Expected FX lock to be marked used');
    }

    /** @Then exactly :count processed event should still be recorded for the payment worker */
    public function exactlyProcessedEventShouldStillBeRecordedForThePaymentWorker(int $count): void
    {
        $this->exactlyProcessedEventShouldBeRecordedForThePaymentWorker($count);
    }

    /** @Then /^exactly (\d+) journal entr(?:y|ies) should be stored$/ */
    public function exactlyJournalEntryShouldBeStored(int $count): void
    {
        $this->assertSame($count, count($this->app->readJournalEntries()));
    }

    /** @Then /^exactly (\d+) ledger entr(?:y|ies) should be stored$/ */
    public function exactlyLedgerEntryShouldBeStored(int $count): void
    {
        $this->assertSame($count, count($this->app->readLedgerEntries()));
    }

    /** @Then the ledger entry account codes should be: */
    public function theLedgerEntryAccountCodesShouldBe(TableNode $table): void
    {
        $accountsById = [];
        foreach ($this->app->readAccounts() as $account) {
            $accountsById[$account['id']] = $account['code'];
        }

        $codes = [];
        foreach ($this->app->readLedgerEntries() as $entry) {
            $codes[] = $accountsById[$entry['account_id']] ?? null;
        }

        sort($codes);
        $expected = array_map(static fn (array $row): string => (string) $row[0], $table->getRows());
        sort($expected);
        $this->assertSame($expected, $codes);
    }

    /** @Then the ledger entries should belong to the same journal entry */
    public function theLedgerEntriesShouldBelongToTheSameJournalEntry(): void
    {
        $entries = $this->app->readLedgerEntries();
        $this->assertSame($entries[0]['journal_entry_id'] ?? null, $entries[1]['journal_entry_id'] ?? null);
    }

    /** @Then the transaction status should remain :status */
    public function theTransactionStatusShouldRemain(string $status): void
    {
        $this->theTransactionStatusShouldBecome($status);
    }

    /** @Then no journal entries should be stored */
    public function noJournalEntriesShouldBeStored(): void
    {
        $this->assertSame([], $this->app->readJournalEntries());
    }

    /** @Then no ledger entries should be stored */
    public function noLedgerEntriesShouldBeStored(): void
    {
        $this->assertSame([], $this->app->readLedgerEntries());
    }

    /** @Then no processed payment-worker event should be recorded */
    public function noProcessedPaymentWorkerEventShouldBeRecorded(): void
    {
        $this->exactlyProcessedEventShouldBeRecordedForThePaymentWorker(0);
    }

    /** @Then no FX rate lock should be stored */
    public function noFxRateLockShouldBeStored(): void
    {
        $this->assertSame([], $this->app->readRateLocks());
    }

    /** @Then the stored transaction status should be :status */
    public function theStoredTransactionStatusShouldBe(string $status): void
    {
        $this->theTransactionStatusShouldBecome($status);
    }

    /** @Then the stored transaction metadata should contain :key = :value */
    public function theStoredTransactionMetadataShouldContain(string $key, string $value): void
    {
        $transaction = $this->requireCurrentTransaction();
        $this->assertSame($value, (string) ($transaction->metadata[$key] ?? null));
    }

    /** @Then the refund ledger entries should be recorded in currency :currency */
    public function theRefundLedgerEntriesShouldBeRecordedInCurrency(string $currency): void
    {
        $entries = $this->app->readLedgerEntries();
        $this->assertSame($currency, $entries[2]['currency'] ?? null);
        $this->assertSame($currency, $entries[3]['currency'] ?? null);
    }

    /** @Then /^exactly (\d+) webhook events? should be processed$/ */
    public function exactlyWebhookEventsShouldBeProcessed(int $count): void
    {
        $this->assertSame($count, $this->lastProcessedCount);
    }

    /** @Then /^exactly (\d+) webhook deliver(?:y|ies) should be stored$/ */
    public function exactlyWebhookDeliveriesShouldBeStored(int $count): void
    {
        $this->assertSame($count, count($this->app->readWebhookDeliveries()));
    }

    /** @Then the first delivery event type should be :eventType */
    public function theFirstDeliveryEventTypeShouldBe(string $eventType): void
    {
        $deliveries = $this->app->readWebhookDeliveries();
        $this->assertSame($eventType, $deliveries[0]['event_type'] ?? null);
    }

    /** @Then delivered webhook payloads should contain signatures */
    public function deliveredWebhookPayloadsShouldContainSignatures(): void
    {
        foreach ($this->app->readWebhookDeliveries() as $delivery) {
            $this->assertTrue(($delivery['signature'] ?? '') !== '', 'Expected webhook signature');
        }
    }

    /** @Then the endpoint :url should receive exactly :count successful delivery */
    public function theEndpointShouldReceiveExactlySuccessfulDelivery(string $url, int $count): void
    {
        $deliveries = array_values(array_filter(
            $this->app->readWebhookDeliveries(),
            fn (array $delivery): bool => ($delivery['url'] ?? null) === $url && ($delivery['status'] ?? null) === 'delivered'
        ));
        $this->assertSame($count, count($deliveries));
    }

    private function operatorHeaders(?string $correlationId = 'corr-operator'): array
    {
        return [
            'X-Operator-Id' => 'op-123',
            'X-Operator-Role' => 'merchant.write',
            'X-Correlation-Id' => $correlationId ?? 'corr-operator',
        ];
    }

    private function merchantHeaders(?string $idempotencyKey = null, ?string $correlationId = null): array
    {
        return $this->merchantHeadersFor($this->merchantId, $this->merchantCredential, $idempotencyKey, $correlationId);
    }

    private function merchantHeadersFor(?string $merchantId, ?array $credential, ?string $idempotencyKey = null, ?string $correlationId = null): array
    {
        $this->assertNotEmpty($merchantId, 'Expected merchant id');
        $this->assertTrue(is_array($credential), 'Expected merchant credentials');

        $headers = [
            'X-Merchant-Id' => $merchantId,
            'X-Merchant-Key-Id' => (string) ($credential['key_id'] ?? ''),
            'X-Merchant-Secret' => (string) ($credential['secret'] ?? ''),
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        if ($correlationId !== null) {
            $headers['X-Correlation-Id'] = $correlationId;
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultTransactionBody(): array
    {
        return [
            'type' => 'authorization',
            'amount' => '125.50',
            'currency' => 'CAD',
            'payment_method' => [
                'type' => 'card_token',
                'token' => 'tok_123',
            ],
            'capture_mode' => 'manual',
            'reference' => 'order-10001',
            'metadata' => [
                'channel' => 'web',
            ],
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRequestLine(string $requestLine): array
    {
        $parts = preg_split('/\s+/', trim($requestLine), 2);
        if (!is_array($parts) || count($parts) !== 2) {
            throw new RuntimeException(sprintf('Invalid request line [%s]', $requestLine));
        }

        return [$parts[0], $parts[1]];
    }

    private function resolvePathAliases(string $path): string
    {
        foreach ($this->transactionAliases as $alias => $transactionId) {
            $path = str_replace($alias, $transactionId, $path);
        }

        return $path;
    }

    private function setTransactionAlias(string $alias, ?string $transactionId): void
    {
        $this->assertNotEmpty($transactionId, 'Expected transaction id for alias');
        $this->transactionAliases[$alias] = $transactionId;
    }

    private function requireResponse(): Response
    {
        if ($this->response === null) {
            throw new RuntimeException('Expected response to be available');
        }

        return $this->response;
    }

    private function requireCurrentTransaction(): \Modules\PaymentProcessing\Domain\Transaction
    {
        $this->assertNotEmpty($this->currentTransactionId, 'Expected current transaction id');
        $transaction = $this->app->transactionRepository()->findById($this->currentTransactionId);
        if ($transaction === null) {
            throw new RuntimeException('Current transaction not found');
        }

        return $transaction;
    }

    private function bodyValue(array $body, string $key): mixed
    {
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }

        if (isset($body['data']) && is_array($body['data']) && array_key_exists($key, $body['data'])) {
            return $body['data'][$key];
        }

        if (isset($body['checks']) && is_array($body['checks']) && array_key_exists($key, $body['checks'])) {
            return $body['checks'][$key];
        }

        throw new RuntimeException(sprintf('Body key [%s] not found', $key));
    }

    private function normalizeScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected [%s], got [%s]', var_export($expected, true), var_export($actual, true)));
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function assertNotEmpty(?string $value, string $message): void
    {
        if ($value === null || $value === '') {
            throw new RuntimeException($message);
        }
    }
}
