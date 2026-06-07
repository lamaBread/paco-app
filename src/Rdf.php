<?php
/**
 * RDF 직렬화 — SQLite 데이터를 PAC v0.4 온톨로지에 충실한 LOD 로 발행.
 *
 * 절차: Repo 데이터 → 트리플 그래프(Graph) → 4형식 직렬화.
 *   - N-Triples : 검증·디버그용(항상 정확)
 *   - Turtle    : 사람이 읽기 좋은 발행본
 *   - RDF/XML   : Protégé/rdflib 호환(DOM 으로 생성, well-formed 보장)
 *   - JSON-LD   : 웹 친화 발행본
 *
 * 인용(oa:Annotation) 구조는 v0.4 pac-sample.owl 과 동일한 중첩(SpecificResource/
 * Selector 를 빈노드로)으로 재현한다. quotedFrom·dct:creator 등 도출 트리플은
 * 추론기 몫이므로 발행하지 않는다(원본 데이터만 발행).
 */

namespace PACO;

final class Rdf
{
    /** @var array<int,array{0:array,1:array,2:array}> [s,p,o] */
    private array $triples = [];
    /** @var array<string,bool> 등장 주어 순서 보존 */
    private array $subjectOrder = [];

    public function __construct(private array $prefixes) {}

    // ---- 용어 생성 (term factory) ----
    public static function iri(string $full): array { return ['type' => 'iri', 'v' => $full]; }
    public static function bnode(string $id): array { return ['type' => 'bnode', 'v' => $id]; }
    public static function lit(string $v, ?string $dt = null, ?string $lang = null): array
    {
        return ['type' => 'lit', 'v' => $v, 'dt' => $dt, 'lang' => $lang];
    }

    public function curie(string $prefixed): array
    {
        [$p, $local] = explode(':', $prefixed, 2);
        if (!isset($this->prefixes[$p])) {
            throw new \InvalidArgumentException("unknown prefix: $p");
        }
        return self::iri($this->prefixes[$p] . $local);
    }

    public function add(array $s, string|array $p, array $o): void
    {
        $pred = is_array($p) ? $p : $this->curie($p);
        $key = $s['type'] . ':' . $s['v'];
        $this->subjectOrder[$key] = true;
        $this->triples[] = [$s, $pred, $o];
    }

    // ============================================================ 그래프 빌드
    public static function buildAbox(Repo $repo, array $cfg): self
    {
        $prefixes = $cfg['prefixes'];
        $prefixes['pacd'] = $cfg['iri_data'];
        $g = new self($prefixes);
        $D = fn(string $id) => self::iri($cfg['iri_data'] . $id);

        $a = 'rdf:type';

        // ---- 인물 ----
        foreach ($repo->people() as $p) {
            $s = $D($p['id']);
            if ((int) $p['is_poet'])   $g->add($s, $a, $g->curie('pac:Poet'));
            if ((int) $p['is_critic']) $g->add($s, $a, $g->curie('pac:Critic'));
            if (!$p['is_poet'] && !$p['is_critic']) $g->add($s, $a, $g->curie('foaf:Person'));
            $g->add($s, 'foaf:name', self::lit($p['name']));
            // 외부 LOD 동일인 링크는 모두 owl:sameAs 로 발행한다(다출처):
            //   Wikidata(same_as) · 국가서지LOD(nl_uri) · ISNI(코드 → isni.org URI).
            $seen = [];
            foreach ([$p['same_as'] ?? null, $p['nl_uri'] ?? null] as $link) {
                $link = $link !== null ? trim((string) $link) : '';
                if ($link !== '' && !isset($seen[$link])) {
                    $g->add($s, 'owl:sameAs', self::iri($link));
                    $seen[$link] = true;
                }
            }
            if (!empty($p['isni'])) {
                $isniUri = NlLod::isniUri($p['isni']);
                if (!isset($seen[$isniUri])) $g->add($s, 'owl:sameAs', self::iri($isniUri));
            }
        }

        // ---- 시집 ----
        foreach ($repo->books() as $b) {
            $s = $D($b['id']);
            $g->add($s, $a, $g->curie('bibo:Book'));
            $g->add($s, 'pac:documentTitle', self::lit($b['title']));
            if (!empty($b['author_id'])) $g->add($s, 'pac:hasAuthor', $D($b['author_id']));
            if (!empty($b['isbn13']))    $g->add($s, 'bibo:isbn13', self::lit($b['isbn13']));
        }

        // ---- 시 ----
        foreach ($repo->poems() as $pm) {
            $s = $D($pm['id']);
            $g->add($s, $a, $g->curie('pac:Poem'));
            $g->add($s, 'pac:documentTitle', self::lit($pm['title']));
            if (!empty($pm['author_id'])) $g->add($s, 'pac:hasAuthor', $D($pm['author_id']));
            if (!empty($pm['book_id']))   $g->add($s, 'dct:isPartOf', $D($pm['book_id']));
        }

        // ---- 비평문 + 인용 ----
        foreach ($repo->articles() as $art) {
            $s = $D($art['id']);
            $g->add($s, $a, $g->curie('bibo:Article'));
            $g->add($s, 'pac:documentTitle', self::lit($art['title']));
            if (!empty($art['author_id'])) $g->add($s, 'pac:hasAuthor', $D($art['author_id']));
            if (!empty($art['created'])) {
                $g->add($s, 'dct:created', self::lit($art['created'], 'xsd:date'));
            }
            if (!empty($art['critiques_id'])) {
                $g->add($s, 'cito:critiques', $D($art['critiques_id']));
            }
            if (($art['full_text'] ?? '') !== '') {
                $g->add($s, 'pac:fullText', self::lit($art['full_text'], 'rdf:HTML'));
            }
            foreach ($repo->quotations($art['id']) as $q) {
                $g->add($s, 'pac:hasQuotation', $D($q['id']));
                self::buildQuotation($g, $D, $q, $art['id']);
            }
        }
        return $g;
    }

    private static function buildQuotation(self $g, callable $D, array $q, string $articleId): void
    {
        $qs = $D($q['id']);
        $g->add($qs, 'rdf:type', $g->curie('pac:Quotation'));
        $qtIri = $q['qtype'] === 'direct' ? 'pac:DirectQuotation' : 'pac:IndirectQuotation';
        $g->add($qs, 'pac:quotationType', $g->curie($qtIri));

        // ---- body (비평문 속 인용 표지) ----
        $body = self::bnode($q['id'] . '_body');
        $g->add($qs, 'oa:hasBody', $body);
        $g->add($body, 'rdf:type', $g->curie('oa:SpecificResource'));
        $g->add($body, 'oa:hasSource', $D($articleId));
        // FragmentSelector(xml:id)
        $frag = self::bnode($q['id'] . '_bodyfrag');
        $g->add($body, 'oa:hasSelector', $frag);
        $g->add($frag, 'rdf:type', $g->curie('oa:FragmentSelector'));
        $g->add($frag, 'dct:conformsTo', self::iri('https://www.w3.org/TR/xml-id/'));
        $g->add($frag, 'rdf:value', self::lit((string) $q['anchor']));
        // v0.4: body 는 FragmentSelector(xml:id) 하나로 충분.
        // 표지 문구는 pac:fullText 의 <q xml:id> 에 이미 있으므로 body 쪽
        // oa:TextQuoteSelector(및 prefix/suffix)는 발행하지 않는다.

        // ---- targets (원시 속 인용 대상) ----
        $k = 0;
        foreach ($q['targets'] as $t) {
            $k++;
            $tgt = self::bnode($q['id'] . '_t' . $k);
            $g->add($qs, 'oa:hasTarget', $tgt);
            $g->add($tgt, 'rdf:type', $g->curie('oa:SpecificResource'));
            if ($t['target_order'] !== null && $t['target_order'] !== '') {
                $g->add($tgt, 'pac:targetOrder', self::lit((string) (int) $t['target_order'], 'xsd:positiveInteger'));
            }
            $g->add($tgt, 'oa:hasSource', $D($t['source_id']));
            // TextSelection(연/행) — startStanza 있으면
            if ($t['start_stanza'] !== null && $t['start_stanza'] !== '') {
                $sel = self::bnode($q['id'] . '_t' . $k . '_sel');
                $g->add($tgt, 'oa:hasSelector', $sel);
                $g->add($sel, 'rdf:type', $g->curie('pac:TextSelection'));
                $g->add($sel, 'pac:startStanza', self::lit((string) (int) $t['start_stanza'], 'xsd:positiveInteger'));
                foreach (['end_stanza' => 'pac:endStanza', 'start_line' => 'pac:startLine', 'end_line' => 'pac:endLine'] as $col => $prop) {
                    if ($t[$col] !== null && $t[$col] !== '') {
                        $g->add($sel, $prop, self::lit((string) (int) $t[$col], 'xsd:positiveInteger'));
                    }
                }
            }
            // TextQuoteSelector(원문) — 있으면
            if (($t['exact'] ?? '') !== '') {
                $tq = self::bnode($q['id'] . '_t' . $k . '_tq');
                $g->add($tgt, 'oa:hasSelector', $tq);
                $g->add($tq, 'rdf:type', $g->curie('oa:TextQuoteSelector'));
                $g->add($tq, 'oa:exact', self::lit($t['exact']));
            }
        }
    }

    // ================================================================ 직렬화
    private function compact(string $iri): ?string
    {
        foreach ($this->prefixes as $p => $ns) {
            if (str_starts_with($iri, $ns)) {
                $local = substr($iri, strlen($ns));
                if ($local !== '' && !str_contains($local, '/') && preg_match('/^[A-Za-z0-9_.\-가-힣]+$/u', $local)) {
                    return "$p:$local";
                }
            }
        }
        return null;
    }

    /** 주어별로 트리플을 묶어 [subjectKey => ['term'=>.., 'po'=>[[p,o]…]]] */
    private function grouped(): array
    {
        $by = [];
        foreach ($this->triples as [$s, $p, $o]) {
            $key = $s['type'] . ':' . $s['v'];
            if (!isset($by[$key])) $by[$key] = ['term' => $s, 'po' => []];
            $by[$key]['po'][] = [$p, $o];
        }
        return $by;
    }

    // ---- N-Triples ----
    public function toNTriples(): string
    {
        $out = '';
        foreach ($this->triples as [$s, $p, $o]) {
            $out .= $this->ntTerm($s) . ' ' . $this->ntTerm($p) . ' ' . $this->ntTerm($o) . " .\n";
        }
        return $out;
    }
    private function ntTerm(array $t): string
    {
        return match ($t['type']) {
            'iri'   => '<' . $t['v'] . '>',
            'bnode' => '_:' . $t['v'],
            'lit'   => $this->ntLiteral($t),
        };
    }
    private function ntLiteral(array $t): string
    {
        $s = '"' . self::escapeString($t['v']) . '"';
        if (!empty($t['lang'])) return $s . '@' . $t['lang'];
        if (!empty($t['dt']))   return $s . '^^<' . $this->expand($t['dt']) . '>';
        return $s;
    }
    private function expand(string $curie): string
    {
        if (str_contains($curie, '://')) return $curie;
        [$p, $l] = explode(':', $curie, 2);
        return ($this->prefixes[$p] ?? ($p . ':')) . $l;
    }

    // ---- Turtle ----
    public function toTurtle(): string
    {
        $out = "# PACO LOD — Turtle (PAC v0.4 온톨로지 인스턴스)\n";
        foreach ($this->prefixes as $p => $ns) {
            $out .= "@prefix $p: <$ns> .\n";
        }
        $out .= "\n";
        foreach ($this->grouped() as $g) {
            $subj = $g['term']['type'] === 'bnode' ? '_:' . $g['term']['v'] : $this->ttlIri($g['term']['v']);
            // 술어별로 다시 묶기
            $byPred = [];
            foreach ($g['po'] as [$p, $o]) {
                $byPred[$p['v']][] = $o;
            }
            $lines = [];
            foreach ($byPred as $predIri => $objs) {
                $pred = ($predIri === $this->prefixes['rdf'] . 'type') ? 'a' : $this->ttlIri($predIri);
                $vals = array_map(fn($o) => $this->ttlTerm($o), $objs);
                $lines[] = '    ' . $pred . ' ' . implode(",\n        ", $vals);
            }
            $out .= $subj . "\n" . implode(" ;\n", $lines) . " .\n\n";
        }
        return $out;
    }
    private function ttlIri(string $iri): string
    {
        return $this->compact($iri) ?? ('<' . $iri . '>');
    }
    private function ttlTerm(array $t): string
    {
        return match ($t['type']) {
            'iri'   => $this->ttlIri($t['v']),
            'bnode' => '_:' . $t['v'],
            'lit'   => $this->ttlLiteral($t),
        };
    }
    private function ttlLiteral(array $t): string
    {
        $s = '"' . self::escapeString($t['v']) . '"';
        if (!empty($t['lang'])) return $s . '@' . $t['lang'];
        if (!empty($t['dt']))   return $s . '^^' . ($this->compact($this->expand($t['dt'])) ?? ('<' . $this->expand($t['dt']) . '>'));
        return $s;
    }

    // ---- RDF/XML (DOM 으로 생성) ----
    public function toRdfXml(): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
        $root = $dom->createElementNS($RDF, 'rdf:RDF');
        foreach ($this->prefixes as $p => $ns) {
            if ($p === 'rdf') continue;
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $p, $ns);
        }
        $dom->appendChild($root);

        foreach ($this->grouped() as $g) {
            $desc = $dom->createElementNS($RDF, 'rdf:Description');
            if ($g['term']['type'] === 'bnode') {
                $desc->setAttributeNS($RDF, 'rdf:nodeID', $g['term']['v']);
            } else {
                $desc->setAttributeNS($RDF, 'rdf:about', $g['term']['v']);
            }
            foreach ($g['po'] as [$p, $o]) {
                $qn = $this->compact($p['v']);
                if ($qn === null) {
                    // 접두사 없는 술어는 생략 불가 → rdf:Description 으로는 표현 어려우므로 무시(설계상 없음)
                    continue;
                }
                [$pre, $local] = explode(':', $qn, 2);
                $el = $dom->createElementNS($this->prefixes[$pre], $qn);
                if ($o['type'] === 'iri') {
                    $el->setAttributeNS($RDF, 'rdf:resource', $o['v']);
                } elseif ($o['type'] === 'bnode') {
                    $el->setAttributeNS($RDF, 'rdf:nodeID', $o['v']);
                } else { // literal
                    if (!empty($o['lang'])) {
                        $el->setAttribute('xml:lang', $o['lang']);
                    } elseif (!empty($o['dt'])) {
                        $el->setAttributeNS($RDF, 'rdf:datatype', $this->expand($o['dt']));
                    }
                    $el->appendChild($dom->createTextNode($o['v']));
                }
                $desc->appendChild($el);
            }
            $root->appendChild($desc);
        }
        return $dom->saveXML();
    }

    // ---- JSON-LD ----
    public function toJsonLd(): string
    {
        $context = [];
        foreach ($this->prefixes as $p => $ns) $context[$p] = $ns;

        $nodes = [];
        foreach ($this->grouped() as $key => $g) {
            $node = [];
            $node['@id'] = $g['term']['type'] === 'bnode' ? '_:' . $g['term']['v'] : $g['term']['v'];
            $types = [];
            $props = [];
            foreach ($g['po'] as [$p, $o]) {
                if ($p['v'] === $this->prefixes['rdf'] . 'type') {
                    $types[] = $this->compact($o['v']) ?? $o['v'];
                    continue;
                }
                $pk = $this->compact($p['v']) ?? $p['v'];
                $props[$pk][] = $this->jsonTerm($o);
            }
            if ($types) $node['@type'] = count($types) === 1 ? $types[0] : $types;
            foreach ($props as $pk => $vals) {
                $node[$pk] = count($vals) === 1 ? $vals[0] : $vals;
            }
            $nodes[] = $node;
        }
        return json_encode(
            ['@context' => $context, '@graph' => $nodes],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }
    private function jsonTerm(array $t): mixed
    {
        if ($t['type'] === 'iri')   return ['@id' => $t['v']];
        if ($t['type'] === 'bnode') return ['@id' => '_:' . $t['v']];
        if (!empty($t['lang']))     return ['@value' => $t['v'], '@language' => $t['lang']];
        if (!empty($t['dt']))       return ['@value' => $t['v'], '@type' => $this->compact($this->expand($t['dt'])) ?? $this->expand($t['dt'])];
        return $t['v'];
    }

    private static function escapeString(string $s): string
    {
        return str_replace(
            ["\\", "\"", "\n", "\r", "\t"],
            ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
            $s
        );
    }

    public function tripleCount(): int { return count($this->triples); }
}
