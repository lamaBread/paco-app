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

    // ============================== 국가서지LOD → Wikidata 동일인 능동 해석 (v0.8)
    /**
     * 국가서지LOD 프로파일의 외부 식별자(ISNI·VIAF)로 Wikidata 동일인 QID 를 역질의한다.
     * 외부 식별자는 정확 일치 → 신뢰(자동 연결 가능). 정확히 1개만 매칭될 때만 돌려준다
     * (한 식별자가 여러 엔티티에 붙은 모호성은 배제). 못 찾으면 null.
     */
    public function resolveQid(?string $isni, ?string $viaf): ?string
    {
        // ① ISNI(P213) — Wikidata 는 16자리를 4자리씩 공백으로 끊어 저장한다.
        $isni16 = NlLod::normalizeIsni($isni);
        if ($isni16 !== null) {
            $spaced = trim(chunk_split($isni16, 4, ' '));
            $qid = $this->resolveByExternalId('P213', $spaced) ?? $this->resolveByExternalId('P213', $isni16);
            if ($qid) return $qid;
        }
        // ② VIAF(P214) — 숫자 id.
        if ($viaf !== null && preg_match('/(\d{3,})/', $viaf, $m)) {
            $qid = $this->resolveByExternalId('P214', $m[1]);
            if ($qid) return $qid;
        }
        return null;
    }

    /** wdt:$pid 값이 정확히 $value 인 엔티티가 유일하면 그 QID, 아니면 null. */
    private function resolveByExternalId(string $pid, string $value): ?string
    {
        // 값은 ISNI(숫자·공백·X) / VIAF(숫자)뿐 — 따옴표 이스케이프만 방어적으로.
        $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $rows = $this->sparql("SELECT ?p WHERE { ?p wdt:$pid \"$v\" } LIMIT 2");
        if (count($rows) === 1) return self::qid($rows[0]['p']['value'] ?? null);
        return null;
    }

    /**
     * 이름(+생년)으로 Wikidata 시인/작가 후보를 찾는다 — 자동 확정 금지, 사용자 확인용.
     * 동명이인 위험이 있어 연결하지 않고 후보만 돌려준다. 생년이 주어지면 ±1 일치를 앞세운다.
     * @return array<int,array{qid:string,uri:string,label:string,birth:?int}>
     */
    public function resolveCandidatesByName(string $name, ?int $birth = null): array
    {
        $name = trim($name);
        if ($name === '') return [];
        $lang = $this->cfg['wikidata']['lang'];
        $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
        // 직업이 시인(Q49757)·작가(Q36180)·저술가(Q482980) 계열인 인물만.
        $q = <<<SPARQL
        SELECT DISTINCT ?p ?pLabel ?by WHERE {
          ?p rdfs:label|skos:altLabel "$esc"@$lang .
          ?p wdt:P106 ?occ . VALUES ?occ { wd:Q49757 wd:Q36180 wd:Q482980 }
          OPTIONAL { ?p wdt:P569 ?dob . BIND(YEAR(?dob) AS ?by) }
          SERVICE wikibase:label { bd:serviceParam wikibase:language "$lang,en". ?p rdfs:label ?pLabel. }
        } LIMIT 10
        SPARQL;
        $out = [];
        foreach ($this->sparql($q) as $r) {
            $qid = self::qid($r['p']['value'] ?? null);
            if (!$qid || isset($out[$qid])) continue;
            $by = isset($r['by']['value']) && $r['by']['value'] !== '' ? (int) $r['by']['value'] : null;
            $out[$qid] = [
                'qid' => $qid, 'uri' => 'http://www.wikidata.org/entity/' . $qid,
                'label' => $r['pLabel']['value'] ?? $name, 'birth' => $by,
            ];
        }
        $out = array_values($out);
        if ($birth !== null) {
            usort($out, fn($a, $b) => (abs(($a['birth'] ?? 9999) - $birth)) <=> (abs(($b['birth'] ?? 9999) - $birth)));
        }
        return $out;
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
