<?php
/** 페이지: 비평문 목록 · 좌우 분할 뷰 · 편집기 · 인용 편집기. */

namespace PACO;

/** 비평문 본문 HTML(rdf:HTML)의 <q xml:id="N"> 에 class/data-anchor 부여 */
function enhance_fulltext(string $html): string
{
    if (trim($html) === '') return '<p class="muted">본문이 비어 있습니다.</p>';
    $doc = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><div id="paco-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    foreach ($doc->getElementsByTagName('q') as $q) {
        $anchor = '';
        foreach (iterator_to_array($q->attributes) as $attr) {
            if ($attr->nodeName === 'xml:id' || $attr->nodeName === 'id') { $anchor = $attr->nodeValue; break; }
        }
        if ($anchor === '') continue;
        $q->setAttribute('class', trim('qmark ' . $q->getAttribute('class')));
        $q->setAttribute('data-anchor', $anchor);
    }
    $root = $doc->documentElement;
    $inner = '';
    foreach (iterator_to_array($root->childNodes) as $child) $inner .= $doc->saveHTML($child);
    return $inner;
}

/** 특정 anchor 의 <q> 내부 텍스트 추출(인용 body_exact 자동 채움용) */
function extract_q_text(string $html, string $anchor): string
{
    if (trim($html) === '') return '';
    $doc = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    foreach ($doc->getElementsByTagName('q') as $q) {
        foreach (iterator_to_array($q->attributes) as $attr) {
            if (($attr->nodeName === 'xml:id' || $attr->nodeName === 'id') && $attr->nodeValue === $anchor) {
                return trim($q->textContent);
            }
        }
    }
    return '';
}

// ============================================================ 비평문 목록
function page_articles(Repo $repo, array $cfg): array
{
    $rows = '';
    foreach ($repo->articles() as $a) {
        $cr = $repo->critiquesLabel($a);
        $target = $cr['title'] ? '<a href="' . h(url($cr['kind'] === 'book' ? 'books/view' : 'poems/view', ['id' => $cr['id']])) . '">' . h($cr['title']) . '</a>' : '—';
        $act = is_static() ? '' :
            '<a class="mini" href="' . h(url('articles/edit', ['id' => $a['id']])) . '">수정</a> '
            . '<a class="mini danger" href="' . h(url('articles/delete', ['id' => $a['id']])) . '" data-confirm="삭제할까요? 인용도 함께 삭제됩니다.">삭제</a>';
        $rows .= '<tr><td><a href="' . h(url('articles/view', ['id' => $a['id']])) . '">' . h($a['title']) . '</a></td>'
            . '<td>' . h($a['author_name'] ?? '—') . '</td><td>' . $target . '</td>'
            . '<td>' . h($a['created'] ?? '') . '</td><td class="num">' . (int) $a['n_quot'] . '</td>'
            . '<td class="actions">' . $act . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="6" class="muted">아직 비평문이 없습니다.</td></tr>';
    $newBtn = is_static() ? '' : '<a class="btn primary" href="' . h(url('articles/edit')) . '">+ 새 비평문</a>';
    $body = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>비평문 (bibo:Article)</h2>{$newBtn}</div>
  <table class="grid">
    <thead><tr><th>제목</th><th>비평자</th><th>대상</th><th>작성일</th><th class="num">인용</th><th class="actions">　</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</section>
HTML;
    return ['비평문', $body];
}

// ====================================================== 좌우 분할 뷰 (핵심)
function page_article_view(Repo $repo, array $cfg, array $req): array
{
    $a = $repo->article($req['id'] ?? '');
    if (!$a) return ['없음', '<p class="muted">비평문을 찾을 수 없습니다.</p>'];
    $cr = $repo->critiquesLabel($a);
    $quotations = $repo->quotations($a['id']);

    // 좌측: 비평 대상(시 / 시집 수록 시들)
    $leftBlocks = '';
    $poemIds = [];
    if ($cr['kind'] === 'poem' && $cr['id']) $poemIds[] = $cr['id'];
    if ($cr['kind'] === 'book' && $cr['id']) {
        foreach ($repo->poems() as $pm) if ($pm['book_id'] === $cr['id']) $poemIds[] = $pm['id'];
    }
    // 인용 대상에 등장하는 시도 포함
    foreach ($quotations as $q) foreach ($q['targets'] as $t) {
        if ($t['source_kind'] === 'poem' && !in_array($t['source_id'], $poemIds, true)) $poemIds[] = $t['source_id'];
    }
    foreach ($poemIds as $pid) {
        $pm = $repo->poem($pid);
        if (!$pm) continue;
        $leftBlocks .= '<div class="poemblock" data-poem="' . h($pid) . '"><h3>' . h($pm['title']) . '</h3>'
            . render_poem_stanzas($repo->poemStanzas($pid)) . '</div>';
    }
    if ($leftBlocks === '') $leftBlocks = '<p class="muted">비평 대상 시 본문이 없습니다. (대상을 시로 지정하고 본문을 입력하세요.)</p>';

    // 우측: 비평문 본문(q 표지 강조)
    $rightHtml = enhance_fulltext($a['full_text']);

    // 인용 카드 + JS 데이터
    $cards = '';
    $jsdata = [];
    foreach ($quotations as $q) {
        $typeBadge = $q['qtype'] === 'direct' ? badge('직접', 'direct') : badge('간접', 'indirect');
        $tlist = '';
        $jtargets = [];
        foreach ($q['targets'] as $t) {
            $loc = loc_label($t);
            $exact = $t['exact'] ? '<span class="q-exact">“' . h(mb_strimwidth($t['exact'], 0, 60, '…')) . '”</span>' : '';
            $ord = ($t['target_order'] !== null && $t['target_order'] !== '') ? '<span class="ord">' . (int) $t['target_order'] . '</span>' : '';
            $tlist .= '<li>' . $ord . '<span class="loc">' . h($loc) . '</span> ' . $exact . '</li>';
            $jtargets[] = [
                'poem' => $t['source_id'], 'kind' => $t['source_kind'],
                'ss' => $t['start_stanza'] !== null ? (int) $t['start_stanza'] : null,
                'es' => $t['end_stanza'] !== null ? (int) $t['end_stanza'] : null,
                'sl' => $t['start_line'] !== null ? (int) $t['start_line'] : null,
                'el' => $t['end_line'] !== null ? (int) $t['end_line'] : null,
            ];
        }
        $disc = count($q['targets']) > 1 ? badge('비연속', 'disc') : '';
        $cards .= '<div class="qcard" data-quotation="' . h($q['id']) . '" data-anchor="' . h($q['anchor']) . '">'
            . '<div class="qcard-head"><span class="qid">#' . h($q['anchor']) . '</span> ' . $typeBadge . ' ' . $disc
            . '<span class="qkey muted">' . h($q['id']) . '</span></div>'
            . (($mk = extract_q_text($a['full_text'], $q['anchor'])) !== '' ? '<div class="qbody">표지: “' . h($mk) . '”</div>' : '')
            . '<ul class="qtargets">' . $tlist . '</ul></div>';
        $jsdata[] = ['id' => $q['id'], 'anchor' => $q['anchor'], 'type' => $q['qtype'], 'targets' => $jtargets];
    }
    if ($cards === '') $cards = '<p class="muted">인용이 없습니다.</p>';
    $json = htmlspecialchars(json_encode($jsdata, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

    $meta = [];
    if ($a['author_name'] ?? null) $meta[] = h($a['author_name']);
    if ($a['created']) $meta[] = h($a['created']);
    if ($cr['title']) $meta[] = '비평 대상: ' . h($cr['title']);
    $metaHtml = implode(' · ', $meta);

    $editBtn = is_static() ? '' : '<a class="btn" href="' . h(url('articles/edit', ['id' => $a['id']])) . '">수정</a>';
    $title = h($a['title']);
    $back = h(url('articles'));

    $body = <<<HTML
<nav class="crumbs"><a href="{$back}">← 비평문</a></nav>
<div class="article-head">
  <div><h1>{$title}</h1><p class="meta">{$metaHtml}</p></div>
  <div class="head-actions">{$editBtn}</div>
</div>
<div class="split" id="split">
  <svg class="connectors" id="connectors" aria-hidden="true"></svg>
  <section class="pane left">
    <div class="pane-label">인용 대상 — 원시(原詩)</div>
    {$leftBlocks}
  </section>
  <section class="pane right">
    <div class="pane-label">비평문 — <code>&lt;q xml:id&gt;</code> 표지를 누르면 좌측 위치와 연결됩니다</div>
    <div class="fulltext" id="fulltext">{$rightHtml}</div>
  </section>
</div>
<section class="panel qpanel">
  <div class="panel-head"><h2>인용 (Web Annotation)</h2></div>
  <div class="qcards" id="qcards">{$cards}</div>
</section>
<script type="application/json" id="paco-quotations">{$json}</script>
HTML;
    return [$a['title'], $body];
}

// =============================================================== 편집기
function page_article_edit(Repo $repo, array $cfg, array $req): array
{
    $cur = !empty($req['id']) ? $repo->article($req['id']) : null;
    $saveUrl = h(url('articles/save'));
    $cancelUrl = h(url('articles'));
    $id = h($cur['id'] ?? '');
    $title = h($cur['title'] ?? '');
    $created = h($cur['created'] ?? paco_today());
    $fullText = h($cur['full_text'] ?? '<p></p>');
    $criticOpts = person_options($repo, $cur['author_id'] ?? null, 'critic');

    // critiques 대상: poem/book 통합 select (값 "poem:ID" / "book:ID")
    $curTarget = '';
    if ($cur && $cur['critiques_kind'] && $cur['critiques_id']) $curTarget = $cur['critiques_kind'] . ':' . $cur['critiques_id'];
    $targetOpts = '<option value="">— 선택 —</option>';
    $pg = ''; foreach ($repo->poems() as $pm) $pg .= opt('poem:' . $pm['id'], $curTarget, $pm['title']);
    $bg = ''; foreach ($repo->books() as $b) $bg .= opt('book:' . $b['id'], $curTarget, $b['title']);
    if ($pg) $targetOpts .= '<optgroup label="시">' . $pg . '</optgroup>';
    if ($bg) $targetOpts .= '<optgroup label="시집">' . $bg . '</optgroup>';

    $heading = $cur ? '비평문 수정' : '새 비평문';

    // 인용 섹션(저장된 비평문에만)
    $quotSection = '<p class="muted note">먼저 비평문을 저장하면 인용을 추가할 수 있습니다.</p>';
    if ($cur) {
        $qrows = '';
        foreach ($repo->quotations($cur['id']) as $q) {
            $loc = '';
            foreach ($q['targets'] as $t) $loc .= '<span class="loc">' . h(loc_label($t)) . '</span> ';
            $qEdit = h(url('quotations/edit', ['id' => $q['id'], 'article_id' => $cur['id']]));
            $qDel = h(url('quotations/delete', ['id' => $q['id'], 'article_id' => $cur['id']]));
            $type = $q['qtype'] === 'direct' ? '직접' : '간접';
            $qrows .= '<tr><td><b>#' . h($q['anchor']) . '</b></td><td>' . h($type) . '</td><td>' . ($loc ?: '<span class="muted">위치 미지정</span>') . '</td>'
                . '<td class="actions"><a class="mini" href="' . $qEdit . '">수정</a> '
                . '<a class="mini danger" href="' . $qDel . '" data-confirm="인용을 삭제할까요?">삭제</a></td></tr>';
        }
        if ($qrows === '') $qrows = '<tr><td colspan="4" class="muted">인용이 없습니다.</td></tr>';
        $newQ = h(url('quotations/edit', ['article_id' => $cur['id']]));
        $viewUrl = h(url('articles/view', ['id' => $cur['id']]));
        $quotSection = <<<HTML
<div class="panel-head"><h3>인용 목록</h3><a class="btn primary" href="{$newQ}">+ 새 인용</a></div>
<table class="grid">
  <thead><tr><th>앵커</th><th>유형</th><th>대상 위치</th><th class="actions">　</th></tr></thead>
  <tbody>{$qrows}</tbody>
</table>
<p class="note">팁: 본문에서 인용 표지를 만들려면, 아래 본문 편집기에서 텍스트를 선택하고 <b>“&lt;q&gt; 삽입”</b>을 누르세요. 부여된 번호(xml:id)를 인용의 <b>앵커</b>로 쓰면 됩니다. → <a href="{$viewUrl}">분할 뷰로 보기</a></p>
HTML;
    }

    $body = <<<HTML
<nav class="crumbs"><a href="{$cancelUrl}">← 비평문</a></nav>
<section class="panel">
  <div class="panel-head"><h2>{$heading}</h2></div>
  <form method="post" action="{$saveUrl}" class="form" id="article-form">
    <input type="hidden" name="id" value="{$id}">
    <label>제목 <span class="req">*</span><input name="title" required value="{$title}" placeholder="예: 「순례」의 마지막 문장에 대하여"></label>
    <div class="row">
      <label>비평자(Critic) <select name="author_id">{$criticOpts}</select></label>
      <label>작성일 (dct:created) <input type="date" name="created" value="{$created}"></label>
      <label>비평 대상 (cito:critiques) <select name="critiques">{$targetOpts}</select></label>
    </div>
    <div class="editor-toolbar">
      <button type="button" class="btn" id="btn-wrap-q">&lt;q&gt; 삽입 (선택 영역 태깅)</button>
      <span class="muted">선택한 텍스트를 <code>&lt;q xml:id="N"&gt;</code> 로 감쌉니다. N 은 자동 증가.</span>
    </div>
    <label>본문 (pac:fullText · rdf:HTML)
      <textarea name="full_text" id="full_text" rows="14" class="mono">{$fullText}</textarea>
    </label>
    <div class="form-actions"><button class="btn primary">저장</button><a class="btn" href="{$cancelUrl}">취소</a></div>
  </form>
</section>
<section class="panel">{$quotSection}</section>
HTML;
    return [$heading, $body];
}

// ========================================================= 인용 편집기
function page_quotation_edit(Repo $repo, array $cfg, array $req): array
{
    $articleId = $req['article_id'] ?? '';
    $article = $repo->article($articleId);
    if (!$article) return ['없음', '<p class="muted">대상 비평문을 찾을 수 없습니다.</p>'];
    $cur = !empty($req['id']) ? $repo->quotation($req['id']) : null;

    $saveUrl = h(url('quotations/save'));
    $backUrl = h(url('articles/edit', ['id' => $articleId]));
    $id = h($cur['id'] ?? '');
    $anchor = h($cur['anchor'] ?? '');
    $qtype = $cur['qtype'] ?? 'indirect';
    $tyDirect = $qtype === 'direct' ? ' checked' : '';
    $tyIndirect = $qtype !== 'direct' ? ' checked' : '';

    // 기존 대상 행들
    $targetRows = '';
    $targets = $cur['targets'] ?? [];
    if (!$targets) $targets = [[]]; // 빈 행 하나
    foreach ($targets as $i => $t) {
        $sel = ($t['source_kind'] ?? '') !== '' ? $t['source_kind'] . ':' . $t['source_id'] : '';
        $opts = build_source_options($repo, $sel);
        $targetRows .= target_row_html($i, $opts, $t);
    }
    $emptyOpts = build_source_options($repo, '');
    $templateRow = target_row_html('__IDX__', $emptyOpts, []);

    $heading = $cur ? '인용 수정' : '새 인용';
    $articleTitle = h($article['title']);
    // 본문 미리보기(앵커 확인용) + 본문에 존재하는 xml:id 자동완성
    $preview = enhance_fulltext($article['full_text']);
    preg_match_all('/xml:id\s*=\s*["\']?(\d+)/', $article['full_text'], $mm);
    $anchorOpts = '';
    foreach (array_values(array_unique($mm[1] ?? [])) as $av) $anchorOpts .= '<option value="' . h($av) . '">';
    $anchorDatalist = '<datalist id="anchor-list">' . $anchorOpts . '</datalist>';
    $fulltextJson = htmlspecialchars(json_encode(['fulltext' => $article['full_text']], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

    $body = <<<HTML
<nav class="crumbs"><a href="{$backUrl}">← {$articleTitle}</a></nav>
<section class="panel">
  <div class="panel-head"><h2>{$heading} <span class="muted">— {$articleTitle}</span></h2></div>
  <div class="qedit">
    <form method="post" action="{$saveUrl}" class="form" id="quotation-form">
      <input type="hidden" name="id" value="{$id}">
      <input type="hidden" name="article_id" value="{$articleId}">
      <div class="row">
        <label>본문 앵커 (xml:id) <span class="req">*</span>
          <input name="anchor" required value="{$anchor}" placeholder="예: 1" list="anchor-list">
          <small>비평문 본문 <code>&lt;q xml:id="N"&gt;</code> 의 N 과 일치시키세요.</small>
        </label>
        <fieldset class="qtype"><legend>인용 유형 (pac:quotationType)</legend>
          <label class="chk"><input type="radio" name="qtype" value="indirect"{$tyIndirect}> 간접</label>
          <label class="chk"><input type="radio" name="qtype" value="direct"{$tyDirect}> 직접</label>
        </fieldset>
      </div>
      <p class="note">본문 표지(oa:hasBody)는 위 <b>앵커(xml:id)</b> 하나로 지정됩니다 — 표지 문구는 본문 <code>&lt;q xml:id&gt;</code> 에 이미 있습니다(v0.4 슬림 모델).</p>
      <fieldset class="boxset"><legend>인용 대상 (oa:hasTarget) — 2개 이상이면 비연속 인용</legend>
        <div id="targets">{$targetRows}</div>
        <button type="button" class="btn" id="add-target">+ 대상 추가</button>
      </fieldset>
      <div class="form-actions"><button class="btn primary">저장</button><a class="btn" href="{$backUrl}">취소</a></div>
    </form>
    <aside class="qpreview">
      <div class="pane-label">본문 미리보기 — 앵커 확인용</div>
      <div class="fulltext">{$preview}</div>
    </aside>
  </div>
</section>
{$anchorDatalist}
<template id="target-template">{$templateRow}</template>
<script type="application/json" id="paco-article">{$fulltextJson}</script>
HTML;
    return [$heading, $body];
}

/** 대상 source <select> 옵션(선택 반영) */
function build_source_options(Repo $repo, string $selected): string
{
    $out = '<option value="">— 출처 —</option>';
    $pg = '';
    foreach ($repo->poems() as $pm) $pg .= opt('poem:' . $pm['id'], $selected, $pm['title']);
    $bg = '';
    foreach ($repo->books() as $b) $bg .= opt('book:' . $b['id'], $selected, $b['title']);
    if ($pg) $out .= '<optgroup label="시">' . $pg . '</optgroup>';
    if ($bg) $out .= '<optgroup label="시집">' . $bg . '</optgroup>';
    return $out;
}

/** 대상 입력 행 HTML */
function target_row_html(string|int $i, string $sourceOpts, array $t): string
{
    $ss = h((string) ($t['start_stanza'] ?? ''));
    $es = h((string) ($t['end_stanza'] ?? ''));
    $sl = h((string) ($t['start_line'] ?? ''));
    $el = h((string) ($t['end_line'] ?? ''));
    $ex = h((string) ($t['exact'] ?? ''));
    return <<<HTML
<div class="target-row" data-idx="{$i}">
  <div class="trow1">
    <label class="grow">출처 (oa:hasSource) <select name="t[{$i}][source]">{$sourceOpts}</select></label>
    <button type="button" class="mini danger rm-target" title="대상 삭제">✕</button>
  </div>
  <div class="trow2">
    <label>시작 연<input type="number" min="1" name="t[{$i}][start_stanza]" value="{$ss}"></label>
    <label>끝 연<input type="number" min="1" name="t[{$i}][end_stanza]" value="{$es}"></label>
    <label>시작 행<input type="number" min="1" name="t[{$i}][start_line]" value="{$sl}"></label>
    <label>끝 행<input type="number" min="1" name="t[{$i}][end_line]" value="{$el}"></label>
  </div>
  <label>원문 (oa:exact)<input name="t[{$i}][exact]" value="{$ex}" placeholder="인용된 시 원문(선택)"></label>
</div>
HTML;
}
