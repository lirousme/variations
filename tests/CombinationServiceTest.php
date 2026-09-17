<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/CombinationService.php';

function assertSame(string $expected, string $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}: esperado '{$expected}', recebido '{$actual}'.");
    }
}

assertSame(
    'Olá joão rabelo!',
    CombinationService::formatValue([
        ['text_value' => 'OLÁ', 'spacing' => 'with_space', 'letter_case' => 'mixed_case'],
        ['text_value' => 'João Rabelo', 'spacing' => 'with_space', 'letter_case' => 'mixed_case'],
        ['text_value' => '!', 'spacing' => 'without_space', 'letter_case' => 'mixed_case'],
    ]),
    'Tipos de maiúscula e minúscula devem ser normalizados conforme a posição',
);

assertSame(
    'Olá João Rabelo',
    CombinationService::formatValue([
        ['text_value' => 'olá', 'spacing' => 'with_space', 'letter_case' => 'mixed_case'],
        ['text_value' => 'João Rabelo', 'spacing' => 'with_space', 'letter_case' => 'initial_always_uppercase'],
    ]),
    'Tipos com inicial sempre maiúscula devem preservar o texto cadastrado',
);

echo "CombinationService tests passed.\n";
