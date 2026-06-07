# PACO — Poem And Criticism Ontology

시(詩)를 인용하는 **비평문을 W3C Web Annotation 모델로 기입·관리**하고, **PAC 온톨로지(v0.4)에 충실한 LOD**(RDF/XML·Turtle·JSON-LD·N-Triples)로 발행하는 로컬 우선(local-first) 웹 시스템.

> 부제 **詩話(시화)** — 시에 대한 비평적 단평을 모은 동아시아 고전 장르에서.
> 순수 PHP 8.1 + SQLite. 빌드 스텝·외부 프레임워크 없음.

---

## 빠른 시작

```bash
php bin/init-db.php --fresh        # SQLite 생성 + v0.4 샘플 시드(황인찬 「순례」)
php -S localhost:8001 -t public    # 편집 앱 실행 → http://localhost:8001
```

정적 아카이브 빌드 / 배포:

```bash
php bin/build.php                  # dist/ 에 정적 사이트 + LOD 덤프 생성
php -S localhost:8002 -t dist      # 빌드 결과 미리보기
# 배포: dist/ 전체를 웹서버 루트(또는 하위 경로)에 업로드
```

> **실행 모델**: PACO 는 공개 repo(`github.com/lamaBread/paco-app`)를 `git clone` 한 뒤
> `php -S localhost:8001 -t public` 로 구동하는 **로컬 우선 PHP 앱**이다. 데스크톱(Electron)
> 배포는 **v2.0.0 로드맵**으로 보류되어 있다(맨 아래 참고).

---

## 설정 · 업데이트 · 마이그레이션

- **설정(`?r=settings`)** — `iri_data`(LOD 발행 도메인) 같은 사용자 값은 `config.php`(출하 기본값)가
  아니라 **DB(`app_setting`)** 에 저장된다. 그래서 코드를 업데이트해도(=파일을 덮어써도) 설정은
  `data/` 안에 있어 보존된다. 설정 페이지에서 바꾸고, 비워서 저장하면 기본값으로 되돌아간다.

- **원클릭 업데이트(`?r=update`)** — GitHub 공개 repo 의 **버전 태그**를 읽어 최신 버전을 임시 폴더에
  클론한 뒤 코드 파일을 덮어쓴다. `data/`(편집 원본 DB)·`.git` 은 건드리지 않으며, 적용 전 코드·DB
  를 `data/backups/` 에 백업하고, 새 코드로 마이그레이션을 별도 프로세스로 돌린 뒤, **실패하면
  백업에서 자동 롤백**한다. CLI 로도 가능:

  ```bash
  php bin/self-update.php --check    # 최신 버전만 확인
  php bin/self-update.php            # 최신 버전으로 업데이트
  php bin/self-update.php 0.2.1      # 특정 버전으로
  ```

  > 개발 중(커밋 안 된 변경이 있는 작업트리)이거나 `PACO_DEV=1` 이면 자가 업데이트는 거부된다
  > (개발자 작업트리 보호). 일반 실행에서만 동작한다.

- **마이그레이션** — 스키마는 `PRAGMA user_version` 으로 단계가 관리된다(`src/Database.php`). 새
  버전이 스키마/온톨로지를 바꾸면 DB 를 열 때 부족한 단계만 자동 적용되고, 데이터 변형 전 백업이
  생성된다. 수동 실행: `php bin/migrate.php`.

---

## 세 가지 얼굴

1. **동적 편집 앱**(localhost) — 인물·시집·시·비평문·인용 CRUD, **좌우 분할 비평문 뷰**(좌: 원시, 우: 비평문 `<q>` 표지, 마우스로 연결선 표시), 본문 드래그 → `<q xml:id>` 자동 태깅.
2. **LOD 발행기** — SQLite 데이터를 온톨로지에 맞는 4형식 RDF 로 직렬화. `iri_data`(config) 만 바꾸면 발행 IRI 도메인이 함께 바뀜.
3. **정적 빌더** — 편집 UI 없는 읽기 전용 평면(flat) 아카이브를 `dist/` 로. Wikidata 추론 결과는 프리페치 캐시에서 렌더되어 오프라인에서도 동작.

---

## 구조

```
paco/
├─ config.php          # 출하 기본값(경로·IRI base·접두사·Wikidata·repo). 사용자값은 DB(설정 페이지)
├─ VERSION             # 0.2.0  ← 푸터 표시·자가 업데이트 비교 기준
├─ public/
│  ├─ index.php        # 프론트 컨트롤러(라우터 + 액션)
│  └─ assets/          # app.css · app.js(q-연결, 드래그 태깅)
├─ src/
│  ├─ Database.php     # SQLite 스키마 + PRAGMA user_version 마이그레이션 프레임워크
│  ├─ Settings.php     # DB(app_setting) 사용자 설정 + config 오버레이
│  ├─ Updater.php      # git 태그 기반 자가 업데이트(클론+덮어쓰기·백업·롤백)
│  ├─ Repo.php         # 엔티티 CRUD
│  ├─ Rdf.php          # 트리플 그래프 → NT / Turtle / RDF·XML / JSON-LD
│  ├─ Wikidata.php     # owl:sameAs 사실·유사 시인 프리페치 캐시
│  ├─ render.php       # 레이아웃 + URL 헬퍼(동적/정적 공용)
│  └─ pages_*.php      # 페이지 컨트롤러 (… + pages_admin: 설정·업데이트)
├─ bin/
│  ├─ init-db.php      # 스키마 + v0.4 샘플 시드
│  ├─ migrate.php      # 마이그레이션 실행기(DB 열어 부족한 스키마 단계 적용)
│  ├─ self-update.php  # 자가 업데이트 CLI (--check / 버전)
│  └─ build.php        # 정적 아카이브 생성
├─ vocab/              # pac-ontology.owl · pac-shapes.ttl (v0.4, 어휘 원천)
├─ data/paco.sqlite    # 편집 원본(생성됨) · data/backups/(마이그레이션·업데이트 백업)
└─ dist/               # 정적 빌드 산출물(생성됨)
```

## 온톨로지 ↔ 관계형 사상

| 온톨로지(v0.4) | 테이블 |
|---|---|
| `pac:Poet` / `pac:Critic` (⊑ `foaf:Person`) | `person(is_poet,is_critic,same_as)` |
| `bibo:Book` | `book` |
| `pac:Poem` (+ 연/행 본문은 표시용 내부 데이터) | `poem` / `poem_line` |
| `bibo:Article` (`pac:fullText` rdf:HTML) | `article` |
| `pac:Quotation` = `oa:Annotation` 의 body | `quotation` |
| `oa:hasTarget`(SpecificResource) 1..N | `quotation_target` |

- **인용 모델(v0.4 슬림)**: `oa:hasBody`(비평문 속 표지 = `oa:FragmentSelector`(xml:id) 하나) ↔ `oa:hasTarget`(원시 속 위치 = `pac:TextSelection` 연/행 + `oa:TextQuoteSelector`의 `oa:exact`). 대상 2개 이상이면 **비연속 인용**(`pac:targetOrder` 부여). v0.4 에서 body 쪽 `oa:TextQuoteSelector`·`oa:prefix/suffix`·`oa:TextPositionSelector` 폐지.
- **시 본문(`poem_line`)** 은 좌측 표시·연행 선택을 위한 시스템 내부 데이터이며, TBox 에 '시 전문' 속성이 없으므로 **LOD 트리플로 발행하지 않는다**(온톨로지 비훼손). 시는 선택자로만 LOD 에 나타난다.
- `pac:quotedFrom`(속성 체인)·`dct:creator`/`dct:title`(하위속성 함의) 등 **도출 트리플은 발행하지 않는다** — OWL 추론기의 몫.

## 검증 (선택)

```bash
# 4형식 동형성·SPARQL: rdflib  /  적합성: pyshacl
pyshacl -s vocab/pac-shapes.ttl -e vocab/pac-ontology.owl -i rdfs -df turtle dist/data/pac-data.ttl
#   → Conforms: True
```

현재 샘플 기준: ABox **110 트리플**(v0.3 130 → OA 슬림화로 감소), 4형식(NT/TTL/RDF·XML/JSON-LD) 동형, v0.4 SHACL **Conforms: True**.

## 설계 결정

| 항목 | 선택 |
|---|---|
| 편집 원본 | **SQLite** + 충실한 RDF 내보내기 |
| 인용 입력 | **폼 + 드래그 보조**(선택 영역 → `<q xml:id>`) |
| LOD 발행 | **통합 덤프 + 설정형 base IRI** |
| 추론 질의 | **Wikidata 프리페치 캐시**(정적/오프라인 동작) |

## 추론 질의 (Wikidata 연합 · v0.4 확장)

`추론 질의` 탭의 **프리페치/갱신** 버튼이 `owl:sameAs` 로 연결된 시인 데이터를 받아 캐시한다(네트워크 필요). 이후 표시·정적 빌드는 캐시에서 이뤄진다.

- **확장 프로파일**(§7.6): 거주지·수상·직업에 더해 출생/사망·국적·사조(P135)·장르(P136)·영향(P737)·대표작(P800).
- **비슷한 시인**(§7.5): 직업 또는 **사조**를 공유하는 다른 시인(공유 근거 표시).
- **다음에 읽을 시인**(§7.7, gap 분석): 유사 시인 중 *아직 비평하지 않은* 시인을 겹침 점수순으로 — 비평가의 다음 독서를 돕는다.
- **친화도**: 내가 비평한 시인들끼리 공유하는 속성으로 묶이는 군집(데이터가 쌓일수록 또렷해짐).

> 추천의 정밀도는 대상 시인의 LOD 풍부도에 비례한다. 사조·장르가 채워진 시인이 많을수록 군집이 선명해진다.

## 로드맵

- **v2.0.0 — Electron 데스크톱 배포** *(보류 중, 장기)*. 현재는 PHP 기반 실행·배포·업데이트로 일원화되어
  있으나, 시스템은 *Electron 으로 패키징 가능한 상태*로 유지된다(`config.php` 의 `PACO_DB_PATH`/
  `PACO_DIST_DIR` 오버라이드 — 번들 내부가 읽기전용일 때 쓰기 가능한 userData 로 DB 를 돌리기 위함).
  Electron 도입은 설계 사상 레벨의 변경이므로 MAJOR(첫 자리) 버전업으로 다룬다.
- 버전 규칙(SemVer): **PATCH**(셋째) 버그 수정 · **MINOR**(둘째) 기능 추가/변경 · **MAJOR**(첫째)
  설계 사상 레벨의 대규모 변경. 자세한 절차는 `../paco-harness/HARNESS.md`.

---

기반 온톨로지: PAC v0.4 — `../v0.4/` (이전: v0.1~v0.3). 라이선스: 교육용.
