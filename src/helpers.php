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
