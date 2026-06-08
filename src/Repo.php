<?php
/** 데이터 접근 계층 — PAC 엔티티 CRUD. */

namespace PACO;

use PDO;

final class Repo
{
    public function __construct(private PDO $db) {}

    public function pdo(): PDO { return $this->db; }

    // ---------------------------------------------------------------- person
    /** @return array<int,array> */
    public function people(): array
    {
        return $this->db->query('SELECT * FROM person ORDER BY name')->fetchAll();
    }
    public function poets(): array
    {
        return $this->db->query('SELECT * FROM person WHERE is_poet=1 ORDER BY name')->fetchAll();
    }
    public function critics(): array
    {
        return $this->db->query('SELECT * FROM person WHERE is_critic=1 ORDER BY name')->fetchAll();
    }
    public function person(string $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM person WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    /** 국가서지LOD 자원 URI(person.nl_uri)로 인물 1명 찾기 — 시집 저자 자동 매칭용. */
    public function personByNlUri(string $uri): ?array
    {
        $uri = trim($uri);
        if ($uri === '') return null;
        $st = $this->db->prepare('SELECT * FROM person WHERE nl_uri=? LIMIT 1');
        $st->execute([$uri]);
        return $st->fetch() ?: null;
    }
    public function savePerson(array $d): string
    {
        $id = $d['id'] ?: ('person_' . paco_slug($d['name']));
        // ISNI 는 표준 16자리로 정규화해 저장(공백·하이픈·isni.org URI 입력도 허용).
        $isni = NlLod::normalizeIsni($d['isni'] ?? '');
        $st = $this->db->prepare(
            'INSERT INTO person (id,name,is_poet,is_critic,same_as,nl_uri,isni,note)
             VALUES (:id,:name,:p,:c,:s,:nl,:isni,:note)
             ON CONFLICT(id) DO UPDATE SET
               name=:name, is_poet=:p, is_critic=:c, same_as=:s, nl_uri=:nl, isni=:isni, note=:note'
        );
        $st->execute([
            ':id' => $id, ':name' => $d['name'],
            ':p' => !empty($d['is_poet']) ? 1 : 0,
            ':c' => !empty($d['is_critic']) ? 1 : 0,
            ':s' => ($d['same_as'] ?? '') !== '' ? $d['same_as'] : null,
            ':nl' => ($d['nl_uri'] ?? '') !== '' ? trim($d['nl_uri']) : null,
            ':isni' => $isni,
            ':note' => $d['note'] ?? null,
        ]);
        return $id;
    }
    public function deletePerson(string $id): void
    {
        $this->db->prepare('DELETE FROM person WHERE id=?')->execute([$id]);
    }

    // ------------------------------------------------------------------ book
    public function books(): array
    {
        return $this->db->query(
            'SELECT b.*, p.name AS author_name FROM book b
             LEFT JOIN person p ON p.id=b.author_id ORDER BY b.title'
        )->fetchAll();
    }
    public function book(string $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM book WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    public function saveBook(array $d): string
    {
        $id = $d['id'] ?: ('book_' . paco_slug($d['title']));
        $st = $this->db->prepare(
            'INSERT INTO book (id,title,author_id,isbn13,nl_uri) VALUES (:id,:t,:a,:i,:nl)
             ON CONFLICT(id) DO UPDATE SET title=:t, author_id=:a, isbn13=:i, nl_uri=:nl'
        );
        $st->execute([
            ':id' => $id, ':t' => $d['title'],
            ':a' => $d['author_id'] !== '' ? $d['author_id'] : null,
            ':i' => $d['isbn13'] !== '' ? $d['isbn13'] : null,
            ':nl' => ($d['nl_uri'] ?? '') !== '' ? trim($d['nl_uri']) : null, // 국가서지LOD 시집 자원
        ]);
        return $id;
    }
    public function deleteBook(string $id): void
    {
        $this->db->prepare('DELETE FROM book WHERE id=?')->execute([$id]);
    }

    // ------------------------------------------------------------------ poem
    public function poems(): array
    {
        return $this->db->query(
            'SELECT pm.*, p.name AS author_name, b.title AS book_title FROM poem pm
             LEFT JOIN person p ON p.id=pm.author_id
             LEFT JOIN book b ON b.id=pm.book_id ORDER BY pm.title'
        )->fetchAll();
    }
    public function poem(string $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM poem WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    /** @return array<int,array> stanza_no,line_no,text */
    public function poemLines(string $poemId): array
    {
        $st = $this->db->prepare(
            'SELECT stanza_no,line_no,text FROM poem_line WHERE poem_id=? ORDER BY stanza_no,line_no'
        );
        $st->execute([$poemId]);
        return $st->fetchAll();
    }
    /** 연/행 구조: [stanza_no => [line_no => text]] */
    public function poemStanzas(string $poemId): array
    {
        $out = [];
        foreach ($this->poemLines($poemId) as $r) {
            $out[(int) $r['stanza_no']][(int) $r['line_no']] = $r['text'];
        }
        return $out;
    }
    public function savePoem(array $d, ?string $bodyInput = null): string
    {
        $id = $d['id'] ?: ('poem_' . paco_slug($d['title']));
        $st = $this->db->prepare(
            'INSERT INTO poem (id,title,author_id,book_id) VALUES (:id,:t,:a,:b)
             ON CONFLICT(id) DO UPDATE SET title=:t, author_id=:a, book_id=:b'
        );
        $st->execute([
            ':id' => $id, ':t' => $d['title'],
            ':a' => $d['author_id'] !== '' ? $d['author_id'] : null,
            ':b' => $d['book_id'] !== '' ? $d['book_id'] : null,
        ]);
        if ($bodyInput !== null) {
            $this->setPoemBody($id, $bodyInput);
        }
        return $id;
    }
    /**
     * 시 본문 저장 — 정식 소스는 pac 시 마크업 XML(<poem><stanza><line>…). (v0.4.0)
     * 입력은 XML 또는 평문(빈 줄=연, 줄바꿈=행) 모두 허용한다(하위 호환). 어느 쪽이든
     *   ① 연/행 구조로 파싱 → ② poem.body_xml 에 표준 XML 로 보관(단일 진실)
     *   ③ poem_line(좌측 표시·연/행 선택·LOD 선택자 좌표)을 그 구조에서 파생 저장.
     * 본문 자체는 LOD 로 발행하지 않는다(온톨로지 비훼손) — 연/행 좌표만 pac:TextSelection 에 쓰인다.
     */
    public function setPoemBody(string $poemId, string $input): void
    {
        $stanzas = self::parsePoemInput($input);
        $this->setPoemLines($poemId, $stanzas);
        $this->db->prepare('UPDATE poem SET body_xml=? WHERE id=?')
            ->execute([$stanzas ? self::buildPoemXml($stanzas) : null, $poemId]);
    }
    /** 연/행 구조([sNo=>[lNo=>text]])를 poem_line 으로 전량 교체 저장. */
    private function setPoemLines(string $poemId, array $stanzas): void
    {
        $this->db->prepare('DELETE FROM poem_line WHERE poem_id=?')->execute([$poemId]);
        if (!$stanzas) return;
        $ins = $this->db->prepare(
            'INSERT INTO poem_line (poem_id,stanza_no,line_no,text) VALUES (?,?,?,?)'
        );
        $sNo = 0;
        foreach ($stanzas as $lines) {
            $sNo++;
            $lNo = 0;
            foreach ($lines as $text) {
                $lNo++;
                $ins->execute([$poemId, $sNo, $lNo, rtrim((string) $text)]);
            }
        }
    }
    /**
     * 시 본문 정식 소스(XML)를 반환. body_xml 이 있으면 그대로, 없으면(구버전 시) poem_line 에서
     * 즉석 도출한다 — 그래서 v0.3 이하에서 만든 시도 편집기에서 XML 로 보인다(마이그레이션은 추가형).
     */
    public function poemBodyXml(string $poemId): string
    {
        $st = $this->db->prepare('SELECT body_xml FROM poem WHERE id=?');
        $st->execute([$poemId]);
        $xml = $st->fetchColumn();
        if (is_string($xml) && trim($xml) !== '') return $xml;
        $stanzas = $this->poemStanzas($poemId);
        return $stanzas ? self::buildPoemXml($stanzas) : '';
    }
    /** 평문 본문(빈 줄=연, 줄바꿈=행) — 구버전 호환·평문 보기용. */
    public function poemBodyText(string $poemId): string
    {
        $parts = [];
        foreach ($this->poemStanzas($poemId) as $lines) {
            $parts[] = implode("\n", $lines);
        }
        return implode("\n\n", $parts);
    }

    // ---- 시 마크업 XML (정식 소스) ↔ 연/행 구조 ----------------------------------
    /** 입력(XML 또는 평문)을 연/행 구조 [stanzaNo => [lineNo => text]] 로. */
    public static function parsePoemInput(string $input): array
    {
        $input = str_replace(["\r\n", "\r"], "\n", trim($input));
        if ($input === '') return [];
        // <poem>/<stanza>/<line> 형태면 XML 로, 아니면 평문으로 파싱(파싱 실패 시 평문 폴백).
        if (preg_match('/<\s*(poem|stanza|line)\b/i', $input)) {
            $xmlStanzas = self::parsePoemXml($input);
            if ($xmlStanzas !== null) return $xmlStanzas;
        }
        return self::parsePoemPlain($input);
    }
    /** pac 시 마크업 XML → 연/행 구조. 실패 시 null(=평문 폴백). */
    private static function parsePoemXml(string $xml): ?array
    {
        // 손으로 입력한 XML 의 흔한 오류(이스케이프 안 한 맨 '&')를 관용적으로 보정한다.
        // 이미 엔티티(&amp; &#123; 등)인 '&' 는 건드리지 않는다. 우리가 생성한 표준형은 항상 적격.
        $xml = preg_replace('/&(?!#?[0-9A-Za-z]+;)/', '&amp;', $xml);
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // 루트가 없을 수도 있으니 <poem> 으로 감싸 안전하게 로드(이미 <poem> 이면 중첩돼도 stanza 탐색엔 무해).
        $ok = $dom->loadXML('<poem-wrap>' . $xml . '</poem-wrap>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) return null;
        $stanzaEls = $dom->getElementsByTagName('stanza');
        $out = [];
        $sNo = 0;
        if ($stanzaEls->length > 0) {
            foreach ($stanzaEls as $st) {
                $sNo++;
                $lNo = 0;
                foreach ($st->getElementsByTagName('line') as $ln) {
                    $lNo++;
                    $out[$sNo][$lNo] = trim($ln->textContent);
                }
                if ($lNo === 0) { $sNo--; } // 빈 연은 건너뜀
            }
        } else {
            // <stanza> 없이 <line> 만 있으면 한 연으로 취급
            $lineEls = $dom->getElementsByTagName('line');
            if ($lineEls->length === 0) return null;
            $sNo = 1; $lNo = 0;
            foreach ($lineEls as $ln) { $out[1][++$lNo] = trim($ln->textContent); }
        }
        return $out;
    }
    /** 평문(빈 줄=연, 줄바꿈=행) → 연/행 구조. */
    private static function parsePoemPlain(string $text): array
    {
        $stanzas = preg_split('/\n[ \t]*\n+/', $text);
        $out = [];
        $sNo = 0;
        foreach ($stanzas as $stanza) {
            $lines = explode("\n", trim($stanza, "\n"));
            if (count($lines) === 1 && trim($lines[0]) === '') continue;
            $sNo++;
            $lNo = 0;
            foreach ($lines as $line) { $out[$sNo][++$lNo] = rtrim($line); }
        }
        return $out;
    }
    /** 연/행 구조 → pac 시 마크업 XML(표준형, n 속성은 문서순서로 생성). */
    public static function buildPoemXml(array $stanzas): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sNo = 0;
        $out = "<poem>\n";
        foreach ($stanzas as $lines) {
            $sNo++;
            $out .= '  <stanza n="' . $sNo . "\">\n";
            $lNo = 0;
            foreach ($lines as $text) {
                $lNo++;
                $t = $esc(rtrim((string) $text));
                $out .= '    <line n="' . $lNo . '">' . $t . "</line>\n";
            }
            $out .= "  </stanza>\n";
        }
        return $out . '</poem>';
    }
    public function deletePoem(string $id): void
    {
        $this->db->prepare('DELETE FROM poem WHERE id=?')->execute([$id]);
    }

    // --------------------------------------------------------------- article
    public function articles(): array
    {
        return $this->db->query(
            'SELECT a.*, p.name AS author_name,
                    (SELECT COUNT(*) FROM quotation q WHERE q.article_id=a.id) AS n_quot
             FROM article a LEFT JOIN person p ON p.id=a.author_id
             ORDER BY a.created DESC, a.title'
        )->fetchAll();
    }
    public function article(string $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM article WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    /** cito:critiques 대상의 표시 라벨 + 종류 */
    public function critiquesLabel(array $article): array
    {
        $kind = $article['critiques_kind'] ?? null;
        $cid  = $article['critiques_id'] ?? null;
        if (!$kind || !$cid) return ['kind' => null, 'id' => null, 'title' => null];
        $title = null;
        if ($kind === 'poem')  { $row = $this->poem($cid); $title = $row['title'] ?? null; }
        if ($kind === 'book')  { $row = $this->book($cid); $title = $row['title'] ?? null; }
        return ['kind' => $kind, 'id' => $cid, 'title' => $title];
    }
    public function saveArticle(array $d): string
    {
        $id = $d['id'] ?: ('article_' . paco_slug($d['title']) . '_' . date('Ymd'));
        $st = $this->db->prepare(
            'INSERT INTO article (id,title,author_id,created,critiques_kind,critiques_id,full_text,updated_at)
             VALUES (:id,:t,:a,:cr,:ck,:ci,:ft,:up)
             ON CONFLICT(id) DO UPDATE SET
               title=:t, author_id=:a, created=:cr, critiques_kind=:ck,
               critiques_id=:ci, full_text=:ft, updated_at=:up'
        );
        $st->execute([
            ':id' => $id, ':t' => $d['title'],
            ':a' => $d['author_id'] !== '' ? $d['author_id'] : null,
            ':cr' => $d['created'] !== '' ? $d['created'] : null,
            ':ck' => $d['critiques_kind'] !== '' ? $d['critiques_kind'] : null,
            ':ci' => $d['critiques_id'] !== '' ? $d['critiques_id'] : null,
            ':ft' => $d['full_text'] ?? '',
            ':up' => date('c'),
        ]);
        return $id;
    }
    public function deleteArticle(string $id): void
    {
        $this->db->prepare('DELETE FROM article WHERE id=?')->execute([$id]);
    }

    // ------------------------------------------------------------- quotation
    /** @return array<int,array> 한 비평문의 인용들 (대상 포함) */
    public function quotations(string $articleId): array
    {
        $st = $this->db->prepare('SELECT * FROM quotation WHERE article_id=? ORDER BY sort_order, anchor');
        $st->execute([$articleId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$q) {
            $q['targets'] = $this->quotationTargets($q['id']);
        }
        return $rows;
    }
    public function quotation(string $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM quotation WHERE id=?');
        $st->execute([$id]);
        $q = $st->fetch();
        if (!$q) return null;
        $q['targets'] = $this->quotationTargets($id);
        return $q;
    }
    public function quotationTargets(string $quotationId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM quotation_target WHERE quotation_id=? ORDER BY COALESCE(target_order,0), id'
        );
        $st->execute([$quotationId]);
        return $st->fetchAll();
    }
    public function nextQuotationId(string $articleId): string
    {
        // q1, q2 … 충돌 시 증가. 전역 유일.
        $n = 1;
        do {
            $id = 'q' . $n;
            $st = $this->db->prepare('SELECT 1 FROM quotation WHERE id=?');
            $st->execute([$id]);
            $n++;
        } while ($st->fetch());
        return $id;
    }
    /**
     * 인용 저장. $d 에 quotation 필드 + 'targets' => [ [source_kind,source_id,start_stanza,...], ... ]
     */
    public function saveQuotation(array $d): string
    {
        $this->db->beginTransaction();
        try {
            $id = $d['id'] ?: $this->nextQuotationId($d['article_id']);
            $st = $this->db->prepare(
                'INSERT INTO quotation (id,article_id,qtype,anchor,sort_order)
                 VALUES (:id,:aid,:ty,:an,:so)
                 ON CONFLICT(id) DO UPDATE SET
                   article_id=:aid, qtype=:ty, anchor=:an, sort_order=:so'
            );
            $st->execute([
                ':id' => $id, ':aid' => $d['article_id'],
                ':ty' => $d['qtype'] === 'direct' ? 'direct' : 'indirect',
                ':an' => $d['anchor'],
                ':so' => (int) ($d['sort_order'] ?? 0),
            ]);
            // 대상 전량 교체
            $this->db->prepare('DELETE FROM quotation_target WHERE quotation_id=?')->execute([$id]);
            $targets = $d['targets'] ?? [];
            $multi = count($targets) > 1;
            $ord = 0;
            $ins = $this->db->prepare(
                'INSERT INTO quotation_target
                   (quotation_id,target_order,source_kind,source_id,start_stanza,end_stanza,start_line,end_line,exact)
                 VALUES (:q,:o,:sk,:si,:ss,:es,:sl,:el,:ex)'
            );
            foreach ($targets as $t) {
                if (($t['source_id'] ?? '') === '') continue;
                $ord++;
                $ins->execute([
                    ':q' => $id,
                    ':o' => $multi ? $ord : ($t['target_order'] !== '' && isset($t['target_order']) ? (int) $t['target_order'] : null),
                    ':sk' => $t['source_kind'] ?: 'poem',
                    ':si' => $t['source_id'],
                    ':ss' => self::intOrNull($t['start_stanza'] ?? ''),
                    ':es' => self::intOrNull($t['end_stanza'] ?? ''),
                    ':sl' => self::intOrNull($t['start_line'] ?? ''),
                    ':el' => self::intOrNull($t['end_line'] ?? ''),
                    ':ex' => ($t['exact'] ?? '') !== '' ? $t['exact'] : null,
                ]);
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    public function deleteQuotation(string $id): void
    {
        $this->db->prepare('DELETE FROM quotation WHERE id=?')->execute([$id]);
    }

    private static function intOrNull($v): ?int
    {
        return ($v === '' || $v === null) ? null : (int) $v;
    }

    // --------------------------------------------------------------- queries
    /** 내가 비평한 시인 + 빈도 (질의 7.4) */
    public function critiquedPoets(): array
    {
        // article → critiques(poem|book) → author(poet)
        $sql = <<<'SQL'
        SELECT p.id, p.name, p.same_as, COUNT(*) AS n FROM article a
        JOIN (
            SELECT id AS work_id, author_id FROM poem
            UNION ALL
            SELECT id AS work_id, author_id FROM book
        ) w ON w.work_id = a.critiques_id
        JOIN person p ON p.id = w.author_id AND p.is_poet = 1
        GROUP BY p.id ORDER BY n DESC, p.name
        SQL;
        return $this->db->query($sql)->fetchAll();
    }

    public function counts(): array
    {
        $c = fn(string $t) => (int) $this->db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        return [
            'poet'     => (int) $this->db->query('SELECT COUNT(*) FROM person WHERE is_poet=1')->fetchColumn(),
            'critic'   => (int) $this->db->query('SELECT COUNT(*) FROM person WHERE is_critic=1')->fetchColumn(),
            'book'     => $c('book'),
            'poem'     => $c('poem'),
            'article'  => $c('article'),
            'quotation' => $c('quotation'),
        ];
    }
}
