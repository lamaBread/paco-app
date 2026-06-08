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

  /* ====== 인용 편집기 A: 본문 미리보기 <q> 클릭 → 앵커 채움 + 양방향 강조 ====== */
  var qform = document.getElementById('quotation-form');
  if (qform) initQuotationEditor(qform);

  function initQuotationEditor(form) {
    var anchorInput = form.querySelector('input[name="anchor"]');
    if (!anchorInput) return;
    var marks = document.querySelectorAll('.qpreview .fulltext .qmark');

    function highlight(val) {
      marks.forEach(function (el) {
        el.classList.toggle('active', val !== '' && el.getAttribute('data-anchor') === val);
      });
    }
    marks.forEach(function (el) {
      el.addEventListener('click', function () {
        var a = el.getAttribute('data-anchor') || '';
        anchorInput.value = a;
        highlight(a);
        anchorInput.focus();
      });
    });
    // 타이핑/자동완성으로 앵커를 직접 입력해도 해당 <q> 가 강조되도록(양방향).
    anchorInput.addEventListener('input', function () { highlight(anchorInput.value.trim()); });
    highlight(anchorInput.value.trim());
  }

  /* ====== 인용 편집기 B: 대상행 연/행 범위 → 시 원문 자동 표시·채움 ====== */
  var artEl = document.getElementById('paco-article');
  var targetsEl = document.getElementById('targets');
  if (artEl && targetsEl) initTargetExact(JSON.parse(artEl.textContent || '{}'), targetsEl);

  function initTargetExact(data, targets) {
    var poems = data.poems || {};

    // 범위 → 시 행 텍스트 배열. 분할뷰 linesFor() 와 같은 규칙:
    //  단일 연(끝연 없음/=시작연) + 행 지정 → 시작연 sl..el행 / 그 외 → 시작연..끝연 전체.
    // null 반환 = 범위 미입력(표시 안 함), [] = 범위는 있으나 본문에서 못 찾음.
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

    function update(row) {
      var sourceSel = row.querySelector('select[name$="[source]"]');
      var exactEl = row.querySelector('[name$="[exact]"]');
      var prev = row.querySelector('.exact-preview');
      if (!sourceSel || !exactEl) return;
      var src = sourceSel.value || '';
      var kind = src.split(':')[0], pid = src.slice(kind.length + 1);

      if (kind === 'book') {
        if (prev) prev.textContent = '시집은 행 좌표가 없어 원문 자동추출을 지원하지 않습니다(행을 인용하려면 해당 시를 출처로 고르세요).';
        return;
      }
      var lines = poems[pid] ? rangeLines(poems[pid], num(row, 'start_stanza'),
        num(row, 'end_stanza'), num(row, 'start_line'), num(row, 'end_line')) : null;

      if (lines == null) { if (prev) prev.textContent = ''; return; }
      if (!lines.length) { if (prev) prev.textContent = '(해당 범위를 찾을 수 없습니다)'; return; }

      var text = lines.join('\n');
      if (prev) prev.textContent = text;
      // 자동채움: 비어 있거나, 직전 자동값 그대로일 때만(사용자 수동 수정은 보존).
      if (exactEl.value === '' || exactEl.value === exactEl.getAttribute('data-auto')) {
        exactEl.value = text;
        exactEl.setAttribute('data-auto', text);
      }
    }

    // 이벤트 위임 — '+ 대상 추가'로 동적 생성된 행도 자동 적용된다.
    targets.addEventListener('input', function (e) {
      var row = e.target.closest('.target-row'); if (row) update(row);
    });
    targets.addEventListener('change', function (e) {
      var row = e.target.closest('.target-row'); if (row) update(row);
    });
    targets.querySelectorAll('.target-row').forEach(update);  // 초기 미리보기 1회
  }

  /* ============ 인용 편집기: 대상 행 추가/삭제 ============ */
  var addBtn = document.getElementById('add-target');
  if (addBtn) {
    var targets = document.getElementById('targets');
    var tpl = document.getElementById('target-template');
    var counter = targets.querySelectorAll('.target-row').length;
    addBtn.addEventListener('click', function () {
      var key = 'n' + (counter++);
      var html = tpl.innerHTML.split('__IDX__').join(key).trim();
      var wrap = document.createElement('div');
      wrap.innerHTML = html;
      targets.appendChild(wrap.firstElementChild);
    });
    targets.addEventListener('click', function (e) {
      var rm = e.target.closest('.rm-target');
      if (!rm) return;
      var rows = targets.querySelectorAll('.target-row');
      if (rows.length <= 1) { alert('대상은 최소 1개가 필요합니다.'); return; }
      rm.closest('.target-row').remove();
    });
  }
})();
