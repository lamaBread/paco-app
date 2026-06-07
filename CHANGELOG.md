# 변경 이력 (Changelog)

PACO 앱의 버전별 변경과 **데이터 마이그레이션 주의사항**을 기록한다.
규칙: [유의적 버전(SemVer)](https://semver.org/lang/ko/) — `MAJOR.MINOR.PATCH`.

> ⚠️ **버전업 시 필수**: 스키마(`src/Database.php`)를 바꿨다면, 반드시 아래 항목에
> *"기존 사용자 DB에 대한 마이그레이션"*을 명시하고 `paco release` 의 헤드리스
> 호환성 테스트(이전 버전 fixture 대비)를 통과시킨 뒤 push 한다.
> 자세한 절차는 `../paco-harness/HARNESS.md` 참고.

## [Unreleased]
- (작업 중)

## [0.1.0] — 2026-06-07
### Added
- PACO 최초 버전. 시(詩) 비평문 기입·관리 + LOD(RDF/XML·Turtle·JSON-LD·N-Triples) 발행.
- PAC v0.4 온톨로지를 관계형으로 사상한 SQLite 스키마
  (person · book · poem · poem_line · article · quotation · quotation_target · wikidata 캐시).
- 샘플 시드: 황인찬 『구관조 씻기기』 「순례」 / 2024-09-03 비평 + 인용 q1~q4.

### 데이터 마이그레이션
- 최초 버전 — 마이그레이션 대상 없음. 이 버전의 시드 DB가 이후 버전 호환성
  테스트의 **기준 fixture**(`paco-harness/fixtures/0.1.0/`)가 된다.

[Unreleased]: https://github.com/lamaBread/paco-app/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lamaBread/paco-app/releases/tag/v0.1.0
