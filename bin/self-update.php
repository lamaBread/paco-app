<?php
/**
 * 자가 업데이트 CLI — GitHub 공개 repo 의 버전 태그 기반(요구사항 #2).
 *   php bin/self-update.php --check          # 최신 버전만 확인(적용 안 함)
 *   php bin/self-update.php                   # 최신 버전으로 업데이트
 *   php bin/self-update.php 0.2.0             # 특정 버전으로
 *
 * 브라우저 원클릭(설정 옆 '업데이트' 탭)과 동일한 엔진(src/Updater.php)을 쓴다.
 * 개발 모드(PACO_DEV=1) 또는 커밋되지 않은 변경이 있는 작업트리에서는 거부한다.
 */

declare(strict_types=1);
namespace PACO;

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';   // Updater 가 Database::backupTo/close 를 사용
require __DIR__ . '/../src/Updater.php';

$args  = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$args  = array_values(array_filter($args, static fn($a) => $a !== '--check'));
$target = $args[0] ?? null;

$u = new Updater($cfg);

$info = $u->check();
echo "현재 버전: v{$info['current']}\n";
if (!$info['ok']) {
    fwrite(STDERR, "확인 실패: {$info['error']}\n");
    exit(2);
}
echo "원격 최신: v{$info['latest']}  " . ($info['hasUpdate'] ? '(업데이트 있음)' : '(최신)') . "\n";

if ($check) {
    exit($info['hasUpdate'] ? 10 : 0);  // 10 = 업데이트 있음(스크립트 친화)
}

$target = $target ?? $info['latest'];
if (!$info['hasUpdate'] && ltrim((string) $target, 'v') === $info['current']) {
    echo "이미 최신입니다. 강제 적용하려면 버전을 명시하세요.\n";
    exit(0);
}

echo "── v{$target} 로 업데이트 ──\n";
$res = $u->apply((string) $target, static function (string $m): void {
    echo $m . "\n";
});

// 가드 거부 등 로그 없이 즉시 반환된 실패는 error 를 명시(조용한 실패 방지).
if (!$res['ok'] && !empty($res['error'])) {
    fwrite(STDERR, '✗ ' . $res['error'] . "\n");
}
exit($res['ok'] ? 0 : 1);
