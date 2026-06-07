<?php
/**
 * 사용자 런타임 설정 — DB(app_setting) 기반 k/v 저장소 + config 오버레이.
 *
 * 설계(v0.2.0): config.php 는 코드와 함께 출하되는 **기본값**일 뿐이며, 자가 업데이트가
 * 이 파일을 통째로 덮어쓴다. 사용자가 바꾸는 값(LOD 발행 도메인 iri_data 등)은
 * DB(app_setting)에 두어, data/ 가 업데이트에서 보존되므로 **설정도 함께 보존**된다.
 *
 * 흐름: Database::connect() 직후 Settings::apply($pdo, $cfg) 로 DB 값을 $cfg 에 덧씌운다.
 *       설정 페이지(r=settings)에서 set() 으로 갱신한다.
 */

namespace PACO;

use PDO;

final class Settings
{
    /** 설정 페이지에서 편집 가능하고 $cfg 에 오버레이되는 키들(라벨·설명·기본값 경로). */
    public const EDITABLE = [
        'iri_data' => [
            'label' => 'LOD 발행 base IRI (iri_data)',
            'hint'  => '발행되는 인스턴스(ABox) IRI 의 도메인. 배포 도메인으로 바꾸면 LOD 가 그 도메인으로 나갑니다. 예: https://my-archive.example/paco/data/',
            'cfg'   => 'iri_data',
        ],
        'app_name' => [
            'label' => '앱 이름 (app_name)',
            'hint'  => '상단/제목에 표시되는 이름. 비워두면 기본값(PACO).',
            'cfg'   => 'app_name',
        ],
        'wikidata_lang' => [
            'label' => 'Wikidata 라벨 언어',
            'hint'  => "추론 질의 프리페치 때 받을 라벨 언어 코드. 기본 'ko'.",
            'cfg'   => ['wikidata', 'lang'],
        ],
    ];

    /** app_setting 전체를 [key=>value] 로. 테이블이 없거나 오류면 빈 배열. */
    public static function all(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('SELECT key, value FROM app_setting')->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    public static function get(PDO $pdo, string $key, ?string $default = null): ?string
    {
        try {
            $st = $pdo->prepare('SELECT value FROM app_setting WHERE key = ?');
            $st->execute([$key]);
            $v = $st->fetchColumn();
        } catch (\Throwable $e) {
            return $default;
        }
        return ($v === false) ? $default : (string) $v;
    }

    /** 값을 저장(upsert). 빈 문자열/null 은 '기본값으로 되돌리기' = 행 삭제로 처리. */
    public static function set(PDO $pdo, string $key, ?string $value): void
    {
        $value = ($value === null) ? '' : trim($value);
        if ($value === '') {
            $st = $pdo->prepare('DELETE FROM app_setting WHERE key = ?');
            $st->execute([$key]);
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO app_setting (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $st->execute([$key, $value, date('c')]);
    }

    /**
     * DB 설정을 $cfg 에 덧씌운 새 배열을 반환한다(원본 불변).
     * EDITABLE 의 'cfg' 경로에 따라 단일 키 또는 중첩 키([wikidata, lang])에 적용.
     */
    public static function apply(PDO $pdo, array $cfg): array
    {
        $vals = self::all($pdo);
        foreach (self::EDITABLE as $key => $meta) {
            if (!isset($vals[$key]) || $vals[$key] === '') {
                continue;
            }
            $path = $meta['cfg'];
            if (is_array($path)) {
                [$a, $b] = $path;
                if (isset($cfg[$a]) && is_array($cfg[$a])) {
                    $cfg[$a][$b] = $vals[$key];
                }
            } else {
                $cfg[$path] = $vals[$key];
            }
        }
        return $cfg;
    }

    /** EDITABLE 키의 '현재 유효값'을 $cfg 에서 읽어 [key=>value] 로(폼 표시용). */
    public static function effective(array $cfg): array
    {
        $out = [];
        foreach (self::EDITABLE as $key => $meta) {
            $path = $meta['cfg'];
            if (is_array($path)) {
                [$a, $b] = $path;
                $out[$key] = (string) ($cfg[$a][$b] ?? '');
            } else {
                $out[$key] = (string) ($cfg[$path] ?? '');
            }
        }
        return $out;
    }
}
