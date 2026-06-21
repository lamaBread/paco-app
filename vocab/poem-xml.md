# PAC 시 마크업 XML (poem markup) — v0.6.0

시(詩)의 **본문 구조(연/행)** 를 기술하는 아주 단순한 XML이다. PACO 에서 시 본문의
**정식 소스**(`poem.body_xml`)로 쓰이며, 이로부터 표시·연/행 선택·인용 좌표가 파생된다.

## 설계 원칙

- **코어는 두 가지뿐**: `<stanza>`(연)와 `<line>`(행). 그 외는 최소한만 둔다.
- 시 본문은 **LOD 로 발행하지 않는다**(온톨로지 비훼손). 이 XML 은 *내부 저장·기술용*이며,
  그 연/행 좌표가 인용 선택자 `pac:TextSelection`(`startStanza`/`startLine`/`endStanza`/`endLine`)
  과 **정확히 일치**한다. 즉 XML 은 인용이 가리키는 좌표계를 형식화한 것이다.
- 제목·저자·수록 시집은 이 XML 에 넣지 않는다(각각 `poem.title`·`poem.author_id`·`poem.book_id`
  = `pac:documentTitle`·`pac:hasAuthor`·`dct:isPartOf` 로 이미 관리). XML 은 **본문 구조만** 담는다.

## 문법

```xml
<poem>
  <stanza n="1">
    <line n="1">황금에 가까운 빛깔의 새 한 마리</line>
    <line n="2">구관조라 불리는 그것을</line>
  </stanza>
  <stanza n="2">
    <line n="1">나는 씻기고 있었다</line>
  </stanza>
</poem>
```

| 요소/속성 | 의미 |
|---|---|
| `<poem>` | 루트. 한 시의 본문. |
| `<stanza>` | 연(聯). 문서 순서가 곧 연 번호(1부터). |
| `<line>` | 행. 연 안에서 문서 순서가 곧 행 번호(1부터). 텍스트 내용이 그 행의 문자열. |
| `n` (선택) | 사람이 읽기 위한 번호 표시. **권위는 문서 순서**이며 `n` 은 저장 시 순서대로 다시 매겨진다. |
| 빈 `<line></line>` | 빈 행(연 내부 공백 행). |

- 텍스트의 `&`, `<`, `>` 는 XML 규칙대로 이스케이프한다(`&amp;` `&lt;` `&gt;`).
- 알 수 없는 요소/속성은 무시한다(관용적 파싱). `<stanza>` 없이 `<line>` 만 있으면 한 연으로 본다.

## 저장·파생 (SQLite 연계)

1. 입력(편집기) — **XML 또는 평문**(빈 줄=연, 줄바꿈=행) 모두 허용한다(하위 호환).
2. 저장 시 — 어느 입력이든 연/행 구조로 파싱한 뒤
   - `poem.body_xml` ← 표준형 XML(단일 진실)
   - `poem_line(stanza_no,line_no,text)` ← 그 구조에서 파생(좌측 표시·연/행 선택·LOD 선택자 좌표)
3. 구버전 시(`body_xml` 없음) — 읽을 때 `poem_line` 에서 즉석으로 XML 을 도출한다(마이그레이션은
   컬럼 추가만 하는 추가형이라 기존 행을 변형하지 않는다).

구현: [`src/Repo.php`](../src/Repo.php) `parsePoemInput` / `buildPoemXml` / `setPoemBody` / `poemBodyXml`.
