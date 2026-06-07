<?php
/**
 * SQLite 연결 + 스키마.
 *
 * 스키마는 PAC v0.4 온톨로지를 관계형으로 사상한 것이다.
 *   person            ← pac:Poet / pac:Critic (foaf:Person 하위, 둘은 배타 아님)
 *   book              ← bibo:Book
 *   poem / poem_line  ← pac:Poem (+ 연/행 본문은 표시·선택용 내부 데이터)
 *   article           ← bibo:Article (pac:fullText 포함)
 *   quotation         ← pac:Quotation (= oa:Annotation) 의 body 측
 *   quotation_target  ← oa:hasTarget(SpecificResource) 1..N (비연속 인용)
 *
 * 주의: poem_line(연/행 본문)은 좌측 시 표시와 연/행 선택을 위한 시스템 내부
 *       표시 데이터다. v0.4 TBox 에는 '시 전문' 속성이 없으므로 LOD 트리플로는
 *       발행하지 않는다(온톨로지 비훼손). 시는 선택자(TextSelection/exact)로만
 *       LOD 에 나타난다.
 */

namespace PACO;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(string $path): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $fresh = !is_file($path);
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
        if ($fresh) {
            self::migrate($pdo);
        } else {
            // 스키마 없는 빈 파일이면 생성
            $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='person'")->fetch();
            if (!$has) {
                self::migrate($pdo);
            }
        }
        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
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
-- v0.4: body 는 FragmentSelector(anchor=xml:id) 하나로 충분 → body 텍스트 선택자 컬럼 제거.
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
    }
}
