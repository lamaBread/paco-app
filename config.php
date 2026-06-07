<?php
/**
 * PACO — Poem And Criticism Ontology platform
 * 전역 설정.
 *
 * 편집 원본은 SQLite(data/paco.sqlite). LOD(RDF/XML·Turtle·JSON-LD)는
 * 이 데이터로부터 온톨로지(vocab/pac-ontology.owl)에 충실하게 생성된다.
 */

return [
    // ---- 경로 (절대경로로 고정해 CWD 의존을 없앤다) ----
    // Electron 패키징: 앱 번들 내부(Resources)는 읽기 전용이므로, 쓰기가 일어나는
    // SQLite DB 경로만 PACO_DB_PATH 환경변수로 외부(userData)로 돌릴 수 있게 한다.
    // 환경변수가 없으면 기존과 동일하게 동작(개발/CLI 호환).
    'base_dir'  => __DIR__,
    'db_path'   => getenv('PACO_DB_PATH') ?: __DIR__ . '/data/paco.sqlite',
    'vocab_dir' => __DIR__ . '/vocab',
    'dist_dir'  => getenv('PACO_DIST_DIR') ?: __DIR__ . '/dist',

    // ---- LOD IRI 전략: 통합 덤프 + 설정형 base IRI ----
    // TBox(온톨로지 용어). v0.4 명세와 동일.
    'iri_tbox'  => 'http://example.org/pac#',
    // ABox(인스턴스). 배포 도메인으로 교체하면 발행되는 LOD가 그 도메인으로 나간다.
    //   예: 'https://my-archive.example/paco/data/'
    'iri_data'  => 'http://example.org/pac/data/',

    // ---- 표준/외부 어휘 접두사 ----
    'prefixes'  => [
        'pac'  => 'http://example.org/pac#',
        // 'pacd' 는 iri_data 로 런타임 주입된다.
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'bibo' => 'http://purl.org/ontology/bibo/',
        'dct'  => 'http://purl.org/dc/terms/',
        'cito' => 'http://purl.org/spar/cito/',
        'oa'   => 'http://www.w3.org/ns/oa#',
        'wd'   => 'http://www.wikidata.org/entity/',
        'wdt'  => 'http://www.wikidata.org/prop/direct/',
        'owl'  => 'http://www.w3.org/2002/07/owl#',
        'rdf'  => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'xsd'  => 'http://www.w3.org/2001/XMLSchema#',
    ],

    // ---- Wikidata 프리페치 ----
    'wikidata' => [
        'endpoint'   => 'https://query.wikidata.org/sparql',
        'user_agent' => 'PACO/0.1 (poem-and-criticism archive; educational)',
        'lang'       => 'ko',
        'timeout'    => 25,
    ],

    'app_name' => 'PACO',
    'app_desc' => 'Poem And Criticism Ontology — 비평문 기입·관리 및 LOD 발행 시스템',
];
