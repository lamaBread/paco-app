<?php
/**
 * 데이터 마이그레이션 실행기 — DB 를 열어 부족한 스키마 단계를 적용한다.
 *   실행: php bin/migrate.php          (현재 DB)
 *         PACO_DB_PATH=/경로 php bin/migrate.php
 *
 * Database::connect() 가 PRAGMA user_version 기준으로 필요한 단계만 적용한다.
 * 자가 업데이트(Updater)가 코드 덮어쓰기 후 *새 코드*로 이 스크립트를 별도 프로세스로
 * 실행해 마이그레이션을 수행한다. 성공 시 exit 0, 실패 시 비0 + 오류 출력.
 */

declare(strict_types=1);
namespace PACO;

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';

try {
    $before = '?';
    if (is_file($cfg['db_path'])) {
        try {
            $tmp = new \PDO('sqlite:' . $cfg['db_path']);
            $before = (string) $tmp->query('PRAGMA user_version')->fetchColumn();
            $tmp = null;
        } catch (\Throwable $e) { /* 무시 */ }
    }

    $pdo = Database::connect($cfg['db_path']);  // 여기서 마이그레이션 적용
    $after = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

    echo "마이그레이션: user_version {$before} → {$after} (코드 기대 = " . Database::SCHEMA_VERSION . ")\n";
    foreach (['person', 'article', 'quotation', 'app_setting'] as $t) {
        try {
            $n = $pdo->query("SELECT count(*) FROM {$t}")->fetchColumn();
            echo "  {$t} = {$n}\n";
        } catch (\Throwable $e) {
            echo "  {$t} = (없음)\n";
        }
    }
    echo "DB: {$cfg['db_path']}\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '마이그레이션 실패: ' . $e->getMessage() . "\n");
    exit(1);
}
