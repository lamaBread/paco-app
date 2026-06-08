<?php
/** 페이지: 설정(app_setting) · 업데이트(git 태그 자가갱신). 정적 빌드에는 포함되지 않는다. */

namespace PACO;

// ============================================================== 설정
function page_settings(Repo $repo, array $cfg, array $req): array
{
    $eff = Settings::effective($cfg);   // 현재 유효값(DB 설정이 덧씌워진 cfg 기준)
    $saveUrl = h(url('settings/save'));
    $saveUrl_people = h(url('people'));

    $fields = '';
    foreach (Settings::EDITABLE as $key => $meta) {
        $val   = h($eff[$key] ?? '');
        $label = h($meta['label']);
        $hint  = h($meta['hint']);
        $kh    = h($key);
        $fields .= <<<HTML
    <label>{$label}
      <input name="{$kh}" value="{$val}">
      <small>{$hint}</small>
    </label>
HTML;
    }

    // 진단 정보
    $uv = '?';
    try {
        $uv = (string) $repo->pdo()->query('PRAGMA user_version')->fetchColumn();
    } catch (\Throwable $e) { /* 무시 */ }
    $ver    = h((string) ($cfg['version'] ?? 'dev'));
    $schema = h($uv) . ' / ' . Database::SCHEMA_VERSION;
    $dbPath = h((string) ($cfg['db_path'] ?? ''));

    // ── 비평자(나) — LOD 등록 없이도 입력하는 '나'(pac:Critic). 새 비평문의 기본 저자. ──
    $meId = Settings::get($repo->pdo(), 'me_person_id');
    $me   = $meId ? $repo->person($meId) : null;
    $meSave = h(url('me/save'));
    $meName = h($me['name'] ?? '');
    $meNote = h($me['note'] ?? '');
    $meSame = h($me['same_as'] ?? '');
    $meNl   = h($me['nl_uri'] ?? '');
    $meIsni = h($me['isni'] ?? '');
    $meChips = $me ? identifier_chips($me) : '';
    $meStatus = $me
        ? '<p class="fill-note ok">현재 ‘나’: <b>' . h($me['name']) . '</b> <span class="idchips">' . $meChips . '</span> — 새 비평문의 비평자로 자동 선택됩니다.</p>'
        : '<p class="muted">아직 ‘나’가 설정되지 않았습니다. 이름만 입력해도 됩니다(LOD 연결은 선택).</p>';

    $body = <<<HTML
<section class="panel">
  <div class="panel-head"><h2>비평자 — 나</h2></div>
  <p class="lead">시를 읽고 비평하는 <b>나(pac:Critic)</b>의 정보입니다. 모두가 국가서지LOD 에
     등록되어 있지는 않으므로, <b>외부 LOD 연결 없이 이름만으로</b> 등록할 수 있습니다.
     여기서 만든 ‘나’가 새 비평문의 기본 비평자가 됩니다.</p>
  {$meStatus}
  <form method="post" action="{$meSave}" class="form">
    <label>이름 <span class="req">*</span><input name="name" required value="{$meName}" placeholder="예: 홍길동 (또는 필명)"></label>
    <label>소개 / 약력 <small>(선택 — 내부 메모. LOD 로 발행되지 않습니다)</small>
      <textarea name="note" rows="2">{$meNote}</textarea>
    </label>
    <fieldset class="idset">
      <legend>외부 LOD 식별자 <small>— 모두 선택사항. 없어도 됩니다(owl:sameAs 로 발행)</small></legend>
      <label>Wikidata IRI <small>(있으면)</small>
        <input name="same_as" value="{$meSame}" placeholder="http://www.wikidata.org/entity/Q…">
      </label>
      <label>국가서지LOD 자원 URI <small>(있으면)</small>
        <input name="nl_uri" value="{$meNl}" placeholder="http://lod.nl.go.kr/resource/KAC…">
      </label>
      <label>ISNI <small>(있으면 · 16자리)</small>
        <input name="isni" value="{$meIsni}" placeholder="0000 0000 0000 0000">
      </label>
    </fieldset>
    <div class="form-actions"><button class="btn primary" type="submit">‘나’ 저장</button>
      <a class="btn" href="{$saveUrl_people}">인물 목록에서 보기</a></div>
  </form>
</section>
<section class="panel">
  <div class="panel-head"><h2>설정</h2></div>
  <p class="lead">아래 값은 DB(<code>app_setting</code>)에 저장되어, 코드 업데이트(파일 덮어쓰기)와
     무관하게 보존됩니다. 비워서 저장하면 출하 기본값으로 되돌아갑니다.</p>
  <form method="post" action="{$saveUrl}" class="form">
{$fields}
    <div class="form-actions"><button class="btn primary" type="submit">저장</button></div>
  </form>
</section>
<section class="panel">
  <div class="panel-head"><h2>진단</h2></div>
  <table class="kv">
    <tr><th>앱 버전</th><td>v{$ver}</td></tr>
    <tr><th>DB 스키마(user_version / 기대)</th><td>{$schema}</td></tr>
    <tr><th>DB 경로</th><td><code>{$dbPath}</code></td></tr>
  </table>
</section>
HTML;
    return ['설정', $body];
}

// ============================================================== 업데이트
function page_update(Repo $repo, array $cfg, array $req): array
{
    $u    = new Updater($cfg);
    $info = $u->check();                       // 네트워크: 원격 태그 조회
    $ver  = h((string) $info['current']);
    $web  = h((string) ($info['repoWeb'] ?? ''));

    // 직전 업데이트 결과(세션) 표시
    $resultHtml = '';
    if (!empty($_SESSION['paco_update_result'])) {
        $r = $_SESSION['paco_update_result'];
        unset($_SESSION['paco_update_result']);
        $cls   = !empty($r['ok']) ? 'ok' : 'danger';
        $head  = !empty($r['ok'])
            ? '업데이트 완료 → v' . h((string) $r['to'])
            : '업데이트 실패';
        $log = '';
        foreach (($r['lines'] ?? []) as $ln) {
            $log .= h((string) $ln) . "\n";
        }
        if ($log === '' && !empty($r['error'])) {   // 가드 거부 등 로그 없는 실패
            $log = h((string) $r['error']) . "\n";
        }
        $resultHtml = <<<HTML
<section class="panel">
  <div class="panel-head"><h2 class="{$cls}">{$head}</h2></div>
  <pre class="log">{$log}</pre>
</section>
HTML;
    }

    // 상태/액션 영역
    if ($info['devMode']) {
        $statusHtml = '<p class="muted">개발 모드(PACO_DEV)에서는 자가 업데이트가 비활성화됩니다. '
            . '<code>paco dev</code> 가 아닌 일반 실행(<code>php -S localhost:8001 -t public</code>)에서 사용하세요.</p>';
    } elseif (!$info['ok']) {
        $err = h((string) $info['error']);
        $statusHtml = '<p class="danger">최신 버전 확인 실패: ' . $err . '</p>'
            . '<p class="muted">인터넷 연결 또는 git 설치를 확인하세요. 수동 확인: '
            . '<a href="' . $web . '/tags" target="_blank" rel="noopener">GitHub 태그</a></p>';
    } else {
        $latest = h((string) $info['latest']);
        if ($info['hasUpdate']) {
            $applyUrl = h(url('update/apply'));
            $tag = h((string) $info['latestTag']);
            $warn = $info['dirty']
                ? '<p class="danger">⚠ 작업트리에 커밋되지 않은 변경이 있어 업데이트가 거부됩니다(개발 중이면 정상).</p>'
                : '';
            $statusHtml = <<<HTML
<p>새 버전 <b>v{$latest}</b> 가 있습니다 (현재 v{$ver}).</p>
{$warn}
<form method="post" action="{$applyUrl}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='업데이트 중… (수십 초)';">
  <input type="hidden" name="tag" value="{$tag}">
  <div class="form-actions">
    <button class="btn primary" type="submit">지금 업데이트 (v{$latest})</button>
    <a class="btn" href="{$web}/releases/tag/{$tag}" target="_blank" rel="noopener">릴리스 노트</a>
  </div>
</form>
<p class="muted">최신 태그를 임시 폴더에 클론한 뒤 코드만 덮어씁니다. 데이터(<code>data/</code>)와
   설정(DB)은 보존되며, 실패하면 백업에서 자동 복원합니다.</p>
HTML;
        } else {
            $statusHtml = '<p class="ok">최신 버전입니다 (v' . $ver . ').</p>';
        }
    }

    $isRepo = $info['isRepo'] ? 'git 클론' : 'git 아님(클론 폴백)';
    $gitOk  = $info['git'] ? '사용 가능' : '미설치';

    $body = <<<HTML
{$resultHtml}
<section class="panel">
  <div class="panel-head"><h2>업데이트</h2></div>
  {$statusHtml}
</section>
<section class="panel">
  <div class="panel-head"><h2>정보</h2></div>
  <table class="kv">
    <tr><th>현재 버전</th><td>v{$ver}</td></tr>
    <tr><th>저장소</th><td><a href="{$web}" target="_blank" rel="noopener">{$web}</a></td></tr>
    <tr><th>git</th><td>{$gitOk}</td></tr>
    <tr><th>실행 위치</th><td>{$isRepo}</td></tr>
  </table>
  <p class="muted">CLI 로도 가능합니다: <code>php bin/self-update.php --check</code> / <code>php bin/self-update.php</code></p>
</section>
HTML;
    return ['업데이트', $body];
}
