<?php
/**
 * 정적 아카이브 빌더 — dist/ 에 배포용 정적 사이트 + LOD 덤프 생성.
 *   실행: php bin/build.php
 *   배포: dist/ 전체를 웹서버 루트(또는 하위 경로)에 업로드.
 *
 * 동적 앱과 같은 페이지 함수를 PACO_STATIC 모드로 호출해, 편집 UI 없는
 * 읽기 전용 평면(flat) 사이트로 출력한다. Wikidata 추론 결과는 프리페치
 * 캐시에서 렌더되므로 오프라인에서도 동작한다.
 */

declare(strict_types=1);
namespace PACO;

$GLOBALS['PACO_STATIC'] = true;

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repo.php';
require __DIR__ . '/../src/Rdf.php';
require __DIR__ . '/../src/Wikidata.php';
require __DIR__ . '/../src/render.php';
require __DIR__ . '/../src/pages_common.php';
require __DIR__ . '/../src/pages_article.php';
require __DIR__ . '/../src/pages_lod.php';

$pdo  = Database::connect($cfg['db_path']);
$repo = new Repo($pdo);
$dist = $cfg['dist_dir'];

rrmdir($dist);
@mkdir($dist, 0775, true);
@mkdir($dist . '/data', 0775, true);
copy_dir(__DIR__ . '/../public/assets', $dist . '/assets');
copy_dir($cfg['vocab_dir'], $dist . '/vocab');

$n = 0;
$emit = function (string $file, array $page, string $active) use ($cfg, $dist, &$n): void {
    [$title, $body] = $page;
    $html = layout($title, $body, ['cfg' => $cfg, 'active' => $active]);
    file_put_contents($dist . '/' . $file, $html);
    $n++;
};

// ---- 목록/단일 페이지 ----
$emit('index.html',     page_dashboard($repo, $cfg), 'dashboard');
$emit('articles.html',  page_articles($repo, $cfg), 'articles');
$emit('poems.html',     page_poems($repo, $cfg, []), 'poems');
$emit('books.html',     page_books($repo, $cfg, []), 'books');
$emit('people.html',    page_people($repo, $cfg, []), 'people');
$emit('insights.html',  page_insights($repo, $cfg), 'insights');
$emit('lod.html',       page_lod($repo, $cfg), 'lod');

// ---- 엔티티별 상세 ----
foreach ($repo->articles() as $a)
    $emit(static_file('articles/view', $a['id']), page_article_view($repo, $cfg, ['id' => $a['id']]), 'articles');
foreach ($repo->poems() as $p)
    $emit(static_file('poems/view', $p['id']), page_poem_view($repo, $cfg, ['id' => $p['id']]), 'poems');
foreach ($repo->books() as $b)
    $emit(static_file('books/view', $b['id']), page_book_view($repo, $cfg, ['id' => $b['id']]), 'books');
foreach ($repo->people() as $p)
    $emit(static_file('people/view', $p['id']), page_person_view($repo, $cfg, ['id' => $p['id']]), 'people');

// ---- LOD 덤프 ----
$g = Rdf::buildAbox($repo, $cfg);
file_put_contents($dist . '/data/pac-data.ttl',    $g->toTurtle());
file_put_contents($dist . '/data/pac-data.owl',    $g->toRdfXml());
file_put_contents($dist . '/data/pac-data.jsonld', $g->toJsonLd());
file_put_contents($dist . '/data/pac-data.nt',     $g->toNTriples());

echo "정적 빌드 완료 → {$dist}\n";
echo "  HTML {$n}쪽 · ABox {$g->tripleCount()} 트리플 (ttl/owl/jsonld/nt)\n";
echo "  로컬 확인:  php -S localhost:8002 -t " . escapeshellarg($dist) . "\n";

// ----------------------------------------------------------------- helpers
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
function copy_dir(string $src, string $dst): void
{
    if (!is_dir($src)) return;
    @mkdir($dst, 0775, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        $s = $src . '/' . $f; $d = $dst . '/' . $f;
        is_dir($s) ? copy_dir($s, $d) : copy($s, $d);
    }
}
