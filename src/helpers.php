<?php
/** 공용 헬퍼 (네임스페이스 전역). */

if (!function_exists('h')) {
    /** HTML 이스케이프 */
    function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('paco_slug')) {
    /**
     * IRI 지역명용 슬러그. 한글은 보존(IRI 는 UTF-8 허용)하되 공백·구분자만 정리.
     * 예: "구관조 씻기기" → "구관조-씻기기"
     */
    function paco_slug(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/[\s\/\\\\]+/u', '-', $s);
        $s = preg_replace('/[^\p{L}\p{N}_\-]+/u', '', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-') ?: ('x' . substr(md5($s . microtime()), 0, 8));
    }
}

if (!function_exists('paco_today')) {
    function paco_today(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('json_for_script')) {
    /**
     * 데이터를 <script type="application/json"> 안에 인라인하기 위한 JSON 직렬화.
     * <script> 는 raw-text 요소라 그 안의 HTML 엔티티(&quot; 등)는 브라우저가 디코드하지 않는다 —
     * 따라서 htmlspecialchars 로 감싸면 textContent 가 &quot; 그대로 남아 JSON.parse 가 깨진다.
     * 대신 JSON_HEX_TAG|JSON_HEX_AMP 로 '<' '>' '&' 를 \uXXXX 로 만들어 </script> 분리만 막으면,
     * 남는 문자열은 그대로 유효한 JSON 이라 textContent 를 바로 파싱할 수 있다.
     */
    function json_for_script($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
