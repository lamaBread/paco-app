<?php
/**
 * PACO — Poem And Criticism Ontology platform
 * 전역 설정.
 *
 * 편집 원본은 SQLite(data/paco.sqlite). LOD(RDF/XML·Turtle·JSON-LD)는 이 데이터에서
 * 온톨로지(vocab/pac-ontology.owl)로 발행한다 — 외부 추론용 원본 사실만(GUI 잔재 비발행).
 */

// 이 파일은 **출하 기본값**이다. 자가 업데이트(bin/self-update.php · 업데이트 탭)가
// 코드와 함께 통째로 덮어쓴다. 사용자가 바꾸는 값(iri_data 등)은 DB(app_setting)에
// 저장되어 보존되며 설정 페이지(?r=settings)에서 편집한다 — src/Settings.php 참고.
return [
    // ---- 버전 / 저장소(자가 업데이트) ----
    'version'   => trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: 'dev',
    // 온톨로지(TBox/SHACL) 버전 — 앱 버전(VERSION)과 별개의 어휘 세트 버전이다.
    // 발행물·GUI 표시('PAC 온톨로지 vX')의 단일 소스. 어휘를 개정하면 여기 한 곳만 올린다.
    //   사용처: render.php 푸터 · pages_lod.php(설명·어휘 행) · Rdf.php Turtle 헤더 · vocab/*.
    'ont_version' => '0.6.0',
    // 원클릭 업데이트가 버전 태그를 읽고 클론하는 공개 repo.
    // PACO_REPO_URL 로 덮어쓸 수 있다(포크/미러 또는 로컬 테스트용).
    'repo'      => [
        'url' => getenv('PACO_REPO_URL') ?: 'https://github.com/lamaBread/paco-app.git',
        'web' => 'https://github.com/lamaBread/paco-app',
    ],

    // ---- 경로 (절대경로로 고정해 CWD 의존을 없앤다) ----
    // 실행 모델은 `php -S localhost:8001 -t public`. 쓰기가 일어나는 SQLite DB 는
    // data/ 에 있어 코드 업데이트(파일 덮어쓰기)에서 보존된다.
    // PACO_DB_PATH/PACO_DIST_DIR 환경변수로 외부 경로로 돌릴 수 있다(먼 미래의
    // Electron 배포(v2.0.0) 대비 — 번들 내부가 읽기전용일 때 userData 로 돌리기 위함).
    'base_dir'  => __DIR__,
    'db_path'   => getenv('PACO_DB_PATH') ?: __DIR__ . '/data/paco.sqlite',
    'vocab_dir' => __DIR__ . '/vocab',
    'dist_dir'  => getenv('PACO_DIST_DIR') ?: __DIR__ . '/dist',

    // ---- LOD IRI 전략: 통합 덤프 + 설정형 base IRI ----
    // TBox(온톨로지 용어). 버전은 ont_version(위) 참조 — IRI 자체는 버전 비의존.
    'iri_tbox'  => 'http://example.org/pac#',
    // ABox(인스턴스) 기본값. 운영 도메인은 설정 페이지(app_setting.iri_data)에서 바꾼다.
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
        // 국가서지LOD(국립중앙도서관) 자원 + ISNI — owl:sameAs 외부 링크가 축약되도록.
        'nlk'  => 'http://lod.nl.go.kr/resource/',
        'isni' => 'http://www.isni.org/isni/',
        'owl'  => 'http://www.w3.org/2002/07/owl#',
        'rdf'  => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
        'xsd'  => 'http://www.w3.org/2001/XMLSchema#',
    ],

    // ---- 추론 출처: 국가서지LOD(기본) → Wikidata(폴백) ----
    // 시인 프로파일은 국립중앙도서관 국가서지LOD 를 우선으로 끌어오고, 국가서지LOD 에 없거나
    // 정보가 부족한 부분(비슷한 시인·사조·수상·거주지 등)은 Wikidata 로 보강한다.

    // 국가서지LOD (국립중앙도서관, lod.nl.go.kr) — '기본' 출처.
    'nllod' => [
        // SPARQL 엔드포인트(이름 검색용). 결과 포맷은 Accept 헤더로만 제어된다.
        'endpoint'   => 'https://lod.nl.go.kr/sparql',
        // 자원 전체 RDF 취득용 — /data/<KAC…id>?output=rdfxml (바운드 주어 SPARQL 은 엔진 버그로
        // 실패하므로, 선택한 저자의 전체 프로파일은 이 RDF/XML 로 받아 파싱한다).
        'data_base'  => 'https://lod.nl.go.kr/data/',
        'user_agent' => 'PACO/0.3 (poem-and-criticism archive; educational)',
        'timeout'    => 25,
    ],

    // ---- Wikidata 프리페치 (폴백/보강 출처) ----
    'wikidata' => [
        'endpoint'   => 'https://query.wikidata.org/sparql',
        'user_agent' => 'PACO/0.1 (poem-and-criticism archive; educational)',
        'lang'       => 'ko',
        'timeout'    => 25,
    ],

    'app_name' => 'PACO',
    'app_desc' => 'Poem And Criticism Ontology — 비평문 기입·관리 및 LOD 발행 시스템',
];
