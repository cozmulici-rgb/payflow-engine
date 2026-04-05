<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Interfaces\Http\Requests;

use App\Http\Request;
use Modules\MerchantManagement\Domain\Merchant;

final class CreateTransactionRequest
{
    /**
     * @return array{errors:array<string,array<int,string>>,data:array<string,mixed>}
     */
    public function validate(Request $request, Merchant $merchant): array
    {
        $errors = [];

        $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?? ''));
        if ($idempotencyKey === '') {
            $errors['idempotency_key'][] = 'The Idempotency-Key header is required.';
        }

        $type = strtolower(trim((string) ($request->body['type'] ?? '')));
        if ($type === '') {
            $errors['type'][] = 'The type field is required.';
        } elseif ($type !== 'authorization') {
            $errors['type'][] = 'The selected type is invalid.';
        }

        $amount = trim((string) ($request->body['amount'] ?? ''));
        if ($amount === '') {
            $errors['amount'][] = 'The amount field is required.';
        } elseif (!preg_match('/^\d+(?:\.\d{1,4})?$/', $amount) || (float) $amount <= 0) {
            $errors['amount'][] = 'The amount field must be a positive decimal value.';
        }

        $currency = strtoupper(trim((string) ($request->body['currency'] ?? '')));
        if ($currency === '') {
            $errors['currency'][] = 'The currency field is required.';
        } elseif ($currency !== strtoupper($merchant->defaultCurrency)) {
            $errors['currency'][] = 'The selected currency is invalid.';
        }

        $paymentMethod = $request->body['payment_method'] ?? null;
        if (!is_array($paymentMethod)) {
            $errors['payment_method'][] = 'The payment_method field is required.';
        } else {
            $paymentMethodType = strtolower(trim((string) ($paymentMethod['type'] ?? '')));
            $paymentMethodToken = trim((string) ($paymentMethod['token'] ?? ''));

            if ($paymentMethodType !== 'card_token') {
                $errors['payment_method.type'][] = 'The selected payment_method.type is invalid.';
            }
            if ($paymentMethodToken === '') {
                $errors['payment_method.token'][] = 'The payment_method.token field is required.';
            }
        }

        $captureMode = strtolower(trim((string) ($request->body['capture_mode'] ?? 'manual')));
        if (!in_array($captureMode, ['manual', 'automatic'], true)) {
            $errors['capture_mode'][] = 'The selected capture_mode is invalid.';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'data' => []];
        }

        return [
            'errors' => [],
            'data' => [
                'idempotency_key' => $idempotencyKey,
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'settlement_currency' => strtoupper((string) ($request->body['settlement_currency'] ?? $currency)),
                'payment_method_type' => strtolower((string) $paymentMethod['type']),
                'payment_method_token' => (string) $paymentMethod['token'],
                'capture_mode' => $captureMode,
                'reference' => isset($request->body['reference']) ? (string) $request->body['reference'] : null,
                'metadata' => is_array($request->body['metadata'] ?? null) ? $request->body['metadata'] : [],
            ],
        ];
    }
}
