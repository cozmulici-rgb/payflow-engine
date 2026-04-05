<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Domain\Merchant;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;
use Modules\PaymentProcessing\Application\GetTransaction\GetTransactionQuery;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;

final class GetTransactionController
{
    public function __construct(
        private readonly FileMerchantRepository $merchants,
        private readonly TransactionRepository $transactions,
        private readonly GetTransactionQuery $query
    ) {
    }

    public function handle(Request $request): Response
    {
        $merchant = $this->authenticate($request);
        if ($merchant === null) {
            return Response::json(['message' => 'Authentication failed'], 401);
        }

        $transactionId = $request->routeParam('transaction_id');
        if ($transactionId === null || $transactionId === '') {
            return Response::json(['message' => 'Transaction not found'], 404);
        }

        $transaction = $this->transactions->findById($transactionId);
        if ($transaction === null || $transaction->merchantId !== $merchant->id) {
            return Response::json(['message' => 'Transaction not found'], 404);
        }

        return Response::json($this->query->toResponse($transaction), 200);
    }

    private function authenticate(Request $request): ?Merchant
    {
        $merchantId = $request->header('X-Merchant-Id');
        $keyId = $request->header('X-Merchant-Key-Id');
        $secret = $request->header('X-Merchant-Secret');

        if ($merchantId === null || $keyId === null || $secret === null) {
            return null;
        }

        return $this->merchants->verifyCredential($merchantId, $keyId, $secret);
    }
}
