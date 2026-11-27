
/* AM 1.5.46 - dispatch archive updated event */
function amDispatchArchiveUpdated1546(target){
  try {
    var el = target && target.nodeType === 1 ? target : document;
    el.dispatchEvent(new CustomEvent('am:archive-updated', { bubbles: true }));
  } catch(e) {
    try {
      var ev = document.createEvent('Event');
      ev.initEvent('am:archive-updated', true, true);
      document.dispatchEvent(ev);
    } catch(ignore) {}
  }
}

(function(){
  "use strict";

  function qs(sel, el){ return (el||document).querySelector(sel); }

  function escHtml(s){
    return String(s||"").replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function escRegExp(s){
    return String(s||"").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function highlightHtml(text, query){
    var t = String(text||"");
    var q = String(query||"").trim();
    if(!q) return escHtml(t);
    try{
      var re = new RegExp(escRegExp(q), 'ig');
      var out = "";
      var last = 0;
      var m;
      while((m = re.exec(t)) !== null){
        var start = m.index;
        var end = start + m[0].length;
        out += escHtml(t.slice(last, start));
        out += '<mark class="am-hl">' + escHtml(t.slice(start, end)) + '</mark>';
        last = end;
        if(re.lastIndex === m.index) re.lastIndex++; // safety
      }
      out += escHtml(t.slice(last));
      return out;
    }catch(e){
      return escHtml(t);
    }
  }

  function postAjax(action, data){
    var fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", (window.AMArchive && AMArchive.nonce) ? AMArchive.nonce : "");
    Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });

    return fetch((window.AMArchive && AMArchive.ajaxUrl) ? AMArchive.ajaxUrl : "", {
      method: "POST",
      credentials: "same-origin",
      body: fd
    }).then(function(r){ return r.json(); });
  }

  function setStatus(box, msg){
    if(!box) return;
    box.textContent = msg || "";
  }

  function makeNode(type, item){
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "am-archive-node am-archive-node--" + type;
    btn.setAttribute("data-id", item.id);
    btn.setAttribute("aria-expanded", "false");

    var count = (item && typeof item.count !== "undefined" && item.count !== null) ? String(item.count) : "";
    var countHtml = count !== "" ? ('<span class="am-archive-node__count" aria-label="' + escHtml(count) + ' schede">' + escHtml(count) + '</span>') : "";

    var rankNames = { division: "Divisione", family: "Famiglia", genus: "Genere" };
    var rankHtml = rankNames[type] ? '<span class="am-archive-node__rank">' + rankNames[type] + '</span>' : "";
    btn.innerHTML = '<span class="am-archive-node__chev" aria-hidden="true">▸</span>' +
                    '<span class="am-archive-node__label">' + escHtml(item.name) + '</span>' +
                    rankHtml + countHtml;
      amDispatchArchiveUpdated1546(document);
    return btn;
  }

  function makeEntryRow(entry, opts){
    opts = opts || {};
    var q = opts.query || "";
    var withThumb = !!opts.withThumb;
    var highlight = !!opts.highlight;

    var a = document.createElement("a");
    a.className = "am-archive-entry";
    a.href = entry.link;

    // 1.6.31: name, infraspecific rank and authority are already in the formatted title.
    var metaBits = [];
    if(entry.type === "article"){ metaBits.push("Articolo scientifico"); }

    var titleHtml = entry.title_html ? entry.title_html : (highlight ? highlightHtml(entry.title, q) : escHtml(entry.title));
    var metaHtml  = metaBits.length ? (highlight ? highlightHtml(metaBits.join(" — "), q) : escHtml(metaBits.join(" — "))) : "";

    var thumbHtml = '';
    if(withThumb && entry.thumb){
      thumbHtml = '<img class="am-archive-entry__thumb" src="' + escHtml(entry.thumb) + '" alt="" loading="lazy" />';
    }

    a.innerHTML =
      '<div class="am-archive-entry__row">' +
        thumbHtml +
        '<div class="am-archive-entry__body">' +
          '<div class="am-archive-entry__title">' + titleHtml + '</div>' +
          (metaHtml ? '<div class="am-archive-entry__meta">' + metaHtml + '</div>' : '') +
        '</div>' +
      '</div>';
    if(entry.title_html && highlight && q){ highlightTextNodes(a.querySelector(".am-archive-entry__title"), q); }
      amDispatchArchiveUpdated1546(document);
    return a;
  }

  // Highlights the query inside already formatted HTML (keeps the italic binomial).
  function highlightTextNodes(el, query){
    if(!el){ return; }
    var re;
    try{ re = new RegExp(escRegExp(String(query).trim()), "ig"); }catch(e){ return; }
    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    while(walker.nextNode()){ nodes.push(walker.currentNode); }
    nodes.forEach(function(node){
      var text = node.nodeValue;
      re.lastIndex = 0;
      if(!re.test(text)){ return; }
      var span = document.createElement("span");
      span.innerHTML = highlightHtml(text, query);
      while(span.firstChild){ node.parentNode.insertBefore(span.firstChild, node); }
      node.parentNode.removeChild(node);
    });
  }

  function initArchive(){
    var root = qs("#am-archive");
    if(!root) return;

    var tree = qs(".am-archive__tree", root);
    var status = qs(".am-archive__status", root);
    var sentinel = qs(".am-archive__sentinel", root);
    var results = qs(".am-archive__results", root);
    var searchInput = qs(".am-archive__search", root);
    var searchBtn = qs(".am-archive__search-btn", root);

    var perPageFamilies = parseInt(root.getAttribute("data-per-page-families") || "30", 10);
    var perPageEntries  = parseInt(root.getAttribute("data-per-page-entries") || "30", 10);
    // 1.6.29: levels open at load, from Impostazioni → Pannelli dell'archivio (all closed by default).
    var openAtLoad = (root.getAttribute("data-open") || "").split(",");
    function openLevel(level){ return openAtLoad.indexOf(level) !== -1; }

    
var famPage = 1;
    var famHasMore = true;
    var famLoading = false;

    function renderDivisions(items){
      items.forEach(function(it){
        var wrap = document.createElement("div");
        wrap.className = "am-archive-branch am-archive-branch--division";
        var node = makeNode("division", it);
        wrap.appendChild(node);
        var children = document.createElement("div");
        children.className = "am-archive-children";
        children.hidden = true;
        wrap.appendChild(children);
        tree.appendChild(wrap);
        node.addEventListener("click", function(){ toggleDivision(node, children); });
        if(openLevel("division")){ toggleDivision(node, children); }
      });
    }

    function renderFamilies(items, host){
      items.forEach(function(it){
        var wrap = document.createElement("div");
        wrap.className = "am-archive-branch am-archive-branch--family";
        var node = makeNode("family", it);
        wrap.appendChild(node);
        var children = document.createElement("div");
        children.className = "am-archive-children";
        children.hidden = true;
        wrap.appendChild(children);
        host.appendChild(wrap);
        node.addEventListener("click", function(){ toggleFamily(node, children); });
        if(openLevel("family")){ toggleFamily(node, children); }
      });
    }

    function toggleDivision(node, childrenBox){
      var expanded = node.getAttribute("aria-expanded") === "true";
      if(expanded){ node.setAttribute("aria-expanded", "false"); childrenBox.hidden = true; return; }
      node.setAttribute("aria-expanded", "true");
      childrenBox.hidden = false;
      if(childrenBox.getAttribute("data-loaded") === "1") return;
      setStatus(status, "Carico le famiglie…");
      postAjax("am_archive_tree", { level: "families", division_id: node.getAttribute("data-id") }).then(function(resp){
        if(!resp || !resp.success){ setStatus(status, (resp && resp.data && resp.data.message) ? resp.data.message : "Errore caricamento famiglie."); return; }
        childrenBox.setAttribute("data-loaded", "1");
        renderFamilies(resp.data.items || [], childrenBox);
        setStatus(status, "");
      }).catch(function(){ setStatus(status, "Errore di rete."); });
    }

    function toggleFamily(node, childrenBox){
      var expanded = node.getAttribute("aria-expanded") === "true";
      if(expanded){ node.setAttribute("aria-expanded", "false"); childrenBox.hidden = true; return; }
      node.setAttribute("aria-expanded", "true");
      childrenBox.hidden = false;
      if(childrenBox.getAttribute("data-loaded") === "1") return;
      setStatus(status, "Carico i generi…");
      postAjax("am_archive_tree", { level: "genera", family_id: node.getAttribute("data-id") }).then(function(resp){
        if(!resp || !resp.success){ setStatus(status, (resp && resp.data && resp.data.message) ? resp.data.message : "Errore caricamento generi."); return; }
        childrenBox.setAttribute("data-loaded", "1");
        (resp.data.items || []).forEach(function(g){
          var gwrap = document.createElement("div");
          gwrap.className = "am-archive-branch am-archive-branch--genus";
          var gnode = makeNode("genus", g);
          gwrap.appendChild(gnode);
          var gchildren = document.createElement("div");
          gchildren.className = "am-archive-children";
          gchildren.hidden = true;
          gwrap.appendChild(gchildren);
          childrenBox.appendChild(gwrap);
          gnode.addEventListener("click", function(){ toggleGenus(gnode, gchildren); });
          if(openLevel("genus")){ toggleGenus(gnode, gchildren); }
        });
        setStatus(status, "");
      }).catch(function(){ setStatus(status, "Errore di rete."); });
    }

    function toggleGenus(node, childrenBox){
      var expanded = node.getAttribute("aria-expanded") === "true";
      if(expanded){ node.setAttribute("aria-expanded", "false"); childrenBox.hidden = true; return; }
      node.setAttribute("aria-expanded", "true");
      childrenBox.hidden = false;
      if(childrenBox.getAttribute("data-loaded") === "1") return;
      setStatus(status, "Carico le schede…");
      postAjax("am_archive_tree", { level: "entries", genus_id: node.getAttribute("data-id"), per_page: perPageEntries, page: 1 }).then(function(resp){
        if(!resp || !resp.success){ setStatus(status, (resp && resp.data && resp.data.message) ? resp.data.message : "Errore caricamento schede."); return; }
        childrenBox.setAttribute("data-loaded", "1");
        (resp.data.items || []).forEach(function(e){
          childrenBox.appendChild(makeEntryRow(e, {withThumb: true}));
        });
        setStatus(status, "");
      }).catch(function(){ setStatus(status, "Errore di rete."); });
    }

    function loadFamilies(){
      if(!famHasMore || famLoading) return;
      famLoading = true;
      setStatus(status, "Carico divisioni…");
      postAjax("am_archive_tree", { level: "divisions", page: famPage, per_page: perPageFamilies }).then(function(resp){
        if(!resp || !resp.success){ setStatus(status, (resp && resp.data && resp.data.message) ? resp.data.message : "Errore caricamento divisioni."); famLoading = false; return; }
        renderDivisions(resp.data.items || []);
        famHasMore = !!resp.data.has_more;
        famPage += 1;
        famLoading = false;
        setStatus(status, "");
      }).catch(function(){ setStatus(status, "Errore di rete."); famLoading = false; });
    }

    // Infinite scroll families
    if("IntersectionObserver" in window && sentinel){
      var famObs = new IntersectionObserver(function(entries){
        entries.forEach(function(en){
          if(en.isIntersecting){ loadFamilies(); }
        });
      }, {root: null, rootMargin: "400px", threshold: 0});
      famObs.observe(sentinel);
    }else{
      // fallback
      window.addEventListener("scroll", function(){
        if((window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 800)){
          loadFamilies();
        }
      });
    }

    // Search
    var searchTimer = null;
    function renderSearch(items){
      results.innerHTML = "";
      if(!items.length){
        results.innerHTML = '<div class="am-archive-empty">Nessun risultato.</div>';
        return;
      }
      items.forEach(function(e){
        results.appendChild(makeEntryRow(e, {query: (searchInput ? searchInput.value : ""), withThumb: true, highlight: true}));
      });
    }

    function doSearch(q){
      q = (q||"").trim();
      if(!q){
        results.hidden = true;
        tree.closest(".am-archive__classification").hidden = false;
        setStatus(status, "");
        return;
      }
      setStatus(status, "Cerco…");
      postAjax("am_archive_search", { s: q }).then(function(resp){
        if(!resp || !resp.success){
          setStatus(status, "Errore ricerca.");
          return;
        }
        tree.closest(".am-archive__classification").hidden = true;
        results.hidden = false;
        renderSearch(resp.data.items || []);
        setStatus(status, "");
      }).catch(function(){
        setStatus(status, "Errore di rete.");
      });
    }

    if(searchInput){
      searchInput.addEventListener("input", function(){
        clearTimeout(searchTimer);
        var val = searchInput.value;
        searchTimer = setTimeout(function(){ doSearch(val); }, 250);
      });

      if(searchBtn){
        searchBtn.addEventListener("click", function(){
          doSearch(searchInput.value);
        });
      }

    }


    // AM 1.5.49 - restore archive search from URL after "Torna ai risultati".
    (function restoreArchiveSearch1549(){
      try {
        var params = new URL(window.location.href).searchParams;
        if(!params.has("am_restore")){ return; }
        var q = params.get("am_search") || params.get("s") || "";
        q = (q || "").trim();
        if(!q || !searchInput){ return; }
        searchInput.value = q;
        doSearch(q);
        window.setTimeout(function(){
          try { document.dispatchEvent(new CustomEvent("am:search-updated", { bubbles: true })); } catch(e) {}
        }, 450);
      } catch(e) {}
    })();

    // Initial (articles are rendered by the server since 1.6.20)
    loadFamilies();

    // 1.6.23: panels start closed; a link such as #articoli opens the one it points to.
    function openFromHash(){
      var id = decodeURIComponent((location.hash || "").slice(1));
      var panel = id ? document.getElementById(id) : null;
      if(panel && panel.tagName === "DETAILS" && root.contains(panel)){
        panel.open = true;
        panel.scrollIntoView({ block: "start" });
      }
    }
    openFromHash();
    window.addEventListener("hashchange", openFromHash);
  }

  function openRecentArchiveEntries(){
    if(window.location.hash !== "#ultime-schede") return;
    var panel = document.getElementById("ultime-schede");
    if(!panel) return;
    panel.open = true;
    panel.querySelector("summary").focus({preventScroll: true});
    panel.scrollIntoView({block: "start"});
  }

  document.addEventListener("DOMContentLoaded", initArchive);
  document.addEventListener("DOMContentLoaded", openRecentArchiveEntries);
  window.addEventListener("hashchange", openRecentArchiveEntries);
  // Selecting the same fragment again does not fire hashchange.
  document.addEventListener("click", function(event){
    if(event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    var link = event.target.closest("a[href]");
    if(!link || link.target && link.target !== "_self") return;
    if(window.location.hash === "#ultime-schede" && link.href === window.location.href){
      openRecentArchiveEntries();
    }
  });
})();
