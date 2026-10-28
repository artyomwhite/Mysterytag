<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;

$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo = null;

for ($i = 1; $i <= 30; $i++) {
    try {
        $pdo = Database::connect();
        break;
    } catch (Throwable $e) {
        fwrite(STDERR, "[migrate] waiting for MySQL ({$i}/30): {$e->getMessage()}\n");
        sleep(2);
    }
}

if ($pdo === null) {
    fwrite(STDERR, "[migrate] MySQL is not available\n");
    exit(1);
}

foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

echo "[migrate] schema is up to date\n";
