<?php
/**
 * SQLite 연결 + 스키마 + **버전 관리 마이그레이션**.
 *
 * 스키마는 PAC 온톨로지를 관계형으로 사상한 것이다.
 *   person            ← pac:Poet / pac:Critic (foaf:Person 하위, 둘은 배타 아님)
 *   book              ← bibo:Book
 *   poem / poem_line  ← pac:Poem (+ 연/행 본문은 표시·선택용 내부 데이터)
 *   article           ← bibo:Article (pac:fullText 포함)
 *   quotation         ← pac:Quotation (= oa:Annotation) 의 body 측
 *   quotation_target  ← oa:hasTarget(SpecificResource) 1..N (비연속 인용)
 *   app_setting       ← 사용자 런타임 설정(iri_data 등) k/v. config.php 와 분리되어
 *                       코드 업데이트(파일 덮어쓰기)와 무관하게 보존된다.
 *
 * ── 마이그레이션(요구사항 #3 / 온톨로지 변경 대응) ──────────────────────────
 * `PRAGMA user_version` 으로 DB 의 스키마 단계를 추적한다. 코드가 새 버전이면
 * (SCHEMA_VERSION 이 올라가면) connect() 가 *기존 사용자 DB* 를 열 때 부족한 단계만
 * 순서대로, 각 단계를 한 트랜잭션으로 적용한다. 데이터를 변형하는 단계 전에는
 * DB 파일을 data/backups/ 에 백업한다(실패 시 사용자가 복구 가능).
 *
 * ⚠️ 온톨로지/스키마를 바꾸면(컬럼·테이블 추가, 값 변환 등):
 *    1) self::SCHEMA_VERSION 을 1 올리고
 *    2) self::migrations() 에 그 번호의 단계(클로저)를 추가한다.
 * 그래야 `paco test-migration`/`paco release` 게이트가 기존 DB 에서 통과한다.
 * (자세한 절차: ../../paco-harness/HARNESS.md, CHANGELOG.md 의 '데이터 마이그레이션')
 *
 * 주의: poem_line(연/행 본문)은 좌측 시 표시·연/행 선택을 위한 시스템 내부 표시
 *       데이터다. TBox 에 '시 전문' 속성이 없으므로 LOD 트리플로는 발행하지 않는다
 *       (온톨로지 비훼손). 시는 선택자(TextSelection/exact)로만 LOD 에 나타난다.
 */

namespace PACO;

use PDO;

final class Database
{
    /** 현재 코드가 기대하는 스키마 단계. 스키마를 바꾸면 +1 하고 migrations() 에 단계 추가. */
    public const SCHEMA_VERSION = 3;

    private static ?PDO $pdo = null;

    public static function connect(string $path): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo = $pdo;
        self::migrate($pdo, $path);
        return $pdo;
    }

    /**
     * 부족한 스키마 단계를 순서대로 적용한다(이미 최신이면 아무것도 안 함).
     * $path 가 주어지고 기존(비어있지 않은) DB 를 올리는 경우, 단계 적용 전 1회 백업한다.
     */
    public static function migrate(PDO $pdo, ?string $path = null): void
    {
        $cur = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($cur >= self::SCHEMA_VERSION) {
            return;
        }

        // 기존 데이터가 있는 DB 를 업그레이드하는 상황이면, 단계 적용 전 1회 백업.
        if ($path !== null && self::hasUserData($pdo)) {
            self::backup($path, $cur);
        }

        // ⚠️ FK 를 끄고 마이그레이션한다. 컬럼 삭제/이름변경/타입변경은 SQLite 에서 '테이블 재작성'
        //   (새 테이블 생성→복사→DROP old→RENAME)으로만 가능한데, foreign_keys=ON 이면 DROP old 가
        //   자식의 ON DELETE CASCADE 를 발동시켜 자식 행을 조용히 날린다(poem_line·quotation_target 등).
        //   foreign_keys 는 '트랜잭션 안'에서는 변경이 무시되므로 반드시 루프(트랜잭션) *밖*에서 끈다.
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            foreach (self::migrations() as $v => $step) {
                if ($v <= $cur) {
                    continue;
                }
                $pdo->beginTransaction();
                try {
                    $step($pdo);
                    // user_version 은 트랜잭션에 포함되어 롤백 시 함께 되돌아간다.
                    $pdo->exec(\sprintf('PRAGMA user_version = %d', $v));
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw new \RuntimeException(
                        "마이그레이션 단계 {$v} 실패: " . $e->getMessage(), 0, $e
                    );
                }
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * 라이브 연결을 닫는다(WAL 체크포인트 후 싱글톤 해제). 자가 업데이트가 DB 파일을
     * 백업·롤백하기 전에 호출해, 열린 핸들이 있는 WAL DB 를 복사/덮어쓸 때 생길 수 있는
     * 비정합/손상을 피한다. (호출자가 잡고 있는 지역 PDO 변수도 함께 null 처리해야 완전히 닫힌다.)
     */
    public static function close(): void
    {
        if (self::$pdo instanceof PDO) {
            try {
                self::$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable $e) {
                // 체크포인트 실패는 무시 — 닫기만 해도 다음 연결이 정상화한다.
            }
            self::$pdo = null;
        }
    }

    /**
     * $src DB 를 $dest 로 WAL-정합 백업한다(단일 파일). 새 연결로 체크포인트 후 VACUUM INTO.
     * 성공 시 true. (열린 핸들과 무관하게 일관 사본을 만든다 — 라이브 연결은 미리 close() 권장.)
     */
    public static function backupTo(string $src, string $dest): bool
    {
        if (!is_file($src) || file_exists($dest)) {
            return false;
        }
        try {
            $tmp = new PDO('sqlite:' . $src);
            $tmp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tmp->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $tmp->exec('VACUUM INTO ' . $tmp->quote($dest));
            $tmp = null;
        } catch (\Throwable $e) {
            return false;
        }
        return is_file($dest);
    }

    /** 'person' 테이블이 이미 있으면 = 기존 데이터 보유 DB 로 본다. */
    private static function hasUserData(PDO $pdo): bool
    {
        $row = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='person'"
        )->fetch();
        return (bool) $row;
    }

    /**
     * 마이그레이션 전 안전 백업. data/backups/ 에 일관된 사본을 만든다(best-effort).
     * VACUUM INTO(단일 파일·WAL 정합) 우선, 실패 시 파일 복사로 폴백.
     * 파일명은 고유해야 한다 — VACUUM INTO 는 대상이 이미 있으면 실패하므로
     * 초 단위 타임스탬프 충돌(같은 초의 두 번째 마이그레이션·재실행)을 난수로 회피한다.
     */
    private static function backup(string $path, int $fromVersion): void
    {
        $dir = \dirname($path) . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir)) {
            return; // 백업 위치를 못 만들면 조용히 포기(추가 단계는 안전하도록 설계)
        }
        $stamp = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $dest  = $dir . "/paco-pre-v{$fromVersion}-{$stamp}.sqlite";
        if (file_exists($dest)) {
            return; // 극히 드문 난수 충돌 — 같은 시점 백업이 이미 있으니 충분
        }
        try {
            $pdo = self::$pdo;
            if ($pdo instanceof PDO) {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
                return;
            }
        } catch (\Throwable $e) {
            // 핸들된 폴백 — stderr 에 'SQLSTATE' 등을 흘리지 않는다(치명오류 오탐 방지).
        }
        // 폴백: 파일 복사(WAL/SHM 도 함께)
        if (@copy($path, $dest)) {
            foreach (['-wal', '-shm'] as $suf) {
                if (is_file($path . $suf)) {
                    @copy($path . $suf, $dest . $suf);
                }
            }
        }
    }

    /**
     * 스키마 단계 레지스트리. 번호(=user_version) → 그 단계로 올리는 작업.
     * 각 단계는 한 트랜잭션에서 멱등에 가깝게(가능한 한 IF NOT EXISTS) 수행한다.
     */
    private static function migrations(): array
    {
        return [
            // ── 1: 기반 스키마 (v0.1.0~). 기존 DB(버전 미기록=0)에도 IF NOT EXISTS 라 안전. ──
            1 => static function (PDO $pdo): void {
                $pdo->exec(<<<'SQL'
-- 인물: 시인(pac:Poet) / 비평자(pac:Critic). 한 사람이 둘 다일 수 있다.
CREATE TABLE IF NOT EXISTS person (
    id         TEXT PRIMARY KEY,            -- IRI 지역명: pacd:{id}
    name       TEXT NOT NULL,               -- foaf:name
    is_poet    INTEGER NOT NULL DEFAULT 0,
    is_critic  INTEGER NOT NULL DEFAULT 0,
    same_as    TEXT,                         -- owl:sameAs (Wikidata IRI, 선택)
    note       TEXT
);

-- 시집: bibo:Book
CREATE TABLE IF NOT EXISTS book (
    id         TEXT PRIMARY KEY,
    title      TEXT NOT NULL,               -- pac:documentTitle (⊑ dct:title)
    author_id  TEXT REFERENCES person(id) ON DELETE SET NULL,  -- pac:hasAuthor
    isbn13     TEXT                          -- bibo:isbn13
);

-- 시: pac:Poem
CREATE TABLE IF NOT EXISTS poem (
    id         TEXT PRIMARY KEY,
    title      TEXT NOT NULL,               -- pac:documentTitle
    author_id  TEXT REFERENCES person(id) ON DELETE SET NULL,  -- pac:hasAuthor
    book_id    TEXT REFERENCES book(id) ON DELETE SET NULL      -- dct:isPartOf
);

-- 시 본문(연/행) — 좌측 표시 및 연/행 선택용 내부 데이터(LOD 비발행)
CREATE TABLE IF NOT EXISTS poem_line (
    poem_id    TEXT NOT NULL REFERENCES poem(id) ON DELETE CASCADE,
    stanza_no  INTEGER NOT NULL,            -- 1부터
    line_no    INTEGER NOT NULL,            -- 연 안에서 1부터
    text       TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (poem_id, stanza_no, line_no)
);

-- 비평문: bibo:Article
CREATE TABLE IF NOT EXISTS article (
    id             TEXT PRIMARY KEY,
    title          TEXT NOT NULL,           -- pac:documentTitle
    author_id      TEXT REFERENCES person(id) ON DELETE SET NULL,  -- pac:hasAuthor (Critic)
    created        TEXT,                     -- dct:created (xsd:date 'YYYY-MM-DD')
    critiques_kind TEXT,                     -- 'poem' | 'book'  (cito:critiques 대상 종류)
    critiques_id   TEXT,                     -- poem.id | book.id
    full_text      TEXT NOT NULL DEFAULT '', -- pac:fullText (rdf:HTML, <q xml:id="N"> 포함)
    updated_at     TEXT
);

-- 인용: pac:Quotation (= oa:Annotation). 여기 한 행이 하나의 oa:hasBody 를 포함.
-- body 는 FragmentSelector(anchor=xml:id) 하나로 충분 → body 텍스트 선택자 컬럼 없음.
CREATE TABLE IF NOT EXISTS quotation (
    id           TEXT PRIMARY KEY,          -- pacd:{id}, 보통 q1, q2 …
    article_id   TEXT NOT NULL REFERENCES article(id) ON DELETE CASCADE, -- pac:hasQuotation / body source
    qtype        TEXT NOT NULL DEFAULT 'indirect',  -- 'direct' | 'indirect' (pac:quotationType)
    anchor       TEXT NOT NULL,             -- 본문 <q xml:id="N"> 의 N (FragmentSelector/rdf:value)
    sort_order   INTEGER NOT NULL DEFAULT 0
);

-- 인용 대상: oa:hasTarget 의 oa:SpecificResource. 한 인용에 1..N (2 이상이면 비연속).
CREATE TABLE IF NOT EXISTS quotation_target (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    quotation_id TEXT NOT NULL REFERENCES quotation(id) ON DELETE CASCADE,
    target_order INTEGER,                    -- pac:targetOrder (비연속일 때)
    source_kind  TEXT NOT NULL,              -- 'poem' | 'book' (oa:hasSource 종류)
    source_id    TEXT NOT NULL,              -- poem.id | book.id
    start_stanza INTEGER,                    -- pac:TextSelection
    end_stanza   INTEGER,
    start_line   INTEGER,
    end_line     INTEGER,
    exact        TEXT                         -- 대상 oa:TextQuoteSelector/oa:exact (원문, 선택)
);

-- Wikidata 프리페치 캐시 (직접속성 P… 단위 사실)
CREATE TABLE IF NOT EXISTS wikidata_fact (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id   TEXT NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    prop_pid    TEXT NOT NULL,              -- 'P106' 등
    prop_label  TEXT,                        -- '직업' 등
    value_iri   TEXT,                        -- wd:Q… (있으면)
    value_label TEXT,                        -- 한국어 라벨
    fetched_at  TEXT
);

-- '비슷한 시인' 추천 캐시(같은 직업으로 묶이는 다른 시인)
CREATE TABLE IF NOT EXISTS wikidata_similar (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id     TEXT NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    via_pid       TEXT,                      -- 어떤 공유 속성으로(P106 등)
    via_label     TEXT,                      -- 공유값 라벨(예: '시인')
    similar_iri   TEXT NOT NULL,             -- wd:Q…
    similar_label TEXT,
    fetched_at    TEXT
);

CREATE INDEX IF NOT EXISTS ix_poem_book      ON poem(book_id);
CREATE INDEX IF NOT EXISTS ix_article_crit   ON article(critiques_kind, critiques_id);
CREATE INDEX IF NOT EXISTS ix_quot_article   ON quotation(article_id);
CREATE INDEX IF NOT EXISTS ix_qtarget_quot   ON quotation_target(quotation_id);
CREATE INDEX IF NOT EXISTS ix_wdfact_person  ON wikidata_fact(person_id);
SQL);
            },

            // ── 2 (v0.2.0): 사용자 런타임 설정 저장소. config.php(출하 기본값)와 분리. ──
            // 코드 업데이트가 config.php 를 덮어써도 사용자 설정(iri_data 등)은 DB 라 보존된다.
            2 => static function (PDO $pdo): void {
                $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_setting (
    key        TEXT PRIMARY KEY,            -- 'iri_data' 등
    value      TEXT,                         -- 사용자 지정 값
    updated_at TEXT
);
SQL);
            },

            // ── 3 (v0.3.0): 시인 식별자를 다출처로 확장 + 국가서지LOD 프리페치 캐시. ──
            // 시인을 Wikidata 외에 국립중앙도서관 국가서지LOD(lod.nl.go.kr)·ISNI 와도 연결한다.
            //   person.nl_uri : 국가서지LOD 자원 URI(owl:sameAs) — 추론의 '기본' 출처
            //   person.isni   : ISNI 코드(16자리). 발행 시 owl:sameAs http://www.isni.org/isni/<코드>
            //   nl_fact       : 국가서지LOD 프로파일 프리페치 캐시(wikidata_fact 와 평행). NL 우선,
            //                   부족분은 Wikidata 폴백으로 보강한다.
            // 모두 '추가형'(컬럼 추가 + 새 테이블)이라 기존 행을 변형하지 않는다.
            3 => static function (PDO $pdo): void {
                self::addColumn($pdo, 'person', 'nl_uri', 'TEXT');  // owl:sameAs (국가서지LOD)
                self::addColumn($pdo, 'person', 'isni',   'TEXT');  // ISNI 코드
                $pdo->exec(<<<'SQL'
-- 국가서지LOD(국립중앙도서관) 프로파일 프리페치 캐시
CREATE TABLE IF NOT EXISTS nl_fact (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id   TEXT NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    prop_uri    TEXT NOT NULL,              -- 'http://lod.nl.go.kr/ontology/isni' 등
    prop_label  TEXT,                        -- '직업' '활동분야' '생몰년' 등 한국어 라벨
    value_iri   TEXT,                        -- 객체가 URI 일 때(owl:sameAs 대상 등)
    value_label TEXT,                        -- 리터럴 값
    fetched_at  TEXT
);
CREATE INDEX IF NOT EXISTS ix_nlfact_person ON nl_fact(person_id);
SQL);
            },
        ];
    }

    /** SQLite 에 컬럼을 멱등 추가(이미 있으면 무시). ALTER TABLE ADD COLUMN 은 중복 시 오류이므로 가드. */
    private static function addColumn(PDO $pdo, string $table, string $col, string $type): void
    {
        $cols = $pdo->query("PRAGMA table_info($table)")->fetchAll();
        foreach ($cols as $c) {
            if (($c['name'] ?? '') === $col) {
                return; // 이미 존재
            }
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
    }
}
