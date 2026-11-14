(function(){
  function wrapTitle(el){
    if(!el || el.dataset.amTitlefmt === '1') return;
    var t = (el.textContent || '').trim();
    if(!t) return;
    // split into 3 parts: first word, second word, rest
    var parts = t.split(/\s+/);
    if(parts.length < 2) return;
    var genus = parts[0];
    var species = parts[1];
    var rest = parts.slice(2).join(' ');

    // clear
    while(el.firstChild) el.removeChild(el.firstChild);

    var span = document.createElement('span');
    span.className = 'am-taxon-gs';
    span.style.fontWeight = '700';

    var i = document.createElement('i');
    i.textContent = genus + ' ' + species;
    span.appendChild(i);

    el.appendChild(span);
    if(rest){
      el.appendChild(document.createTextNode(' ' + rest));
    }
    el.dataset.amTitlefmt = '1';
    el.setAttribute('data-am-titlefmt-ver', '1.3.0');
  }

  function run(){
    // WordPress and plugin titles
    var selectors = [
      'h1.entry-title',
      'h1.post-title',
      'h1.am-entry-title',
      '.am-entry-title'
    ];
    selectors.forEach(function(sel){
      document.querySelectorAll(sel).forEach(wrapTitle);
    });
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();