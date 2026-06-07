<?php
/**
 * Wikidata 프리페치 캐시.
 *
 * owl:sameAs 로 연결된 시인에 대해 거주지(P551)·수상(P166)·직업(P106)을 받아
 * wikidata_fact 에, 같은 직업으로 묶이는 다른 시인(시나리오 7.5)을
 * wikidata_similar 에 캐시한다. 정적 빌드/오프라인에서도 이 캐시로 추론 질의
 * 결과를 보여줄 수 있다.
 *
 * 네트워크 실패는 조용히 요약에 담아 반환한다(서버를 막지 않음).
 */

namespace PACO;

final class Wikidata
{
    public function __construct(private Repo $repo, private array $cfg) {}

    public static function qid(?string $sameAs): ?string
    {
        if (!$sameAs) return null;
        if (preg_match('/(Q\d+)\b/', $sameAs, $m)) return $m[1];
        return null;
    }

    /** 모든 (owl:sameAs 있는) 시인 갱신. @return array 요약 */
    public function refreshAll(): array
    {
        $summary = ['poets' => 0, 'facts' => 0, 'similar' => 0, 'skipped' => 0, 'errors' => []];
        $db = $this->repo->pdo();
        foreach ($this->repo->poets() as $poet) {
            $qid = self::qid($poet['same_as']);
            if (!$qid) { $summary['skipped']++; continue; }
            $summary['poets']++;
            try {
                $f = $this->fetchFacts($poet['id'], $qid);
                $summary['facts'] += $f;
                $s = $this->fetchSimilar($poet['id'], $qid);
                $summary['similar'] += $s;
            } catch (\Throwable $e) {
                $summary['errors'][] = $poet['name'] . ': ' . $e->getMessage();
            }
        }
        return $summary;
    }

    /** v0.4 프로파일 속성: 출생·사망·국적·직업·사조·장르·수상·거주지·영향·대표작 */
    private const FACT_PROPS = ['P569', 'P570', 'P27', 'P106', 'P135', 'P136', 'P166', 'P551', 'P737', 'P800'];

    private function fetchFacts(string $personId, string $qid): int
    {
        $lang  = $this->cfg['wikidata']['lang'];
        $props = 'wd:' . implode(' wd:', self::FACT_PROPS);
        $q = <<<SPARQL
        SELECT ?pid ?propLabel ?v ?vLabel WHERE {
          VALUES ?prop { $props }
          ?prop wikibase:directClaim ?dc .
          wd:$qid ?dc ?v .
          BIND(STRAFTER(STR(?prop), "entity/") AS ?pid)
          SERVICE wikibase:label { bd:serviceParam wikibase:language "$lang,en".
            ?prop rdfs:label ?propLabel. ?v rdfs:label ?vLabel. }
        }
        SPARQL;
        $rows = $this->sparql($q);
        $db = $this->repo->pdo();
        $db->prepare('DELETE FROM wikidata_fact WHERE person_id=?')->execute([$personId]);
        $ins = $db->prepare(
            'INSERT INTO wikidata_fact (person_id,prop_pid,prop_label,value_iri,value_label,fetched_at)
             VALUES (?,?,?,?,?,?)'
        );
        $n = 0;
        foreach ($rows as $r) {
            $vval = $r['v']['value'] ?? null;
            $vlab = $r['vLabel']['value'] ?? null;
            // 날짜 값(P569/P570 등)은 연도만 표시 — 라벨 서비스가 ISO 문자열을 줄 때도 덮어쓴다
            if ($vval !== null && preg_match('/^(-?\d{3,4})-\d{2}-\d{2}T/', (string) $vval, $m)) {
                $vlab = $m[1] . '년';
            }
            $ins->execute([
                $personId, $r['pid']['value'] ?? '', $r['propLabel']['value'] ?? null,
                $vval, $vlab, date('c'),
            ]);
            $n++;
        }
        return $n;
    }

    private function fetchSimilar(string $personId, string $qid): int
    {
        // 시나리오 7.5 — 직업(P106) 또는 사조·운동(P135)을 공유하며 직업이 시인(Q49757)인 다른 인물.
        $lang = $this->cfg['wikidata']['lang'];
        $q = <<<SPARQL
        SELECT DISTINCT ?similar ?similarLabel ?shared ?sharedLabel WHERE {
          VALUES ?p { wdt:P106 wdt:P135 }
          wd:$qid ?p ?shared .
          ?similar ?p ?shared ; wdt:P106 wd:Q49757 .
          FILTER(?similar != wd:$qid)
          SERVICE wikibase:label { bd:serviceParam wikibase:language "$lang,en".
            ?similar rdfs:label ?similarLabel . ?shared rdfs:label ?sharedLabel . }
        }
        LIMIT 60
        SPARQL;
        $rows = $this->sparql($q);
        $db = $this->repo->pdo();
        $db->prepare('DELETE FROM wikidata_similar WHERE person_id=?')->execute([$personId]);
        $ins = $db->prepare(
            'INSERT INTO wikidata_similar (person_id,via_pid,via_label,similar_iri,similar_label,fetched_at)
             VALUES (?,?,?,?,?,?)'
        );
        $n = 0;
        foreach ($rows as $r) {
            $ins->execute([
                $personId,
                self::qid($r['shared']['value'] ?? null),
                $r['sharedLabel']['value'] ?? null,
                $r['similar']['value'] ?? '',
                $r['similarLabel']['value'] ?? null,
                date('c'),
            ]);
            $n++;
        }
        return $n;
    }

    /** @return array<int,array> SPARQL JSON bindings */
    private function sparql(string $query): array
    {
        $w = $this->cfg['wikidata'];
        $url = $w['endpoint'] . '?format=json&query=' . rawurlencode($query);
        if (!function_exists('curl_init')) {
            // curl 없으면 stream 으로 폴백
            $ctx = stream_context_create(['http' => [
                'header'  => "Accept: application/sparql-results+json\r\nUser-Agent: {$w['user_agent']}\r\n",
                'timeout' => $w['timeout'],
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new \RuntimeException('네트워크 요청 실패(file_get_contents)');
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $w['timeout'],
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/sparql-results+json',
                    'User-Agent: ' . $w['user_agent'],
                ],
            ]);
            $body = curl_exec($ch);
            if ($body === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException('네트워크 요청 실패: ' . $err);
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) throw new \RuntimeException("Wikidata HTTP $code");
        }
        $json = json_decode($body, true);
        return $json['results']['bindings'] ?? [];
    }

    // ---- 표시용 조회 ----
    public function factsByPerson(string $personId): array
    {
        $st = $this->repo->pdo()->prepare(
            'SELECT * FROM wikidata_fact WHERE person_id=? ORDER BY prop_pid, value_label'
        );
        $st->execute([$personId]);
        return $st->fetchAll();
    }
    public function similarByPerson(string $personId): array
    {
        $st = $this->repo->pdo()->prepare(
            'SELECT * FROM wikidata_similar WHERE person_id=? ORDER BY similar_label LIMIT 40'
        );
        $st->execute([$personId]);
        return $st->fetchAll();
    }
    public function lastFetched(): ?string
    {
        $v = $this->repo->pdo()->query('SELECT MAX(fetched_at) FROM wikidata_fact')->fetchColumn();
        return $v ?: null;
    }

    /**
     * 시나리오 7.7 — 추천(gap 분석): 유사 시인 중 내 컬렉션에 아직 없는 시인을,
     * 여러 비평 대상과 겹칠수록 높은 점수로. "다음에 읽을 시인".
     * @return array<int,array{similar_iri,similar_label,score,vias,via_for}>
     */
    public function recommendations(int $limit = 24): array
    {
        $sql = <<<'SQL'
        SELECT s.similar_iri, MAX(s.similar_label) AS similar_label,
               COUNT(DISTINCT s.person_id) AS score,
               GROUP_CONCAT(DISTINCT s.via_label) AS vias
        FROM wikidata_similar s
        WHERE s.similar_label IS NOT NULL
          AND s.similar_iri NOT IN (
              SELECT same_as FROM person WHERE same_as IS NOT NULL AND same_as <> ''
          )
        GROUP BY s.similar_iri
        ORDER BY score DESC, similar_label
        LIMIT :lim
        SQL;
        $st = $this->repo->pdo()->prepare($sql);
        $st->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * 내가 비평한 시인들끼리 공유하는 Wikidata 값(직업·사조·국적 등)의 수 — 친화도.
     * 데이터가 쌓일수록 '유사/동떨어진 시인' 군집이 드러난다.
     */
    public function affinity(): array
    {
        $sql = <<<'SQL'
        SELECT a.person_id AS p1, b.person_id AS p2,
               COUNT(DISTINCT a.value_iri) AS shared,
               GROUP_CONCAT(DISTINCT a.value_label) AS labels
        FROM wikidata_fact a
        JOIN wikidata_fact b ON a.value_iri = b.value_iri AND a.person_id < b.person_id
        WHERE a.value_iri IS NOT NULL AND a.value_iri LIKE 'http%'
        GROUP BY a.person_id, b.person_id
        ORDER BY shared DESC LIMIT 30
        SQL;
        return $this->repo->pdo()->query($sql)->fetchAll();
    }
}
