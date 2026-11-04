(function(){
  'use strict';

  function $(sel, root){ return (root||document).querySelector(sel); }

  var cfg = (typeof AMSearchPreview !== 'undefined') ? AMSearchPreview : null;
  if (!cfg || !cfg.ajaxUrl || !cfg.nonce) return;

  var input = document.getElementById('am_s');
  var box   = document.getElementById('am-search-preview');
  if (!input || !box) return;

  // Restore query from URL if present (so Back button keeps the typed query)
  try {
    var url0 = new URL(window.location.href);
    var q0 = url0.searchParams.get('am_s');
    if (q0 && !input.value) {
      input.value = q0;
    }
  } catch(e) {}

  var timer = null;
  var lastQ = '';
  var inflight = null;

  function esc(s){
    return String(s||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  // Decode HTML entities coming from legacy content/imports (e.g. "&amp;" -> "&").
  // We still escape before injecting into the DOM, so this is safe.
  function decodeEntities(s){
    s = String(s||'');
    if (s.indexOf('&') === -1) return s;
    var t = document.createElement('textarea');
    t.innerHTML = s;
    return t.value;
  }

  function escRe(s){
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function highlight(text, q){
    var t = decodeEntities(String(text||''));
    var qq = String(q||'').trim();
    if (!qq) return esc(t);
    try {
      var re = new RegExp('(' + escRe(qq) + ')', 'ig');
      return esc(t).replace(re, '<mark class="am-search-preview__mark">$1</mark>');
    } catch(e) {
      return esc(t);
    }
  }

  function syncUrl(q){
    try {
      var u = new URL(window.location.href);
      if (q && String(q).trim() !== '') u.searchParams.set('am_s', String(q).trim());
      else u.searchParams.delete('am_s');
      window.history.replaceState({}, '', u.toString());
    } catch(e) {}
  }

  function postAjax(action, data){
    var params = new URLSearchParams();
    params.append('action', action);
    params.append('nonce', cfg.nonce);
    Object.keys(data||{}).forEach(function(k){
      params.append(k, data[k]);
    });

    if (inflight && inflight.abort) {
      inflight.abort();
    }
    inflight = new AbortController();

    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: params.toString(),
      signal: inflight.signal
    }).then(function(r){ return r.json(); });
  }

  function renderItems(items, q){
    if (!items || !items.length) {
      box.innerHTML = '';
      box.classList.remove('is-open');
      return;
    }

    var html = '<div class="am-search-preview__panel">';
    html += '<div class="am-search-preview__hint">Anteprime</div>';

    var max = Math.min(items.length, 10);
    for (var i=0;i<max;i++){
      var it = items[i];
      var metaParts = [];
      if (it.forma) metaParts.push('f./var. ' + highlight(it.forma, q));
      if (it.autore) metaParts.push('Autore ' + highlight(it.autore, q));
      var meta = metaParts.join(' — ');

      html += '<a class="am-search-preview__item" href="' + esc(it.link) + '">';
      if (it.thumb) {
        html += '<div class="am-search-preview__thumb"><img loading="lazy" alt="" src="' + esc(it.thumb) + '" /></div>';
      }
      html +=   '<div class="am-search-preview__body">';
      html +=     '<div class="am-search-preview__title">' + highlight(it.title, q) + '</div>';
      if (meta) html += '<div class="am-search-preview__meta">' + meta + '</div>';
      html +=   '</div>';
      html += '</a>';
    }

    if (items.length > max) {
      html += '<div class="am-search-preview__more">+ ' + (items.length - max) + ' altri… (premi Invio per la lista completa)</div>';
    }

    html += '</div>';
    box.innerHTML = html;
    box.classList.add('is-open');
  }

  function doSearch(q){
    q = String(q||'').trim();
    if (q.length < 2) {
      lastQ = q;
      box.innerHTML = '';
      box.classList.remove('is-open');
      return;
    }
    if (q === lastQ) return;
    lastQ = q;

    box.innerHTML = '<div class="am-search-preview__panel"><div class="am-search-preview__loading">Ricerca…</div></div>';
    box.classList.add('is-open');

    postAjax('am_archive_search', { s: q }).then(function(resp){
      if (!resp || !resp.success) { renderItems([]); return; }
    renderItems((resp.data && resp.data.items) ? resp.data.items : [], q);
    }).catch(function(){
      // abort is ok
    });
  }

  function debounce(){
    clearTimeout(timer);
    timer = setTimeout(function(){ syncUrl(input.value); doSearch(input.value); }, 250);
  }

  input.addEventListener('input', debounce);

  // Run once on load if there is already a value
  if (String(input.value||'').trim().length >= 2) {
    syncUrl(input.value);
    doSearch(input.value);
  }

  // Chiudi suggerimenti quando esci dal campo (con piccolo delay per permettere click)
  input.addEventListener('blur', function(){
    setTimeout(function(){
      if (document.activeElement && box.contains(document.activeElement)) return;
      box.classList.remove('is-open');
    }, 180);
  });

  input.addEventListener('focus', function(){
    if (box.innerHTML.trim() !== '') box.classList.add('is-open');
  });
})();
