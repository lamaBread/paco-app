# 변경 이력 (Changelog)

PACO 앱의 버전별 변경과 **데이터 마이그레이션 주의사항**을 기록한다.
규칙: [유의적 버전(SemVer)](https://semver.org/lang/ko/) — `MAJOR.MINOR.PATCH`.

> ⚠️ **버전업 시 필수**: 스키마(`src/Database.php`)를 바꿨다면, 반드시 아래 항목에
> *"기존 사용자 DB에 대한 마이그레이션"*을 명시하고 `paco release` 의 헤드리스
> 호환성 테스트(이전 버전 fixture 대비)를 통과시킨 뒤 push 한다.
> 자세한 절차는 `../paco-harness/HARNESS.md` 참고.

## [Unreleased]
- (없음)

## [0.7.2] — 2026-06-22
> **프리페치 중 개발서버가 세그폴트로 죽던 문제 수정.** 국가서지LOD 갱신을 별도
> 백그라운드 프로세스로 분리해, 장시간 네트워크 작업이 `php -S` 서버를 더는 막지 않는다.

### Fixed
- **`insights/refresh` 프리페치 → `php -S` 세그폴트.** `refreshAll()`+`fetchCandidates()` 는
  공개 LOD 엔드포인트에 수십 번 네트워크 호출을 하는 장시간 작업인데, 이를 빌트인 개발서버(단일
  스레드·블로킹) 요청 안에서 동기로 돌렸다. 서버 스레드가 수 분간 묶이는 동안 브라우저가 그 요청을
  중단/재시도하면, 끊긴 클라이언트 연결이 서버의 이벤트 루프(`php_cli_server_do_event_for_each_fd_callback`)를
  망가뜨려 `SIGSEGV`(KERN_INVALID_ADDRESS)로 죽었다 — 네이티브 크래시라 `try/catch`·`ignore_user_abort`
  로 막을 수 없다. **장시간 작업을 요청 밖으로 분리**해 해결: 인앱 버튼은 이제 별도 CLI 프로세스
  (`bin/prefetch.php`)를 백그라운드(detached)로 띄우고 즉시 반환한다(`src/Prefetch.php`).

### Added
- **백그라운드 프리페치 + 진행 상태 표시.** 인사이트 페이지가 갱신 상태(진행 중/완료/실패)를
  배너로 비추고, 진행 중에는 3초마다 폴링해 결과를 자동 표시한다. 상태는 `data/prefetch-status.json`
  으로 주고받는다(스키마 변경 없음). 좀비 lock 은 프로세스 생존(pid) 확인으로 자동 해제한다.
- **`bin/prefetch.php`** — 프리페치를 터미널에서 직접 실행하는 CLI(인앱 버튼과 동일 엔진).

### Changed
- **`PRAGMA busy_timeout = 5000`** 추가(`src/Database.php`) — 백그라운드 프리페치가 `nl_fact`/
  `nl_candidate` 에 쓰는 동안 웹 요청이 같은 DB 를 만질 때 'database is locked' 를 피한다(WAL 모드
  보강).

### 데이터 마이그레이션
- 없음(`SCHEMA_VERSION`=5 그대로). 코드·표시 로직 변경만. `data/prefetch-status.json`·
  `data/prefetch.log` 는 런타임 산출물(`data/` 는 gitignore — 업데이트·백업에 영향 없음).

## [0.7.1] — 2026-06-22
> v0.7.0 의 **추론 질의 M4(편식 지도) 치명 버그 수정.** 프리페치로 국가서지LOD 생몰년이
> 채워지면 추론 질의 페이지 전체가 죽던 것을 고친다.

### Fixed
- **M4 편식 지도 `max()` ArgumentCountError.** `max(1, ...array_map('count', $bm['decades']))` 가
  문자열 키('1970년대' 등)를 가진 연관배열을 스프레드해, PHP 8.1+ 에서 *named parameters* 로 해석되며
  `max() does not accept unknown named parameters` 치명 오류가 났다. 출생 세대가 1개라도 잡히면
  (= 프리페치로 생몰년이 들어오면) 추론 질의 페이지 전체가 `Fatal error` 로 죽었다. `array_values()`
  로 감싸 위치 인자로 넘기도록 수정(`src/pages_lod.php`). v0.7.0 의 fixture·게이트는 `nl_fact` 가
  비어 이 분기를 타지 않아 미검출 — 회귀 방지로 생몰년이 있는 시나리오도 확인했다.

### 데이터 마이그레이션
- 없음(`SCHEMA_VERSION`=5 그대로). 표시 로직 한 줄 수정.

## [0.7.0] — 2026-06-22
> **추론 질의 페이지를 '비평가의 거울과 나침반'으로 전면 재설계.** 외부 전기 사실을 긁어와
> 나열하던 옛 질의(7.4 빈도 · 7.5/7.6 비슷한 시인 · 7.7 추천 · 친화도)는 비평 대상 시인 대다수가
> Wikidata 링크가 없어 비거나(친화도·추천), 직업 '작가'만 공유하는 무의미한 세계 문인 목록을
> 내놓았다(비슷한 시인). 이를 폐기하고, **비평가 자신이 생산한 발행 그래프**(비평문·인용·연/행)를
> 거울로 비추는 4개 질의(M1~M4)와, 국가서지LOD 로 다음 행동을 가리키는 2개 질의(C1~C2)로
> 교체한다. 거울은 네트워크 없이 로컬 그래프만으로 동작한다. **C2 후보 풀 캐시(`nl_candidate`)**
> 추가로 `SCHEMA_VERSION` 4 → 5.

### Added
- **거울(Mirror) — 로컬 발행 그래프만으로:**
  - **M1 인용 방식 거울** — 직접/간접 인용 비율(전체·비평문별). `pac:quotationType`.
  - **M2 텍스트 밀착도** — 비평문별 인용 선택 수·다룬 연 범위·시 전체 대비 커버리지. 선택 1개뿐인
    표면적 비평을 옅게 강조. `oa:hasTarget`/`oa:hasSelector`/`pac:TextSelection`.
  - **M3 인용 위치 편향** — 인용 시작 연을 시의 처음/중간/마지막으로 정규화한 분포(내부 `poem_line`
    의 전체 연 수 사용 — LOD 비발행 좌표지만 내부 계산엔 사용).
  - **M4 편식 지도** — 비평한 시인을 국가서지LOD 출생 세대·활동분야로 묶은 분포(`nl_fact` 집계).
- **나침반(Compass):**
  - **C1 비평 공백** — 등록만 하고 아직 비평 안 한 내 시·시집(로컬 안티조인 `FILTER NOT EXISTS`).
  - **C2 근거 있는 다음 시인** — 같은 활동분야에서 내가 비운 출생 세대를 채울 시인을, **이미 비평/등록한
    시인은 제외**하고 사유와 함께 추천(`gap` 배지 = 내가 비운 세대).
- **`src/Insights.php`** — 위 6개 질의의 읽기/집계 전담(네트워크 없음). 데이터가 없으면 빈 값을
  돌려 화면이 비어도 깨지지 않는다.
- **`NlLod::searchPoetsByField()` · `NlLod::fetchCandidates()`** — C2 후보 풀 프리페치. 비평한 시인의
  활동분야로 국가서지LOD 저자(시인)를 검색(변수 주어 정확일치 → 부분일치 폴백), 출생년은 비용
  제한(상위 40명)으로 프로파일에서 보강해 `nl_candidate` 에 캐시.
- **CSS** — `.grouphead`/`.statline`/`.distbox`/`.distrow`/`.ibar`/`.cols2` 등 거울/나침반 표시용
  (`public/assets/app.css`).

### Changed
- **`page_insights()` 전면 재작성**(`src/pages_lod.php`) — 히어로 카피·상단 요약(옛 7.4 대체)·거울 4
  섹션·나침반 2 섹션, 각 섹션에 형식 정의용 SPARQL(발행 그래프 기준) 동봉.
- **`insights/refresh`** — 국가서지LOD 프로파일 갱신 뒤 **C2 후보 풀(`fetchCandidates`)을 함께
  프리페치**하도록 변경. 갱신 메시지를 후보 수 중심으로 정정(`public/index.php`).

### Removed (화면에서만 — RDB·발행 LOD 는 무변경)
- 옛 추론 질의 **7.4 빈도표 · 7.5/7.6 시인 프로파일·비슷한 시인 · 7.7 추천 · 친화도** 섹션 제거.
- Wikidata 기반 **비슷한 시인 프리페치 중단** — `insights/refresh` 가 더는 `Wikidata::refreshAll()`
  을 호출하지 않는다(직업 매칭이 무의미했음). `Wikidata` 클래스와 `wikidata_fact`/`wikidata_similar`
  테이블·데이터는 **보존**(NL→Wikidata 자동연결의 `Wikidata::qid()` 는 계속 사용). 화면에서만 미노출.

### 유지 (비발행/불변식)
- **발행 LOD·온톨로지·SHACL 무변경.** 새 기능은 모두 *질의/표시* 계층이라 ABox/TBox 발행은 그대로다
  (`ont_version` 0.6.0 유지). 시 본문·내부 앵커 비발행 불변식도 그대로.

### 데이터 마이그레이션
- **`SCHEMA_VERSION` 4 → 5: `nl_candidate` 테이블 추가**(C2 후보 풀 캐시). 순수 *추가형* — 새 테이블
  생성만 하고 기존 행은 건드리지 않는다(멱등, `CREATE TABLE IF NOT EXISTS`). 비어 있어도 C2 가 빈
  채로만 나오고 다른 기능에 영향 없음. 채우려면 추론 질의 페이지의 *프리페치 / 갱신* 실행.
- `wikidata_fact`/`wikidata_similar` 는 **삭제하지 않는다**(과거 캐시 보존, 무손실).

## [0.6.0] — 2026-06-22
> 발행 LOD 를 **'외부 추론용 학술 그래프'로 재정향**한다. 비평가의 비평 궤적·성장을 외부 LOD
> (국가서지LOD·Wikidata)와 연계해 추론(예: "다음에 어떤 시인을 볼까")하는 데 필요한 원본 사실만
> 발행하고, **GUI 직렬화에서 생긴 내부 앵커는 발행하지 않는다.** v0.5 #3 이 "내부 앵커(정규 발행
> 사실 아님)"라 주석만 달고 발행은 했던 반걸음을 실제 비발행으로 완성. SQLite 스키마·RDB 무변경
> (`SCHEMA_VERSION`=4) — 시 본문 LOD 비발행 불변식 유지(내부 앵커도 비발행으로 확장).

### Changed
- **발행 목적 재정향(문서·어휘).** `src/Rdf.php` 도크블록·`README`·`src/pages_lod.php`·`config.php`
  의 "충실한 직렬화" 카피를 "외부 추론용 학술 그래프 / 원본 사실만 발행"으로 정정. 온톨로지/SHACL
  `versionInfo` 0.5.0 → 0.6.0, `priorVersion` 0.4.0 → 0.5.0, `config.ont_version` 0.5.0 → 0.6.0.

### Removed (발행 LOD 에서만 — RDB·GUI 는 무변경)
- **비평문 속 인용 표지 body 비발행.** `pac:Quotation` 에서 `oa:hasBody`(→`oa:SpecificResource`
  →`oa:FragmentSelector`→`rdf:value`=xml:id)를 더는 발행하지 않는다(인용당 7트리플 감소). 그
  xml:id 는 GUI 분할뷰용 내부 앵커일 뿐 외부 추론엔 노이즈다. 인용은 `oa:hasTarget` 만 가진다
  (W3C Web Annotation 상 target-only 어노테이션은 적법). 비평문↔인용 연결은 `pac:hasQuotation`
  으로 유지. (`src/Rdf.php` `buildQuotation`)
- **`pac:fullText`(비평문 전문 HTML) 비발행.** 외부 추론의 조인 키가 아니고, 그 안의 `<q xml:id>`
  가 위 앵커를 다시 흘리는 통로이므로 함께 제외(비평문당 1트리플 감소). 전문은 시스템 내부(RDB
  `article.full_text`·GUI)에만 남는다. (`src/Rdf.php` `buildAbox`)
- **SHACL 동기화 완화.** `pac:BodyShape`·`pac:FragmentSelectorShape`·`QuotationShape` 의
  `oa:hasBody` 제약·`ArticleShape` 의 `pac:fullText` 제약과 fullText↔앵커 SPARQL 검사 2건 제거
  (발행 그래프에 더는 없는 노드를 검사하던 shape). **유지**: `hasQuotation`/`hasTarget` 최소개수,
  `TargetShape`·`TextSelectionShape`·`TextQuoteSelectorShape`. (`vocab/pac-shapes.ttl`)

### 유지 (비발행 아님 — 외부 추론의 신호)
- 인용 대상 `oa:hasTarget`(`pac:TextSelection` 연/행 · `oa:exact` · `pac:targetOrder`)은 그대로
  발행한다. "비평가가 어느 구절에 반응했나"는 `cito:critiques`(작품 레벨)로 환원 불가능한 원본
  신호이며, `oa:exact` 의 시 원문 *단편*은 시 본문 비발행 불변식 대상이 아니다(의도적 lossy 발췌).

### 데이터 마이그레이션
- **없음.** `SCHEMA_VERSION` 무변경(=4). SQLite 스키마/행 무변형 — `src/Rdf.php`(발행 매핑)·
  `vocab/pac-shapes.ttl`(검증)·`vocab`/문서/버전 문자열만 변경. `quotation.anchor`·
  `article.full_text` 컬럼은 GUI 가 쓰므로 RDB 에 그대로 둔다(발행에서만 제외).
- 검증: v0.1.0~v0.5.0 fixture `paco test-migration` 통과(행수 보존·전 라우트 HTTP 200). 발행물은
  인용 body·fullText 트리플이 사라진다(의도된 변경; 샘플 ABox 114→85). pyshacl **Conforms: True**
  (완화된 shapes·기본/rdfs 추론 모두).

### 비고
- **GUI 무영향.** 분할뷰·편집기·연결선은 RDB(`quotation.anchor` 등)에서 직접 렌더하므로 그대로
  동작한다. 단 `?r=lod` 발행 페이지의 Turtle 미리보기·4형식 덤프는 의도대로 가벼워진다(버그 아님).
- **추론 가동 전제(별건).** "다음에 어떤 시인" 추론(`?r=insights`)을 실제로 굴리려면 비평가가 비평한
  *시인*의 `owl:sameAs`(국가서지LOD·Wikidata) 링크가 채워져 있어야 한다 — 이 변경과 무관한 데이터
  충실화 과제다.

## [0.5.0] — 2026-06-21
> 온톨로지(TBox)를 외부 어휘 비훼손 원칙에 맞게 정직화하고, 온톨로지가 "발행 매핑 산출물"임을
> 어휘·문서로 명문화한다. SQLite 스키마·발행 데이터 무변경(`SCHEMA_VERSION`=4) — 시 본문 LOD
> 비발행 불변식 유지. v0.4.2 의문 목록(#1~#4) 대응.

### Added
- **시집/비평문 응용 하위클래스.** `pac:PoetryCollection ⊑ bibo:Book`,
  `pac:CriticalEssay ⊑ bibo:Article` 신설. 발행 시 인스턴스가 bibo 표준 타입과 pac 하위타입을
  **함께** 갖는다(추론기 없는 소비자도 `?x a bibo:Book` 으로 조회 가능). (`vocab/pac-ontology.owl`
  · `src/Rdf.php` books/articles 루프) (#2)
- **3계층(RDB+소스코드 / `Rdf.php` 매핑 / 발행 온톨로지) 매핑표**를 `README.md`·명세서에 추가. 온톨로지가
  시스템이 아니라 발행 결과물임을 명문화. (#3)
- **온톨로지 버전 단일 소스 `config.ont_version`.** 발행물·GUI 의 'PAC 온톨로지 vX' 표시를 한 곳에서
  관리(`render.php` 푸터 · `pages_lod.php` · `Rdf.php` Turtle 헤더). 앱 버전(`VERSION`)과 분리. (#3)

### Changed
- **`pac:QuotationType` 을 닫힌 열거로 정직화** — 빈 `owl:Class` → `owl:equivalentClass owl:oneOf
  {DirectQuotation, IndirectQuotation}` + `owl:NamedIndividual` + `owl:AllDifferent`. 발행 그래프의
  IRI 는 동일해 SHACL `sh:in` 무변경. (#1)
- **`bibo:Book`/`bibo:Article` 의 도메인 한국어 라벨('시집'/'비평문')을 중립 라벨('도서(외부)'/
  '글·기고(외부)')로 환원** — 외부 어휘 비훼손. 응용 의미는 위 하위클래스가 담는다. (#2)
- **`pac:fullText`·`oa:FragmentSelector`·`pac:TextSelection` 에 "내부 직렬화 앵커(정규 발행 사실
  아님)" 주석 추가** — 층위 분리 가시화. (#3)
- 온톨로지/SHACL `versionInfo` 0.4.0 → 0.5.0, `priorVersion` 0.3.0 → 0.4.0. 앱·발행물의 'v0.4'
  표시 문자열 갱신(render 푸터, pages_lod, `Rdf.php` Turtle 헤더, `vocab/` 헤더, README).

### 데이터 마이그레이션
- **없음.** `SCHEMA_VERSION` 무변경(=4). SQLite 스키마/행 무변형. TBox·SHACL(주석)·버전 문자열과
  `Rdf.php` 타입 트리플 2줄(추가형)만 변경 — 시 본문 비발행 불변식 유지.
- 검증: v0.1.0~v0.4.2(6 fixture) `paco test-migration` 통과(행수 보존·전 라우트 HTTP 200),
  시드 q1~q4 발행 동일(+ 시집/비평문에 하위타입 1개씩 추가). pyshacl 은 `_ontology_legacy/v0.5`
  에서 수동 검증.

### 비고
- #4(fulltext 중복): 실제 중복은 `oa:exact`(인용 발췌) ↔ 본문 슬라이스뿐이며 의도적 lossy 사본임을
  확인. 진짜 "본문 1회 저장 + 선택자 참조"는 **시 본문 LOD 발행**(불변식 변경)이 전제라 본 릴리스
  범위에서 제외(후속 릴리스 후보). publish-time `oa:exact` 파생은 시드 발행 리터럴을 바꾸므로 기각.

## [0.4.2] — 2026-06-08
> 인용 편집기를 **3분할 워크벤치**로 개편한다. 시 본문을 옆에 펼쳐 두고 연·행 좌표를 보며 입력하고,
> 한 화면에서 여러 인용을 **페이지 이탈 없이 연속 저장·수정**한다. 간접/직접 라디오가 세로로
> 깨지던 UI 도 고친다. 스키마·온톨로지(TBox) 무변경(`SCHEMA_VERSION`=4) — 기존 컬럼만 활용.

### Added
- **인용 편집기 3분할 레이아웃.** 좌측에 *인용 대상 시 본문*(연·행 번호 표기), 가운데 *인용 기입*,
  우측 *비평문 미리보기*를 나란히 둔다. 그동안 좌표를 적으려면 시 본문이 보이지 않아 연·행을 가늠할 수
  없던 문제를 해소했다. 대상 행에 연/행을 입력하면 좌측 시 본문의 해당 행이 실시간 강조(`.pline.hot`)되고
  그 위치로 스크롤된다. 좌측 시는 분할뷰와 같은 규칙으로 모은다(비평 대상 시 + 시집 수록시 + 기존 인용
  대상시). (`src/pages_article.php` `page_quotation_edit` · `public/assets/app.css` `.qedit3`/`.qsource`)
- **페이지 이탈 없는 연속 저장·수정(인용 워크벤치).** 저장이 비동기(`fetch`)로 처리되어 화면을 벗어나지
  않는다. 우측 본문에서 `<q>` 표지를 클릭하면 그 앵커가 *불러와져*(이미 저장된 인용이면 폼에 채워 수정,
  없으면 새 인용) 입력 후 *저장* → 곧바로 다음 표지를 클릭하면 된다. 가운데 폼 아래의 *이 비평문의 인용*
  목록에서 항목을 눌러도 불러와 수정한다. 표지↔인용은 1:1 모델이라 같은 앵커 중복 생성을 막고(기존 id
  재사용), 앵커 변경(이름 바꾸기)도 처리한다. 저장 시 토스트로 결과를 알린다.
  (`public/assets/app.js` `initQuotationWorkbench` · `src/pages_article.php` `quotation_client`)
- **인용 저장 라우트의 비동기(JSON) 응답.** `quotations/save` 가 `X-PACO-Ajax` 헤더를 받으면 리다이렉트
  대신 저장 결과를 JSON(`{ok, quotation}`)으로 돌려준다. JS 가 없거나 구형 브라우저면 기존처럼 일반
  POST→리다이렉트로 동작한다(점진적 향상, 폴백 유지). (`public/index.php` `is_ajax`/`json_out`)

### Fixed
- **간접/직접 라디오가 세로로 늘어져 영역이 비정상적으로 길어지던 UI 깨짐.** 폼 전역 규칙
  `.form input{width:100%}` 가 라디오 버튼에도 적용돼 버튼이 가로를 꽉 채우고 라벨 글자가 아래로
  밀렸다. `.form input[type=radio],[type=checkbox]{width:auto}` 로 제외했다. (`public/assets/app.css`)
- **삭제된 출처를 가진 대상을 불러올 때 조용히 누락되던 문제.** 출처(시/시집)가 지워져 `<select>` 에
  해당 옵션이 없으면 `value` 설정이 조용히 실패→빈 출처→저장 시 그 대상이 통째로 빠졌다. 임시 옵션
  *(삭제된 출처: …)* 을 끼워 값을 보존하고 사용자에게 보이게 했다. (`public/assets/app.js` `buildRow`)

### Changed
- 편집기 데이터 인라인 스크립트를 `#paco-article` → `#paco-qedit` 로 바꾸고, 시 연/행 텍스트(`poems`)에
  더해 *이 비평문의 기존 인용 전부*(`existing`, 클라이언트 편집 모델)와 `saveUrl` 을 함께 공급한다.
  과거의 분리된 편집기 스크립트(`initQuotationEditor`·`initTargetExact`·대상행 추가 블록)는 단일
  `initQuotationWorkbench` 로 통합했다. 분할뷰(`initSplit`)·`<q>` 삽입 버튼은 그대로다.

### 데이터 마이그레이션
- **없음.** `SCHEMA_VERSION` 무변경(=4). 컬럼·테이블 추가가 없고, 인용 저장 경로(`saveQuotation`)와
  저장 데이터 형식도 동일하다(비동기 응답만 추가). 기존 인용·대상은 그대로 열리고 저장된다. 검증:
  시드 DB 의 q1~q4 가 3분할 편집기에서 정확히 불러와지고, 비동기 저장이 중복 없이 갱신됨을 확인(전
  라우트 HTTP 200, 릴리스 게이트 `paco test-migration` 통과 유지).

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

[Unreleased]: https://github.com/lamaBread/paco-app/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/lamaBread/paco-app/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/lamaBread/paco-app/compare/v0.4.2...v0.5.0
[0.4.2]: https://github.com/lamaBread/paco-app/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/lamaBread/paco-app/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/lamaBread/paco-app/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/lamaBread/paco-app/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/lamaBread/paco-app/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lamaBread/paco-app/releases/tag/v0.1.0
