# 변경 이력 (Changelog)

PACO 앱의 버전별 변경과 **데이터 마이그레이션 주의사항**을 기록한다.
규칙: [유의적 버전(SemVer)](https://semver.org/lang/ko/) — `MAJOR.MINOR.PATCH`.

> ⚠️ **버전업 시 필수**: 스키마(`src/Database.php`)를 바꿨다면, 반드시 아래 항목에
> *"기존 사용자 DB에 대한 마이그레이션"*을 명시하고 `paco release` 의 헤드리스
> 호환성 테스트(이전 버전 fixture 대비)를 통과시킨 뒤 push 한다.
> 자세한 절차는 `../paco-harness/HARNESS.md` 참고.

## [Unreleased]
- (작업 중)

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

[Unreleased]: https://github.com/lamaBread/paco-app/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/lamaBread/paco-app/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lamaBread/paco-app/releases/tag/v0.1.0
