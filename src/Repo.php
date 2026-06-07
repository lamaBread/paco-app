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
            'INSERT INTO book (id,title,author_id,isbn13) VALUES (:id,:t,:a,:i)
             ON CONFLICT(id) DO UPDATE SET title=:t, author_id=:a, isbn13=:i'
        );
        $st->execute([
            ':id' => $id, ':t' => $d['title'],
            ':a' => $d['author_id'] !== '' ? $d['author_id'] : null,
            ':i' => $d['isbn13'] !== '' ? $d['isbn13'] : null,
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
    public function savePoem(array $d, ?string $bodyText = null): string
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
        if ($bodyText !== null) {
            $this->setPoemBody($id, $bodyText);
        }
        return $id;
    }
    /**
     * 시 본문 텍스트를 연/행으로 분해해 저장.
     * 규약: 빈 줄(\n\n)로 연 구분, 줄바꿈으로 행 구분.
     */
    public function setPoemBody(string $poemId, string $text): void
    {
        $this->db->prepare('DELETE FROM poem_line WHERE poem_id=?')->execute([$poemId]);
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') return;
        $stanzas = preg_split('/\n[ \t]*\n+/', $text);
        $ins = $this->db->prepare(
            'INSERT INTO poem_line (poem_id,stanza_no,line_no,text) VALUES (?,?,?,?)'
        );
        $sNo = 0;
        foreach ($stanzas as $stanza) {
            $lines = explode("\n", trim($stanza, "\n"));
            if (count($lines) === 1 && trim($lines[0]) === '') continue;
            $sNo++;
            $lNo = 0;
            foreach ($lines as $line) {
                $lNo++;
                $ins->execute([$poemId, $sNo, $lNo, rtrim($line)]);
            }
        }
    }
    public function poemBodyText(string $poemId): string
    {
        $stanzas = $this->poemStanzas($poemId);
        $parts = [];
        foreach ($stanzas as $lines) {
            $parts[] = implode("\n", $lines);
        }
        return implode("\n\n", $parts);
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
