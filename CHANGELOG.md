# 변경 이력 (Changelog)

PACO 앱의 버전별 변경과 **데이터 마이그레이션 주의사항**을 기록한다.
규칙: [유의적 버전(SemVer)](https://semver.org/lang/ko/) — `MAJOR.MINOR.PATCH`.

> ⚠️ **버전업 시 필수**: 스키마(`src/Database.php`)를 바꿨다면, 반드시 아래 항목에
> *"기존 사용자 DB에 대한 마이그레이션"*을 명시하고 `paco release` 의 헤드리스
> 호환성 테스트(이전 버전 fixture 대비)를 통과시킨 뒤 push 한다.
> 자세한 절차는 `../paco-harness/HARNESS.md` 참고.

## [Unreleased]
- (작업 중)

## [0.4.1] — 2026-06-08
> v0.4.0 인용 편집기에서 본문 표지(`<q>`)가 칠해지기만 하고 클릭에 반응하지 않던 문제를 해소하고,
> 연/행 범위로 시 원문을 자동으로 찾아 띄운다. 스키마·온톨로지(TBox) 무변경 — 기존 컬럼
> (`quotation_target.exact`·`start_line`·`end_line`)만 활용.

### Added
- **인용 편집기 미리보기의 `<q>` 표지 클릭 → 앵커 자동 지정.** 우측 본문 미리보기에서 인용 표지
  (`<q xml:id="N">`)를 누르면 그 `N` 이 *본문 앵커(xml:id)* 입력칸에 채워지고 해당 표지가 강조된다.
  앵커를 직접 입력·자동완성해도 대응하는 표지가 실시간으로 강조된다(양방향). 그동안 분할뷰에서만
  살아 있던 표지 상호작용을 편집기에서도 쓸 수 있게 했다. (`public/assets/app.js` `initQuotationEditor`)
- **연/행 범위 → 시 원문 자동 추출·표시.** 대상(`oa:hasTarget`) 행에 시작/끝 연·행을 입력하면 그 시
  (`poem_line`)에서 해당 범위의 행 텍스트를 찾아 **라이브 미리보기**로 띄우고, *원문(`oa:exact`)* 칸이
  비어 있을 때 자동으로 채운다(사용자 수동 수정은 보존). 범위 해석은 분할뷰 연결선과 동일하다(단일 연
  +행 지정 → 그 행들, 그 외 → 연 전체). 시 연/행 텍스트는 편집기 페이지에 JSON 으로 인라인 공급한다
  (`<script id="paco-article">` 의 `poems`) — 시 본문은 여전히 LOD 비발행(읽기 전용 편의이며 새 라우트
  없음). 시집(`book`)을 출처로 고르면 행 좌표가 없어 자동추출을 건너뛴다. (`src/pages_article.php` ·
  `public/assets/app.js` `initTargetExact`)

### Changed
- 인용 대상의 *원문(`oa:exact`)* 입력을 한 줄 `<input>` → 여러 줄 `<textarea>` 로 바꿔, 여러 행 인용 시
  시행(詩行) 줄바꿈을 그대로 보존한다. (`src/pages_article.php` `target_row_html` · `public/assets/app.css`)

### Fixed
- **분할뷰의 표지↔연/행 하이라이트·연결선이 그려지지 않던 버그.** 인용 데이터(`#paco-quotations`)를
  `<script type="application/json">` 에 `htmlspecialchars(ENT_QUOTES)` 로 넣었는데, `<script>` 는
  raw-text 라 브라우저가 `&quot;` 를 디코드하지 않아 `JSON.parse` 가 즉시 throw → 그 뒤의 분할뷰
  인터랙션 JS 가 통째로 죽고 있었다(실제 Chrome 에서 재현·확인). `json_for_script()`
  (`JSON_HEX_TAG|JSON_HEX_AMP` 로 `</script>` 만 차단, `htmlspecialchars` 제거)로 바꿔 유효 JSON 이
  그대로 파싱되게 했다. 같은 패턴이던 인용 편집기 데이터(`#paco-article`)도 함께 정상화 →
  위 Feature A/B 가 동작하는 전제가 된다. (`src/helpers.php` `json_for_script` · `src/pages_article.php`)

### 데이터 마이그레이션
- **없음.** `SCHEMA_VERSION` 무변경(=4). `quotation_target` 의 `start_line`·`end_line`·`exact` 가 이미
  존재해 추가할 컬럼·단계가 없다. 기존 인용의 `exact`(예: 공백으로 이어진 시드 값)는 그대로 보존되며,
  자동채움은 빈 칸에만 적용된다. 검증: 시드 DB 의 q1~q4(1~4연·5연1행·2연·1연) 범위가 본문에서 정확히
  추출됨을 확인. 전 라우트 HTTP 200(릴리스 게이트 `paco test-migration`)도 그대로 통과한다.

## [0.4.0] — 2026-06-08
> v0.3.0 사용 중 기록된 문제 3건을 해결한다. TBox(온톨로지 용어) 무변경 — 모두 기존 어휘로 표현.

### Added
- **비평자(나) 설정 — LOD 등록 없이도 ‘나’를 입력.** 설정 페이지(`?r=settings`)에 *비평자 — 나*
  패널을 추가. 시를 읽고 비평하는 개인은 국가서지LOD 에 없을 수 있으므로, **이름만으로**(외부 LOD
  연결은 선택) `pac:Critic` 인 ‘나’를 만든다. 안정 id(`person_me`)로 upsert 하고 `me_person_id`
  설정에 기록하여, **새 비평문의 비평자 기본값**이 된다. (`src/pages_admin.php`, 라우트 `me/save`)
- **시집 ISBN ↔ 국가서지LOD 연동.** 시집 추가/수정에서 **제목으로 국가서지LOD 시집(`nlon:Book`)
  검색 → 후보 선택**(발행처·연도로 판 구분)하면 **ISBN-13 이 자동 입력**되고, 그 시집의 국가서지LOD
  자원 URI(`book.nl_uri`)가 연결된다. 저자 전거(`dcterms:creator` = KAC…)가 이미 인물에 `nl_uri`
  로 연결돼 있으면 **저자도 자동 선택**된다. 시인 이름 검색과 같은 전략(변수 주어 SPARQL → 자원
  RDF/XML 파싱, `~` 접미사 보정). (`src/NlLod.php` `searchBooks`/`fetchBookProfile`)
- **시 마크업 XML — 시 본문의 정식 소스.** 연/행을 기술하는 최소 XML(`<poem><stanza><line>…`)을
  도입(`vocab/poem-xml.md`). 시 편집기는 이 XML 을 정식 소스로 보여주고 저장하며(평문 입력도 받아
  자동 변환), `poem_line`(좌측 표시·연/행 선택·LOD 선택자 좌표)은 이 XML 에서 파생된다. 본문은
  여전히 LOD 비발행(온톨로지 비훼손) — XML 의 연/행 좌표가 `pac:TextSelection` 과 일치한다.

### Changed
- **비평문 본문에 `<p>`/`<br>` 를 직접 쓰지 않는다.** 본문은 평문으로 쓰고, **저장 시** 표준 산문
  규칙으로 발행용 HTML(`pac:fullText` · `rdf:HTML`)을 만든다: **빈 줄(엔터 2번)=새 문단 `<p>`,
  한 줄 엔터=문단 내 줄바꿈 `<br>`**. 인용 표지 `<q xml:id>` 는 보존된다. 편집기는 저장된 HTML 을
  다시 평문으로 역변환해 보여줘(왕복 idempotent) `<p>` 를 직접 다루지 않게 한다.
  (`src/pages_article.php` `source_to_fulltext`/`fulltext_to_source`)
- 시집 목록/상세에 국가서지LOD 식별자 칩(NL)을 표시. LOD 발행 시 시집의 `owl:sameAs`(국가서지LOD
  자원)도 함께 나간다(인물의 다출처 `owl:sameAs` 와 평행).

### 데이터 마이그레이션
- **user_version 3 → 4** (모두 *추가형* — 기존 행 무변형): ① `book` 에 `nl_uri` 컬럼 추가
  (`ALTER TABLE ADD COLUMN`, 컬럼 존재 가드로 멱등) ② `poem` 에 `body_xml` 컬럼 추가. 변형 단계가
  없어 행수는 그대로 보존되며, 변형 전 자동 백업(`data/backups/`) 정책도 동일.
- 구버전 시(`body_xml` 없음)는 편집·표시 시 `poem_line` 에서 XML 을 즉석 도출하므로 그대로 동작한다
  (저장하면 그때 `body_xml` 이 채워진다). 비평문 본문도 기존 HTML 그대로 읽히고, 편집 후 저장할 때
  새 규칙으로 정규화된다.
- 검증: v0.1.0·v0.2.0·v0.3.0 fixture 에서 `paco test-migration` 통과(행수 보존 · 전 라우트 HTTP
  200 · 치명 오류 없음). 네트워크 질의(국가서지LOD/Wikidata)는 사용자가 *검색/프리페치* 를 누를
  때만 일어나므로 일반 페이지 로드·호환성 테스트는 오프라인에서도 안전하다.

## [0.3.0] — 2026-06-08
### Added
- **국가서지LOD(국립중앙도서관, lod.nl.go.kr) 연동 — 시인 식별·추론의 '기본' 출처.** 인물에
  국가서지LOD 자원 URI(`person.nl_uri`)와 **ISNI**(`person.isni`) 를 연결한다. 인물 추가/수정
  화면에서 **이름으로 국가서지LOD 저자 검색→후보 선택** 시 자원 URI·ISNI·생몰년이 자동 입력되고,
  국가서지LOD 의 `owl:sameAs` 에 Wikidata 링크가 있으면 **Wikidata 도 자동 연결**된다. 동명이인이
  많아 후보는 직업(`schema:jobTitle`)으로 '시인'을 표시한다. (`src/NlLod.php`)
  - SPARQL 엔드포인트(`https://lod.nl.go.kr/sparql`)로 이름 검색, 선택한 저자의 전체 프로파일은
    `/data/<id>?output=rdfxml`(RDF/XML)로 받아 파싱(엔진 특성상 바운드 주어 SPARQL·birthYear 혼합
    질의가 실패하므로). JSON 리터럴의 `~` 접미사 제거 등 엔진 보정 포함.
- **추론 폴백(국가서지LOD 기본 → Wikidata 폴백/보강).** 시인 프로파일(생몰년·직업·활동분야)은
  국가서지LOD 를 우선 표시하고, 국가서지LOD 에 없거나 부족한 관계 추론(비슷한 시인·사조·수상·
  거주지 등)은 Wikidata 로 보강한다. *추론 질의*·*인물 상세*에 출처 배지를 표시. 프리페치/갱신은
  두 출처를 한 번에 수행(`nl_fact` 캐시 + 기존 `wikidata_fact`/`wikidata_similar`).
- **LOD 발행 확장.** 인물의 외부 동일인 링크를 모두 `owl:sameAs` 로 발행:
  Wikidata(`nl_uri` 와 함께) · 국가서지LOD(`nlk:`) · ISNI(`isni:` = `http://www.isni.org/isni/<코드>`).
  접두사 `nlk`·`isni` 추가로 Turtle/JSON-LD 가 축약된다. (온톨로지 TBox 무변경 — `owl:sameAs` 만 사용)

### Changed
- 인물 목록/상세의 'Wikidata' 칸을 **식별자 칩**(WD·NL·ISNI)으로 일반화. *추론 질의* 페이지를
  '국가서지LOD 기본 · Wikidata 폴백' 구조로 개편.

### 데이터 마이그레이션
- **user_version 2 → 3** (모두 *추가형* — 기존 행 무변형): ① `person` 에 `nl_uri`·`isni` 컬럼 추가
  (`ALTER TABLE ADD COLUMN`, 컬럼 존재 가드로 멱등) ② 국가서지LOD 프리페치 캐시 `nl_fact` 테이블 +
  인덱스 추가. 변형 단계가 없어 행수는 그대로 보존되며, 변형 전 자동 백업(`data/backups/`) 정책도 동일.
- 검증: v0.1.0·v0.2.0 fixture 에서 `paco test-migration` 통과(행수 보존 · 전 라우트 HTTP 200 ·
  치명 오류 없음). 네트워크 질의(국가서지LOD/Wikidata)는 사용자가 *프리페치/검색* 을 누를 때만
  일어나므로 일반 페이지 로드·호환성 테스트는 오프라인에서도 안전하다.

## [0.2.0] — 2026-06-08
### Added
- **자가 업데이트(원클릭) 토대** — GitHub 공개 repo(`lamaBread/paco-app`)의 **버전 태그** 기준으로
  최신 버전을 임시 폴더에 `git clone` → 코드 덮어쓰기로 갱신. 안전장치: 클론 검증 · 코드+DB
  백업(`data/backups/`) · `data/`·`.git` 보존 · 새 코드로 별도 프로세스 마이그레이션 · 실패 시
  자동 롤백 · 개발 모드(`PACO_DEV`)/dirty 작업트리 거부. 진입점: 인앱 **업데이트** 탭(`?r=update`)
  + CLI `php bin/self-update.php [--check] [버전]`. (`src/Updater.php`, `bin/self-update.php`)
- **스키마 마이그레이션 프레임워크** — `PRAGMA user_version` 기반 단계 적용기를 `src/Database.php`
  에 도입(`SCHEMA_VERSION` + `migrations()` 레지스트리). 온톨로지/스키마가 바뀌면 단계만 추가하면
  기존 사용자 DB가 자동 마이그레이션된다. 데이터 변형 전 자동 백업. CLI `php bin/migrate.php`.
- **DB 기반 사용자 설정 + 설정 페이지(`?r=settings`)** — `iri_data`(LOD 발행 도메인) 등 사용자
  값을 `config.php`(출하 기본값)에서 분리해 **`app_setting` 테이블**로 저장. 코드 업데이트가
  `config.php` 를 덮어써도 설정은 DB(=`data/`)에 있어 보존된다. (`src/Settings.php`)
- 푸터에 앱 버전 표시, 내비에 **설정·업데이트** 탭(편집 앱 전용; 정적 아카이브 제외).

### Changed
- **실행 모델을 `php -S localhost:8001 -t public` 로 표준화.** (Electron 데스크톱 배포는 장기
  로드맵으로 보류 — 아래 *로드맵* 참고. `PACO_DB_PATH`/`PACO_DIST_DIR` 오버라이드는 그대로 유지.)

### 데이터 마이그레이션
- **user_version 0(=v0.1.x DB) → 2**: ① 기반 스키마 재확인(IF NOT EXISTS, 무손상)
  ② `app_setting` 테이블 추가(추가형, 데이터 변형 없음). 기존 행(person/article/quotation 등)은
  그대로 보존된다. 첫 업그레이드 시 `data/backups/paco-pre-v0-*.sqlite` 백업 1개 생성.
- 검증: v0.1.0 fixture(110 트리플 샘플)에서 행수 보존 · `paco test-migration` 통과.

### 로드맵
- **Electron 데스크톱 배포는 v2.0.0 으로 보류**(설계 사상 레벨 변경 → MAJOR). 그때까지 시스템은
  *Electron 으로 패키징 가능한 상태*로 유지한다(`config.php` 의 `PACO_DB_PATH`/`PACO_DIST_DIR`
  오버라이드, 하네스 `paco-harness/wrapper/` 보존). 현재 배포·실행·업데이트는 모두 PHP 기반.

## [0.1.0] — 2026-06-07
### Added
- PACO 최초 버전. 시(詩) 비평문 기입·관리 + LOD(RDF/XML·Turtle·JSON-LD·N-Triples) 발행.
- PAC v0.4 온톨로지를 관계형으로 사상한 SQLite 스키마
  (person · book · poem · poem_line · article · quotation · quotation_target · wikidata 캐시).
- 샘플 시드: 황인찬 『구관조 씻기기』 「순례」 / 2024-09-03 비평 + 인용 q1~q4.

### 데이터 마이그레이션
- 최초 버전 — 마이그레이션 대상 없음. 이 버전의 시드 DB가 이후 버전 호환성
  테스트의 **기준 fixture**(`paco-harness/fixtures/0.1.0/`)가 된다.

[Unreleased]: https://github.com/lamaBread/paco-app/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/lamaBread/paco-app/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/lamaBread/paco-app/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lamaBread/paco-app/releases/tag/v0.1.0
