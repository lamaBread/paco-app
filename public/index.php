<?php
/**
 * PACO 프론트 컨트롤러.
 *   실행: php -S localhost:8001 -t public
 *   라우트: index.php?r=<route>[&id=…]
 */

declare(strict_types=1);
namespace PACO;

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Settings.php';
require __DIR__ . '/../src/Repo.php';
require __DIR__ . '/../src/Rdf.php';
require __DIR__ . '/../src/Wikidata.php';
require __DIR__ . '/../src/Updater.php';
require __DIR__ . '/../src/render.php';
require __DIR__ . '/../src/pages_common.php';
require __DIR__ . '/../src/pages_article.php';
require __DIR__ . '/../src/pages_lod.php';
require __DIR__ . '/../src/pages_admin.php';

session_start();

$pdo  = Database::connect($cfg['db_path']);   // 스키마 마이그레이션 자동 적용
$cfg  = Settings::apply($pdo, $cfg);          // DB(app_setting) 사용자 설정을 cfg 에 덧씌움
$repo = new Repo($pdo);

$route = $_GET['r'] ?? 'dashboard';
$req   = $_GET;

/** 리다이렉트 + 플래시 */
function redirect(string $route, array $params = [], ?string $flash = null): never
{
    if ($flash !== null) $_SESSION['flash'] = $flash;
    $qs = http_build_query(array_merge(['r' => $route], $params));
    header('Location: index.php?' . $qs);
    exit;
}

function post(string $k, string $def = ''): string
{
    return isset($_POST[$k]) ? trim((string) $_POST[$k]) : $def;
}

// --------------------------------------------------------- 액션(변경) 라우트
try {
    switch ($route) {

        case 'people/save':
            $id = $repo->savePerson([
                'id' => post('id'), 'name' => post('name'),
                'is_poet' => isset($_POST['is_poet']), 'is_critic' => isset($_POST['is_critic']),
                'same_as' => post('same_as'),
            ]);
            redirect('people', [], '인물을 저장했습니다.');

        case 'people/delete':
            $repo->deletePerson($req['id'] ?? '');
            redirect('people', [], '인물을 삭제했습니다.');

        case 'books/save':
            $repo->saveBook(['id' => post('id'), 'title' => post('title'),
                'author_id' => post('author_id'), 'isbn13' => post('isbn13')]);
            redirect('books', [], '시집을 저장했습니다.');

        case 'books/delete':
            $repo->deleteBook($req['id'] ?? '');
            redirect('books', [], '시집을 삭제했습니다.');

        case 'poems/save':
            $id = $repo->savePoem(
                ['id' => post('id'), 'title' => post('title'),
                 'author_id' => post('author_id'), 'book_id' => post('book_id')],
                $_POST['body_text'] ?? ''
            );
            redirect('poems', [], '시를 저장했습니다.');

        case 'poems/delete':
            $repo->deletePoem($req['id'] ?? '');
            redirect('poems', [], '시를 삭제했습니다.');

        case 'articles/save':
            $d = ['id' => post('id'), 'title' => post('title'),
                  'author_id' => post('author_id'), 'created' => post('created'),
                  'full_text' => $_POST['full_text'] ?? '',
                  'critiques_kind' => '', 'critiques_id' => ''];
            $crit = post('critiques');
            if ($crit !== '' && str_contains($crit, ':')) {
                [$k, $cid] = explode(':', $crit, 2);
                $d['critiques_kind'] = $k; $d['critiques_id'] = $cid;
            }
            $id = $repo->saveArticle($d);
            redirect('articles/edit', ['id' => $id], '비평문을 저장했습니다. 이제 인용을 추가할 수 있습니다.');

        case 'articles/delete':
            $repo->deleteArticle($req['id'] ?? '');
            redirect('articles', [], '비평문을 삭제했습니다.');

        case 'quotations/save':
            $aid = post('article_id');
            $anchor = post('anchor');
            $targets = [];
            foreach (($_POST['t'] ?? []) as $row) {
                $src = trim((string) ($row['source'] ?? ''));
                if ($src === '') continue;
                $parts = explode(':', $src, 2);
                $targets[] = [
                    'source_kind' => $parts[0] ?? 'poem',
                    'source_id'   => $parts[1] ?? '',
                    'start_stanza' => $row['start_stanza'] ?? '',
                    'end_stanza'   => $row['end_stanza'] ?? '',
                    'start_line'   => $row['start_line'] ?? '',
                    'end_line'     => $row['end_line'] ?? '',
                    'exact'        => $row['exact'] ?? '',
                    'target_order' => '',
                ];
            }
            $repo->saveQuotation([
                'id' => post('id'), 'article_id' => $aid,
                'qtype' => post('qtype', 'indirect'), 'anchor' => $anchor,
                'targets' => $targets,
            ]);
            redirect('articles/edit', ['id' => $aid], '인용을 저장했습니다.');

        case 'quotations/delete':
            $repo->deleteQuotation($req['id'] ?? '');
            redirect('articles/edit', ['id' => $req['article_id'] ?? ''], '인용을 삭제했습니다.');

        case 'insights/refresh':
            $wd = new Wikidata($repo, $cfg);
            $sum = $wd->refreshAll();
            $msg = "Wikidata 갱신: 시인 {$sum['poets']}명 · 사실 {$sum['facts']}건 · 비슷한 시인 {$sum['similar']}건.";
            if (!empty($sum['errors'])) $msg .= ' (오류: ' . implode(' / ', array_slice($sum['errors'], 0, 3)) . ')';
            redirect('insights', [], $msg);

        case 'settings/save':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                redirect('settings');   // GET 으로 빈 값이 들어와 설정이 초기화되는 것을 방지
            }
            foreach (array_keys(Settings::EDITABLE) as $k) {
                Settings::set($pdo, $k, $_POST[$k] ?? '');
            }
            redirect('settings', [], '설정을 저장했습니다.');

        case 'update/apply':
            // 파괴적·장시간 작업이므로 POST 로만 허용(우발적/교차사이트 GET 트리거 방지).
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                redirect('update');
            }
            // 라이브 DB 연결을 닫아(WAL 체크포인트) 자가 업데이트의 백업/롤백 파일조작 정합성 보장.
            $repo = null;
            $pdo  = null;
            Database::close();
            // 자가 업데이트 적용(수십 초 소요 가능). 결과는 세션에 담아 update 페이지에서 표시.
            $u = new Updater($cfg);
            $tag = post('tag');
            if ($tag === '') {
                $chk = $u->check();
                $tag = $chk['latestTag'] ?? '';
            }
            $res = $u->apply($tag);
            $_SESSION['paco_update_result'] = $res;
            redirect('update', [], $res['ok']
                ? '업데이트 완료 — 페이지를 새로고침하면 새 버전으로 동작합니다.'
                : '업데이트 실패: ' . ($res['error'] ?? '알 수 없는 오류'));

        case 'lod/dump':
            $fmt = $req['fmt'] ?? 'ttl';
            $g = Rdf::buildAbox($repo, $cfg);
            [$ct, $out, $fn] = match ($fmt) {
                'owl'    => ['application/rdf+xml; charset=utf-8', $g->toRdfXml(), 'pac-data.owl'],
                'jsonld' => ['application/ld+json; charset=utf-8', $g->toJsonLd(), 'pac-data.jsonld'],
                'nt'     => ['application/n-triples; charset=utf-8', $g->toNTriples(), 'pac-data.nt'],
                default  => ['text/turtle; charset=utf-8', $g->toTurtle(), 'pac-data.ttl'],
            };
            header('Content-Type: ' . $ct);
            header('Content-Disposition: inline; filename="' . $fn . '"');
            echo $out;
            exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    $_SESSION['flash'] = '오류: ' . $e->getMessage();
    // 안전한 곳으로
    header('Location: index.php?r=dashboard');
    exit;
}

// ------------------------------------------------------------- 페이지 라우트
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

[$title, $bodyHtml] = match ($route) {
    'dashboard', ''   => page_dashboard($repo, $cfg),
    'people'          => page_people($repo, $cfg, $req),
    'people/view'     => page_person_view($repo, $cfg, $req),
    'books'           => page_books($repo, $cfg, $req),
    'books/view'      => page_book_view($repo, $cfg, $req),
    'poems'           => page_poems($repo, $cfg, $req),
    'poems/view'      => page_poem_view($repo, $cfg, $req),
    'articles'        => page_articles($repo, $cfg),
    'articles/view'   => page_article_view($repo, $cfg, $req),
    'articles/edit'   => page_article_edit($repo, $cfg, $req),
    'quotations/edit' => page_quotation_edit($repo, $cfg, $req),
    'insights'        => page_insights($repo, $cfg),
    'lod'             => page_lod($repo, $cfg),
    'settings'        => page_settings($repo, $cfg, $req),
    'update'          => page_update($repo, $cfg, $req),
    default           => ['404', '<p class="muted">페이지를 찾을 수 없습니다: ' . h($route) . '</p>'],
};

$active = explode('/', $route)[0] ?: 'dashboard';
echo layout($title, $bodyHtml, ['cfg' => $cfg, 'active' => $active, 'flash' => $flash]);
