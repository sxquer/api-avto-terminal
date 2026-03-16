#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['name::', 'json', 'revoke-same-name']);
$tokenName = (string) ($options['name'] ?? ('cli-token-' . date('Ymd-His')));
$asJson = array_key_exists('json', $options);
$revokeSameName = array_key_exists('revoke-same-name', $options);

$users = User::query()->orderBy('id')->get(['id', 'name', 'email']);

if ($users->isEmpty()) {
    fwrite(STDOUT, "No users found.\n");
    exit(0);
}

$rows = [];

foreach ($users as $user) {
    if ($revokeSameName) {
        $user->tokens()->where('name', $tokenName)->delete();
    }

    $plainTextToken = $user->createToken($tokenName)->plainTextToken;

    $rows[] = [
        'id' => $user->id,
        'email' => $user->email,
        'name' => $user->name,
        'token_name' => $tokenName,
        'token' => $plainTextToken,
    ];
}

if ($asJson) {
    fwrite(STDOUT, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, "Issued tokens for " . count($rows) . " users (token_name={$tokenName}).\n");
fwrite(STDOUT, "IMPORTANT: existing tokens in DB are hashed and cannot be restored in plain text.\n\n");
fwrite(STDOUT, "id\temail\tname\ttoken\n");

foreach ($rows as $row) {
    fwrite(
        STDOUT,
        $row['id'] . "\t" . $row['email'] . "\t" . $row['name'] . "\t" . $row['token'] . "\n"
    );
}

