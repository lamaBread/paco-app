<?php
/**
 * 국가서지LOD (국립중앙도서관, lod.nl.go.kr) 연동 — '기본' 추론 출처.
 *
 * 시인을 국가서지LOD 의 저자 전거(KAC…)와 연결해 권위 있는 기본 프로파일(생몰년·직업·
 * 활동분야·출생지)과 외부 동일인 링크(ISNI·VIAF·Wikidata·위키백과)를 받아 nl_fact 에
 * 캐시한다. 국가서지LOD 에 없거나 부족한 부분(비슷한 시인·사조·수상·거주지 등)은
 * Wikidata(Wikidata.php)가 폴백/보강한다.
 *
 * 엔진 특성(검증됨, 2026-06):
 *  - SPARQL 엔드포인트 결과 포맷은 Accept 헤더로만 제어. JSON 리터럴 값 끝에 '~' 가 붙어 나옴
 *    → rtrim 필수.
 *  - 바운드 주어 질의(<URI> ?p ?o)나 nlon:birthYear 를 섞은 다중 패턴은 엔진 버그로 실패.
 *    → 선택한 저자의 '전체 프로파일'은 /data/<id>?output=rdfxml (RDF/XML, '~' 없음)로 받아 파싱.
 *  - 동명이인이 매우 많다 → 이름 검색은 직업(schema:jobTitle)으로 '시인'을 가려 보여준다.
 *
 * 네트워크 실패는 조용히 요약/빈값으로 반환한다(서버를 막지 않음).
 */

namespace PACO;

final class NlLod
{
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    public function __construct(private Repo $repo, private array $cfg) {}

    // ---------------------------------------------------------- 식별자 헬퍼
    /** 국가서지LOD 자원 URI → id(KAC…). 'http://lod.nl.go.kr/resource/KAC…' 또는 그냥 id 도 허용. */
    public static function resourceId(?string $uri): ?string
    {
        if (!$uri) return null;
        $uri = trim($uri);
        if (preg_match('#/resource/([A-Za-z0-9]+)#', $uri, $m)) return $m[1];
        if (preg_match('/^[A-Za-z]{2,4}\d[A-Za-z0-9]+$/', $uri)) return $uri; // 맨 id 입력
        return null;
    }

    /** 사용자가 넣은 ISNI(공백·하이픈·isni.org URI 무관)를 16자리 표준형으로. 실패 시 null. */
    public static function normalizeIsni(?string $raw): ?string
    {
        if (!$raw) return null;
        if (preg_match('#isni\.org/isni/([0-9X]{16})#i', $raw, $m)) return strtoupper($m[1]);
        $s = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw));
        return (strlen($s) === 16) ? $s : null;
    }

    /** ISNI 코드 → isni.org URI(owl:sameAs 발행·링크용). */
    public static function isniUri(string $isni): string
    {
        return 'http://www.isni.org/isni/' . $isni;
    }

    // ------------------------------------------------------------ 이름 검색
    /**
     * 이름으로 국가서지LOD 저자 후보를 찾는다(추가 폼의 자동완성용).
     * @return array<int,array{id:string,uri:string,jobs:array<int,string>,is_poet:bool}>
     */
    public function searchByName(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [];
        $lit = self::sparqlStr($name);

        // ① 이름 + 직업(한 질의로 동작 — 동명이인을 '시인' 여부로 가리는 핵심 정보).
        $qJob = <<<SPARQL
        PREFIX rdf:    <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
        PREFIX foaf:   <http://xmlns.com/foaf/0.1/>
        PREFIX nlon:   <http://lod.nl.go.kr/ontology/>
        PREFIX schema: <http://schema.org/>
        SELECT ?s ?job WHERE {
          ?s rdf:type nlon:Author ; foaf:name "$lit" ; schema:jobTitle ?job .
        } LIMIT 120
        SPARQL;
        // ② 이름만(직업이 없는 저자도 후보에 포함되도록).
        $qAll = <<<SPARQL
        PREFIX rdf:  <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
        PREFIX foaf: <http://xmlns.com/foaf/0.1/>
        PREFIX nlon: <http://lod.nl.go.kr/ontology/>
        SELECT ?s WHERE { ?s rdf:type nlon:Author ; foaf:name "$lit" . } LIMIT 120
        SPARQL;

        $cands = [];
        $touch = function (string $uri) use (&$cands): void {
            $id = self::resourceId($uri);
            if ($id && !isset($cands[$id])) {
                $cands[$id] = ['id' => $id, 'uri' => $uri, 'jobs' => [], 'is_poet' => false];
            }
        };
        foreach ($this->sparql($qAll) as $r) {
            $touch($r['s']['value'] ?? '');
        }
        foreach ($this->sparql($qJob) as $r) {
            $uri = $r['s']['value'] ?? '';
            $touch($uri);
            $id = self::resourceId($uri);
            $job = self::cleanTerm($r['job']['value'] ?? '');
            if ($id && $job !== '' && !in_array($job, $cands[$id]['jobs'], true)) {
                $cands[$id]['jobs'][] = $job;
                if (mb_strpos($job, '시인') !== false) $cands[$id]['is_poet'] = true;
            }
        }
        // 시인 먼저, 그다음 id 순.
        $out = array_values($cands);
        usort($out, fn($a, $b) => ($b['is_poet'] <=> $a['is_poet']) ?: strcmp($a['id'], $b['id']));
        return $out;
    }

    // ----------------------------------------------------- 자원 프로파일 취득
    /**
     * 저자 자원의 전체 RDF(/data/<id>?output=rdfxml)를 받아 핵심 프로파일을 파싱한다.
     * @return array{name:?string,isni:?string,birth:?string,death:?string,
     *               jobs:array<int,string>,fields:array<int,string>,birthplace:?string,
     *               same_as:array<int,string>,see_also:array<int,string>,
     *               wikidata:?string,viaf:?string}|null  네트워크/파싱 실패 시 null
     */
    public function fetchProfile(string $uri): ?array
    {
        $id = self::resourceId($uri);
        if (!$id) return null;
        $url = rtrim($this->cfg['nllod']['data_base'], '/') . '/' . rawurlencode($id) . '?output=rdfxml';
        try {
            $body = $this->httpGet($url, 'application/rdf+xml');
        } catch (\Throwable $e) {
            return null;
        }
        if (!$body || stripos($body, '<rdf:RDF') === false) return null;

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($body)) return null;

        $prof = [
            'name' => null, 'isni' => null, 'birth' => null, 'death' => null,
            'jobs' => [], 'fields' => [], 'birthplace' => null,
            'same_as' => [], 'see_also' => [], 'wikidata' => null, 'viaf' => null,
        ];
        $target = 'http://lod.nl.go.kr/resource/' . $id;
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof \DOMElement)) continue;
            $about = $node->getAttributeNS(self::RDF_NS, 'about');
            if ($about !== $target) continue;
            foreach ($node->childNodes as $p) {
                if (!($p instanceof \DOMElement)) continue;
                $name = $p->localName;
                $res  = $p->getAttributeNS(self::RDF_NS, 'resource');
                $val  = trim($p->textContent);
                switch ($name) {
                    case 'name':       // foaf:name
                    case 'prefLabel':  // skos:prefLabel
                        if ($val !== '' && $prof['name'] === null) $prof['name'] = $val;
                        break;
                    case 'isni':
                        if ($val !== '') $prof['isni'] = self::normalizeIsni($val) ?? $val;
                        break;
                    case 'birthYear':
                        if ($val !== '') $prof['birth'] = $val;
                        break;
                    case 'deathYear':
                        if ($val !== '') $prof['death'] = $val;
                        break;
                    case 'jobTitle':
                        $j = self::cleanTerm($val);
                        if ($j !== '' && !in_array($j, $prof['jobs'], true)) $prof['jobs'][] = $j;
                        break;
                    case 'fieldOfActivity':
                        $f = self::cleanTerm($val);
                        if ($f !== '' && !in_array($f, $prof['fields'], true)) $prof['fields'][] = $f;
                        break;
                    case 'birthPlace':
                        if ($val !== '' && $prof['birthplace'] === null) $prof['birthplace'] = self::cleanTerm($val);
                        break;
                    case 'sameAs':
                        if ($res !== '' && !in_array($res, $prof['same_as'], true)) $prof['same_as'][] = $res;
                        break;
                    case 'seeAlso':
                        if ($res !== '' && !in_array($res, $prof['see_also'], true)) $prof['see_also'][] = $res;
                        break;
                }
            }
        }
        // 동일인 링크에서 Wikidata/VIAF 발견(폴백 자동 연결에 사용).
        foreach (array_merge($prof['same_as'], $prof['see_also']) as $link) {
            if (!$prof['wikidata'] && preg_match('#wikidata\.org/entity/(Q\d+)#', $link)) {
                $prof['wikidata'] = 'http://www.wikidata.org/entity/' . Wikidata::qid($link);
            }
            if (!$prof['viaf'] && preg_match('#viaf\.org/viaf/\d+#', $link)) {
                $prof['viaf'] = $link;
            }
        }
        return $prof;
    }

    // ------------------------------------------------------ 캐시 채우기/갱신
    /**
     * 한 시인의 국가서지LOD 프로파일을 받아 nl_fact 에 저장한다.
     * @return array{facts:int,wikidata:?string,profile:?array}
     */
    public function fetchFacts(string $personId, string $uri): array
    {
        $prof = $this->fetchProfile($uri);
        $db = $this->repo->pdo();
        $db->prepare('DELETE FROM nl_fact WHERE person_id=?')->execute([$personId]);
        if (!$prof) return ['facts' => 0, 'wikidata' => null, 'profile' => null];

        $ins = $db->prepare(
            'INSERT INTO nl_fact (person_id,prop_uri,prop_label,value_iri,value_label,fetched_at)
             VALUES (?,?,?,?,?,?)'
        );
        $now = date('c');
        $n = 0;
        $row = function (string $propUri, string $label, ?string $iri, ?string $lit) use ($ins, $personId, $now, &$n): void {
            $ins->execute([$personId, $propUri, $label, $iri, $lit, $now]);
            $n++;
        };
        $on = 'http://lod.nl.go.kr/ontology/';
        if ($prof['birth'] || $prof['death']) {
            $row($on . 'birthYear', '생몰년', null, self::lifespan($prof['birth'], $prof['death']));
        }
        foreach ($prof['jobs'] as $j)    $row('http://schema.org/jobTitle', '직업', null, $j);
        foreach ($prof['fields'] as $f)  $row($on . 'fieldOfActivity', '활동분야', null, $f);
        if ($prof['birthplace'])         $row('http://schema.org/birthPlace', '출생지', null, $prof['birthplace']);
        // 동일인 링크(외부 권위) — 표시 + 출처 추적용.
        foreach ($prof['same_as'] as $link) {
            $row('http://www.w3.org/2002/07/owl#sameAs', '동일인 링크', $link, self::linkLabel($link));
        }
        foreach ($prof['see_also'] as $link) {
            $row('http://www.w3.org/2000/01/rdf-schema#seeAlso', '관련 자료', $link, self::linkLabel($link));
        }
        return ['facts' => $n, 'wikidata' => $prof['wikidata'], 'profile' => $prof];
    }

    /**
     * nl_uri 가 있는 모든 시인의 국가서지LOD 캐시를 갱신한다. 갱신하면서 국가서지LOD 의
     * owl:sameAs 에 Wikidata 링크가 있고 그 시인에 Wikidata(same_as)가 비어 있으면 자동 연결한다
     * (→ Wikidata 폴백 추론이 그대로 동작).
     * @return array 요약
     */
    public function refreshAll(): array
    {
        $sum = ['poets' => 0, 'facts' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => []];
        $setWd = $this->repo->pdo()->prepare('UPDATE person SET same_as=? WHERE id=? AND (same_as IS NULL OR same_as="")');
        foreach ($this->repo->poets() as $poet) {
            if (empty($poet['nl_uri'])) { $sum['skipped']++; continue; }
            $sum['poets']++;
            try {
                $r = $this->fetchFacts($poet['id'], $poet['nl_uri']);
                $sum['facts'] += $r['facts'];
                if ($r['wikidata'] && empty($poet['same_as'])) {
                    $setWd->execute([$r['wikidata'], $poet['id']]);
                    if ($setWd->rowCount() > 0) $sum['linked']++;
                }
            } catch (\Throwable $e) {
                $sum['errors'][] = $poet['name'] . ': ' . $e->getMessage();
            }
        }
        return $sum;
    }

    // ----------------------------------------------------------- 표시용 조회
    /** 시인의 국가서지LOD 캐시 사실(라벨별 묶음 표시용 원본 행). */
    public function factsByPerson(string $personId): array
    {
        $st = $this->repo->pdo()->prepare(
            'SELECT * FROM nl_fact WHERE person_id=? ORDER BY id'
        );
        $st->execute([$personId]);
        return $st->fetchAll();
    }

    public function lastFetched(): ?string
    {
        try {
            $v = $this->repo->pdo()->query('SELECT MAX(fetched_at) FROM nl_fact')->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        return $v ?: null;
    }

    // --------------------------------------------------------------- 내부 HTTP
    /** @return array<int,array> SPARQL JSON bindings (리터럴 값의 '~' 접미사 제거됨) */
    private function sparql(string $query): array
    {
        $url = $this->cfg['nllod']['endpoint'] . '?query=' . rawurlencode($query);
        try {
            $body = $this->httpGet($url, 'application/sparql-results+json');
        } catch (\Throwable $e) {
            return [];
        }
        $json = json_decode($body, true);
        $rows = $json['results']['bindings'] ?? [];
        // OntoBase 가 JSON 리터럴 끝에 붙이는 '~' 제거.
        foreach ($rows as &$r) {
            foreach ($r as &$cell) {
                if (($cell['type'] ?? '') === 'literal' && isset($cell['value'])) {
                    $cell['value'] = rtrim($cell['value'], '~');
                }
            }
            unset($cell);
        }
        unset($r);
        return $rows;
    }

    private function httpGet(string $url, string $accept): string
    {
        $ua = $this->cfg['nllod']['user_agent'];
        $to = (int) $this->cfg['nllod']['timeout'];
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => [
                'header'  => "Accept: $accept\r\nUser-Agent: $ua\r\n",
                'timeout' => $to,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new \RuntimeException('네트워크 요청 실패(file_get_contents)');
            return $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $to,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: ' . $accept, 'User-Agent: ' . $ua],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('네트워크 요청 실패: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new \RuntimeException("국가서지LOD HTTP $code");
        return (string) $body;
    }

    // -------------------------------------------------------------- 작은 헬퍼
    /** 통제어휘 표기 정리: '시인(시)[詩人]' → '시인', '한국 문학[韓國文學]' → '한국 문학'. */
    private static function cleanTerm(string $s): string
    {
        $s = rtrim(trim($s), '~');
        $s = preg_replace('/\s*[\(\[].*$/u', '', $s); // 첫 괄호/대괄호 이후 제거
        return trim($s);
    }

    private static function lifespan(?string $b, ?string $d): string
    {
        $b = $b ? trim($b) : '';
        $d = $d ? trim($d) : '';
        if ($b !== '' && $d !== '') return "{$b}–{$d}";
        if ($b !== '') return "{$b}–";
        return "–{$d}";
    }

    /** 외부 링크 URL → 짧은 표시 라벨(VIAF·Wikidata·ISNI·위키백과 등). */
    private static function linkLabel(string $url): string
    {
        if (preg_match('#wikidata\.org/entity/(Q\d+)#', $url, $m)) return 'Wikidata ' . $m[1];
        if (preg_match('#viaf\.org/viaf/(\d+)#', $url, $m))        return 'VIAF ' . $m[1];
        if (preg_match('#isni\.org/isni/([0-9X]+)#', $url, $m))    return 'ISNI ' . $m[1];
        if (stripos($url, 'wikipedia.org') !== false)             return '위키백과';
        if (stripos($url, 'id.loc.gov') !== false || stripos($url, 'loc.gov') !== false) return 'LoC';
        if (stripos($url, 'd-nb.info') !== false)                 return 'GND';
        if (stripos($url, 'data.bnf.fr') !== false)               return 'BnF';
        if (stripos($url, 'ndl.go.jp') !== false)                 return 'NDL';
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: $url;
    }

    private static function sparqlStr(string $s): string
    {
        return str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s);
    }
}
