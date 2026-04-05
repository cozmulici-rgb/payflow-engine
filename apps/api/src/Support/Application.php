<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Request;
use App\Http\Response;
use Modules\Audit\Application\WriteAuditRecord;
use Modules\Audit\Infrastructure\Persistence\FileAuditLogWriter;
use Modules\MerchantManagement\Application\CreateMerchant\CreateMerchantHandler;
use Modules\MerchantManagement\Application\IssueApiCredential\IssueApiCredentialHandler;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;
use Modules\MerchantManagement\Interfaces\Http\CreateMerchantController;
use Modules\MerchantManagement\Interfaces\Http\IssueApiCredentialController;
use Modules\PaymentProcessing\Application\CreateTransaction\CreateTransactionHandler;
use Modules\PaymentProcessing\Application\GetTransaction\GetTransactionQuery;
use Modules\PaymentProcessing\Application\AuthorizeTransaction\AuthorizeTransactionHandler;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;
use Modules\PaymentProcessing\Infrastructure\Providers\Fraud\FraudScreeningService;
use Modules\PaymentProcessing\Infrastructure\Persistence\IdempotencyRepository;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;
use Modules\PaymentProcessing\Infrastructure\Workers\ProcessTransactionWorker;
use Modules\PaymentProcessing\Interfaces\Http\CreateTransactionController;
use Modules\PaymentProcessing\Interfaces\Http\GetTransactionController;
use Modules\PaymentProcessing\Interfaces\Http\Requests\CreateTransactionRequest;
use Modules\FXCrossBorder\Infrastructure\Persistence\RateLockRepository;
use Modules\FXCrossBorder\Application\LockRate\FxRateLockService;
use Modules\Shared\Infrastructure\Http\CorrelationIdMiddleware;
use Modules\Shared\Interfaces\Http\HealthController;

final class Application
{
    /** @var array<string,array<string,callable>> */
    private array $routes;

    public function __construct(
        private readonly string $basePath,
        private readonly string $storagePath,
        array $routes
    ) {
        $this->routes = $routes;
    }

    public function handle(Request $request): Response
    {
        $request = (new CorrelationIdMiddleware())->handle($request);
        $handler = $this->resolveHandler($request);

        if ($handler === null) {
            return Response::json(['message' => 'Not Found'], 404);
        }

        return $handler($request, $this);
    }

    public function healthController(): HealthController
    {
        return new HealthController();
    }

    public function createMerchantController(): CreateMerchantController
    {
        return new CreateMerchantController(
            new CreateMerchantHandler(
                $this->merchantRepository(),
                $this->auditWriterUseCase()
            )
        );
    }

    public function issueApiCredentialController(): IssueApiCredentialController
    {
        return new IssueApiCredentialController(
            new IssueApiCredentialHandler(
                $this->merchantRepository(),
                $this->auditWriterUseCase()
            )
        );
    }

    public function createTransactionController(): CreateTransactionController
    {
        return new CreateTransactionController(
            $this->merchantRepository(),
            new CreateTransactionRequest(),
            new CreateTransactionHandler(
                $this->transactionRepository(),
                $this->idempotencyRepository(),
                $this->commandPublisher(),
                $this->auditWriterUseCase()
            )
        );
    }

    public function getTransactionController(): GetTransactionController
    {
        return new GetTransactionController(
            $this->merchantRepository(),
            $this->transactionRepository(),
            new GetTransactionQuery()
        );
    }

    public function resetStorage(): void
    {
        foreach ([
            'merchants.json',
            'audit_log.json',
            'transactions.json',
            'transaction_status_history.json',
            'idempotency_records.json',
            'command_bus.json',
            'processed_events.json',
            'rate_locks.json',
        ] as $file) {
            $path = $this->storagePath . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function readAuditLog(): array
    {
        return $this->readJson('audit_log.json');
    }

    public function readMerchants(): array
    {
        return $this->readJson('merchants.json');
    }

    public function readTransactions(): array
    {
        return $this->readJson('transactions.json');
    }

    public function readTransactionStatusHistory(): array
    {
        return $this->readJson('transaction_status_history.json');
    }

    public function readIdempotencyRecords(): array
    {
        return $this->readJson('idempotency_records.json');
    }

    public function readCommandBus(): array
    {
        return $this->readJson('command_bus.json');
    }

    public function readProcessedEvents(): array
    {
        return $this->readJson('processed_events.json');
    }

    public function readRateLocks(): array
    {
        return $this->readJson('rate_locks.json');
    }

    public function merchantRepository(): FileMerchantRepository
    {
        return new FileMerchantRepository($this->storagePath . '/merchants.json');
    }

    public function transactionRepository(): TransactionRepository
    {
        return new TransactionRepository(
            $this->storagePath . '/transactions.json',
            $this->storagePath . '/transaction_status_history.json'
        );
    }

    public function idempotencyRepository(): IdempotencyRepository
    {
        return new IdempotencyRepository($this->storagePath . '/idempotency_records.json');
    }

    public function commandPublisher(): KafkaCommandPublisher
    {
        $config = require $this->basePath . '/config/payflow.php';

        return new KafkaCommandPublisher(
            $this->storagePath . '/command_bus.json',
            (string) ($config['payment_processing']['transaction_command_topic'] ?? 'transaction.processing')
        );
    }

    public function transactionEventPublisher(): KafkaCommandPublisher
    {
        $config = require $this->basePath . '/config/payflow.php';

        return new KafkaCommandPublisher(
            $this->storagePath . '/command_bus.json',
            (string) ($config['payment_processing']['transaction_event_topic'] ?? 'transaction.events')
        );
    }

    public function processTransactionWorker(): ProcessTransactionWorker
    {
        return new ProcessTransactionWorker(
            new AuthorizeTransactionHandler(
                $this->transactionRepository(),
                new ProcessorRouter(),
                new FraudScreeningService(),
                new FxRateLockService($this->rateLockRepository(), $this->basePath . '/config/payflow.php'),
                $this->transactionEventPublisher(),
                $this->auditWriterUseCase(),
                $this->storagePath . '/processed_events.json',
                $this->basePath . '/config/payflow.php'
            )
        );
    }

    public function processPendingTransactionCommands(): int
    {
        $worker = $this->processTransactionWorker();
        $processed = 0;

        foreach ($this->readCommandBus() as $message) {
            if (($message['topic'] ?? null) !== 'transaction.processing') {
                continue;
            }

            $payload = $message['payload'] ?? null;
            if (!is_array($payload)) {
                continue;
            }

            $worker->handle($payload);
            $processed++;
        }

        return $processed;
    }

    private function rateLockRepository(): RateLockRepository
    {
        return new RateLockRepository($this->storagePath . '/rate_locks.json');
    }

    private function auditWriterUseCase(): WriteAuditRecord
    {
        return new WriteAuditRecord(new FileAuditLogWriter($this->storagePath . '/audit_log.json'));
    }

    private function resolveHandler(Request $request): ?callable
    {
        foreach ($this->routes[$request->method] ?? [] as $pattern => $handler) {
            $params = $this->matchRoute($pattern, $request->path);
            if ($params === null) {
                continue;
            }

            $request->routeParams = $params;
            return $handler;
        }

        return null;
    }

    /**
     * @return array<string,string>|null
     */
    private function matchRoute(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $matches): string => '(?P<' . $matches[1] . '>[^/]+)',
            $pattern
        );

        if ($regex === null || !preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function readJson(string $file): array
    {
        $path = $this->storagePath . '/' . $file;
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }
}
