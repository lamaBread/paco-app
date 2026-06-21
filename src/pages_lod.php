<?php
/** 페이지: LOD 발행 · 추론 질의(Wikidata 연합). */

namespace PACO;

// ============================================================ LOD 발행
function page_lod(Repo $repo, array $cfg): array
{
    $g = Rdf::buildAbox($repo, $cfg);
    $ttl = $g->toTurtle();
    $n = $g->tripleCount();
    $ttlPreview = h($ttl);

    $owlUrl = h(dump_url('owl'));
    $ttlUrl = h(dump_url('ttl'));
    $jsonUrl = h(dump_url('jsonld'));
    $ntUrl = h(dump_url('nt'));

    $base = h($cfg['iri_data']);
    $tbox = h($cfg['iri_tbox']);
    $ontVer = h((string) ($cfg['ont_version'] ?? 'dev'));

    $body = <<<HTML
<section class="hero small">
  <h1>LOD 발행</h1>
  <p class="lead">편집 원본(SQLite)을 PAC v{$ontVer} 온톨로지로 <b>외부 추론용</b> Linked Open Data 로 발행합니다(GUI 잔재 비발행). 현재 ABox <b>{$n}</b> 트리플.</p>
</section>

<section class="panel">
  <div class="panel-head"><h2>덤프 내려받기</h2></div>
  <div class="dl-grid">
    <a class="dl" href="{$ttlUrl}"><b>Turtle</b><span>pac-data.ttl</span></a>
    <a class="dl" href="{$owlUrl}"><b>RDF/XML</b><span>pac-data.owl · Protégé</span></a>
    <a class="dl" href="{$jsonUrl}"><b>JSON-LD</b><span>pac-data.jsonld</span></a>
    <a class="dl" href="{$ntUrl}"><b>N-Triples</b><span>pac-data.nt · 검증용</span></a>
  </div>
  <table class="kv">
    <tr><th>TBox IRI</th><td><code>{$tbox}</code></td></tr>
    <tr><th>ABox base IRI</th><td><code>{$base}</code> <small>— config.php 의 <code>iri_data</code> 를 배포 도메인으로 바꾸면 발행 IRI 가 함께 바뀝니다.</small></td></tr>
    <tr><th>어휘(TBox)</th><td>vocab/pac-ontology.owl · vocab/pac-shapes.ttl (v{$ontVer})</td></tr>
  </table>
  <p class="note">검증(SHACL): <code>pyshacl -s vocab/pac-shapes.ttl -e vocab/pac-ontology.owl -df ttl pac-data.ttl</code></p>
</section>

<section class="panel">
  <div class="panel-head"><h2>Turtle 미리보기</h2></div>
  <pre class="code">{$ttlPreview}</pre>
</section>
HTML;
    return ['LOD 발행', $body];
}

// ======================================================== 추론 질의
//   '비평가의 거울과 나침반' — 외부 전기 사실을 긁어오던 옛 7.x 질의를 폐기하고,
//   비평가 자신의 발행 그래프(비평문·인용·연/행)를 거울로 비추고(M1~M4) 국가서지LOD 로
//   다음 행동을 가리킨다(C1~C2). 집계는 src/Insights.php, 네트워크 fetch 는 NlLod.
function page_insights(Repo $repo, array $cfg): array
{
    $ins = new Insights($repo);
    $nl  = new NlLod($repo, $cfg);
    $nlLast = $nl->lastFetched();

    // ── 표시 헬퍼 ──────────────────────────────────────────────
    $bar = static function (int $v, int $max, int $w = 18): string {
        if ($v <= 0 || $max <= 0) return '';
        $n = max(1, (int) round($v * $w / $max));
        return '<span class="ibar">' . str_repeat('▮', $n) . '</span>';
    };
    $pct = static fn(int $a, int $b): int => $b > 0 ? (int) round($a * 100 / $b) : 0;

    // ── 상단 요약(옛 7.4 대체) ─────────────────────────────────
    $s = $ins->summary();
    $summaryHtml = '<div class="statline">'
        . '<span><b>' . (int) $s['articles'] . '</b> 비평문</span>'
        . '<span><b>' . (int) $s['poets'] . '</b> 비평한 시인</span>'
        . '<span><b>' . (int) $s['quotations'] . '</b> 인용 <span class="muted">(직접 ' . (int) $s['direct']
        . ' · 간접 ' . (int) $s['indirect'] . ')</span></span></div>';

    // ── M1 인용 방식 거울 ─────────────────────────────────────
    $qs = $ins->quotationStyle();
    $od = (int) $qs['overall']['direct']; $oi = (int) $qs['overall']['indirect']; $ot = $od + $oi;
    $m1 = '<div class="distrow"><span class="k">직접 인용</span>' . $bar($od, max(1, $ot))
        . '<span class="v">' . $od . ' <span class="muted">(' . $pct($od, $ot) . '%)</span></span></div>'
        . '<div class="distrow"><span class="k">간접 인용</span>' . $bar($oi, max(1, $ot))
        . '<span class="v">' . $oi . ' <span class="muted">(' . $pct($oi, $ot) . '%)</span></span></div>';
    $m1rows = '';
    foreach ($qs['perArticle'] as $a) {
        $m1rows .= '<tr><td><a href="' . h(url('articles/view', ['id' => $a['id']])) . '">' . h($a['title']) . '</a></td>'
            . '<td class="num">' . $a['direct'] . '</td><td class="num">' . $a['indirect'] . '</td>'
            . '<td>' . $bar($a['direct'], max(1, $a['total']), 10)
            . '<span class="muted">' . $bar($a['indirect'], max(1, $a['total']), 10) . '</span></td></tr>';
    }
    if ($m1rows === '') $m1rows = '<tr><td colspan="4" class="muted">아직 인용이 없습니다.</td></tr>';

    // ── M2 텍스트 밀착도 ──────────────────────────────────────
    $m2rows = '';
    foreach ($ins->closeReading() as $r) {
        $cov = $r['stanzas_total'] > 0
            ? $r['stanzas_touched'] . '/' . $r['stanzas_total'] . '연 <span class="muted">(' . $pct($r['stanzas_touched'], $r['stanzas_total']) . '%)</span>'
            : '<span class="muted">—</span>';
        $thin = $r['selections'] <= 1 ? ' class="thin"' : '';
        $m2rows .= '<tr' . $thin . '><td><a href="' . h(url('articles/view', ['id' => $r['article_id']])) . '">' . h($r['article_title']) . '</a>'
            . '<div class="muted small">' . h($r['poem_title']) . ($r['poet'] ? ' · ' . h($r['poet']) : '') . '</div></td>'
            . '<td class="num">' . $r['quotations'] . '</td><td class="num">' . $r['selections'] . '</td><td>' . $cov . '</td></tr>';
    }
    if ($m2rows === '') $m2rows = '<tr><td colspan="4" class="muted">시를 대상으로 한 비평문이 아직 없습니다.</td></tr>';

    // ── M3 인용 위치 편향 ─────────────────────────────────────
    $pb = $ins->positionBias();
    $maxB = max(1, ...array_values($pb['buckets']));
    $m3 = '';
    foreach (['처음' => '시의 처음(첫 연)', '중간' => '시의 중간', '마지막' => '시의 마지막(끝 연)', '단연' => '단연 시(한 연)'] as $k => $label) {
        $v = (int) $pb['buckets'][$k];
        $m3 .= '<div class="distrow"><span class="k">' . h($label) . '</span>' . $bar($v, $maxB)
            . '<span class="v">' . $v . '</span></div>';
    }
    if ($pb['total'] === 0) $m3 = '<p class="muted">연/행을 지정한 인용이 아직 없습니다.</p>';

    // ── M4 편식 지도 ─────────────────────────────────────────
    $bm = $ins->biasMap();
    $m4dec = '';
    if ($bm['decades']) {
        $maxD = max(1, ...array_values(array_map('count', $bm['decades'])));
        foreach ($bm['decades'] as $label => $poets) {
            $m4dec .= '<div class="distrow"><span class="k">' . h($label) . '생</span>' . $bar(count($poets), $maxD)
                . '<span class="v">' . count($poets) . ' <span class="muted">' . h(implode(', ', $poets)) . '</span></span></div>';
        }
    } else {
        $m4dec = '<p class="muted">국가서지LOD 생몰년 캐시가 없습니다. 위 버튼으로 프리페치하세요.</p>';
    }
    $m4field = '';
    if ($bm['fields']) {
        $maxF = max(1, ...array_values($bm['fields']));
        foreach ($bm['fields'] as $field => $cnt) {
            $m4field .= '<div class="distrow"><span class="k">' . h($field) . '</span>' . $bar((int) $cnt, $maxF)
                . '<span class="v">' . (int) $cnt . '</span></div>';
        }
    } else {
        $m4field = '<p class="muted">활동분야 캐시가 없습니다.</p>';
    }
    $m4note = $bm['noProfile']
        ? '<p class="note">국가서지LOD 프로파일이 없는 시인: ' . h(implode(', ', $bm['noProfile']))
            . ' <span class="muted">— 인물 편집에서 국가서지LOD 자원(nl_uri)을 연결하면 편식 지도에 들어옵니다.</span></p>'
        : '';

    // ── C1 비평 공백(backlog) ─────────────────────────────────
    $hold = $ins->uncritiquedHoldings();
    $c1 = '';
    if ($hold['poems']) {
        $c1 .= '<div class="sub">아직 비평 안 한 시</div><div class="chips">';
        foreach ($hold['poems'] as $p) {
            $sub = $p['poet'] ? ' <span class="muted">· ' . h($p['poet']) . '</span>' : '';
            $c1 .= '<a class="chip" href="' . h(url('poems/view', ['id' => $p['id']])) . '"><b>' . h($p['title']) . '</b>' . $sub . '</a>';
        }
        $c1 .= '</div>';
    }
    if ($hold['books']) {
        $c1 .= '<div class="sub">아직 비평 안 한 시집</div><div class="chips">';
        foreach ($hold['books'] as $b) {
            $sub = $b['poet'] ? ' <span class="muted">· ' . h($b['poet']) . '</span>' : '';
            $c1 .= '<a class="chip" href="' . h(url('books/view', ['id' => $b['id']])) . '"><b>' . h($b['title']) . '</b>' . $sub . '</a>';
        }
        $c1 .= '</div>';
    }
    if ($c1 === '') $c1 = '<p class="muted">등록한 시·시집을 모두 비평했습니다. 새 자료를 등록하거나 아래 추천을 보세요.</p>';

    // ── C2 근거 있는 다음 시인 ────────────────────────────────
    $c2 = '';
    foreach ($ins->recommendations(24) as $r) {
        $link = $r['nl_uri'] ? ' href="' . h($r['nl_uri']) . '" target="_blank" rel="noopener"' : '';
        $gap = $r['fills_gap'] ? '<span class="score">gap</span> ' : '';
        $c2 .= '<a class="chip rec"' . $link . ' title="' . h($r['why']) . '">' . $gap . '<b>' . h($r['name']) . '</b>'
            . '<span class="muted"> · ' . h($r['why']) . '</span></a>';
    }
    if ($c2 === '') {
        $c2 = '<p class="muted">후보가 아직 없습니다. 비평한 시인에 국가서지LOD 자원을 연결하고 위 버튼으로 프리페치하면, '
            . '같은 활동분야에서 <b>당신이 비운 세대</b>를 채울 시인이 — 이미 비평한 시인은 빼고 — 사유와 함께 모입니다.</p>';
    } else {
        $c2 = '<div class="chips reclist">' . $c2 . '</div>';
    }

    // ── 툴바 ─────────────────────────────────────────────────
    $refreshBtn = '';
    if (!is_static()) {
        $refreshUrl = h(url('insights/refresh'));
        $refreshBtn = '<a class="btn primary" href="' . $refreshUrl . '" data-confirm="국가서지LOD 공개 엔드포인트에 질의해 프로파일·후보 캐시를 갱신합니다. 계속할까요?">프리페치 / 갱신</a>';
    }
    $lastHtml = $nlLast ? '<span class="muted">마지막 갱신: 국가서지LOD ' . h($nlLast) . '</span>' : '<span class="muted">아직 갱신 안 됨</span>';

    // ── 형식 정의용 SPARQL(발행 그래프 기준) ───────────────────
    $qM1 = h(<<<'SPARQL'
SELECT ?type (COUNT(?q) AS ?n) WHERE {
  ?art a bibo:Article ; pac:hasQuotation ?q .
  ?q   pac:quotationType ?type .     # pac:DirectQuotation | pac:IndirectQuotation
} GROUP BY ?type
SPARQL);
    $qM2 = h(<<<'SPARQL'
SELECT ?poem ?title (COUNT(DISTINCT ?sel) AS ?selections)
                    (COUNT(DISTINCT ?st)  AS ?stanzas) WHERE {
  ?art  a bibo:Article ; cito:critiques ?poem ; pac:hasQuotation ?q .
  ?poem a pac:Poem ; pac:documentTitle ?title .
  ?q oa:hasTarget ?t . ?t oa:hasSource ?poem ; oa:hasSelector ?sel .
  ?sel a pac:TextSelection ; pac:startStanza ?st .
} GROUP BY ?poem ?title ORDER BY ?selections
SPARQL);
    $qM3 = h(<<<'SPARQL'
SELECT ?startStanza (COUNT(*) AS ?n) WHERE {
  ?art a bibo:Article ; pac:hasQuotation ?q .
  ?q oa:hasTarget/oa:hasSelector ?sel .
  ?sel pac:startStanza ?startStanza .
} GROUP BY ?startStanza ORDER BY ?startStanza
# 시 전체 연 수(내부 poem_line)로 시작 연을 처음/중간/마지막으로 정규화한다.
SPARQL);
    $qM4 = h(<<<'SPARQL'
SELECT ?decade (COUNT(DISTINCT ?poet) AS ?n) WHERE {
  ?art a bibo:Article ; cito:critiques ?w . ?w pac:hasAuthor ?poet .
  ?poet owl:sameAs ?nl .                         # 국가서지LOD 자원
  SERVICE <https://lod.nl.go.kr/sparql> { ?nl nlon:birthYear ?by }
  BIND( FLOOR(?by/10)*10 AS ?decade )
} GROUP BY ?decade ORDER BY ?decade
# 구현은 nl_fact 프리페치 캐시에서 집계(엔진 제약 회피). 활동분야는 nlon:fieldOfActivity.
SPARQL);
    $qC1 = h(<<<'SPARQL'
SELECT ?poem ?title ?poet WHERE {
  ?poem a pac:Poem ; pac:documentTitle ?title ; pac:hasAuthor ?a .
  ?a foaf:name ?poet .
  FILTER NOT EXISTS { ?art cito:critiques ?poem }   # 아직 비평 안 된 시
}
SPARQL);
    $qC2 = h(<<<'SPARQL'
PREFIX nlon:   <http://lod.nl.go.kr/ontology/>
PREFIX schema: <http://schema.org/>
SELECT ?cand ?name ?by WHERE {
  ?cand a nlon:Author ; schema:jobTitle "시인" ; foaf:name ?name ;
        nlon:fieldOfActivity ?field ; nlon:birthYear ?by .
  VALUES ?field { … 내가 주로 보는 활동분야 … }
  FILTER NOT EXISTS { ?mine a pac:Poet ; foaf:name ?name . }   # 이미 등록/비평한 시인 제외
} LIMIT 24
# 구현: 분야별 검색 결과를 nl_candidate 에 프리페치하고, 내가 비운 출생 세대를 우선 정렬.
SPARQL);

    $body = <<<HTML
<section class="hero small">
  <h1>추론 질의 <small>— 비평가의 거울과 나침반</small></h1>
  <p class="lead">외부 사실을 나열하는 대신, <b>당신의 비평 그래프</b>(비평문·인용·연/행)를 거울로 비추고 <b>국가서지LOD</b> 프로파일로 다음 행동을 가리킵니다. <b>거울</b>은 인용 습관·텍스트 밀착도·위치 편향·시대/분야 편식을 보여주고(네트워크 불필요), <b>나침반</b>은 아직 안 다룬 내 자료와, 내가 비운 세대를 채울 시인을 — 이미 비평한 시인은 빼고 — 사유와 함께 추천합니다.</p>
  <div class="toolbar">{$refreshBtn} {$lastHtml}</div>
  {$summaryHtml}
</section>

<h2 class="grouphead">거울 — 내 비평을 비춘다 <small>(로컬 그래프)</small></h2>

<section class="panel">
  <div class="panel-head"><h2>M1 · 인용 방식 거울 <small>직접 vs 간접</small></h2></div>
  <p class="note">주장을 시인의 <b>정확한 말로 정박(직접 인용)</b>하는지, <b>풀어 쓰는지(간접)</b>를 비춥니다. 한쪽으로 치우치면 증거 사용의 버릇이 드러납니다.</p>
  <div class="distbox">{$m1}</div>
  <table class="grid">
    <thead><tr><th>비평문</th><th class="num">직접</th><th class="num">간접</th><th>분포</th></tr></thead>
    <tbody>{$m1rows}</tbody>
  </table>
  <details class="sparql"><summary>SPARQL (M1)</summary><pre class="code">{$qM1}</pre></details>
</section>

<section class="panel">
  <div class="panel-head"><h2>M2 · 텍스트 밀착도 <small>한 편을 얼마나 촘촘히 읽나</small></h2></div>
  <p class="note">비평문이 시에서 고른 <b>인용 선택 수</b>와 <b>다룬 연의 범위</b>입니다. 선택이 1개뿐인 행(<span class="thin-legend">옅게</span>)은 한 구절로 시 전체를 논한 표면적 비평일 수 있습니다.</p>
  <table class="grid">
    <thead><tr><th>비평문 · 대상 시</th><th class="num">인용</th><th class="num">선택</th><th>연 커버리지</th></tr></thead>
    <tbody>{$m2rows}</tbody>
  </table>
  <details class="sparql"><summary>SPARQL (M2)</summary><pre class="code">{$qM2}</pre></details>
</section>

<section class="panel">
  <div class="panel-head"><h2>M3 · 인용 위치 편향 <small>시의 어디를 인용하나</small></h2></div>
  <p class="note">인용한 부분이 시의 처음·중간·마지막 중 어디인지의 분포. <b>늘 결말만</b> 붙잡는 등 구조적 독해 버릇을 비춥니다.</p>
  <div class="distbox">{$m3}</div>
  <details class="sparql"><summary>SPARQL (M3)</summary><pre class="code">{$qM3}</pre></details>
</section>

<section class="panel">
  <div class="panel-head"><h2>M4 · 편식 지도 <small>출생 세대 · 활동분야</small></h2></div>
  <p class="note">내가 비평한 시인을 <b>국가서지LOD</b> 생몰년·활동분야로 묶은 분포입니다. 빈 칸이 곧 사각지대 — 아래 나침반(C2)의 입력이 됩니다.</p>
  <div class="cols2">
    <div><div class="sub">출생 세대</div><div class="distbox">{$m4dec}</div></div>
    <div><div class="sub">활동분야</div><div class="distbox">{$m4field}</div></div>
  </div>
  {$m4note}
  <details class="sparql"><summary>SPARQL (M4)</summary><pre class="code">{$qM4}</pre></details>
</section>

<h2 class="grouphead">나침반 — 다음에 무엇을 읽을까</h2>

<section class="panel">
  <div class="panel-head"><h2>C1 · 비평 공백 <small>등록만 하고 안 다룬 내 자료</small></h2></div>
  <p class="note">내 PACO 에 있지만 아직 비평문이 없는 시·시집. 가장 구체적인 '다음에 읽을 것' — 외부 의존 0.</p>
  {$c1}
  <details class="sparql"><summary>SPARQL (C1)</summary><pre class="code">{$qC1}</pre></details>
</section>

<section class="panel highlight">
  <div class="panel-head"><h2>C2 · 근거 있는 다음 시인 <small>비운 세대를 채우기</small></h2></div>
  <p class="note">M4 가 비었다고 알려준 세대를, 내가 실제로 다루는 <b>활동분야</b> 안에서 채울 시인을 국가서지LOD 에서 찾습니다. <b>이미 비평한 시인은 제외</b>하고, 각 추천에 사유(<span class="score">gap</span> = 내가 비운 세대)를 답니다.</p>
  {$c2}
  <details class="sparql"><summary>SPARQL (C2)</summary><pre class="code">{$qC2}</pre></details>
</section>
HTML;
    return ['추론 질의', $body];
}
