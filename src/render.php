<?php
/** 레이아웃 + URL 헬퍼. 동적(localhost) / 정적(dist) 양쪽을 같은 템플릿으로 출력. */

namespace PACO;

/** 정적 빌드 모드 여부 */
function is_static(): bool { return !empty($GLOBALS['PACO_STATIC']); }

/** 정적 파일명 (flat: 모든 페이지를 루트에 평평하게) */
function static_file(string $route, ?string $id = null): string
{
    // 엔티티 id 는 타입 접두사(article_/book_/poem_/person_)를 이미 포함하므로 그대로 사용
    return match ($route) {
        '', 'dashboard'   => 'index.html',
        'articles'        => 'articles.html',
        'articles/view'   => $id . '.html',
        'poems'           => 'poems.html',
        'poems/view'      => $id . '.html',
        'books'           => 'books.html',
        'books/view'      => $id . '.html',
        'people'          => 'people.html',
        'people/view'     => $id . '.html',
        'insights'        => 'insights.html',
        'lod'             => 'lod.html',
        default           => 'index.html',
    };
}

/** 라우트 → URL (동적: ?r=…, 정적: 파일명) */
function url(string $route, array $params = []): string
{
    if (is_static()) {
        $id = $params['id'] ?? null;
        return static_file($route, $id !== null ? (string) $id : null);
    }
    $q = array_merge(['r' => $route], $params);
    return 'index.php?' . http_build_query($q);
}

/** 다운로드(LOD 덤프) URL */
function dump_url(string $fmt): string
{
    if (is_static()) {
        return match ($fmt) {
            'owl'    => 'data/pac-data.owl',
            'ttl'    => 'data/pac-data.ttl',
            'jsonld' => 'data/pac-data.jsonld',
            'nt'     => 'data/pac-data.nt',
            default  => 'data/pac-data.ttl',
        };
    }
    return url('lod/dump', ['fmt' => $fmt]);
}

/**
 * 전체 페이지 렌더.
 * @param array $ctx ['active'=>route, 'cfg'=>config, 'flash'=>?string]
 */
function layout(string $title, string $body, array $ctx = []): string
{
    $cfg = $ctx['cfg'] ?? [];
    $app = $cfg['app_name'] ?? 'PACO';
    $active = $ctx['active'] ?? '';
    $static = is_static();

    $nav = [
        'dashboard' => ['대시보드', url('dashboard')],
        'articles'  => ['비평문', url('articles')],
        'poems'     => ['시', url('poems')],
        'books'     => ['시집', url('books')],
        'people'    => ['인물', url('people')],
        'insights'  => ['추론 질의', url('insights')],
        'lod'       => ['LOD 발행', url('lod')],
    ];
    // 설정·업데이트는 편집 앱에서만(정적 아카이브에는 없음)
    if (!$static) {
        $nav['settings'] = ['설정', url('settings')];
        $nav['update']   = ['업데이트', url('update')];
    }
    $navHtml = '';
    foreach ($nav as $key => [$label, $href]) {
        $cls = ($active === $key) ? ' class="on"' : '';
        $navHtml .= "<a$cls href=\"" . h($href) . '">' . h($label) . '</a>';
    }

    $flash = '';
    if (!empty($ctx['flash'])) {
        $flash = '<div class="flash">' . h($ctx['flash']) . '</div>';
    }
    $badge = $static ? '<span class="badge-static">정적 아카이브</span>' : '';

    $year = date('Y');
    $ver  = h((string) ($cfg['version'] ?? 'dev'));
    $ontVer = h((string) ($cfg['ont_version'] ?? 'dev'));
    // 사용자 설정값(app_name 등)·페이지 제목은 출력 시 이스케이프(설정에서 들어온 값일 수 있음).
    $titleEsc = h($title);
    $appEsc   = h($app);
    $descEsc  = h((string) ($cfg['app_desc'] ?? ''));
    return <<<HTML
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleEsc} · {$appEsc}</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
  <div class="brand"><a href="{$nav['dashboard'][1]}"><b>PACO</b> <span>詩話 · Poem And Criticism Ontology</span></a> {$badge}</div>
  <nav class="mainnav">{$navHtml}</nav>
</header>
<main class="wrap">
{$flash}
{$body}
</main>
<footer class="foot">
  <span>{$appEsc} <span class="muted">v{$ver}</span> — {$descEsc}</span>
  <span>PAC 온톨로지 v{$ontVer} · W3C Web Annotation · LOD(RDF/XML·Turtle·JSON-LD) · &copy; {$year}</span>
</footer>
<script src="assets/app.js"></script>
</body>
</html>
HTML;
}

/** 작은 UI 헬퍼 */
function badge(string $text, string $kind = ''): string
{
    return '<span class="tag ' . h($kind) . '">' . h($text) . '</span>';
}

/** 인용 위치를 사람이 읽는 문자열로 (예: "1–4연", "5연 1행") */
function loc_label(array $t): string
{
    $ss = $t['start_stanza']; $es = $t['end_stanza'];
    $sl = $t['start_line'];   $el = $t['end_line'];
    if ($ss === null || $ss === '') return '위치 미지정';
    $stanza = ($es !== null && $es !== '' && (int)$es !== (int)$ss) ? "{$ss}–{$es}연" : "{$ss}연";
    if ($sl !== null && $sl !== '') {
        $line = ($el !== null && $el !== '' && (int)$el !== (int)$sl) ? " {$sl}–{$el}행" : " {$sl}행";
        return $stanza . $line;
    }
    return $stanza;
}
