<?php
/**
 * 국가서지LOD 프리페치 CLI — 추론 질의(M4 편식 지도 · C2 다음 시인)의 입력 캐시를 채운다.
 *   php bin/prefetch.php
 *
 * 인앱 '프리페치 / 갱신' 버튼이 이 스크립트를 백그라운드(detached)로 띄운다(src/Prefetch.php).
 * 왜 별도 프로세스인가: 장시간 네트워크 작업을 빌트인 개발서버(php -S, 단일 스레드) 요청 안에서
 * 돌리면 서버가 세그폴트로 죽기 때문이다(v0.7.2). 진행 상태는 data/prefetch-status.json 으로
 * 보고되어 인사이트 페이지가 표시한다. 별도 터미널에서 직접 실행해도 동일하게 동작한다.
 */

declare(strict_types=1);
namespace PACO;

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Settings.php';
require __DIR__ . '/../src/Repo.php';
require __DIR__ . '/../src/Wikidata.php';   // NlLod::fetchProfile 이 Wikidata::qid 를 사용
require __DIR__ . '/../src/NlLod.php';
require __DIR__ . '/../src/Prefetch.php';

$pdo  = Database::connect($cfg['db_path']);   // 스키마 마이그레이션 자동 적용 + WAL/busy_timeout
$cfg  = Settings::apply($pdo, $cfg);          // 사용자 설정(iri 등) 반영 — 웹과 동일 환경
$repo = new Repo($pdo);

echo '[' . date('c') . '] PACO 프리페치 시작 (pid ' . getmypid() . ")\n";
$res = Prefetch::run($repo, $cfg, static function (string $m): void {
    echo date('H:i:s') . ' ' . $m . "\n";
});
exit($res['ok'] ? 0 : 1);
