<?php
/** 페이지: 대시보드, 인물, 시집, 시 (목록·폼·상세). */

namespace PACO;

/** <option> 생성 */
function opt(string $value, ?string $current, string $label): string
{
    $sel = ((string) $current === $value) ? ' selected' : '';
    return '<option value="' . h($value) . "\"$sel>" . h($label) . '</option>';
}

function person_options(Repo $repo, ?string $current, string $filter = 'all'): string
{
    $list = $filter === 'poet' ? $repo->poets() : ($filter === 'critic' ? $repo->critics() : $repo->people());
    $out = '<option value="">— 선택 —</option>';
    foreach ($list as $p) $out .= opt($p['id'], $current, $p['name']);
    return $out;
}

// ============================================================== 대시보드
function page_dashboard(Repo $repo, array $cfg): array
{
    $c = $repo->counts();
    $cards = [
        ['비평문', $c['article'], 'articles'], ['인용', $c['quotation'], 'articles'],
        ['시', $c['poem'], 'poems'], ['시집', $c['book'], 'books'],
        ['시인', $c['poet'], 'people'], ['비평자', $c['critic'], 'people'],
    ];
    $cardHtml = '';
    foreach ($cards as [$label, $n, $route]) {
        $cardHtml .= '<a class="stat" href="' . h(url($route)) . '"><b>' . (int) $n . '</b><span>' . h($label) . '</span></a>';
    }
    $rows = '';
    foreach (array_slice($repo->articles(), 0, 8) as $a) {
        $cr = $repo->critiquesLabel($a);
        $rows .= '<tr><td><a href="' . h(url('articles/view', ['id' => $a['id']])) . '">' . h($a['title']) . '</a></td>'
            . '<td>' . h($a['author_name'] ?? '—') . '</td><td>' . h($cr['title'] ?? '—') . '</td>'
            . '<td>' . h($a['created'] ?? '') . '</td><td class="num">' . (int) $a['n_quot'] . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="5" class="muted">아직 비평문이 없습니다.</td></tr>';
    $newBtn = is_static() ? '' : '<a class="btn primary" href="' . h(url('articles/edit')) . '">+ 새 비평문</a>';

    $body = <<<HTML
<section class="hero">
  <h1>비평문 아카이브</h1>
  <p class="lead">시(詩)를 인용하는 비평문을 W3C Web Annotation 모델로 기입하고, PAC 온톨로지에 맞춰 LOD 로 발행합니다.</p>
  <div class="stats">{$cardHtml}</div>
</section>
<section class="panel">
  <div class="panel-head"><h2>최근 비평문</h2>{$newBtn}</div>
  <table class="grid">
    <thead><tr><th>제목</th><th>비평자</th><th>대상</th><th>작성일</th><th class="num">인용</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</section>
HTML;
    return ['대시보드', $body];
}

// ================================================================== 인물
function page_people(Repo $repo, array $cfg, array $req): array
{
    $edit = $req['edit'] ?? '';
    $cur = $edit ? $repo->person($edit) : null;
    $rows = '';
    foreach ($repo->people() as $p) {
        $roles = [];
        if ($p['is_poet']) $roles[] = badge('시인', 'poet');
        if ($p['is_critic']) $roles[] = badge('비평자', 'critic');
        $wd = $p['same_as']
            ? '<a class="wd" href="' . h($p['same_as']) . '" target="_blank" rel="noopener">' . h(Wikidata::qid($p['same_as']) ?? 'Wikidata') . '</a>'
            : '<span class="muted">—</span>';
        $act = is_static() ? '' :
            '<a class="mini" href="' . h(url('people', ['edit' => $p['id']])) . '">수정</a> '
            . '<a class="mini danger" href="' . h(url('people/delete', ['id' => $p['id']])) . '" data-confirm="삭제할까요?">삭제</a>';
        $rows .= '<tr><td><a href="' . h(url('people/view', ['id' => $p['id']])) . '">' . h($p['name']) . '</a></td>'
            . '<td>' . implode(' ', $roles) . '</td><td>' . $wd . '</td><td class="actions">' . $act . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="4" class="muted">등록된 인물이 없습니다.</td></tr>';

    $form = '';
    if (!is_static()) {
        $saveUrl = h(url('people/save'));
        $cancelUrl = h(url('people'));
        $id = h($cur['id'] ?? '');
        $name = h($cur['name'] ?? '');
        $poet = !empty($cur['is_poet']) ? ' checked' : ($cur ? '' : ' checked');
        $critic = !empty($cur['is_critic']) ? ' checked' : '';
        $same = h($cur['same_as'] ?? '');
        $heading = $cur ? '인물 수정' : '인물 추가';
        $form = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>{$heading}</h2></div>
  <form method="post" action="{$saveUrl}" class="form">
    <input type="hidden" name="id" value="{$id}">
    <label>이름 <span class="req">*</span><input name="name" required value="{$name}" placeholder="예: 황인찬"></label>
    <div class="row">
      <label class="chk"><input type="checkbox" name="is_poet" value="1"{$poet}> 시인 (pac:Poet)</label>
      <label class="chk"><input type="checkbox" name="is_critic" value="1"{$critic}> 비평자 (pac:Critic)</label>
    </div>
    <label>owl:sameAs — Wikidata IRI
      <input name="same_as" value="{$same}" placeholder="http://www.wikidata.org/entity/Q12625888">
      <small>시인을 외부 LOD 와 연결하면 추론 질의(거주지·수상·직업·비슷한 시인)가 가능해집니다.</small>
    </label>
    <div class="form-actions"><button class="btn primary" type="submit">저장</button>
      <a class="btn" href="{$cancelUrl}">취소</a></div>
  </form>
</section>
HTML;
    }
    $body = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>인물 — 시인 · 비평자</h2></div>
  <table class="grid">
    <thead><tr><th>이름</th><th>역할</th><th>Wikidata</th><th class="actions">　</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</section>
{$form}
HTML;
    return ['인물', $body];
}

function page_person_view(Repo $repo, array $cfg, array $req): array
{
    $p = $repo->person($req['id'] ?? '');
    if (!$p) return ['없음', '<p class="muted">인물을 찾을 수 없습니다.</p>'];
    $wd = new Wikidata($repo, $cfg);

    $roles = [];
    if ($p['is_poet']) $roles[] = badge('시인', 'poet');
    if ($p['is_critic']) $roles[] = badge('비평자', 'critic');
    $rolesHtml = $roles ? implode(' ', $roles) : '<span class="muted">—</span>';

    $works = '';
    foreach ($repo->poems() as $pm) {
        if ($pm['author_id'] === $p['id'])
            $works .= '<li><a href="' . h(url('poems/view', ['id' => $pm['id']])) . '">' . h($pm['title']) . '</a> <span class="muted">시</span></li>';
    }
    foreach ($repo->books() as $b) {
        if ($b['author_id'] === $p['id'])
            $works .= '<li><a href="' . h(url('books/view', ['id' => $b['id']])) . '">' . h($b['title']) . '</a> <span class="muted">시집</span></li>';
    }
    $works = $works ?: '<li class="muted">없음</li>';

    $facts = $wd->factsByPerson($p['id']);
    if ($facts) {
        $byProp = [];
        foreach ($facts as $f) $byProp[$f['prop_label'] ?: $f['prop_pid']][] = $f['value_label'] ?: $f['value_iri'];
        $factHtml = '<table class="kv">';
        foreach ($byProp as $label => $vals) $factHtml .= '<tr><th>' . h($label) . '</th><td>' . h(implode(', ', $vals)) . '</td></tr>';
        $factHtml .= '</table>';
    } else {
        $factHtml = '<p class="muted">캐시된 Wikidata 사실이 없습니다. <em>추론 질의</em> 탭에서 프리페치하세요.</p>';
    }

    $simHtml = '';
    foreach (array_slice($wd->similarByPerson($p['id']), 0, 24) as $s) {
        $simHtml .= '<a class="chip" href="' . h($s['similar_iri']) . '" target="_blank" rel="noopener">' . h($s['similar_label'] ?: $s['similar_iri']) . '</a>';
    }
    $simHtml = $simHtml ?: '<span class="muted">없음</span>';

    $wdLink = $p['same_as'] ? '<a href="' . h($p['same_as']) . '" target="_blank" rel="noopener">' . h($p['same_as']) . '</a>' : '<span class="muted">연결 없음</span>';
    $name = h($p['name']);
    $back = h(url('people'));
    $body = <<<HTML
<nav class="crumbs"><a href="{$back}">← 인물</a></nav>
<article class="detail">
  <h1>{$name}</h1>
  <table class="kv">
    <tr><th>역할</th><td>{$rolesHtml}</td></tr>
    <tr><th>owl:sameAs</th><td>{$wdLink}</td></tr>
  </table>
  <h2>저작</h2><ul class="list">{$works}</ul>
  <h2>Wikidata 사실 <small>(P551 거주지 · P166 수상 · P106 직업)</small></h2>
  {$factHtml}
  <h2>같은 직업으로 묶이는 다른 시인 <small>(시나리오 7.5)</small></h2>
  <div class="chips">{$simHtml}</div>
</article>
HTML;
    return [$p['name'], $body];
}

// ================================================================== 시집
function page_books(Repo $repo, array $cfg, array $req): array
{
    $edit = $req['edit'] ?? '';
    $cur = $edit ? $repo->book($edit) : null;
    $rows = '';
    foreach ($repo->books() as $b) {
        $act = is_static() ? '' :
            '<a class="mini" href="' . h(url('books', ['edit' => $b['id']])) . '">수정</a> '
            . '<a class="mini danger" href="' . h(url('books/delete', ['id' => $b['id']])) . '" data-confirm="삭제할까요?">삭제</a>';
        $rows .= '<tr><td><a href="' . h(url('books/view', ['id' => $b['id']])) . '">' . h($b['title']) . '</a></td>'
            . '<td>' . h($b['author_name'] ?? '—') . '</td><td>' . h($b['isbn13'] ?? '') . '</td>'
            . '<td class="actions">' . $act . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="4" class="muted">등록된 시집이 없습니다.</td></tr>';
    $form = '';
    if (!is_static()) {
        $saveUrl = h(url('books/save'));
        $cancelUrl = h(url('books'));
        $id = h($cur['id'] ?? '');
        $title = h($cur['title'] ?? '');
        $isbn = h($cur['isbn13'] ?? '');
        $authOpts = person_options($repo, $cur['author_id'] ?? null, 'poet');
        $heading = $cur ? '시집 수정' : '시집 추가';
        $form = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>{$heading}</h2></div>
  <form method="post" action="{$saveUrl}" class="form">
    <input type="hidden" name="id" value="{$id}">
    <label>제목 <span class="req">*</span><input name="title" required value="{$title}" placeholder="예: 구관조 씻기기"></label>
    <label>저자(시인) <select name="author_id">{$authOpts}</select></label>
    <label>ISBN-13 <input name="isbn13" value="{$isbn}" placeholder="13자리 숫자"></label>
    <div class="form-actions"><button class="btn primary">저장</button><a class="btn" href="{$cancelUrl}">취소</a></div>
  </form>
</section>
HTML;
    }
    $body = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>시집 (bibo:Book)</h2></div>
  <table class="grid">
    <thead><tr><th>제목</th><th>저자</th><th>ISBN-13</th><th class="actions">　</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</section>
{$form}
HTML;
    return ['시집', $body];
}

function page_book_view(Repo $repo, array $cfg, array $req): array
{
    $b = $repo->book($req['id'] ?? '');
    if (!$b) return ['없음', '<p class="muted">시집을 찾을 수 없습니다.</p>'];
    $author = $b['author_id'] ? $repo->person($b['author_id']) : null;
    $poems = '';
    foreach ($repo->poems() as $pm) {
        if ($pm['book_id'] === $b['id'])
            $poems .= '<li><a href="' . h(url('poems/view', ['id' => $pm['id']])) . '">' . h($pm['title']) . '</a></li>';
    }
    $poems = $poems ?: '<li class="muted">수록 시 없음</li>';
    $authHtml = $author ? '<a href="' . h(url('people/view', ['id' => $author['id']])) . '">' . h($author['name']) . '</a>' : '<span class="muted">—</span>';
    $isbn = $b['isbn13'] ? h($b['isbn13']) : '<span class="muted">—</span>';
    $title = h($b['title']);
    $back = h(url('books'));
    $body = <<<HTML
<nav class="crumbs"><a href="{$back}">← 시집</a></nav>
<article class="detail">
  <h1>{$title}</h1>
  <table class="kv"><tr><th>저자</th><td>{$authHtml}</td></tr><tr><th>ISBN-13</th><td>{$isbn}</td></tr></table>
  <h2>수록 시</h2><ul class="list">{$poems}</ul>
</article>
HTML;
    return [$b['title'], $body];
}

// ==================================================================== 시
function page_poems(Repo $repo, array $cfg, array $req): array
{
    $edit = $req['edit'] ?? '';
    $cur = $edit ? $repo->poem($edit) : null;
    $rows = '';
    foreach ($repo->poems() as $pm) {
        $act = is_static() ? '' :
            '<a class="mini" href="' . h(url('poems', ['edit' => $pm['id']])) . '">수정</a> '
            . '<a class="mini danger" href="' . h(url('poems/delete', ['id' => $pm['id']])) . '" data-confirm="삭제할까요?">삭제</a>';
        $rows .= '<tr><td><a href="' . h(url('poems/view', ['id' => $pm['id']])) . '">' . h($pm['title']) . '</a></td>'
            . '<td>' . h($pm['author_name'] ?? '—') . '</td><td>' . h($pm['book_title'] ?? '—') . '</td>'
            . '<td class="actions">' . $act . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="4" class="muted">등록된 시가 없습니다.</td></tr>';
    $form = '';
    if (!is_static()) {
        $saveUrl = h(url('poems/save'));
        $cancelUrl = h(url('poems'));
        $id = h($cur['id'] ?? '');
        $title = h($cur['title'] ?? '');
        $authOpts = person_options($repo, $cur['author_id'] ?? null, 'poet');
        $bookOpts = '<option value="">— 시집 없음 —</option>';
        foreach ($repo->books() as $b) $bookOpts .= opt($b['id'], $cur['book_id'] ?? null, $b['title']);
        $bodyText = $cur ? h($repo->poemBodyText($cur['id'])) : '';
        $heading = $cur ? '시 수정' : '시 추가';
        $form = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>{$heading}</h2></div>
  <form method="post" action="{$saveUrl}" class="form">
    <input type="hidden" name="id" value="{$id}">
    <label>제목 <span class="req">*</span><input name="title" required value="{$title}" placeholder="예: 순례"></label>
    <div class="row">
      <label>저자(시인) <select name="author_id">{$authOpts}</select></label>
      <label>수록 시집 (dct:isPartOf) <select name="book_id">{$bookOpts}</select></label>
    </div>
    <label>시 본문 <small>— 빈 줄로 연(聯) 구분, 줄바꿈으로 행 구분. 좌측 표시·연/행 선택에 쓰입니다(LOD 트리플로는 발행하지 않음).</small>
      <textarea name="body_text" rows="12" class="mono" placeholder="첫째 연 첫째 행&#10;첫째 연 둘째 행&#10;&#10;둘째 연 첫째 행">{$bodyText}</textarea>
    </label>
    <div class="form-actions"><button class="btn primary">저장</button><a class="btn" href="{$cancelUrl}">취소</a></div>
  </form>
</section>
HTML;
    }
    $body = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>시 (pac:Poem)</h2></div>
  <table class="grid">
    <thead><tr><th>제목</th><th>저자</th><th>수록 시집</th><th class="actions">　</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</section>
{$form}
HTML;
    return ['시', $body];
}

function page_poem_view(Repo $repo, array $cfg, array $req): array
{
    $pm = $repo->poem($req['id'] ?? '');
    if (!$pm) return ['없음', '<p class="muted">시를 찾을 수 없습니다.</p>'];
    $author = $pm['author_id'] ? $repo->person($pm['author_id']) : null;
    $book = $pm['book_id'] ? $repo->book($pm['book_id']) : null;
    $poemHtml = render_poem_stanzas($repo->poemStanzas($pm['id']));
    $refs = '';
    foreach ($repo->articles() as $a) {
        if ($a['critiques_kind'] === 'poem' && $a['critiques_id'] === $pm['id'])
            $refs .= '<li><a href="' . h(url('articles/view', ['id' => $a['id']])) . '">' . h($a['title']) . '</a> <span class="muted">' . h($a['created'] ?? '') . '</span></li>';
    }
    $refs = $refs ?: '<li class="muted">아직 비평문 없음</li>';
    $authHtml = $author ? '<a href="' . h(url('people/view', ['id' => $author['id']])) . '">' . h($author['name']) . '</a>' : '<span class="muted">—</span>';
    $bookHtml = $book ? '<a href="' . h(url('books/view', ['id' => $book['id']])) . '">' . h($book['title']) . '</a>' : '<span class="muted">—</span>';
    $title = h($pm['title']);
    $back = h(url('poems'));
    $body = <<<HTML
<nav class="crumbs"><a href="{$back}">← 시</a></nav>
<article class="detail">
  <h1>{$title}</h1>
  <table class="kv"><tr><th>저자</th><td>{$authHtml}</td></tr><tr><th>수록 시집</th><td>{$bookHtml}</td></tr></table>
  <h2>본문</h2>
  <div class="poem">{$poemHtml}</div>
  <h2>이 시를 비평한 글</h2><ul class="list">{$refs}</ul>
</article>
HTML;
    return [$pm['title'], $body];
}

/** 연/행 구조를 HTML 로 (data-stanza/data-line 부여) */
function render_poem_stanzas(array $stanzas): string
{
    if (!$stanzas) return '<p class="muted">본문이 입력되지 않았습니다.</p>';
    $html = '';
    foreach ($stanzas as $sNo => $lines) {
        $html .= '<div class="stanza" data-stanza="' . (int) $sNo . '"><span class="stanza-no">' . (int) $sNo . '</span><div class="lines">';
        foreach ($lines as $lNo => $text) {
            $html .= '<div class="pline" data-stanza="' . (int) $sNo . '" data-line="' . (int) $lNo . '">'
                . '<span class="ln">' . (int) $lNo . '</span><span class="tx">' . h($text) . '</span></div>';
        }
        $html .= '</div></div>';
    }
    return $html;
}
