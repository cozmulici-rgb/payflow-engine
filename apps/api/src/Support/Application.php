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
        $handler = $this->routes[$request->method][$request->path] ?? null;

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

    public function resetStorage(): void
    {
        foreach (['merchants.json', 'audit_log.json'] as $file) {
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

    public function merchantRepository(): FileMerchantRepository
    {
        return new FileMerchantRepository($this->storagePath . '/merchants.json');
    }

    private function auditWriterUseCase(): WriteAuditRecord
    {
        return new WriteAuditRecord(new FileAuditLogWriter($this->storagePath . '/audit_log.json'));
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
