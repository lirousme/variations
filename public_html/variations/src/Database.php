<?php
declare(strict_types=1);
final class Database {
    public static function connect(): PDO {
        $env = self::env(dirname(__DIR__, 3) . '/.env');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_NAME'] ?? 'variations');
        return new PDO($dsn, $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    private static function env(string $path): array {
        if (!is_file($path)) return [];
        return array_reduce(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), static function(array $env, string $line): array {
            if ($line[0] !== '#' && str_contains($line, '=')) { [$key, $value] = explode('=', $line, 2); $env[trim($key)] = trim($value); }
            return $env;
        }, []);
    }
}
