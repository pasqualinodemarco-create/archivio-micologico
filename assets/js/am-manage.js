/* Archivio Micologico - public management of taxonomy and vocabularies ([am_tassonomia], [am_vocabolari]). */
(function () {
  'use strict';

  function format(text, value) {
    return String(text || '').replace('%s', value);
  }

  function init(root) {
    var configNode = root.querySelector('[data-am-config]');
    var dialog = root.querySelector('[data-am-dialog]');
    if (!configNode || !dialog) return;
    var config = JSON.parse(configNode.textContent || '{}');
    var text = config.text || {};
    var tree = config.mode === 'tree';
    var form = dialog.querySelector('form');
    var title = dialog.querySelector('[data-am-dialog-title]');
    var hint = dialog.querySelector('[data-am-dialog-text]');
    var submit = dialog.querySelector('[data-am-dialog-submit]');
    var nameInput = form.elements.name;
    var parentInput = form.querySelector('input[name="parent"]');
    var parentSelect = dialog.querySelector('[data-am-parent-select]');
    var parentLabel = dialog.querySelector('[data-am-parent-label]');
    var targetSelect = dialog.querySelector('[data-am-target-select]');
    var fields = {};
    dialog.querySelectorAll('[data-am-field]').forEach(function (el) { fields[el.getAttribute('data-am-field')] = el; });

    function show(names) {
      Object.keys(fields).forEach(function (key) { fields[key].hidden = names.indexOf(key) === -1; });
      nameInput.required = names.indexOf('name') !== -1;
      targetSelect.required = names.indexOf('target') !== -1;
      parentSelect.required = names.indexOf('parent') !== -1;
    }

    function fillSelect(select, items, exclude, current) {
      select.replaceChildren();
      var groups = {};
      items.forEach(function (item) {
        if (String(item.id) === String(exclude)) return;
        var option = new Option(item.name, item.id, false, String(item.id) === String(current));
        if (item.group) {
          if (!groups[item.group]) {
            groups[item.group] = document.createElement('optgroup');
            groups[item.group].label = item.group;
            select.appendChild(groups[item.group]);
          }
          groups[item.group].appendChild(option);
        } else {
          select.appendChild(option);
        }
      });
    }

    function open(op, source, button) {
      var item = source ? { id: source.getAttribute('data-id'), name: source.getAttribute('data-name'), rank: source.getAttribute('data-rank'), parent: source.getAttribute('data-parent') } : null;
      form.elements.op.value = op;
      form.elements.term_id.value = item ? item.id : '';
      parentInput.value = '';
      nameInput.value = '';
      nameInput.placeholder = '';
      submit.className = 'am-manage__btn';
      submit.textContent = text.save;

      if (op === 'add') {
        var rank = button.getAttribute('data-am-rank');
        var parentId = button.getAttribute('data-am-parent') || '0';
        parentInput.value = parentId;
        title.textContent = tree ? format(text.add, (config.ranks || {})[rank] || '') : String(text.add || '').replace(/^\+\s*/, '');
        var parentNode = parentId !== '0' ? root.querySelector('[data-id="' + parentId + '"]') : null;
        hint.textContent = parentNode ? format(text.addIn, parentNode.getAttribute('data-name')) : (text.addHint || '');
        nameInput.placeholder = button.getAttribute('data-am-placeholder') || '';
        show(['name']);
      } else if (op === 'rename') {
        title.textContent = format(text.rename, item.name);
        hint.textContent = text.renameHint;
        nameInput.value = item.name;
        show(['name']);
      } else if (op === 'move') {
        var ranks = ['division', 'family', 'genus', 'species'];
        var upper = ranks[ranks.indexOf(item.rank) - 1];
        title.textContent = format(text.move, item.name);
        hint.textContent = text.moveHint;
        parentLabel.textContent = format(text.moveTo, (config.ranks || {})[upper] || '');
        fillSelect(parentSelect, (config.options || {})[upper] || [], null, item.parent);
        parentInput.value = parentSelect.value;
        show(['parent']);
      } else if (op === 'merge') {
        title.textContent = format(text.merge, item.name);
        hint.textContent = format(text.mergeHint, item.name);
        fillSelect(targetSelect, config.options || [], item.id, null);
        submit.textContent = text.confirmMerge;
        show(['target']);
      } else if (op === 'delete') {
        title.textContent = format(text.delete, item.name);
        hint.textContent = text.deleteHint;
        submit.className = 'am-manage__btn am-manage__btn--danger';
        submit.textContent = text.confirmDelete;
        show([]);
      }

      if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
      (fields.name.hidden ? (fields.parent.hidden ? (fields.target.hidden ? submit : targetSelect) : parentSelect) : nameInput).focus();
      if (!fields.name.hidden) nameInput.select();
    }

    function close() {
      if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    }

    parentSelect.addEventListener('change', function () { parentInput.value = parentSelect.value; });
    dialog.querySelector('[data-am-dialog-cancel]').addEventListener('click', close);
    dialog.addEventListener('click', function (event) { if (event.target === dialog) close(); });
    form.addEventListener('submit', function () {
      submit.disabled = true;
      root.classList.add('is-busy');
    });

    root.addEventListener('click', function (event) {
      var button = event.target.closest('[data-am-op]');
      if (button && !button.disabled && root.contains(button)) {
        open(button.getAttribute('data-am-op'), button.closest('[data-id]'), button);
        return;
      }
      var toggle = event.target.closest('[data-am-toggle]');
      if (toggle) {
        var node = toggle.closest('.am-mnode');
        node.setAttribute('aria-expanded', node.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
        return;
      }
      var expand = event.target.closest('[data-am-expand]');
      if (expand) {
        var state = expand.getAttribute('data-am-expand') === '1' ? 'true' : 'false';
        root.querySelectorAll('.am-mnode').forEach(function (node) {
          if (node.querySelector('.am-mtree')) node.setAttribute('aria-expanded', state);
        });
      }
    });

    // Search: keep matching items and the path that leads to them.
    var filter = root.querySelector('[data-am-filter]');
    var empty = root.querySelector('[data-am-no-results]');
    var items = Array.prototype.slice.call(root.querySelectorAll(tree ? '.am-mnode' : '.am-mrow'));
    var expandedBeforeSearch = null;
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      if (q && expandedBeforeSearch === null) {
        expandedBeforeSearch = items.map(function (n) { return n.getAttribute('aria-expanded'); });
      }
      var visible = 0;
      if (!tree) {
        items.forEach(function (row) {
          var hit = !q || row.getAttribute('data-name').toLowerCase().indexOf(q) !== -1;
          row.hidden = !hit;
          if (hit) visible++;
        });
      } else {
        items.forEach(function (node) { node.hidden = !!q; node.classList.remove('is-match'); });
        items.forEach(function (node) {
          if (!q || node.getAttribute('data-name').toLowerCase().indexOf(q) === -1) return;
          visible++;
          node.classList.add('is-match');
          node.hidden = false;
          node.querySelectorAll('.am-mnode').forEach(function (child) { child.hidden = false; });
          for (var up = node.parentElement.closest('.am-mnode'); up; up = up.parentElement.closest('.am-mnode')) {
            up.hidden = false;
            up.setAttribute('aria-expanded', 'true');
          }
        });
        if (!q && expandedBeforeSearch) {
          items.forEach(function (n, i) { n.setAttribute('aria-expanded', expandedBeforeSearch[i]); });
          expandedBeforeSearch = null;
        }
        if (!q) visible = items.length;
      }
      empty.hidden = visible > 0;
    });

    // Start with divisions open and families closed, then reveal the item just changed.
    if (tree) {
      items.forEach(function (node) {
        if (node.querySelector('.am-mtree') && node.getAttribute('data-rank') !== 'division') node.setAttribute('aria-expanded', 'false');
      });
    }
    var focusId = root.getAttribute('data-am-focus');
    var focused = focusId && focusId !== '0' ? root.querySelector('[data-id="' + focusId + '"]') : null;
    if (focused) {
      for (var up = focused.parentElement && focused.parentElement.closest('.am-mnode'); up; up = up.parentElement.closest('.am-mnode')) {
        up.setAttribute('aria-expanded', 'true');
      }
      focused.classList.add('is-focus');
      focused.scrollIntoView({ block: 'center' });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-am-manage]').forEach(init);
  });
})();
