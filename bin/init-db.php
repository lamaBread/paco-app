<?php
/**
 * DB 초기화 + v0.4 샘플 시드(황인찬 『구관조 씻기기』 「순례」 / 2024-09-03 비평).
 *   실행: php bin/init-db.php          (없으면 생성, 있으면 갱신=업서트)
 *         php bin/init-db.php --fresh  (기존 DB 삭제 후 재생성)
 *
 * 주의: 「순례」의 연/행 구획은 좌측 표시·연행 선택 시연을 위한 근사 편집이며,
 *       인용 대상 지정(q1=1~4연, q2=5연1행, q3=2연, q4=1연+5연)과 정합하도록
 *       나눈 것이다. 실제 지면 형태와 다를 수 있으니 편집기에서 교정 가능.
 */

declare(strict_types=1);
namespace PACO;

$cfg = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repo.php';

if (in_array('--fresh', $argv, true) && is_file($cfg['db_path'])) {
    foreach ([$cfg['db_path'], $cfg['db_path'] . '-wal', $cfg['db_path'] . '-shm'] as $f) {
        if (is_file($f)) unlink($f);
    }
    echo "기존 DB 삭제.\n";
}

$pdo  = Database::connect($cfg['db_path']);
$repo = new Repo($pdo);

// ---- 인물 ----
$repo->savePerson(['id' => 'person_hwang-inchan', 'name' => '황인찬',
    'is_poet' => true, 'is_critic' => false,
    'same_as' => 'http://www.wikidata.org/entity/Q12625888']);
$repo->savePerson(['id' => 'person_me', 'name' => '나',
    'is_poet' => false, 'is_critic' => true, 'same_as' => '']);

// ---- 시집 ----
$repo->saveBook(['id' => 'book_gugwanjo', 'title' => '구관조 씻기기',
    'author_id' => 'person_hwang-inchan', 'isbn13' => '9788937408090']);

// ---- 시 + 본문(연/행) ----
$poemBody = <<<'POEM'
그는 내가 눈이 맑다고 했다
그는 내가 보호받고 있다고 말했다
저녁 다섯 시, 사람들이 가득하다

그는 내 말을 듣기를 원했다
그는 내가 걱정된다고 말했다
그는 내가 행복해지기를, 그가 내 위안이 되길 원했다

"어디 가서 차라도 한잔할래요?" 그가 한 말이었다
그는 내게 좋은 곳에 가자고 했다
그는 내가 거기서 더욱 나아질 것이라 믿었다

나는 좋은 곳을 믿는다
나는 아무 말도 하지 않는다

저녁 다섯 시, 나는 돌아온다
POEM;
$repo->savePoem(['id' => 'poem_sunrye', 'title' => '순례',
    'author_id' => 'person_hwang-inchan', 'book_id' => 'book_gugwanjo'], $poemBody);

// ---- 비평문 본문(rdf:HTML, <q xml:id> 포함) ----
$fullText = <<<'HTML'
<p>마지막 문장의 의미가 무엇일까? 나름의 결정을 내리는데 상당한 시간이 걸렸다. 저녁 5시에 사이비 종교 혹은 교회의 집부(?)가 종교공동체로 시적 화자를 끌어들이려는 상황. (혹은 다단계일지도 모른다.) <q xml:id="1">4연까지</q>는 단순한 상황을 있는 그대로 담담히 서술하며 어려움이 없으나, <q xml:id="2">5연의 문장</q>은 다르다. 분명 5시에 포교를 듣지 않았나? 그런데 4연과 5연의 사이에 시간이 흘러야만 '돌아올 수' 있지 않은가? 그러므로 나는 시적 화자가 포교자의 불순한 의도에도 불구하고, 그 의도를 이해했음에도 불구하고 모종의 이유로 공동체에 가입했다고 생각한다. 그렇다면 왜 포교에 응했는가? 외로움이나 슬픔 때문이라고 생각한다. 불확실한 미래에 대한 불안, 무언가를 믿어야만 더 나아갈 수 있는 절망적 상황에 처한 시의 화자가 포교에 응하고, 자신을 꼬셨던 사람처럼 누군가를 꼬시러 나온 것이 아닐까? 특히 2연의 서술이 내가 생각한 바와 방향이 비슷하다고 보인다. <q xml:id="3">"그가 내 위안이 되길 원했다"</q> 이 부분은 … <q xml:id="4">1연과 5연에서는</q> 화자의 태도가 분명히 갈린다.</p>
HTML;
$repo->saveArticle(['id' => 'article_sunrye_20240903',
    'title' => '「순례」의 마지막 문장에 대하여', 'author_id' => 'person_me',
    'created' => '2024-09-03', 'critiques_kind' => 'poem', 'critiques_id' => 'poem_sunrye',
    'full_text' => $fullText]);

// ---- 인용 q1~q4 ----
$bigBlock = '그는 내가 눈이 맑다고 했다 그는 내가 보호받고 있다고 말했다 저녁 다섯 시, 사람들이 가득하다 '
    . '그는 내 말을 듣기를 원했다 그는 내가 걱정된다고 말했다 그는 내가 행복해지기를, 그가 내 위안이 되길 원했다 '
    . '"어디 가서 차라도 한잔할래요?" 그가 한 말이었다 그는 내게 좋은 곳에 가자고 했다 그는 내가 거기서 더욱 나아질 것이라 믿었다 '
    . '나는 좋은 곳을 믿는다 나는 아무 말도 하지 않는다';

$repo->saveQuotation(['id' => 'q1', 'article_id' => 'article_sunrye_20240903',
    'qtype' => 'indirect', 'anchor' => '1',
    'targets' => [[
        'source_kind' => 'poem', 'source_id' => 'poem_sunrye',
        'start_stanza' => 1, 'end_stanza' => 4, 'start_line' => '', 'end_line' => '',
        'exact' => $bigBlock, 'target_order' => '',
    ]],
]);

$repo->saveQuotation(['id' => 'q2', 'article_id' => 'article_sunrye_20240903',
    'qtype' => 'indirect', 'anchor' => '2',
    'targets' => [[
        'source_kind' => 'poem', 'source_id' => 'poem_sunrye',
        'start_stanza' => 5, 'end_stanza' => 5, 'start_line' => 1, 'end_line' => 1,
        'exact' => '저녁 다섯 시, 나는 돌아온다', 'target_order' => '',
    ]],
]);

$repo->saveQuotation(['id' => 'q3', 'article_id' => 'article_sunrye_20240903',
    'qtype' => 'direct', 'anchor' => '3',
    'targets' => [[
        'source_kind' => 'poem', 'source_id' => 'poem_sunrye',
        'start_stanza' => 2, 'end_stanza' => 2, 'start_line' => '', 'end_line' => '',
        'exact' => '그가 내 위안이 되길 원했다', 'target_order' => '',
    ]],
]);

// q4 — 비연속 인용(1연 + 5연)
$repo->saveQuotation(['id' => 'q4', 'article_id' => 'article_sunrye_20240903',
    'qtype' => 'indirect', 'anchor' => '4',
    'targets' => [
        ['source_kind' => 'poem', 'source_id' => 'poem_sunrye',
         'start_stanza' => 1, 'end_stanza' => 1, 'start_line' => '', 'end_line' => '', 'exact' => '', 'target_order' => ''],
        ['source_kind' => 'poem', 'source_id' => 'poem_sunrye',
         'start_stanza' => 5, 'end_stanza' => 5, 'start_line' => '', 'end_line' => '',
         'exact' => '저녁 다섯 시, 나는 돌아온다', 'target_order' => ''],
    ],
]);

$c = $repo->counts();
echo "시드 완료 — 시인 {$c['poet']} · 비평자 {$c['critic']} · 시집 {$c['book']} · 시 {$c['poem']} · 비평문 {$c['article']} · 인용 {$c['quotation']}\n";
echo "DB: {$cfg['db_path']}\n";
