<?php

declare(strict_types=1);

function console_commands(): array
{
    return [
        'test' => 'Run lightweight phase test suite',
        'settlement:run' => 'Run settlement window for eligible captured transactions',
    ];
}
