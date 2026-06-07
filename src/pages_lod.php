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

    $body = <<<HTML
<section class="hero small">
  <h1>LOD 발행</h1>
  <p class="lead">편집 원본(SQLite)을 PAC v0.4 온톨로지에 충실한 Linked Open Data 로 직렬화합니다. 현재 ABox <b>{$n}</b> 트리플.</p>
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
    <tr><th>어휘(TBox)</th><td>vocab/pac-ontology.owl · vocab/pac-shapes.ttl (v0.4)</td></tr>
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
function page_insights(Repo $repo, array $cfg): array
{
    $nl = new NlLod($repo, $cfg);
    $wd = new Wikidata($repo, $cfg);
    $poets = $repo->critiquedPoets();
    $last = $wd->lastFetched();
    $nlLast = $nl->lastFetched();

    // 7.4 — 내가 비평한 시인 + 빈도
    $freqRows = '';
    foreach ($poets as $p) {
        $wdcell = $p['same_as']
            ? '<a href="' . h($p['same_as']) . '" target="_blank" rel="noopener">' . h(Wikidata::qid($p['same_as'])) . '</a>'
            : '<span class="muted">sameAs 없음</span>';
        $freqRows .= '<tr><td><a href="' . h(url('people/view', ['id' => $p['id']])) . '">' . h($p['name']) . '</a></td>'
            . '<td>' . $wdcell . '</td><td class="num">' . (int) $p['n'] . '</td></tr>';
    }
    if ($freqRows === '') $freqRows = '<tr><td colspan="3" class="muted">아직 비평 대상 시인이 없습니다.</td></tr>';

    $names = [];
    foreach ($repo->people() as $pp) $names[$pp['id']] = $pp['name'];

    // 7.7 — 추천(gap): 유사 시인 중 내 컬렉션에 아직 없는 시인
    $recHtml = '';
    foreach ($wd->recommendations(24) as $r) {
        $why = $r['vias'] ? '<span class="muted"> · ' . h(str_replace(',', ', ', (string) $r['vias'])) . '</span>' : '';
        $recHtml .= '<a class="chip rec" href="' . h($r['similar_iri']) . '" target="_blank" rel="noopener">'
            . '<span class="score">' . (int) $r['score'] . '</span> <b>' . h($r['similar_label']) . '</b>' . $why . '</a>';
    }
    if ($recHtml === '') $recHtml = '<p class="muted">추천할 시인이 아직 없습니다. 시인에 owl:sameAs 를 연결하고 프리페치하면, 내가 비평한 시인들과 직업·사조를 공유하지만 아직 비평하지 않은 시인이 여기 모입니다.</p>';

    // 7.5/7.6 — 시인별 확장 프로파일: 국가서지LOD(기본) + Wikidata(폴백·비슷한 시인)
    $detail = '';
    foreach ($poets as $p) {
        $nlRows = $nl->factsByPerson($p['id']);
        $facts = $wd->factsByPerson($p['id']);
        $sim = $wd->similarByPerson($p['id']);
        if (!$nlRows && !$facts && !$sim) continue;
        $nlHtml = fact_table($nlRows);
        $byProp = [];
        foreach ($facts as $f) $byProp[$f['prop_label'] ?: $f['prop_pid']][] = $f['value_label'] ?: $f['value_iri'];
        $factHtml = '';
        foreach ($byProp as $label => $vals) $factHtml .= '<tr><th>' . h($label) . '</th><td>' . h(implode(', ', array_unique($vals))) . '</td></tr>';
        $simHtml = '';
        foreach (array_slice($sim, 0, 18) as $s) {
            $t = $s['via_label'] ? ' title="공유: ' . h($s['via_label']) . '"' : '';
            $simHtml .= '<a class="chip"' . $t . ' href="' . h($s['similar_iri']) . '" target="_blank" rel="noopener">' . h($s['similar_label'] ?: $s['similar_iri']) . '</a>';
        }
        $src = $nlRows ? '<span class="tag nlsrc">국가서지LOD</span>' : '<span class="tag wdsrc">Wikidata 폴백</span>';
        $detail .= '<div class="insight-card"><h3>' . h($p['name']) . ' ' . $src . '</h3>'
            . ($nlHtml ? '<div class="sub">국가서지LOD 프로파일</div>' . $nlHtml : '')
            . ($factHtml ? '<div class="sub">Wikidata 보강</div><table class="kv">' . $factHtml . '</table>' : '')
            . ($simHtml ? '<div class="sub">비슷한 시인 <span class="muted">(직업·사조 공유 — Wikidata)</span></div><div class="chips">' . $simHtml . '</div>' : '')
            . '</div>';
    }
    if ($detail === '') $detail = '<p class="muted">캐시된 결과가 없습니다. 위 버튼으로 프리페치하세요(네트워크 필요).</p>';

    // 친화도 — 내가 비평한 시인들끼리 공유하는 속성(직업·사조·국적 등)
    $affHtml = '';
    foreach ($wd->affinity() as $af) {
        $n1 = $names[$af['p1']] ?? $af['p1']; $n2 = $names[$af['p2']] ?? $af['p2'];
        $affHtml .= '<li><b>' . h($n1) . '</b> ↔ <b>' . h($n2) . '</b> <span class="score">' . (int) $af['shared'] . '</span> <span class="muted">'
            . h(str_replace(',', ', ', (string) $af['labels'])) . '</span></li>';
    }
    $affBlock = $affHtml ? '<ul class="afflist">' . $affHtml . '</ul>'
        : '<p class="muted">비평한 시인이 2명 이상이고 프리페치가 끝나면, 공유 속성(직업·사조·국적 등)으로 묶이는 친화도가 여기 나타납니다.</p>';

    $refreshBtn = '';
    if (!is_static()) {
        $refreshUrl = h(url('insights/refresh'));
        $refreshBtn = '<a class="btn primary" href="' . $refreshUrl . '" data-confirm="국가서지LOD(기본)·Wikidata(폴백) 공개 엔드포인트에 질의해 캐시를 갱신합니다. 계속할까요?">프리페치 / 갱신</a>';
    }
    $lastBits = [];
    if ($nlLast) $lastBits[] = '국가서지LOD ' . h($nlLast);
    if ($last)   $lastBits[] = 'Wikidata ' . h($last);
    $lastHtml = $lastBits ? '<span class="muted">마지막 갱신: ' . implode(' · ', $lastBits) . '</span>' : '<span class="muted">아직 갱신 안 됨</span>';

    $q74 = h(<<<'SPARQL'
SELECT ?poet (COUNT(?article) AS ?n) WHERE {
  ?article a bibo:Article ; cito:critiques ?work .
  ?work pac:hasAuthor ?poet . ?poet a pac:Poet .
} GROUP BY ?poet ORDER BY DESC(?n)
SPARQL);
    $q75 = h(<<<'SPARQL'
SELECT DISTINCT ?similar ?similarLabel ?sharedLabel WHERE {
  ?article a bibo:Article ; cito:critiques ?work .
  ?work pac:hasAuthor ?poet . ?poet owl:sameAs ?wdPoet .
  SERVICE <https://query.wikidata.org/sparql> {
    VALUES ?p { wdt:P106 wdt:P135 }              # 직업 또는 사조 공유
    ?wdPoet ?p ?shared . ?similar ?p ?shared ; wdt:P106 wd:Q49757 .
    FILTER(?similar != ?wdPoet)
    SERVICE wikibase:label { bd:serviceParam wikibase:language "ko,en".
      ?similar rdfs:label ?similarLabel . ?shared rdfs:label ?sharedLabel . }
  }
} LIMIT 50
SPARQL);
    $q77 = h(<<<'SPARQL'
SELECT ?similar ?similarLabel (COUNT(DISTINCT ?poet) AS ?score) WHERE {
  ?article a bibo:Article ; cito:critiques ?work .
  ?work pac:hasAuthor ?poet . ?poet owl:sameAs ?wdPoet .
  SERVICE <https://query.wikidata.org/sparql> {
    VALUES ?p { wdt:P106 wdt:P135 }
    ?wdPoet ?p ?shared . ?similar ?p ?shared ; wdt:P106 wd:Q49757 .
    FILTER(?similar != ?wdPoet)
    ?similar rdfs:label ?similarLabel . FILTER(LANG(?similarLabel)="ko")
  }
  FILTER NOT EXISTS { ?a2 cito:critiques/pac:hasAuthor/owl:sameAs ?similar . }  # 아직 안 본 시인만
} GROUP BY ?similar ?similarLabel ORDER BY DESC(?score) LIMIT 30
SPARQL);

    $body = <<<HTML
<section class="hero small">
  <h1>추론 질의 <small>— 국가서지LOD 기본 · Wikidata 폴백</small></h1>
  <p class="lead">owl:sameAs 로 연결된 시인의 외부 LOD 사실을 끌어와 비평 활동을 돕습니다. <b>국가서지LOD(국립중앙도서관)</b>를 기본 출처로 권위 있는 프로파일(생몰년·직업·활동분야)을 받고, 국가서지LOD에 없거나 부족한 관계 추론(비슷한 시인·사조·수상 등)은 <b>Wikidata</b>로 보강합니다. 결과는 프리페치 캐시에서 옵니다.</p>
  <div class="toolbar">{$refreshBtn} {$lastHtml}</div>
</section>

<section class="panel highlight">
  <div class="panel-head"><h2>7.7 — 다음에 읽을 시인 <small>(추천 · gap 분석)</small></h2></div>
  <p class="note">내가 비평한 시인들과 직업·사조를 공유하지만 <b>아직 비평하지 않은</b> 시인. 숫자는 내 시인 몇 명과 겹치는지(높을수록 강한 추천).</p>
  <div class="chips reclist">{$recHtml}</div>
  <details class="sparql"><summary>SPARQL (7.7)</summary><pre class="code">{$q77}</pre></details>
</section>

<section class="panel">
  <div class="panel-head"><h2>7.4 — 내가 비평한 시인과 빈도</h2></div>
  <table class="grid">
    <thead><tr><th>시인</th><th>Wikidata</th><th class="num">비평 수</th></tr></thead>
    <tbody>{$freqRows}</tbody>
  </table>
  <details class="sparql"><summary>SPARQL</summary><pre class="code">{$q74}</pre></details>
</section>

<section class="panel">
  <div class="panel-head"><h2>7.5 / 7.6 — 시인 프로파일 · 비슷한 시인</h2></div>
  <p class="note">시인별로 <b>국가서지LOD</b> 권위 프로파일(생몰년·직업·활동분야)을 먼저, 그 다음 <b>Wikidata</b> 보강(출생·국적·사조·수상·대표작)과 직업·사조를 공유하는 비슷한 시인을 보여줍니다. 국가서지LOD 캐시가 없는 시인은 Wikidata로 폴백합니다.</p>
  <div class="insights">{$detail}</div>
  <details class="sparql"><summary>SPARQL (7.5 비슷한 시인 · 7.6 프로파일)</summary><pre class="code">{$q75}</pre></details>
</section>

<section class="panel">
  <div class="panel-head"><h2>친화도 — 내 시인들끼리 공유 속성</h2></div>
  {$affBlock}
</section>
HTML;
    return ['추론 질의', $body];
}
