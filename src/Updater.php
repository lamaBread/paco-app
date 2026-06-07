<?php
/**
 * 자가 업데이트 — GitHub 공개 repo 의 **버전 태그** 기반 (요구사항 #2).
 *
 * 실행 모델은 `php -S localhost:8001 -t public` 이고, 앱은 공개 repo 의 클론이다.
 * 업데이트는 "최신 태그를 임시 폴더에 git clone → 코드 파일을 덮어쓰기"(사용자 채택안)로
 * 하되, 다음 안전장치를 둔다:
 *   ① 사전 검증(클론 결과의 VERSION/구조)  ② 코드+DB 백업(data/backups/)
 *   ③ data/(편집 원본 DB)·.git 은 절대 건드리지 않음  ④ 마이그레이션은 새 코드로 별도
 *      프로세스에서 실행  ⑤ 어느 단계든 실패하면 백업에서 자동 롤백.
 *
 * 데이터·설정 보존: 사용자 데이터(DB)는 data/ 에, 사용자 설정은 DB(app_setting)에 있어
 * 코드 덮어쓰기와 무관하게 보존된다(config.php 는 출하 기본값일 뿐 — Settings 참고).
 *
 * 안전 가드: 개발 모드(PACO_DEV) 또는 커밋되지 않은 변경이 있는 git 작업트리에서는
 * 적용을 거부한다(개발자 작업트리 보호). 먼 미래의 Electron 배포(v2.0.0)에서는 이 PHP
 * 자가갱신 대신 Electron 자동업데이트로 대체될 수 있다 — 로드맵 참고.
 */

namespace PACO;

final class Updater
{
    private string $baseDir;     // 앱 루트(= config.php 가 있는 곳, 덮어쓰기 대상)
    private string $dbPath;
    private string $repoUrl;     // 공개 repo (https)
    private string $repoWeb;     // 사람용 웹 URL
    private string $current;     // 현재 VERSION

    /** 코드 덮어쓰기/백업에서 절대 건드리지 않는 최상위 항목(편집 DB·git·빌드산출물). */
    private const PRESERVE = ['.git', 'data', 'dist'];

    public function __construct(array $cfg)
    {
        $this->baseDir = rtrim((string) ($cfg['base_dir'] ?? __DIR__ . '/..'), '/');
        $this->dbPath  = (string) ($cfg['db_path'] ?? $this->baseDir . '/data/paco.sqlite');
        $this->repoUrl = (string) ($cfg['repo']['url'] ?? '');
        $this->repoWeb = (string) ($cfg['repo']['web'] ?? '');
        $this->current = (string) ($cfg['version'] ?? 'dev');
    }

    // ─────────────────────────────────────────────────────────── 상태 조회

    /** 업데이트 가능 여부 점검(네트워크). 항상 배열을 반환(예외는 error 로 흡수). */
    public function check(): array
    {
        $res = [
            'ok'        => false,
            'current'   => $this->current,
            'latest'    => null,
            'latestTag' => null,
            'hasUpdate' => false,
            'git'       => $this->gitAvailable(),
            'isRepo'    => is_dir($this->baseDir . '/.git'),
            'devMode'   => (bool) getenv('PACO_DEV'),
            'dirty'     => false,
            'repoWeb'   => $this->repoWeb,
            'error'     => null,
        ];
        if ($this->repoUrl === '') {
            $res['error'] = 'repo URL 이 설정되지 않았습니다(config.php repo.url).';
            return $res;
        }
        if (!$res['git']) {
            $res['error'] = 'git 이 설치되어 있지 않습니다.';
            return $res;
        }
        $res['dirty'] = $this->isDirtyGitTree();
        try {
            $tags = $this->remoteTags();
        } catch (\Throwable $e) {
            $res['error'] = $e->getMessage();
            return $res;
        }
        if (!$tags) {
            $res['error'] = '원격에 버전 태그가 없습니다.';
            return $res;
        }
        $latest = $tags[0]; // remoteTags() 가 내림차순 정렬
        $res['latest']    = $latest['ver'];
        $res['latestTag'] = $latest['tag'];
        $res['hasUpdate'] = version_compare($latest['ver'], $this->current, '>');
        $res['ok'] = true;
        return $res;
    }

    /** 원격 semver 태그를 내림차순으로. [['tag'=>'v0.2.0','ver'=>'0.2.0'], …] */
    public function remoteTags(): array
    {
        [$code, $out] = $this->git(['ls-remote', '--tags', $this->repoUrl]);
        if ($code !== 0) {
            throw new \RuntimeException('원격 태그 조회 실패: ' . $this->firstLine($out));
        }
        $tags = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('#refs/tags/(v?\d+\.\d+\.\d+)(\^\{\})?$#', $line, $m)) {
                continue;
            }
            $tag = $m[1];
            $ver = ltrim($tag, 'v');
            $tags[$ver] = ['tag' => $tag, 'ver' => $ver]; // ^{} 중복 자동 제거
        }
        usort($tags, static fn($a, $b) => version_compare($b['ver'], $a['ver']));
        return array_values($tags);
    }

    // ─────────────────────────────────────────────────────────── 적용

    /**
     * 대상 버전으로 자가 업데이트한다. $log(callable) 로 진행을 흘려보낼 수 있다.
     * 반환: ['ok'=>bool, 'from'=>v, 'to'=>v|null, 'lines'=>[…], 'error'=>?str]
     */
    public function apply(string $target, ?callable $log = null): array
    {
        $result = ['ok' => false, 'from' => $this->current, 'to' => null, 'lines' => [], 'error' => null];
        $L = function (string $m) use (&$result, $log): void {
            $result['lines'][] = $m;
            if ($log) {
                $log($m);
            }
        };

        // ── 0. 가드 ──
        if (getenv('PACO_DEV')) {
            $result['error'] = '개발 모드(PACO_DEV)에서는 자가 업데이트가 비활성화됩니다.';
            return $result;
        }
        if (!$this->gitAvailable()) {
            $result['error'] = 'git 이 설치되어 있지 않습니다.';
            return $result;
        }
        if ($this->isDirtyGitTree()) {
            $result['error'] = '작업트리에 커밋되지 않은 변경이 있어 중단합니다(개발 중이면 정상). 변경을 정리한 뒤 다시 시도하세요.';
            return $result;
        }

        // 대상 태그 정규화 + 원격 존재 검증(원격 목록 안의 태그만 허용)
        $norm = ltrim(trim($target), 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $norm)) {
            $result['error'] = "잘못된 버전 형식: {$target}";
            return $result;
        }
        try {
            $tags = $this->remoteTags();
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            return $result;
        }
        $tag = null;
        foreach ($tags as $t) {
            if ($t['ver'] === $norm) {
                $tag = $t['tag'];
                break;
            }
        }
        if ($tag === null) {
            $result['error'] = "원격에 태그 v{$norm} 가 없습니다.";
            return $result;
        }

        $tmp    = sys_get_temp_dir() . '/paco-update-' . bin2hex(random_bytes(4));
        $newDir = $tmp . '/new';
        @mkdir($tmp, 0775, true);
        $backup = null;

        try {
            // ── 1. 클론(얕게, 해당 태그) ──
            $L("새 버전 클론: {$tag}  ({$this->repoUrl})");
            [$c, $o] = $this->git(['clone', '--depth', '1', '--branch', $tag, $this->repoUrl, $newDir]);
            if ($c !== 0) {
                throw new \RuntimeException('클론 실패: ' . $this->firstLine($o));
            }

            // ── 2. 검증 ──
            if (!is_file($newDir . '/public/index.php')) {
                throw new \RuntimeException('클론 검증 실패: public/index.php 가 없습니다.');
            }
            $clonedVer = trim((string) @file_get_contents($newDir . '/VERSION'));
            if ($clonedVer !== $norm) {
                throw new \RuntimeException("클론 버전 불일치: VERSION={$clonedVer}, 기대={$norm}");
            }
            $L("검증 통과 (VERSION={$clonedVer})");

            // ── 3. 백업(코드 + DB) ──
            $backup = $this->backupAll($L);

            // ── 4. 코드 덮어쓰기 (data/ · .git 보존) ──
            $L('코드 덮어쓰기 (data/ · .git 은 보존)');
            $this->overwrite($newDir, $this->baseDir);

            // ── 5. 마이그레이션 (새 코드, 별도 프로세스) ──
            $L('데이터 마이그레이션 실행');
            [$mc, $mo] = $this->runMigrate();
            foreach (preg_split('/\r?\n/', rtrim($mo)) as $ln) {
                if ($ln !== '') {
                    $L('  ' . $ln);
                }
            }
            if ($mc !== 0) {
                throw new \RuntimeException("마이그레이션 실패 (exit {$mc})");
            }

            // ── 6. git 메타데이터 정합(설치본이 git 클론일 때) ──
            // 클론+덮어쓰기는 작업트리 파일만 바꾸므로, git 클론 설치본은 HEAD 가 옛 커밋에
            // 머물러 '더티'로 보인다 → 다음 업데이트가 dirty 가드에 막힌다. HEAD/인덱스를 새
            // 태그로 맞춰(작업트리는 이미 새 내용) status 를 깨끗하게 만든다. best-effort.
            $this->syncGitHead($tag, $L);

            // ── 성공 ──
            $result['ok'] = true;
            $result['to'] = $norm;
            $L("업데이트 완료: v{$result['from']} → v{$norm}. 페이지를 새로고침하면 새 버전으로 동작합니다.");
        } catch (\Throwable $e) {
            $L('✗ 오류: ' . $e->getMessage());
            if ($backup !== null) {
                $L('롤백: 백업에서 코드·DB 를 복원합니다');
                try {
                    $this->rollback($backup);
                    $this->cleanGitTree();   // 복원도 copy(모드 차이) → HEAD 로 정합해 다음 업데이트가 막히지 않게
                    $L('롤백 완료 — 이전 버전(v' . $this->current . ')이 유지됩니다.');
                } catch (\Throwable $r) {
                    $L('⚠ 롤백 중 오류: ' . $r->getMessage());
                    $L('  수동 복원: ' . ($backup['code'] ?? '?') . ' (코드) / ' . ($backup['db'] ?? '?') . ' (DB)');
                }
            }
            $result['error'] = $e->getMessage();
        } finally {
            $this->rrmdir($tmp);
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────── 내부

    private function gitAvailable(): bool
    {
        [$c] = $this->git(['--version']);
        return $c === 0;
    }

    private function isDirtyGitTree(): bool
    {
        if (!is_dir($this->baseDir . '/.git')) {
            return false;
        }
        [$c, $o] = $this->git(['status', '--porcelain'], $this->baseDir);
        return $c === 0 && trim($o) !== '';
    }

    /** git 실행(인자 배열, 각 인자 escapeshellarg). 반환 [exitCode, combinedOutput]. */
    private function git(array $args, ?string $cwd = null): array
    {
        $parts = ['git'];
        if ($cwd !== null) {
            $parts[] = '-C';
            $parts[] = $cwd;
        }
        foreach ($args as $a) {
            $parts[] = (string) $a;
        }
        $cmd = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    /**
     * 설치본이 git 클론이면 HEAD/작업트리를 새 태그로 맞춰 status 를 깨끗하게 한다. best-effort.
     * (클론+덮어쓰기는 작업트리 파일만 바꾸므로 HEAD 가 옛 커밋에 머물러 '더티'로 보인다.
     *  --hard 로 새 태그에 정합 — 내용은 이미 동일, 파일모드 차이까지 정리. data/ 는 gitignore 라 보존.)
     */
    private function syncGitHead(string $tag, callable $L): void
    {
        if (!is_dir($this->baseDir . '/.git')) {
            return;
        }
        [$cf] = $this->git(['fetch', '--tags', '--quiet', 'origin'], $this->baseDir);
        if ($cf !== 0) {
            return; // 원격에서 태그를 못 받으면 정합 생략(업데이트 자체는 이미 성공)
        }
        // 분기된 클론 보호: HEAD 가 태그의 '조상'일 때만 hard-reset. 사용자가 로컬 커밋을 올린
        // 포크/브랜치에서 reset --hard 하면 그 커밋들이 사라지므로(reflog 외 복구 어려움) 건너뛴다.
        [$ca] = $this->git(['merge-base', '--is-ancestor', 'HEAD', $tag], $this->baseDir);
        if ($ca !== 0) {
            $L('로컬 커밋이 있어(분기) git HEAD 동기화를 건너뜁니다 — 작업트리 코드는 새 버전입니다.');
            return;
        }
        [$c] = $this->git(['reset', '--hard', '--quiet', $tag], $this->baseDir);
        if ($c === 0) {
            $L("git HEAD 를 {$tag} 로 동기화(작업트리 정합).");
        }
    }

    /** 롤백 후 작업트리를 HEAD 로 정합(네트워크 불필요 — 복원 내용 == HEAD). best-effort. */
    private function cleanGitTree(): void
    {
        if (is_dir($this->baseDir . '/.git')) {
            $this->git(['reset', '--hard', '--quiet', 'HEAD'], $this->baseDir);
        }
    }

    /** 새 코드(별도 프로세스)로 마이그레이션 실행. 반환 [exitCode, output]. */
    private function runMigrate(): array
    {
        $php    = PHP_BINARY ?: 'php';
        $script = $this->baseDir . '/bin/migrate.php';
        // PACO_DB_PATH 는 현재 프로세스 env 를 상속(설정돼 있으면 그대로, 없으면 새 코드가 기본값 사용 → 동일 DB).
        $cmd = implode(' ', array_map('escapeshellarg', [$php, $script])) . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    /** 코드 스냅샷 + DB 백업을 data/backups/ 에. 반환 ['code'=>dir,'db'=>file|null]. */
    private function backupAll(callable $L): array
    {
        $root = \dirname($this->dbPath) . '/backups';
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        $ts  = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $ver = $this->current;

        // 코드 스냅샷(보존 항목 제외)
        $codeDir = $root . "/app-pre-v{$ver}-{$ts}";
        @mkdir($codeDir, 0775, true);
        foreach (scandir($this->baseDir) as $f) {
            if ($f === '.' || $f === '..' || in_array($f, self::PRESERVE, true)) {
                continue;
            }
            $sp = $this->baseDir . '/' . $f;
            $dp = $codeDir . '/' . $f;
            is_dir($sp) ? $this->cpdir($sp, $dp) : @copy($sp, $dp);
        }
        $L('백업: 코드 → ' . $codeDir);

        // DB 백업 — WAL-정합 단일 파일(Database::backupTo = 체크포인트 + VACUUM INTO).
        // 라이브 WAL DB 를 .sqlite/-wal/-shm 으로 따로 복사하면 비정합·복원 손상 위험이 있어 쓰지 않는다.
        // 백업이 실패하면 복원점 없이 진행하지 않도록 예외로 중단한다(overwrite 전 단계라 안전).
        $dbBak = null;
        if (is_file($this->dbPath)) {
            $dbBak = $root . "/db-pre-v{$ver}-{$ts}.sqlite";
            if (!Database::backupTo($this->dbPath, $dbBak)) {
                throw new \RuntimeException('DB 백업 실패 — 안전한 복원점을 만들 수 없어 업데이트를 중단합니다.');
            }
            $L('백업: DB → ' . $dbBak);
        }
        return ['code' => $codeDir, 'db' => $dbBak];
    }

    /**
     * $src(클론)의 내용을 $dst(앱 루트)에 덮어쓴다. PRESERVE(data/·.git·dist) 는 건드리지 않는다.
     * 새 버전에서 사라진 최상위 항목은 제거해, git/비-git 설치본이 같은 파일집합으로 수렴하고
     * 옛 파일이 남아 stale include 를 일으키지 않게 한다.
     */
    private function overwrite(string $src, string $dst): void
    {
        $kept = [];
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..' || in_array($f, self::PRESERVE, true)) {
                continue;
            }
            $kept[$f] = true;
            $sp = $src . '/' . $f;
            $dp = $dst . '/' . $f;
            if (is_dir($sp)) {
                $this->rrmdir($dp);   // 디렉터리는 통째로 교체(삭제된 파일까지 반영)
                $this->cpdir($sp, $dp);
            } else {
                @copy($sp, $dp);
            }
        }
        // 새 버전에 없는 최상위 항목 제거(PRESERVE 제외)
        foreach (scandir($dst) as $f) {
            if ($f === '.' || $f === '..' || in_array($f, self::PRESERVE, true) || isset($kept[$f])) {
                continue;
            }
            $p = $dst . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
    }

    /** 백업에서 코드·DB 를 복원. */
    private function rollback(array $backup): void
    {
        $codeDir = $backup['code'] ?? null;
        if ($codeDir && is_dir($codeDir)) {
            foreach (scandir($codeDir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $sp = $codeDir . '/' . $f;
                $dp = $this->baseDir . '/' . $f;
                if (is_dir($sp)) {
                    $this->rrmdir($dp);
                    $this->cpdir($sp, $dp);
                } else {
                    @copy($sp, $dp);
                }
            }
        }
        $dbBak = $backup['db'] ?? null;
        if ($dbBak && is_file($dbBak)) {
            // 백업은 단일 정합 파일이다. 라이브 -wal/-shm 을 먼저 제거하고 본 파일만 교체한다
            // (라이브 연결은 apply 진입 시 close 되어 열린 핸들이 없다).
            foreach (['-wal', '-shm'] as $suf) {
                if (is_file($this->dbPath . $suf)) {
                    @unlink($this->dbPath . $suf);
                }
            }
            @copy($dbBak, $this->dbPath);
        }
    }

    private function cpdir(string $src, string $dst): void
    {
        @mkdir($dst, 0775, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            is_dir($s) ? $this->cpdir($s, $d) : @copy($s, $d);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function firstLine(string $s): string
    {
        $s = trim($s);
        $nl = strpos($s, "\n");
        return $nl === false ? $s : substr($s, 0, $nl);
    }
}
