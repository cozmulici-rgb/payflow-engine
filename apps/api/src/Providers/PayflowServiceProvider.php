<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Audit\Application\WriteAuditRecord;
use Modules\Audit\Infrastructure\Persistence\FileAuditLogWriter;
use Modules\FXCrossBorder\Infrastructure\Persistence\RateLockRepository;
use Modules\Ledger\Application\LedgerPostingService;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;
use Modules\MerchantManagement\Infrastructure\Persistence\WebhookEndpointRepository;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Persistence\IdempotencyRepository;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;
use Modules\PaymentProcessing\Infrastructure\Providers\Fraud\FraudScreeningService;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;
use Modules\Settlement\Infrastructure\Persistence\SettlementBatchRepository;
use Modules\Settlement\Infrastructure\Storage\SettlementArtifactStore;
use Modules\Shared\Infrastructure\Persistence\WebhookDeliveryRepository;

/**
 * Wires all module dependencies into the service container.
 *
 * In the current phase the storage layer uses file-backed JSON repositories.
 * Replace each binding with the Eloquent / Redis / Kafka implementation as
 * infrastructure phases are delivered.
 */
final class PayflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $storagePath = $this->app->storagePath();
        $config = $this->app['config'];

        // ── Shared infrastructure ─────────────────────────────────────────────

        $this->app->singleton(KafkaCommandPublisher::class, static fn () =>
            new KafkaCommandPublisher(
                brokers: (string) $config->get('kafka.brokers', 'localhost:9092'),
                topic: (string) $config->get('payflow.payment_processing.transaction_event_topic', 'transaction.events'),
                commandBusPath: $storagePath . '/command_bus.json',
            )
        );

        // ── Merchant management ───────────────────────────────────────────────

        $this->app->singleton(FileMerchantRepository::class, static fn () =>
            new FileMerchantRepository($storagePath . '/merchants.json')
        );

        $this->app->singleton(WebhookEndpointRepository::class, static fn () =>
            new WebhookEndpointRepository($storagePath . '/webhook_endpoints.json')
        );

        // ── Payment processing ────────────────────────────────────────────────

        $this->app->singleton(TransactionRepository::class, static fn () =>
            new TransactionRepository(
                $storagePath . '/transactions.json',
                $storagePath . '/transaction_status_history.json',
            )
        );

        $this->app->singleton(IdempotencyRepository::class, static fn () =>
            new IdempotencyRepository($storagePath . '/idempotency.json')
        );

        $this->app->singleton(FraudScreeningService::class, static fn () =>
            new FraudScreeningService(
                highRiskChannels: (array) $config->get('payflow.fraud.high_risk_channels', ['fraud']),
            )
        );

        $this->app->singleton(ProcessorRouter::class, static fn () =>
            new ProcessorRouter()
        );

        // ── FX cross-border ───────────────────────────────────────────────────

        $this->app->singleton(RateLockRepository::class, static fn () =>
            new RateLockRepository($storagePath . '/fx_rate_locks.json')
        );

        // ── Ledger ────────────────────────────────────────────────────────────

        $this->app->singleton(LedgerRepository::class, static fn () =>
            new LedgerRepository(
                $storagePath . '/accounts.json',
                $storagePath . '/journal_entries.json',
                $storagePath . '/ledger_entries.json',
            )
        );

        $this->app->singleton(LedgerPostingService::class, static fn (mixed $app) =>
            new LedgerPostingService($app->make(LedgerRepository::class))
        );

        // ── Settlement ────────────────────────────────────────────────────────

        $this->app->singleton(SettlementBatchRepository::class, static fn () =>
            new SettlementBatchRepository(
                $storagePath . '/settlement_batches.json',
                $storagePath . '/settlement_items.json',
            )
        );

        $this->app->singleton(SettlementArtifactStore::class, static fn () =>
            new SettlementArtifactStore($storagePath . '/settlement_artifacts')
        );

        // ── Audit ─────────────────────────────────────────────────────────────

        $this->app->singleton(WriteAuditRecord::class, static fn () =>
            new WriteAuditRecord(
                new FileAuditLogWriter($storagePath . '/audit_log.json')
            )
        );

        // ── Webhook delivery ──────────────────────────────────────────────────

        $this->app->singleton(WebhookDeliveryRepository::class, static fn () =>
            new WebhookDeliveryRepository($storagePath . '/webhook_deliveries.json')
        );
    }

    public function boot(): void {}
}
