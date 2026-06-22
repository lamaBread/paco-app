<?php
/**
 * 추론 질의 — '비평가의 거울과 나침반' (v0.7.0).
 *
 * 외부 전기 사실을 긁어오던 옛 추론 질의(7.x)를 폐기하고, 비평가 자신이 생산한
 * 발행 그래프(비평문·인용·연/행 좌표)를 *거울* 로 비추고, 국가서지LOD 프로파일로
 * 편식을 측정해 *나침반*(다음에 읽을 자료·시인)을 가리킨다.
 *
 *   거울(Mirror) — 네트워크 불필요, 로컬 발행 그래프만:
 *     M1 인용 방식 거울      — 직접/간접 인용 비율(전체·비평문별)
 *     M2 텍스트 밀착도        — 비평문별 인용 선택 수·커버한 연 범위
 *     M3 인용 위치 편향        — 시의 처음/중간/마지막 중 어디를 인용하나
 *     M4 편식 지도            — 출생 세대·활동분야 분포(국가서지LOD nl_fact)
 *   나침반(Compass):
 *     C1 비평 공백            — 등록만 하고 아직 비평 안 한 내 시/시집(로컬 안티조인)
 *     C2 근거 있는 다음 시인  — 국가서지LOD 분야×세대 후보 풀(nl_candidate)에서,
 *                              비평한 시인을 제외하고 내가 비운 세대를 채울 시인 추천
 *
 * 이 클래스는 읽기/집계만 한다(네트워크 fetch 는 NlLod 가 담당). 모든 메서드는
 * 데이터가 없으면 빈 배열/0 을 돌려주어 화면이 비어도 깨지지 않는다.
 */

namespace PACO;

use PDO;

final class Insights
{
    public function __construct(private Repo $repo)
    {
    }

    private function pdo(): PDO
    {
        return $this->repo->pdo();
    }

    // ============================================================ 상단 분포 요약
    /** 헤더용 한눈 요약: 비평문·시인·인용(직접/간접) 합계. */
    public function summary(): array
    {
        $db = $this->pdo();
        $n = fn(string $sql): int => (int) $db->query($sql)->fetchColumn();
        $direct   = $n("SELECT COUNT(*) FROM quotation WHERE qtype='direct'");
        $indirect = $n("SELECT COUNT(*) FROM quotation WHERE qtype='indirect'");
        return [
            'articles'  => $n('SELECT COUNT(*) FROM article'),
            'poets'     => count($this->repo->critiquedPoets()),
            'direct'    => $direct,
            'indirect'  => $indirect,
            'quotations' => $direct + $indirect,
        ];
    }

    // ============================================================ M1 인용 방식 거울
    /**
     * 직접 vs 간접 인용. 전체 합계와 비평문별 분해를 함께 돌려준다.
     * @return array{overall:array{direct:int,indirect:int}, perArticle:array<int,array>}
     */
    public function quotationStyle(): array
    {
        $db = $this->pdo();
        $overall = ['direct' => 0, 'indirect' => 0];
        foreach ($db->query("SELECT qtype, COUNT(*) n FROM quotation GROUP BY qtype") as $r) {
            $k = $r['qtype'] === 'direct' ? 'direct' : 'indirect';
            $overall[$k] += (int) $r['n'];
        }
        $sql = <<<'SQL'
        SELECT a.id, a.title, a.created,
               SUM(CASE WHEN q.qtype='direct'   THEN 1 ELSE 0 END) AS direct,
               SUM(CASE WHEN q.qtype='indirect' THEN 1 ELSE 0 END) AS indirect,
               COUNT(q.id) AS total
        FROM article a
        LEFT JOIN quotation q ON q.article_id = a.id
        GROUP BY a.id
        ORDER BY a.created DESC, a.title
        SQL;
        $perArticle = [];
        foreach ($db->query($sql) as $r) {
            $perArticle[] = [
                'id' => $r['id'], 'title' => $r['title'],
                'direct' => (int) $r['direct'], 'indirect' => (int) $r['indirect'],
                'total' => (int) $r['total'],
            ];
        }
        return ['overall' => $overall, 'perArticle' => $perArticle];
    }

    // ============================================================ M2 텍스트 밀착도
    /**
     * 시를 비평한 비평문별로 close-reading 깊이: 인용 수, 선택(연/행 선택자) 수,
     * 시에서 실제로 다룬 연의 집합과 시 전체 연 대비 커버리지.
     * 시(poem) 를 대상으로 한 비평문만(시집 대상은 연 좌표가 없어 제외).
     * @return array<int,array>
     */
    public function closeReading(): array
    {
        $db = $this->pdo();
        $totalStanzas = $this->poemStanzaCounts();

        // 시를 대상으로 한 비평문 + 그 시 제목·시인
        $arts = $db->query(<<<'SQL'
            SELECT a.id AS article_id, a.title AS article_title,
                   pm.id AS poem_id, pm.title AS poem_title,
                   p.name AS poet
            FROM article a
            JOIN poem pm   ON a.critiques_kind='poem' AND a.critiques_id = pm.id
            LEFT JOIN person p ON p.id = pm.author_id
            ORDER BY a.created DESC, a.title
        SQL)->fetchAll();

        $tgtSt = $db->prepare(<<<'SQL'
            SELECT qt.start_stanza, qt.end_stanza
            FROM quotation q
            JOIN quotation_target qt ON qt.quotation_id = q.id
            WHERE q.article_id = ? AND qt.source_kind='poem' AND qt.source_id = ?
              AND qt.start_stanza IS NOT NULL
        SQL);
        $qCount = $db->prepare('SELECT COUNT(*) FROM quotation WHERE article_id=?');

        $out = [];
        foreach ($arts as $a) {
            $tgtSt->execute([$a['article_id'], $a['poem_id']]);
            $touched = [];
            $selections = 0;
            foreach ($tgtSt->fetchAll() as $t) {
                $selections++;
                $s = (int) $t['start_stanza'];
                $e = $t['end_stanza'] !== null ? (int) $t['end_stanza'] : $s;
                if ($e < $s) [$s, $e] = [$e, $s];
                for ($i = $s; $i <= $e; $i++) $touched[$i] = true;
            }
            $qCount->execute([$a['article_id']]);
            $total = $totalStanzas[$a['poem_id']] ?? 0;
            $out[] = [
                'article_id' => $a['article_id'], 'article_title' => $a['article_title'],
                'poem_id' => $a['poem_id'], 'poem_title' => $a['poem_title'], 'poet' => $a['poet'],
                'quotations' => (int) $qCount->fetchColumn(),
                'selections' => $selections,
                'stanzas_touched' => count($touched),
                'stanzas_total' => $total,
            ];
        }
        // 얕은 비평(선택 적음)부터 위로 — 손봐야 할 것이 먼저 보이게.
        usort($out, fn($x, $y) => $x['selections'] <=> $y['selections']);
        return $out;
    }

    // ============================================================ M3 인용 위치 편향
    /**
     * 인용 선택의 *시작 연* 이 시의 처음/중간/마지막 중 어디인가의 분포.
     * 시 전체 연 수는 내부 poem_line 에서 얻는다(LOD 비발행 좌표지만 내부 계산엔 사용).
     * @return array{buckets:array<string,int>, total:int}
     */
    public function positionBias(): array
    {
        $db = $this->pdo();
        $totalStanzas = $this->poemStanzaCounts();
        $rows = $db->query(<<<'SQL'
            SELECT qt.source_id AS poem_id, qt.start_stanza
            FROM quotation_target qt
            JOIN quotation q ON q.id = qt.quotation_id
            WHERE qt.source_kind='poem' AND qt.start_stanza IS NOT NULL
        SQL)->fetchAll();

        $buckets = ['처음' => 0, '중간' => 0, '마지막' => 0, '단연' => 0];
        $total = 0;
        foreach ($rows as $r) {
            $t = $totalStanzas[$r['poem_id']] ?? 0;
            $s = (int) $r['start_stanza'];
            $total++;
            if ($t <= 1) { $buckets['단연']++; continue; }
            if ($s <= 1)      $buckets['처음']++;
            elseif ($s >= $t) $buckets['마지막']++;
            else              $buckets['중간']++;
        }
        return ['buckets' => $buckets, 'total' => $total];
    }

    // ============================================================ M4 편식 지도
    /**
     * 내가 비평한 시인을 출생 세대(국가서지LOD 생몰년)·활동분야로 묶은 분포.
     * 사각지대(빈 칸)가 곧 C2 추천의 입력이 된다.
     * @return array{decades:array<string,array>, fields:array<string,int>,
     *               noProfile:array<int,string>, coveredFields:array<int,string>,
     *               coveredDecades:array<int,int>}
     */
    public function biasMap(): array
    {
        $db = $this->pdo();
        $poets = $this->repo->critiquedPoets();
        $factSt = $db->prepare('SELECT prop_label, value_label FROM nl_fact WHERE person_id=?');
        // 사조(P135)·장르(P136)는 국가서지LOD 에 없어 Wikidata 보강 캐시에서 읽는다(v0.8).
        $wdSt = null;
        try {
            $wdSt = $db->prepare("SELECT prop_pid, value_label FROM wikidata_fact WHERE person_id=? AND prop_pid IN ('P135','P136')");
        } catch (\Throwable $e) { /* 구버전 DB: 캐시 테이블 없음 → 사조/장르 축 생략 */ }

        $decades = [];      // '1980년대' => [이름…]
        $fields = [];       // '한국 시' => 시인 수
        $movements = [];    // '모더니즘' => 시인 수 (Wikidata P135)
        $genres = [];       // '서정시' => 시인 수 (Wikidata P136)
        $noProfile = [];
        $coveredDecades = [];

        foreach ($poets as $p) {
            // 사조(P135)·장르(P136)는 국가서지LOD 프로파일과 독립적인 Wikidata 보강이다.
            // nl_fact 가 없어 아래에서 continue 하더라도 이 축은 잡혀야 하므로 먼저 읽는다.
            if ($wdSt) {
                $wdSt->execute([$p['id']]);
                $seenMv = $seenGn = [];
                foreach ($wdSt->fetchAll() as $f) {
                    $val = trim((string) $f['value_label']);
                    if ($val === '') continue;
                    if ($f['prop_pid'] === 'P135' && !isset($seenMv[$val])) {
                        $seenMv[$val] = true; $movements[$val] = ($movements[$val] ?? 0) + 1;
                    } elseif ($f['prop_pid'] === 'P136' && !isset($seenGn[$val])) {
                        $seenGn[$val] = true; $genres[$val] = ($genres[$val] ?? 0) + 1;
                    }
                }
            }
            $factSt->execute([$p['id']]);
            $rows = $factSt->fetchAll();
            if (!$rows) { $noProfile[] = $p['name']; continue; }
            // 생몰년 없이 활동분야만 있는 시인은 fields 에만 잡힌다(noProfile 아님).
            $seenFields = [];
            foreach ($rows as $f) {
                if ($f['prop_label'] === '생몰년') {
                    $dec = self::decadeOf($f['value_label']);
                    if ($dec !== null) {
                        $decades[$dec . '년대'][] = $p['name'];
                        $coveredDecades[$dec] = true;
                    }
                } elseif ($f['prop_label'] === '활동분야') {
                    $val = trim((string) $f['value_label']);
                    if ($val !== '' && !isset($seenFields[$val])) {
                        $seenFields[$val] = true;
                        $fields[$val] = ($fields[$val] ?? 0) + 1;
                    }
                }
            }
        }
        ksort($decades);
        arsort($fields);
        arsort($movements);
        arsort($genres);
        return [
            'decades' => $decades,
            'fields' => $fields,
            'movements' => $movements,
            'genres' => $genres,
            'noProfile' => $noProfile,
            'coveredFields' => array_keys($fields),
            'coveredMovements' => array_keys($movements),
            'coveredDecades' => array_map('intval', array_keys($coveredDecades)),
        ];
    }

    // ============================================================ C1 비평 공백
    /**
     * 등록만 하고 아직 비평문이 없는 내 시/시집.
     * @return array{poems:array<int,array>, books:array<int,array>}
     */
    public function uncritiquedHoldings(): array
    {
        $db = $this->pdo();
        $poems = $db->query(<<<'SQL'
            SELECT pm.id, pm.title, p.name AS poet, b.title AS book_title
            FROM poem pm
            LEFT JOIN person p ON p.id = pm.author_id
            LEFT JOIN book b   ON b.id = pm.book_id
            WHERE NOT EXISTS (
                SELECT 1 FROM article a WHERE a.critiques_kind='poem' AND a.critiques_id = pm.id
            )
            ORDER BY p.name, pm.title
        SQL)->fetchAll();
        $books = $db->query(<<<'SQL'
            SELECT b.id, b.title, p.name AS poet
            FROM book b
            LEFT JOIN person p ON p.id = b.author_id
            WHERE NOT EXISTS (
                SELECT 1 FROM article a WHERE a.critiques_kind='book' AND a.critiques_id = b.id
            )
            ORDER BY p.name, b.title
        SQL)->fetchAll();
        return ['poems' => $poems, 'books' => $books];
    }

    // ============================================================ C2 근거 있는 다음 시인
    /**
     * 국가서지LOD 후보 풀(nl_candidate)에서 추천. 이미 등록/비평한 시인은 이름·nl_uri 로 제외.
     * 내가 *비운* 세대(M4 의 coveredDecades 에 없는 세대)를 먼저, 같은 활동분야 안에서.
     * 각 추천은 사람이 읽을 사유 문자열을 단다.
     * @return array<int,array{nl_uri:string,name:string,birth_year:?int,field:string,
     *                         why:string,fills_gap:bool}>
     */
    public function recommendations(int $limit = 24): array
    {
        $db = $this->pdo();
        // nl_candidate 가 아직 없으면(구버전 DB) 조용히 빈 배열.
        try {
            $cands = $db->query(<<<'SQL'
                SELECT c.nl_uri, c.name, c.birth_year, c.field
                FROM nl_candidate c
                WHERE c.name IS NOT NULL AND c.name <> ''
                  AND c.name NOT IN (SELECT name FROM person WHERE is_poet=1)
                  AND (c.nl_uri IS NULL OR c.nl_uri NOT IN (
                        SELECT nl_uri FROM person WHERE nl_uri IS NOT NULL AND nl_uri <> ''))
                GROUP BY c.nl_uri
            SQL)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        if (!$cands) return [];

        $covered = array_flip($this->biasMap()['coveredDecades']); // 이미 본 세대
        $out = [];
        foreach ($cands as $c) {
            $by = $c['birth_year'] !== null ? (int) $c['birth_year'] : null;
            $dec = $by !== null ? intdiv($by, 10) * 10 : null;
            $fillsGap = $dec !== null && !isset($covered[$dec]);
            $whyBits = [];
            if ($c['field']) $whyBits[] = '활동분야 ' . $c['field'];
            if ($dec !== null) $whyBits[] = $dec . '년대생' . ($fillsGap ? '(내가 비운 세대)' : '');
            $whyBits[] = '아직 비평 안 함';
            $out[] = [
                'nl_uri' => $c['nl_uri'], 'name' => $c['name'],
                'birth_year' => $by, 'field' => (string) $c['field'],
                'why' => implode(' · ', $whyBits), 'fills_gap' => $fillsGap,
            ];
        }
        // 빈 세대를 채우는 후보 먼저, 그다음 출생연도(최근)·이름 순.
        usort($out, function ($a, $b) {
            return ($b['fills_gap'] <=> $a['fills_gap'])
                ?: (($b['birth_year'] ?? 0) <=> ($a['birth_year'] ?? 0))
                ?: strcmp($a['name'], $b['name']);
        });
        return array_slice($out, 0, $limit);
    }

    // ====================================================== C3 사조·계보로 잇는 다음 시인
    /**
     * 내가 비평한 시인과 문예사조·특정 직업을 공유하지만 아직 내 컬렉션에 없는 Wikidata 시인.
     * 7.0 에서 폐기됐던 '비슷한 시인'을, 무의미한 일반 직업(시인·작가 등)을 걸러 사조/계보 중심으로
     * 되살린 것. wikidata_similar 프리페치 캐시 기반(여러 시인과 겹칠수록 위로). 캐시가 없으면 빈 배열.
     * @return array<int,array{uri:string,name:string,vias:array<int,string>,shared_with:int,why:string}>
     */
    public function movementRecommendations(int $limit = 24): array
    {
        $db = $this->pdo();
        try {
            $st = $db->prepare(<<<'SQL'
                SELECT s.similar_iri AS uri, MAX(s.similar_label) AS name,
                       COUNT(DISTINCT s.person_id) AS shared_with,
                       GROUP_CONCAT(DISTINCT s.via_label) AS vias
                FROM wikidata_similar s
                WHERE s.similar_label IS NOT NULL AND s.similar_label <> ''
                  AND s.via_label IS NOT NULL
                  AND s.via_label NOT IN ('시인','작가','문인','저술가','소설가','poet','writer','author','novelist')
                  AND s.similar_iri NOT IN (
                      SELECT same_as FROM person WHERE same_as IS NOT NULL AND same_as <> '')
                GROUP BY s.similar_iri
                ORDER BY shared_with DESC, name
                LIMIT :lim
            SQL);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $vias = array_values(array_filter(array_map('trim', explode(',', (string) $r['vias']))));
            $why = ($vias ? implode(' · ', array_slice($vias, 0, 3)) . ' 공유' : '사조·계보 공유')
                . ' · 내가 비평한 시인 ' . (int) $r['shared_with'] . '명과 겹침 · 아직 비평 안 함';
            $out[] = [
                'uri' => $r['uri'], 'name' => (string) $r['name'],
                'vias' => $vias, 'shared_with' => (int) $r['shared_with'], 'why' => $why,
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------- 헬퍼
    /** 시 id → 전체 연 수(poem_line 기준). */
    private function poemStanzaCounts(): array
    {
        $out = [];
        $rows = $this->pdo()->query(
            'SELECT poem_id, MAX(stanza_no) AS n FROM poem_line GROUP BY poem_id'
        );
        foreach ($rows as $r) $out[$r['poem_id']] = (int) $r['n'];
        return $out;
    }

    /** '1973–' · '1988–' · '1950–2010' 등에서 출생 10년대(예: 1970) 추출. 실패 시 null. */
    public static function decadeOf(?string $lifespan): ?int
    {
        if (!$lifespan) return null;
        if (!preg_match('/(\d{4})/', $lifespan, $m)) return null;
        return intdiv((int) $m[1], 10) * 10;
    }
}
