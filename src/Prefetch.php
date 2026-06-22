<?php
/**
 * 국가서지LOD 프리페치의 '백그라운드 실행 + 상태 추적'.
 *
 * 왜 백그라운드인가(v0.7.2 세그폴트 수정):
 *  refreshAll()+fetchCandidates() 는 공개 LOD 엔드포인트에 수십 번 네트워크 호출을 하는
 *  장시간 작업이다. 이를 `php -S`(빌트인 개발서버: 단일 스레드·블로킹) 요청 안에서 동기로
 *  돌리면 서버 스레드가 수 분간 묶이고, 그 사이 브라우저가 그 요청을 중단/재시도하거나 다른
 *  연결을 열면 OS 백로그에 쌓였다가 일부가 끊긴다. 블로킹이 끝나 이벤트 루프가 재개될 때
 *  그 끊긴 연결의 stale 포인터를 건드려 SIGSEGV(php_cli_server_do_event_for_each_fd_callback,
 *  KERN_INVALID_ADDRESS)가 난다. 예외가 아니라 네이티브 크래시라 try/catch 로 못 막는다.
 *  → 유일한 근본 해결은 '긴 블로킹 작업을 요청 밖으로 빼는 것'. 그래서 별도 CLI 프로세스
 *  (bin/prefetch.php)로 분리해 detached 로 띄우고, 웹 요청은 즉시 반환한다.
 *
 * 상태는 data/ 의 JSON 파일(prefetch-status.json)로 주고받는다 — 스키마 변경 불필요.
 */

namespace PACO;

final class Prefetch
{
    /** pid 확인이 불가한 환경에서 'running' lock 을 죽은 것으로 보기까지의 시간(좀비 lock 방지). */
    private const STALE_SECONDS = 1800;

    public static function statusPath(array $cfg): string
    {
        return \dirname($cfg['db_path']) . '/prefetch-status.json';
    }

    public static function logPath(array $cfg): string
    {
        return \dirname($cfg['db_path']) . '/prefetch.log';
    }

    /** 현재 상태(파일이 없으면 idle). */
    public static function status(array $cfg): array
    {
        $def = ['state' => 'idle', 'started_at' => null, 'finished_at' => null,
                'summary' => null, 'error' => null, 'pid' => null];
        $f = self::statusPath($cfg);
        if (!is_file($f)) return $def;
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) ? array_merge($def, $j) : $def;
    }

    private static function save(array $cfg, array $s): void
    {
        @file_put_contents(
            self::statusPath($cfg),
            json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * 'running' 상태이고 그 프로세스가 실제로 살아있는가. 프로세스가 죽었으면(크래시·강제종료)
     * lock 을 자동으로 해제(false)해 재실행을 막지 않는다.
     */
    public static function isRunning(array $cfg): bool
    {
        $s = self::status($cfg);
        if (($s['state'] ?? '') !== 'running') return false;
        $pid = (int) ($s['pid'] ?? 0);
        if ($pid > 0 && function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) return true;                 // 살아있음
            return posix_get_last_error() === 1; // EPERM = 살아있으나 권한 없음 / ESRCH = 죽음
        }
        // pid 미기록(자식이 아직 시작 전)이거나 posix 불가 — 시간으로 staleness 판단.
        $t = strtotime((string) ($s['started_at'] ?? '')) ?: 0;
        return $t > 0 && (time() - $t) < self::STALE_SECONDS;
    }

    /**
     * 프리페치를 백그라운드 CLI 프로세스(bin/prefetch.php)로 띄우고 즉시 반환한다.
     * @return array{ok:bool,started:bool,message:string}
     */
    public static function spawn(array $cfg): array
    {
        if (self::isRunning($cfg)) {
            return ['ok' => true, 'started' => false, 'message' => '이미 국가서지LOD 갱신이 진행 중입니다.'];
        }
        $php    = PHP_BINARY ?: 'php';
        $script = $cfg['base_dir'] . '/bin/prefetch.php';
        if (!is_file($script)) {
            return ['ok' => false, 'started' => false, 'message' => '갱신 스크립트를 찾을 수 없습니다: ' . $script];
        }
        $log = self::logPath($cfg);
        // nohup … & 로 부모(웹 요청)와 분리. `echo $!` 로 백그라운드 pid 를 받아 즉시 기록한다.
        $cmd = 'nohup ' . implode(' ', array_map('escapeshellarg', [$php, $script]))
             . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $out = [];
        @exec($cmd, $out);
        $pid = (int) ($out[0] ?? 0);
        self::save($cfg, ['state' => 'running', 'started_at' => date('c'), 'finished_at' => null,
                          'summary' => null, 'error' => null, 'pid' => $pid ?: null]);
        return ['ok' => true, 'started' => true, 'message' => '국가서지LOD 갱신을 백그라운드에서 시작했습니다. 잠시 후 결과가 표시됩니다.'];
    }

    /**
     * 실제 프리페치 작업(CLI 프로세스에서 호출). 상태를 running→done|error 로 전이한다.
     * @param callable(string):void|null $echo 진행 로그(CLI 표시용)
     * @return array{ok:bool,summary?:string,error?:string}
     */
    public static function run(Repo $repo, array $cfg, ?callable $echo = null): array
    {
        $log = $echo ?? static function (string $m): void {};

        // 자신의 실제 pid 를 기록(웹 쪽 isRunning 의 생존 판정 정확도↑).
        $prev = self::status($cfg);
        self::save($cfg, [
            'state'      => 'running',
            'started_at' => $prev['started_at'] ?: date('c'),
            'finished_at' => null, 'summary' => null, 'error' => null,
            'pid'        => function_exists('getmypid') ? (getmypid() ?: null) : null,
        ]);
        $startedAt = $prev['started_at'] ?: date('c');

        try {
            $nl = new NlLod($repo, $cfg);
            $log('국가서지LOD 프로파일 갱신(refreshAll)…');
            $nls = $nl->refreshAll();
            $linkedTotal = (int) $nls['linked'] + (int) ($nls['resolved'] ?? 0);
            $log("  → 시인 {$nls['poets']}명 · 사실 {$nls['facts']}건 · Wikidata 연결 {$nls['linked']}(NL)+"
                . ($nls['resolved'] ?? 0) . "(ISNI/VIAF)명 · 확인필요 후보 " . ($nls['candidates'] ?? 0) . "건");

            // 국가서지LOD 로 same_as 가 정해진 뒤에 Wikidata 보강(사조 P135·장르 P136·유사 시인)을
            // 받는다 — 순서 중요(능동 해석으로 막 연결된 시인도 이번 갱신에 포함된다).
            $log('Wikidata 보강 갱신(사조·장르·유사 시인)…');
            $wd = new Wikidata($repo, $cfg);
            $wds = $wd->refreshAll();
            $log("  → 시인 {$wds['poets']}명 · 사실 {$wds['facts']}건 · 유사 시인 {$wds['similar']}건");

            $log('C2 후보 풀 프리페치(fetchCandidates)…');
            $cnd = $nl->fetchCandidates();
            $log("  → 후보 {$cnd['candidates']}명 · {$cnd['fields']}개 분야 · 프로파일 {$cnd['profiled']}건");

            $summary = "국가서지LOD: 시인 {$nls['poets']}명 · 사실 {$nls['facts']}건"
                . ($linkedTotal ? " · Wikidata 연결 {$linkedTotal}명" : '')
                . " · Wikidata 보강(사실 {$wds['facts']}·유사 {$wds['similar']})"
                . " · 다음 시인 후보 {$cnd['candidates']}명({$cnd['fields']}개 분야)";
            $errs = array_merge($nls['errors'] ?? [], $wds['errors'] ?? []);
            if ($errs) {
                $summary .= ' · 오류 ' . count($errs) . '건(' . implode(' / ', array_slice($errs, 0, 3)) . ')';
            }

            self::save($cfg, ['state' => 'done', 'started_at' => $startedAt, 'finished_at' => date('c'),
                              'summary' => $summary, 'error' => null, 'pid' => null]);
            $log('완료.');
            return ['ok' => true, 'summary' => $summary];
        } catch (\Throwable $e) {
            self::save($cfg, ['state' => 'error', 'started_at' => $startedAt, 'finished_at' => date('c'),
                              'summary' => null, 'error' => $e->getMessage(), 'pid' => null]);
            $log('실패: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
