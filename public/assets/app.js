/* PACO — 클라이언트 인터랙션 */
(function () {
  'use strict';

  /* ---------- 삭제 확인 ---------- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-confirm]');
    if (a && !window.confirm(a.getAttribute('data-confirm'))) e.preventDefault();
  });

  /* ================= 분할 뷰: q ↔ 인용 연결 ================= */
  var dataEl = document.getElementById('paco-quotations');
  if (dataEl) initSplit(JSON.parse(dataEl.textContent || '[]'));

  function initSplit(quotations) {
    var split = document.getElementById('split');
    var svg = document.getElementById('connectors');
    var index = {};
    quotations.forEach(function (q) { index[q.id] = q; });
    var pinned = null;

    function clear() {
      document.querySelectorAll('.pline.hot').forEach(function (el) { el.classList.remove('hot'); });
      document.querySelectorAll('.qmark.active').forEach(function (el) { el.classList.remove('active'); });
      document.querySelectorAll('.qcard.active').forEach(function (el) { el.classList.remove('active'); });
      if (svg) svg.innerHTML = '';
    }

    function linesFor(target) {
      var block = document.querySelector('.poemblock[data-poem="' + cssEsc(target.poem) + '"]');
      if (!block) return [];
      var out = [];
      var single = (target.es == null || target.es === target.ss);
      if (target.sl != null && single) {
        var end = target.el != null ? target.el : target.sl;
        for (var ln = target.sl; ln <= end; ln++) {
          var el = block.querySelector('.pline[data-stanza="' + target.ss + '"][data-line="' + ln + '"]');
          if (el) out.push(el);
        }
      } else if (target.ss != null) {
        var es = target.es != null ? target.es : target.ss;
        for (var s = target.ss; s <= es; s++) {
          block.querySelectorAll('.pline[data-stanza="' + s + '"]').forEach(function (el) { out.push(el); });
        }
      }
      return out;
    }

    function activate(id) {
      clear();
      var q = index[id];
      if (!q) return;
      var mark = document.querySelector('.qmark[data-anchor="' + cssEsc(q.anchor) + '"]');
      var card = document.querySelector('.qcard[data-quotation="' + cssEsc(id) + '"]');
      if (mark) mark.classList.add('active');
      if (card) card.classList.add('active');
      var anchors = [];
      q.targets.forEach(function (t) {
        var lines = linesFor(t);
        lines.forEach(function (el) { el.classList.add('hot'); });
        if (lines.length) anchors.push(lines[0]);
      });
      drawConnectors(mark, anchors);
    }

    function drawConnectors(mark, lineEls) {
      if (!svg || !mark || !split || !lineEls.length) return;
      var base = split.getBoundingClientRect();
      var m = mark.getBoundingClientRect();
      var x2 = m.left - base.left;                 // 마크 왼쪽
      var y2 = m.top - base.top + m.height / 2;
      var frag = '';
      lineEls.forEach(function (el) {
        var r = el.getBoundingClientRect();
        var x1 = r.right - base.left;               // 행 오른쪽
        var y1 = r.top - base.top + r.height / 2;
        var mx = (x1 + x2) / 2;
        frag += '<path d="M' + x1 + ',' + y1 + ' C' + mx + ',' + y1 + ' ' + mx + ',' + y2 + ' ' + x2 + ',' + y2 +
          '" fill="none" stroke="#d98a00" stroke-width="1.6" opacity="0.85"/>';
        frag += '<circle cx="' + x1 + '" cy="' + y1 + '" r="3" fill="#d98a00"/>';
        frag += '<circle cx="' + x2 + '" cy="' + y2 + '" r="3" fill="#d98a00"/>';
      });
      svg.innerHTML = frag;
    }

    function bind(selector, getId) {
      document.querySelectorAll(selector).forEach(function (el) {
        el.addEventListener('mouseenter', function () { if (!pinned) activate(getId(el)); });
        el.addEventListener('click', function () {
          var id = getId(el);
          if (pinned === id) { pinned = null; clear(); }
          else { pinned = id; activate(id); }
        });
      });
    }
    // qmark 의 id 는 anchor → quotation 매핑
    var byAnchor = {};
    quotations.forEach(function (q) { byAnchor[q.anchor] = q.id; });
    bind('.qmark', function (el) { return byAnchor[el.getAttribute('data-anchor')]; });
    bind('.qcard', function (el) { return el.getAttribute('data-quotation'); });

    document.querySelector('.pane.left') &&
      document.querySelector('.pane.left').addEventListener('mouseleave', function () { if (!pinned) clear(); });

    window.addEventListener('resize', function () { if (pinned) activate(pinned); });
    window.addEventListener('scroll', function () { if (pinned) activate(pinned); }, { passive: true });
  }

  function cssEsc(s) { return String(s).replace(/["\\]/g, '\\$&'); }

  /* ============ 편집기: 선택 영역을 <q xml:id> 로 감싸기 ============ */
  var wrapBtn = document.getElementById('btn-wrap-q');
  if (wrapBtn) {
    wrapBtn.addEventListener('click', function () {
      var ta = document.getElementById('full_text');
      if (!ta) return;
      var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
      if (s === e) { alert('먼저 본문에서 인용 표지로 만들 구간을 드래그해 선택하세요.'); return; }
      var sel = v.slice(s, e);
      var ids = [], m, re = /xml:id\s*=\s*["']?(\d+)/g;
      while ((m = re.exec(v))) ids.push(+m[1]);
      var n = ids.length ? Math.max.apply(null, ids) + 1 : 1;
      var wrapped = '<q xml:id="' + n + '">' + sel + '</q>';
      ta.value = v.slice(0, s) + wrapped + v.slice(e);
      var pos = s + wrapped.length;
      ta.focus(); ta.setSelectionRange(pos, pos);
      alert('인용 표지 #' + n + ' 을(를) 삽입했습니다.\n인용을 만들 때 앵커(xml:id)로 "' + n + '" 을 입력하세요.');
    });
  }

  /* ===== 인용 편집기 워크벤치(3분할): 시 본문(좌)·기입(중)·미리보기(우) =====
     한 화면에서 표지(<q>) 클릭 → 좌표 입력 → 저장(비동기) → 다음 표지로,
     페이지 이탈 없이 여러 인용을 연속 기입·수정한다. (v0.4.2) */
  var qeditEl = document.getElementById('paco-qedit');
  if (qeditEl && document.getElementById('quotation-form')) {
    initQuotationWorkbench(JSON.parse(qeditEl.textContent || '{}'));
  }

  function initQuotationWorkbench(data) {
    var form = document.getElementById('quotation-form');
    var poems = data.poems || {};
    var saveUrl = data.saveUrl || form.getAttribute('action');
    var targetsEl = document.getElementById('targets');
    var tpl = document.getElementById('target-template');
    var anchorInput = form.querySelector('input[name="anchor"]');
    var idInput = document.getElementById('q-id');
    var stateEl = document.getElementById('qedit-state');
    var savedListEl = document.getElementById('saved-list');
    var saveBtn = document.getElementById('q-save');
    var marks = Array.prototype.slice.call(document.querySelectorAll('.qpreview .fulltext .qmark'));
    var counter = targetsEl.querySelectorAll('.target-row').length;

    // anchor(문자열) → 저장된 인용(클라이언트 모델). 앵커↔인용은 1:1(표지 모델).
    var byAnchor = {};
    (data.existing || []).forEach(function (q) { byAnchor[q.anchor] = q; });

    /* ---------- 연/행 → 시 행 텍스트 (원문 자동추출, 분할뷰와 동일 규칙) ---------- */
    function rangeLines(poem, ss, es, sl, el) {
      if (!poem || !ss) return null;
      var out = [];
      var single = (!es || es === ss);
      if (sl && single) {
        var st = poem[ss]; if (!st) return [];
        var end = el || sl;
        for (var ln = sl; ln <= end; ln++) if (st[ln] != null) out.push(st[ln]);
      } else {
        var e = es || ss;
        for (var s = ss; s <= e; s++) {
          var stz = poem[s]; if (!stz) continue;
          Object.keys(stz).map(Number).sort(function (a, b) { return a - b; })
            .forEach(function (k) { out.push(stz[k]); });
        }
      }
      return out;
    }
    function num(row, field) {
      var inp = row.querySelector('[name$="[' + field + ']"]');
      var v = (inp && inp.value !== '') ? parseInt(inp.value, 10) : null;
      return (v && v > 0) ? v : null;
    }

    /* ---------- 좌측 시 본문에 인용 범위를 실시간 강조 ---------- */
    function clearPoemHot() {
      document.querySelectorAll('.qsource .pline.hot').forEach(function (el) { el.classList.remove('hot'); });
    }
    function highlightPoem() {
      clearPoemHot();
      var first = null;
      targetsEl.querySelectorAll('.target-row').forEach(function (row) {
        var sel = row.querySelector('select[name$="[source]"]'); if (!sel) return;
        var src = sel.value || ''; var kind = src.split(':')[0]; var pid = src.slice(kind.length + 1);
        if (kind !== 'poem') return;
        var block = document.querySelector('.qsource .poemblock[data-poem="' + cssEsc(pid) + '"]');
        if (!block) return;
        var ss = num(row, 'start_stanza'), es = num(row, 'end_stanza'),
            sl = num(row, 'start_line'), el = num(row, 'end_line');
        if (!ss) return;
        var hits = [];
        var single = (!es || es === ss);
        if (sl && single) {
          var end = el || sl;
          for (var ln = sl; ln <= end; ln++) {
            var e1 = block.querySelector('.pline[data-stanza="' + ss + '"][data-line="' + ln + '"]');
            if (e1) hits.push(e1);
          }
        } else {
          var e2 = es || ss;
          for (var s = ss; s <= e2; s++) {
            block.querySelectorAll('.pline[data-stanza="' + s + '"]').forEach(function (el) { hits.push(el); });
          }
        }
        hits.forEach(function (el) { el.classList.add('hot'); });
        if (!first && hits.length) first = hits[0];
      });
      if (first) scrollWithin(document.querySelector('.qsource'), first);
    }
    function scrollWithin(container, el) {
      if (!container) return;
      var c = container.getBoundingClientRect(), r = el.getBoundingClientRect();
      if (r.top < c.top || r.bottom > c.bottom) container.scrollTop += (r.top - c.top) - 16;
    }

    /* ---------- 한 대상행: 원문 미리보기/자동채움 + 좌측 강조 ---------- */
    function updateRow(row) {
      var sourceSel = row.querySelector('select[name$="[source]"]');
      var exactEl = row.querySelector('[name$="[exact]"]');
      var prev = row.querySelector('.exact-preview');
      if (!sourceSel || !exactEl) return;
      var src = sourceSel.value || '';
      var kind = src.split(':')[0], pid = src.slice(kind.length + 1);
      if (kind === 'book') {
        if (prev) prev.textContent = '시집은 행 좌표가 없어 원문 자동추출을 지원하지 않습니다(행을 인용하려면 해당 시를 출처로 고르세요).';
      } else {
        var lines = poems[pid] ? rangeLines(poems[pid], num(row, 'start_stanza'),
          num(row, 'end_stanza'), num(row, 'start_line'), num(row, 'end_line')) : null;
        if (lines == null) { if (prev) prev.textContent = ''; }
        else if (!lines.length) { if (prev) prev.textContent = '(해당 범위를 찾을 수 없습니다)'; }
        else {
          var text = lines.join('\n');
          if (prev) prev.textContent = text;
          // 자동채움: 비어 있거나 직전 자동값 그대로일 때만(사용자 수동 수정은 보존).
          if (exactEl.value === '' || exactEl.value === exactEl.getAttribute('data-auto')) {
            exactEl.value = text;
            exactEl.setAttribute('data-auto', text);
          }
        }
      }
      highlightPoem();
    }

    /* ---------- 대상행 생성/세팅 ---------- */
    function buildRow(t) {
      var key = 'n' + (counter++);
      var html = tpl.innerHTML.split('__IDX__').join(key).trim();
      var wrap = document.createElement('div'); wrap.innerHTML = html;
      var row = wrap.firstElementChild;
      if (t) {
        var sel = row.querySelector('select[name$="[source]"]');
        if (sel && t.source) {
          sel.value = t.source;
          // 출처(시/시집)가 삭제돼 옵션이 없으면 값이 조용히 비워진다 → 저장 시 누락.
          // 잃지 않도록 임시 옵션을 끼워 값을 보존하고 사용자에게 보이게 한다.
          if (sel.value !== t.source) {
            var opt = document.createElement('option');
            opt.value = t.source;
            opt.textContent = '(삭제된 출처: ' + t.source + ')';
            sel.insertBefore(opt, sel.firstChild);
            sel.value = t.source;
          }
        }
        setVal(row, 'start_stanza', t.ss); setVal(row, 'end_stanza', t.es);
        setVal(row, 'start_line', t.sl); setVal(row, 'end_line', t.el);
        var ex = row.querySelector('[name$="[exact]"]');
        if (ex && t.exact != null && t.exact !== '') { ex.value = t.exact; }
      }
      return row;
    }
    function setVal(row, field, v) {
      var inp = row.querySelector('[name$="[' + field + ']"]');
      if (inp) inp.value = (v == null ? '' : v);
    }
    function addTarget(t) { var row = buildRow(t); targetsEl.appendChild(row); return row; }
    function setQtype(ty) {
      var r = form.querySelector('input[name="qtype"][value="' + (ty === 'direct' ? 'direct' : 'indirect') + '"]');
      if (r) r.checked = true;
    }

    /* ---------- 폼 ↔ 인용 모델 ---------- */
    function loadQuotation(q) {
      idInput.value = q.id || '';
      anchorInput.value = q.anchor || '';
      setQtype(q.qtype);
      targetsEl.innerHTML = '';
      var ts = (q.targets && q.targets.length) ? q.targets : [null];
      ts.forEach(function (t) { addTarget(t); });
      targetsEl.querySelectorAll('.target-row').forEach(updateRow);
      sync();
    }
    function blankForm(anchor) {
      idInput.value = '';
      anchorInput.value = anchor || '';
      setQtype('indirect');
      targetsEl.innerHTML = '';
      addTarget(null);
      clearPoemHot();
      sync();
    }
    function selectAnchor(a) {
      if (byAnchor[a]) loadQuotation(byAnchor[a]);
      else blankForm(a);
      anchorInput.focus();
    }

    /* ---------- 우측 표지·상태·목록 동기화 ---------- */
    function sync() {
      var a = anchorInput.value.trim();
      marks.forEach(function (el) {
        var ma = el.getAttribute('data-anchor') || '';
        el.classList.toggle('active', a !== '' && ma === a);
        el.classList.toggle('done', !!byAnchor[ma]);
      });
      if (idInput.value) stateEl.textContent = '— 수정 중: #' + a + ' (' + idInput.value + ')';
      else if (a) stateEl.textContent = '— 새 인용: #' + a;
      else stateEl.textContent = '— 새 인용';
      renderSavedList();
    }
    function renderSavedList() {
      var items = Object.keys(byAnchor).map(function (k) { return byAnchor[k]; });
      items.sort(function (a, b) {
        var na = parseInt(a.anchor, 10), nb = parseInt(b.anchor, 10);
        if (!isNaN(na) && !isNaN(nb) && na !== nb) return na - nb;
        return String(a.anchor).localeCompare(String(b.anchor));
      });
      savedListEl.innerHTML = '';
      if (!items.length) {
        var li0 = document.createElement('li'); li0.className = 'muted';
        li0.textContent = '아직 저장된 인용이 없습니다.'; savedListEl.appendChild(li0); return;
      }
      items.forEach(function (q) {
        var li = document.createElement('li');
        li.className = 'saved-item' + (idInput.value && idInput.value === q.id ? ' active' : '');
        var loc = q.targets.map(function (t) { return t.loc; }).filter(Boolean).join(', ');
        li.innerHTML = '<span class="qid">#' + escHtml(q.anchor) + '</span>' +
          ' <span class="tag ' + (q.qtype === 'direct' ? 'direct' : 'indirect') + '">' +
          (q.qtype === 'direct' ? '직접' : '간접') + '</span>' +
          ' <span class="loc">' + escHtml(loc || '위치 미지정') + '</span>';
        li.addEventListener('click', function () { selectAnchor(q.anchor); });
        savedListEl.appendChild(li);
      });
    }

    /* ---------- 이벤트 ---------- */
    marks.forEach(function (el) {
      el.style.cursor = 'pointer';
      el.addEventListener('click', function () { selectAnchor(el.getAttribute('data-anchor') || ''); });
    });
    anchorInput.addEventListener('input', function () { sync(); });

    document.getElementById('add-target').addEventListener('click', function () { addTarget(null); });
    targetsEl.addEventListener('click', function (e) {
      var rm = e.target.closest('.rm-target'); if (!rm) return;
      if (targetsEl.querySelectorAll('.target-row').length <= 1) { alert('대상은 최소 1개가 필요합니다.'); return; }
      rm.closest('.target-row').remove(); highlightPoem();
    });
    targetsEl.addEventListener('input', function (e) { var row = e.target.closest('.target-row'); if (row) updateRow(row); });
    targetsEl.addEventListener('change', function (e) { var row = e.target.closest('.target-row'); if (row) updateRow(row); });

    document.getElementById('q-new').addEventListener('click', function () { blankForm(''); });

    /* ---------- 비동기 저장(페이지 이탈 없음) ---------- */
    form.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) return; // 구형 브라우저 → 일반 POST 폴백
      e.preventDefault();
      var anchor = anchorInput.value.trim();
      if (!anchor) { alert('본문 앵커(xml:id)를 입력하거나 오른쪽 표지를 클릭하세요.'); anchorInput.focus(); return; }
      // 같은 앵커가 이미 있으면 그 인용을 갱신(중복 생성 방지). 표지↔인용 1:1.
      if (!idInput.value && byAnchor[anchor]) idInput.value = byAnchor[anchor].id;
      var old = saveBtn.textContent; saveBtn.disabled = true; saveBtn.textContent = '저장 중…';
      fetch(saveUrl, { method: 'POST', body: new FormData(form), headers: { 'X-PACO-Ajax': '1' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.ok || !res.quotation) throw new Error(res && res.error ? res.error : '저장 실패');
          var q = res.quotation;
          // 앵커 변경(이름 바꾸기) 대비: 같은 id 의 옛 앵커 키를 정리하고 다시 등록.
          Object.keys(byAnchor).forEach(function (k) { if (byAnchor[k].id === q.id) delete byAnchor[k]; });
          byAnchor[q.anchor] = q;
          idInput.value = q.id;
          sync();
          toast('인용 #' + q.anchor + ' 저장됨 — 다음 표지를 클릭하세요');
        })
        .catch(function (err) { alert('저장 실패: ' + err.message); })
        .finally(function () { saveBtn.disabled = false; saveBtn.textContent = old; });
    });

    /* ---------- 초기화: 서버가 그린 폼 상태를 모델과 맞춘다 ---------- */
    sync();
    targetsEl.querySelectorAll('.target-row').forEach(updateRow);
  }

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function toast(msg) {
    var t = document.getElementById('paco-toast');
    if (!t) { t = document.createElement('div'); t.id = 'paco-toast'; t.className = 'toast'; document.body.appendChild(t); }
    t.textContent = msg; t.classList.add('show');
    if (t._h) clearTimeout(t._h);
    t._h = setTimeout(function () { t.classList.remove('show'); }, 2200);
  }
})();
