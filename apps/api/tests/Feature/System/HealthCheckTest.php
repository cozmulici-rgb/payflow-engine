<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');

$health = $app->handle(new Request('GET', '/internal/health'));
$ready = $app->handle(new Request('GET', '/internal/ready'));

TestCase::assertSame(200, $health->status);
TestCase::assertSame('ok', $health->body['status']);
TestCase::assertTrue(isset($health->body['correlation_id']) && $health->body['correlation_id'] !== '');

TestCase::assertSame(200, $ready->status);
TestCase::assertSame('ready', $ready->body['status']);
TestCase::assertSame('ok', $ready->body['checks']['storage']);
