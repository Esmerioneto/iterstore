/*
 * Admin behaviors. Forms with data-confirm ask before submitting, using
 * SweetAlert2 (vendored in /assets/vendor/sweetalert2). If the library
 * failed to load, falls back to the native confirm() so nothing breaks.
 * data-confirm-button changes the confirm button's text (default "Remover").
 * form.submit() does not fire the submit event, so there is no loop.
 *
 * Flash messages are rendered by the server in .admin-messages (works
 * without JS); when SweetAlert2 is loaded they become toasts instead.
 */
// Theme ink color for SweetAlert2 icons (the library's defaults are colored).
function inkColor() {
  return getComputedStyle(document.documentElement).getPropertyValue('--color-ink').trim();
}

document.addEventListener('submit', function (event) {
  var form = event.target;
  var question = form.dataset.confirm;
  if (!question) {
    return;
  }
  event.preventDefault();

  if (typeof Swal === 'undefined') {
    if (window.confirm(question)) {
      form.submit();
    }
    return;
  }

  Swal.fire({
    title: 'Confirmar',
    text: question,
    icon: 'warning',
    iconColor: inkColor(),
    showCancelButton: true,
    confirmButtonText: form.dataset.confirmButton || 'Remover',
    cancelButtonText: 'Cancelar',
    focusCancel: true,
    buttonsStyling: false,
    customClass: {
      popup: 'admin-swal',
      confirmButton: 'admin-swal-button admin-swal-confirm',
      cancelButton: 'admin-swal-button'
    }
  }).then(function (result) {
    if (result.isConfirmed) {
      form.submit();
    }
  });
});

document.addEventListener('DOMContentLoaded', function () {
  var box = document.querySelector('.admin-messages');
  if (!box || typeof Swal === 'undefined') {
    return;
  }
  var messages = Array.prototype.slice.call(box.querySelectorAll('.message'));
  if (!messages.length) {
    return;
  }
  box.hidden = true;

  var icons = { success: 'success', error: 'error', warning: 'warning' };
  // One toast at a time: each message waits for the previous one to close.
  messages.reduce(function (previous, message) {
    var type = message.className.replace(/.*message-(\w+).*/, '$1');
    return previous.then(function () {
      return Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icons[type] || 'info',
        iconColor: stateColor(type),
        title: message.dataset.label,
        text: message.textContent,
        showConfirmButton: false,
        showCloseButton: true,
        // Success fades out by itself; errors and warnings stay until closed.
        timer: type === 'success' ? 4000 : undefined,
        timerProgressBar: type === 'success',
        customClass: { popup: 'admin-swal admin-swal-toast admin-swal-toast-' + type }
      });
    });
  }, Promise.resolve());
});

// The state's color (--color-success/-danger/-warning in theme.css) for a toast's icon; ink otherwise.
function stateColor(type) {
  var name = { success: '--color-success', error: '--color-danger', warning: '--color-warning' }[type];
  return (name && getComputedStyle(document.documentElement).getPropertyValue(name).trim()) || inkColor();
}

// Narrow screens: the header's "Menu" button opens the sidebar as a drawer; a click outside it,
// a link inside it or Esc closes it. On wide screens the button is hidden and nothing changes.
(function () {
  var shell = document.querySelector('.admin-shell');
  var toggle = document.querySelector('.admin-menu-toggle');
  var menu = document.getElementById('admin-menu');
  if (!shell || !toggle || !menu) {
    return;
  }
  function setOpen(open) {
    shell.classList.toggle('is-menu-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  toggle.addEventListener('click', function (event) {
    event.stopPropagation();
    setOpen(!shell.classList.contains('is-menu-open'));
  });
  document.addEventListener('click', function (event) {
    if (shell.classList.contains('is-menu-open') && (!menu.contains(event.target) || event.target.closest('a'))) {
      setOpen(false);
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && shell.classList.contains('is-menu-open')) {
      setOpen(false);
      toggle.focus();
    }
  });
})();

// A select with data-options-box shows that block only while the chosen
// <option> has data-supports-options="1"; disabling the fieldset also keeps
// its inputs out of the POST. Without JS the block just stays visible.
function syncOptionsBox(select) {
  var box = document.getElementById(select.dataset.optionsBox);
  var chosen = select.options[select.selectedIndex];
  if (!box) {
    return;
  }
  var on = !!chosen && chosen.dataset.supportsOptions === '1';
  box.hidden = !on;
  box.disabled = !on;
}

document.addEventListener('change', function (event) {
  if (event.target.dataset && event.target.dataset.optionsBox) {
    syncOptionsBox(event.target);
  }
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('select[data-options-box]').forEach(syncOptionsBox);
});

// A checkbox with data-check-all="name" checks or unchecks every checkbox of that name in its form
// (form.elements, so the boxes outside it that point to it with the form attribute count too).
// A form with data-mass="name" (a grid's mass actions) shows only with something checked, with the count.
function massBoxes(form, name) {
  return Array.prototype.filter.call(form.elements, function (el) {
    return el.type === 'checkbox' && el.name === name;
  });
}

function updateMass(form) {
  var checked = massBoxes(form, form.dataset.mass).filter(function (box) { return box.checked; }).length;
  form.hidden = checked === 0;
  var count = form.querySelector('[data-mass-count]');
  if (count) {
    count.textContent = checked + ' produto(s) marcado(s)';
  }
  form.dataset.confirm = 'Aplicar a ação a ' + checked + ' produto(s)?';
}

document.addEventListener('change', function (event) {
  var form = event.target.form;
  if (!form) {
    return;
  }
  var name = event.target.dataset && event.target.dataset.checkAll;
  if (name) {
    massBoxes(form, name).forEach(function (box) {
      box.checked = event.target.checked;
    });
  }
  if (form.dataset.mass) {
    updateMass(form);
  }
});

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form[data-mass]').forEach(updateMass); // boxes the browser kept checked on "back"
});

// Entity form blocks packed: each block spans as many 4px rows as its height plus the 16px gap
// (.is-packed), so a short block doesn't leave a hole under it until the tallest of its row ends.
// Re-measured whenever a block changes size (fields shown/hidden, addresses added, arranging bars).
document.addEventListener('DOMContentLoaded', function () {
  var grid = document.querySelector('[data-layout-grid]');
  if (!grid || typeof ResizeObserver === 'undefined') {
    return;
  }
  function pack(block) {
    var height = block.getBoundingClientRect().height;
    block.style.gridRowEnd = height ? 'span ' + Math.ceil((height + 16) / 4) : '';
  }
  // the first pass right away: a background tab only gets the observer's calls once shown
  grid.classList.add('is-packed');
  Array.prototype.forEach.call(grid.children, pack);
  var observer = new ResizeObserver(function (entries) {
    entries.forEach(function (entry) { pack(entry.target); });
  });
  Array.prototype.forEach.call(grid.children, function (block) { observer.observe(block); });
});

// Entity form (product, customer) "Organizar tela": blocks ([data-block]) and the fields inside a
// block ([data-field]) are reordered by native drag and drop, or by the ↑ ↓
// buttons (touch and keyboard); ↔ makes a block span the whole row. The order
// is saved for the logged admin only (POST, JSON reply); the entity's values
// aren't touched and the page doesn't reload.
document.addEventListener('DOMContentLoaded', function () {
  var grid = document.querySelector('[data-layout-grid]');
  if (!grid || !document.querySelector('[data-layout-toggle]')) {
    return;
  }
  var toggle = document.querySelector('[data-layout-toggle]');
  var actions = document.querySelector('[data-layout-actions]');
  var status = document.querySelector('[data-layout-status]');
  var columns = document.querySelector('[data-layout-columns]');
  var dragged = null;

  // the label's own text: a label wrapping a <select> would also bring every option's text
  function fieldTitle(item) {
    var label = item.querySelector('label, legend');
    return label && Array.prototype.filter.call(label.childNodes, function (node) { return node.nodeType === 3; })
      .map(function (node) { return node.textContent; }).join(' ').trim();
  }

  function bar(item, title, wide) {
    var div = document.createElement('div');
    div.className = 'product-layout-bar';
    div.innerHTML = '<span>⠿ </span>' +
      '<button type="button" data-move="-1" aria-label="Mover para cima">↑</button>' +
      '<button type="button" data-move="1" aria-label="Mover para baixo">↓</button>' +
      (wide ? '<button type="button" data-wide aria-label="Largura normal ou total">↔</button>' : '');
    div.firstChild.appendChild(document.createTextNode(title));
    item.insertBefore(div, item.firstChild);
  }

  function arranging(on) {
    grid.classList.toggle('is-arranging', on);
    toggle.hidden = on;
    actions.hidden = !on;
    grid.querySelectorAll('[data-block], [data-field]').forEach(function (item) {
      item.draggable = on;
      if (on && !item.querySelector(':scope > .product-layout-bar')) {
        bar(item, item.dataset.title || fieldTitle(item) || item.dataset.field, 'block' in item.dataset);
      }
    });
  }

  function say(text) {
    status.textContent = text;
  }

  function send(url, body) {
    var data = new FormData();
    data.append('form_key', grid.dataset.formKey);
    Object.keys(body).forEach(function (key) { data.append(key, body[key]); });
    return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) {
      return response.json().catch(function () { return { message: 'Não foi possível salvar. Recarregue a página e tente de novo.' }; })
        .then(function (json) { if (!response.ok) { throw new Error(json.message); } return json; });
    });
  }

  toggle.addEventListener('click', function () { say(''); arranging(true); });
  columns.addEventListener('change', function () {
    grid.classList.toggle('has-columns', columns.value !== '0');
    grid.style.setProperty('--layout-columns', columns.value);
  });
  document.querySelector('[data-layout-cancel]').addEventListener('click', function () { location.reload(); });
  document.querySelector('[data-layout-reset]').addEventListener('click', function () {
    send(grid.dataset.resetUrl, {}).then(function () { location.reload(); }, function (error) { say(error.message); });
  });
  document.querySelector('[data-layout-save]').addEventListener('click', function () {
    var layout = { blocks: [], fields: {}, columns: Number(columns.value) };
    grid.querySelectorAll('[data-block]').forEach(function (block) {
      layout.blocks.push({ id: block.dataset.block, wide: block.classList.contains('is-wide') });
      var codes = Array.prototype.map.call(block.querySelectorAll('[data-field]'), function (field) { return field.dataset.field; });
      if (codes.length) {
        layout.fields[block.dataset.block] = codes;
      }
    });
    send(grid.dataset.saveUrl, { layout: JSON.stringify(layout) }).then(function (json) {
      arranging(false);
      say(json.message);
    }, function (error) { say(error.message); });
  });

  grid.addEventListener('click', function (event) {
    var button = event.target.closest('.product-layout-bar button');
    if (!button) {
      return;
    }
    var item = button.closest('[data-field], [data-block]');
    if ('wide' in button.dataset) {
      item.classList.toggle('is-wide');
    } else if (button.dataset.move === '-1' && item.previousElementSibling) {
      item.parentNode.insertBefore(item, item.previousElementSibling);
    } else if (button.dataset.move === '1' && item.nextElementSibling) {
      item.parentNode.insertBefore(item.nextElementSibling, item);
    }
    button.focus();
  });

  grid.addEventListener('dragstart', function (event) {
    dragged = event.target.closest('[data-field], [data-block]');
    dragged.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', ''); // Firefox only drags with some data
  });
  grid.addEventListener('dragend', function () {
    dragged.classList.remove('is-dragging');
    dragged = null;
  });
  // a field only moves among its own group's fields; a block among the blocks
  grid.addEventListener('dragover', function (event) {
    if (!dragged) {
      return;
    }
    var over = event.target.closest('field' in dragged.dataset ? '[data-field]' : '[data-block]');
    if (!over || over.parentNode !== dragged.parentNode) {
      return;
    }
    event.preventDefault();
    if (over !== dragged) {
      var siblings = Array.prototype.slice.call(over.parentNode.children);
      over.parentNode.insertBefore(dragged, siblings.indexOf(dragged) < siblings.indexOf(over) ? over.nextSibling : over);
    }
  });
  grid.addEventListener('drop', function (event) { event.preventDefault(); });
});

// Image fields: the thumbnail opens the full image in a popup (the link still opens a new tab without JS)
document.addEventListener('click', function (event) {
  var link = event.target.closest('[data-image-popup]');
  if (!link || typeof Swal === 'undefined') {
    return;
  }
  event.preventDefault();
  Swal.fire({
    imageUrl: link.href,
    imageAlt: link.dataset.imagePopup,
    title: link.dataset.imagePopup,
    width: 'auto',
    showConfirmButton: false,
    showCloseButton: true,
    customClass: { popup: 'admin-swal admin-swal-image' }
  });
});

// Checkboxes with data-required-group: at least one of the same name must be checked
// (native "required" on every box while none is, so the browser blocks the submit)
function syncRequiredGroup(box) {
  var boxes = document.querySelectorAll('input[data-required-group][name="' + box.name + '"]');
  var checked = Array.prototype.some.call(boxes, function (b) { return b.checked; });
  boxes.forEach(function (b) { b.required = !checked; });
}
// Category tree (data-category-parent): checking a category checks its parents,
// unchecking it unchecks its subcategories
function cascadeCategory(box) {
  var group = 'input[data-category-parent][name="' + box.name + '"]';
  var byId = function (id) { return document.querySelector(group + '[value="' + id + '"]'); };
  if (box.checked) {
    for (var parent = byId(box.dataset.categoryParent); parent; parent = byId(parent.dataset.categoryParent)) {
      parent.checked = true;
    }
  } else {
    document.querySelectorAll(group + '[data-category-parent="' + box.value + '"]').forEach(function (child) {
      child.checked = false;
      cascadeCategory(child);
    });
  }
}
document.addEventListener('change', function (event) {
  if (event.target.matches('input[data-category-parent]')) {
    cascadeCategory(event.target);
  }
  if (event.target.matches('input[data-required-group]')) {
    syncRequiredGroup(event.target);
  }
});
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('input[data-required-group]').forEach(syncRequiredGroup);
  document.querySelectorAll('input[data-category-parent]:checked').forEach(cascadeCategory);
});

// <button data-add-row="tbody id">: appends a blank copy of the tbody's last row, with the
// next index in names like options[3][value]
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-add-row]');
  if (!button) {
    return;
  }
  var rows = document.getElementById(button.dataset.addRow);
  var row = rows.lastElementChild.cloneNode(true);
  var index = rows.children.length;
  row.querySelectorAll('input, select, textarea').forEach(function (input) {
    input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
    clearField(input);
  });
  row.querySelectorAll('[data-cep-last]').forEach(function (input) { delete input.dataset.cepLast; });
  row.querySelectorAll('[data-cep-status]').forEach(function (status) { status.remove(); });
  rows.appendChild(row);
  row.querySelector('input:not([type="hidden"]), select').focus();
});

function clearField(input) {
  if (input.type === 'checkbox') {
    input.checked = false;
  } else if (input.tagName === 'SELECT') {
    input.selectedIndex = 0;
  } else {
    input.value = '';
  }
}

// <button data-clear-row> empties the fields of its row (a blank row is dropped on save)
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-clear-row]');
  if (button) {
    button.closest('fieldset').querySelectorAll('input, select, textarea').forEach(clearField);
  }
});

// <input type="checkbox" data-exclusive="name">: checking one unchecks the others with the same name
document.addEventListener('change', function (event) {
  var input = event.target;
  if (input.matches('input[data-exclusive]') && input.checked) {
    document.querySelectorAll('input[data-exclusive="' + input.dataset.exclusive + '"]').forEach(function (other) {
      other.checked = other === input;
    });
  }
});

// <select data-autosubmit>: choosing an option submits its form (e.g. the scope of Configurações)
document.addEventListener('change', function (event) {
  if (event.target.matches('select[data-autosubmit]')) {
    event.target.form.submit();
  }
});

// Configurações in a website scope: typing in a field (or choosing a file) stops it from using the default value
document.addEventListener('input', function (event) {
  var name = event.target.name || '';
  var item = name.indexOf('config[') === 0 && event.target.closest('[data-config-item], p');
  var useDefault = item ? item.querySelector('input[name^="config_default["]') : null;
  if (useDefault) {
    useDefault.checked = false;
  }
});

// An image over data-max-bytes is scaled down in the browser before it's posted:
// PHP drops an upload over upload_max_filesize before the system sees it.
// Output is WebP when the field accepts it (keeps transparency), else JPEG.
// ponytail: saving during the second it takes posts the original, which the server refuses with its size message.
function shrinkImage(file, maxBytes, type) {
  return createImageBitmap(file).then(function (bitmap) {
    var scale = Math.min(1, Math.sqrt(maxBytes / file.size) * 1.5);
    var attempt = function () {
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      var context = canvas.getContext('2d');
      if (type === 'image/jpeg') {
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
      }
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) { canvas.toBlob(resolve, type, 0.85); }).then(function (blob) {
        if (blob.size <= maxBytes) {
          return blob;
        }
        scale *= 0.8;
        return attempt();
      });
    };
    return attempt();
  });
}

document.addEventListener('change', function (event) {
  var input = event.target;
  var chosen = Array.prototype.slice.call(input.files || []); // several in a "multiple" field
  var maxBytes = Number(input.dataset.maxBytes);
  if (!input.matches('input[data-image-input]') || !maxBytes || event.detail === 'shrunk'
    || !chosen.some(function (file) { return file.size > maxBytes; })) {
    return;
  }
  event.stopImmediatePropagation(); // the preview below waits for the smaller file
  var webp = input.accept.indexOf('image/webp') !== -1
    && document.createElement('canvas').toDataURL('image/webp').indexOf('data:image/webp') === 0;
  var type = webp ? 'image/webp' : 'image/jpeg';
  var mb = function (bytes) { return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB'; };
  var shrunk = [];
  Promise.all(chosen.map(function (file) {
    if (file.size <= maxBytes) {
      return file;
    }
    return shrinkImage(file, maxBytes, type).then(function (blob) {
      shrunk.push(file.name + ' tinha ' + mb(file.size) + ' (limite ' + mb(maxBytes) + '); foi reduzida para ' + mb(blob.size) + '.');
      return new File([blob], file.name.replace(/\.[^.]*$/, '') + (webp ? '.webp' : '.jpg'), { type: type });
    }).catch(function () {
      return file; // not decodable here (e.g. AVIF in an older browser): it goes as is and the server explains the limit
    });
  })).then(function (results) {
    var files = new DataTransfer();
    results.forEach(function (file) { files.items.add(file); });
    input.files = files.files;
    if (shrunk.length && typeof Swal !== 'undefined') {
      Swal.fire({
        toast: true, position: 'top-end', icon: 'info', iconColor: inkColor(), showConfirmButton: false, showCloseButton: true,
        timer: 6000, timerProgressBar: true, customClass: { popup: 'admin-swal admin-swal-toast' },
        title: shrunk.length > 1 ? 'Imagens reduzidas' : 'Imagem reduzida',
        text: shrunk.join(' ')
      });
    }
    input.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: 'shrunk' }));
  });
});

// Image settings and EAV image fields: show the chosen file right away (it's only uploaded on "Salvar")
document.addEventListener('change', function (event) {
  if (!event.target.matches('input[data-image-input]')) {
    return;
  }
  // a "multiple" field (e.g. the category's banners): one thumb per chosen file in its [data-image-previews]
  var previews = event.target.multiple && (event.target.closest('p') || event.target.parentNode).querySelector('[data-image-previews]');
  if (previews) {
    previews.textContent = '';
    Array.prototype.forEach.call(event.target.files, function (chosen) {
      var img = document.createElement('img');
      img.className = 'category-banner-thumb';
      img.alt = img.title = chosen.name + ' (salve para gravar)';
      img.src = URL.createObjectURL(chosen);
      previews.appendChild(img);
    });
    return;
  }
  // Catálogo → Web: the thumb right before the input (or its <label>), e.g. a slide's desktop and iPhone images
  var before = (event.target.closest('label') || event.target).previousElementSibling;
  var preview = before && (before.matches('[data-image-preview]') ? before : before.querySelector('[data-image-preview]'));
  preview = preview || (event.target.closest('p') || event.target.parentNode).querySelector('[data-image-preview]');
  var file = event.target.files[0];
  if (!preview) {
    return;
  }
  if (!('saved' in preview.dataset)) {
    preview.dataset.saved = preview.getAttribute('src') || ''; // the stored image, if any
  }
  var saved = preview.dataset.saved;
  var placeholder = preview.getAttribute('data-placeholder');
  var link = preview.closest('a[data-image-popup]');
  preview.hidden = !file && !placeholder && !saved;
  preview.classList.toggle('eav-file-placeholder', !file && !!placeholder);
  if (file) {
    preview.src = URL.createObjectURL(file);
  } else if (placeholder || saved) {
    preview.src = placeholder || saved;
  }
  if (link) {
    link.dataset.savedHref = link.dataset.savedHref || link.href;
    link.href = file ? preview.src : link.dataset.savedHref;
  }
});

// CEP lookup (ViaCEP): 8 digits in [data-cep="lookup"] fill the [data-cep="<ViaCEP field>"] inputs of its
// [data-cep-scope] (one address among many), else of its form
document.addEventListener('input', function (event) {
  var cep = event.target;
  if (!cep.matches('[data-cep="lookup"]')) {
    return;
  }
  var digits = cep.value.replace(/\D/g, '');
  var status = cep.parentNode.querySelector('[data-cep-status]');
  if (!status) {
    status = document.createElement('small');
    status.setAttribute('data-cep-status', '');
    status.setAttribute('aria-live', 'polite');
    cep.insertAdjacentElement('afterend', status);
  }
  status.textContent = '';
  if (digits.length !== 8 || digits === cep.dataset.cepLast) {
    return;
  }
  cep.dataset.cepLast = digits;
  status.textContent = 'Buscando CEP…';
  fetch('https://viacep.com.br/ws/' + digits + '/json/')
    .then(function (response) { return response.json(); })
    .then(function (address) {
      if (address.erro) {
        status.textContent = 'CEP não encontrado. Preencha o endereço.';
        return;
      }
      cep.value = digits.slice(0, 5) + '-' + digits.slice(5);
      (cep.closest('[data-cep-scope]') || cep.form).querySelectorAll('[data-cep]:not([data-cep="lookup"])').forEach(function (input) {
        if (address[input.dataset.cep]) {
          input.value = address[input.dataset.cep];
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
      status.textContent = 'Endereço preenchido pelo CEP. Confira e complete o número.';
    })
    .catch(function () {
      cep.dataset.cepLast = '';
      status.textContent = 'Não foi possível buscar o CEP agora. Preencha o endereço.';
    });
});

// input masks: [data-mask="cep|cpf|cnpj|cpf_cnpj|phone|email"] are formatted while typing (and on load);
// the pattern also makes the browser refuse an incomplete value
var masks = {
  cep: { max: 8, format: function () { return '00000-000'; }, pattern: '\\d{5}-\\d{3}' },
  cpf: { max: 11, format: function () { return '000.000.000-00'; }, pattern: '\\d{3}\\.\\d{3}\\.\\d{3}-\\d{2}', title: 'CPF: 000.000.000-00' },
  cnpj: { max: 14, format: function () { return '00.000.000/0000-00'; }, pattern: '\\d{2}\\.\\d{3}\\.\\d{3}/\\d{4}-\\d{2}', title: 'CNPJ: 00.000.000/0000-00' },
  cpf_cnpj: {
    max: 14,
    format: function (digits) { return digits.length > 11 ? '00.000.000/0000-00' : '000.000.000-00'; },
    pattern: '\\d{3}\\.\\d{3}\\.\\d{3}-\\d{2}|\\d{2}\\.\\d{3}\\.\\d{3}/\\d{4}-\\d{2}',
    title: 'CPF (000.000.000-00) ou CNPJ (00.000.000/0000-00)'
  },
  phone: {
    max: 11,
    format: function (digits) { return digits.length > 10 ? '(00) 00000-0000' : '(00) 0000-0000'; },
    pattern: '\\(\\d{2}\\) \\d{4,5}-\\d{4}',
    title: 'Telefone com DDD: (00) 0000-0000 ou (00) 00000-0000'
  }
};
function applyMask(input) {
  if (input.dataset.mask === 'email') {
    input.value = input.value.replace(/\s/g, '').toLowerCase();
    return;
  }
  var mask = masks[input.dataset.mask];
  var digits = input.value.replace(/\D/g, '').slice(0, mask.max);
  var format = mask.format(digits);
  var value = '';
  for (var i = 0, d = 0; i < format.length && d < digits.length; i++) {
    value += format[i] === '0' ? digits[d++] : format[i];
  }
  input.value = value;
}
document.querySelectorAll('input[data-mask]').forEach(function (input) {
  var mask = masks[input.dataset.mask];
  if (mask) {
    input.inputMode = 'numeric';
    input.pattern = mask.pattern;
    input.title = mask.title || '';
  }
  if (input.value !== '') {
    applyMask(input);
  }
});
document.addEventListener('input', function (event) {
  if (event.target.matches('input[data-mask]')) {
    applyMask(event.target);
  }
});

// customer form: the type select shows its block; the other one is hidden and its
// fieldset disabled, so its required fields don't block the submit and aren't posted
document.querySelectorAll('select[data-customer-type]').forEach(function (select) {
  select.addEventListener('change', function () {
    document.querySelectorAll('[data-customer-type-block]').forEach(function (block) {
      var off = block.dataset.customerTypeBlock !== select.value;
      block.hidden = off;
      block.querySelector('fieldset').disabled = off;
    });
  });
});

// Lists reordered by hand ([data-sort-item] in a [data-sortable]: Catálogo → Mobile, a category's banners):
// native drag and drop from the ⠿ handle ([data-drag-handle]), or the ↑ ↓ buttons ([data-move]); the form
// posts the items in page order. Every move fires a bubbling "sort" event on the item.
document.addEventListener('DOMContentLoaded', function () {
  var dragged = null;
  var sorted = function (item) { item.dispatchEvent(new Event('sort', { bubbles: true })); };

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-sort-item] button[data-move]');
    var item = button && button.closest('[data-sort-item]');
    if (!item) {
      return;
    }
    if (button.dataset.move === '-1' && item.previousElementSibling) {
      item.parentNode.insertBefore(item, item.previousElementSibling);
    } else if (button.dataset.move === '1' && item.nextElementSibling) {
      item.parentNode.insertBefore(item.nextElementSibling, item);
    } else {
      return;
    }
    sorted(item);
    button.focus();
  });

  // only the handle starts a drag, so the inputs inside an item still take clicks and text selection
  document.addEventListener('mousedown', function (event) {
    var handle = event.target.closest('[data-sort-item] [data-drag-handle]');
    if (handle) {
      handle.closest('[data-sort-item]').draggable = true;
    }
  });
  document.addEventListener('mouseup', function (event) { // a click on the handle, without dragging
    var handle = event.target.closest('[data-sort-item] [data-drag-handle]');
    if (handle && !dragged) {
      handle.closest('[data-sort-item]').draggable = false;
    }
  });
  document.addEventListener('dragstart', function (event) {
    var item = event.target.closest && event.target.closest('[data-sort-item]');
    if (!item || !item.draggable) {
      return;
    }
    dragged = item;
    dragged.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', ''); // Firefox only drags with some data
  });
  document.addEventListener('dragend', function () {
    if (!dragged) {
      return;
    }
    dragged.classList.remove('is-dragging');
    dragged.draggable = false;
    sorted(dragged);
    dragged = null;
  });
  // an item only moves among its own list's items (a product inside its category, a category among the categories)
  document.addEventListener('dragover', function (event) {
    if (!dragged) {
      return;
    }
    var over = event.target.closest('[data-sort-item]');
    while (over && over.parentNode !== dragged.parentNode) {
      over = over.parentNode.closest('[data-sort-item]');
    }
    if (!over) {
      return;
    }
    event.preventDefault();
    if (over !== dragged) {
      var siblings = Array.prototype.slice.call(over.parentNode.children);
      over.parentNode.insertBefore(dragged, siblings.indexOf(dragged) < siblings.indexOf(over) ? over.nextSibling : over);
    }
  });
  document.addEventListener('drop', function (event) {
    if (dragged) {
      event.preventDefault();
    }
  });
});

// Catálogo → Mobile ([data-mobile-order]): categories and their chosen products are sortable lists (above).
// "Adicionar" moves a product from the category's hidden pool to the chosen ones (its input enabled),
// ✕ moves it back. The counts, the note and "depois do mostrar mais" follow the changes.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-mobile-order]');
  if (!form) {
    return;
  }

  function refresh(category) {
    var limit = Number(category.querySelector('[data-mobile-limit]').value) || 0;
    var chosen = category.querySelectorAll('[data-mobile-chosen] > li');
    var shown = 0;
    chosen.forEach(function (item) {
      var active = item.dataset.active === '1';
      item.querySelector('[data-mobile-after]').hidden = !active || shown < limit;
      shown += active ? 1 : 0;
    });
    var products = category.querySelectorAll('[data-product-id][data-active="1"]').length;
    var newest = Math.max(0, Math.min(limit - shown, category.querySelectorAll('[data-mobile-pool] > li').length));
    category.querySelector('[data-mobile-summary]').textContent = (category.querySelector('[data-mobile-visible]').checked ? '' : 'fora do app · ') + products + ' produtos · exibe ' + limit + ' · ' + chosen.length + ' escolhidos';
    category.querySelector('[data-mobile-count]').textContent = 'Escolhidos à mão (' + chosen.length + ' de ' + limit + ')';
    category.querySelector('[data-mobile-note]').textContent = newest ? '+ ' + newest + ' mais novos da categoria completam os ' + limit + ' iniciais' : '';
    category.querySelector('[data-mobile-add-select]').disabled = chosen.length >= limit;
    category.querySelector('[data-mobile-add]').disabled = chosen.length >= limit;
  }

  function move(item, list, chosen) {
    list.appendChild(item);
    item.querySelector('input').disabled = !chosen;
    var option = item.closest('[data-mobile-category]').querySelector('[data-mobile-add-select] option[value="' + item.dataset.productId + '"]');
    option.hidden = chosen;
    option.disabled = chosen;
  }

  form.querySelectorAll('[data-mobile-category]').forEach(refresh);
  form.addEventListener('input', function (event) {
    if ('mobileLimit' in event.target.dataset || 'mobileVisible' in event.target.dataset) {
      refresh(event.target.closest('[data-mobile-category]'));
    }
  });
  form.addEventListener('sort', function (event) {
    refresh(event.target.closest('[data-mobile-category]'));
  });
  form.addEventListener('click', function (event) {
    var button = event.target.closest('button[type="button"]');
    var category = button && button.closest('[data-mobile-category]');
    if (!button) {
      return;
    }
    var item = button.closest('[data-sort-item]');
    if ('mobileAdd' in button.dataset) {
      var select = category.querySelector('[data-mobile-add-select]');
      var pooled = select.value && category.querySelector('[data-mobile-pool] > [data-product-id="' + select.value + '"]');
      if (pooled) {
        move(pooled, category.querySelector('[data-mobile-chosen]'), true);
        select.value = '';
      }
    } else if ('mobileRemove' in button.dataset) {
      move(item, category.querySelector('[data-mobile-pool]'), false);
    } else {
      return;
    }
    refresh(category);
    button.isConnected && button.focus();
  });
});

// [data-refresh="N"] with an id: every N seconds the page is fetched again and the block is swapped for
// the new one (Mensagens → conversa). X-Admin-Poll: the refresh doesn't keep the session alive; logged out (401) or failing, all stays as it is
document.querySelectorAll('[data-refresh][id]').forEach(function (block) {
  setInterval(function () {
    fetch(location.href, { headers: { 'X-Admin-Poll': '1' } })
      .then(function (response) { return response.ok ? response.text() : null; })
      .then(function (html) {
        var fresh = html && new DOMParser().parseFromString(html, 'text/html').getElementById(block.id);
        refreshMenuBadges(); // after the fetch above read this conversation's messages
        var atBottom = block.scrollHeight - block.scrollTop - block.clientHeight < 40;
        if (fresh && fresh.innerHTML !== block.innerHTML) {
          block.innerHTML = fresh.innerHTML;
          if (atBottom) block.scrollTop = block.scrollHeight;
        }
      })
      .catch(function () {});
  }, Number(block.dataset.refresh) * 1000);
});

// The menu's red counters (e.g. unread messages) refresh by themselves every 15 s on every screen, from
// /admin/auth/badges (menu URL => count). A group shows the sum of its links, as the server renders it.
// Only the badges change, so an open group stays open; logged out (401) or failing, they stay as they are.
function refreshMenuBadges() {
  var menu = document.getElementById('admin-menu');
  if (!menu) {
    return;
  }
  fetch('/admin/auth/badges', { headers: { 'X-Admin-Poll': '1' } })
    .then(function (response) { return response.ok ? response.json() : null; })
    .then(function (counts) {
      if (!counts) {
        return;
      }
      var set = function (host, n) {
        var badge = host.querySelector(':scope > .admin-badge');
        if (n > 0) {
          badge = badge || host.appendChild(document.createElement('span'));
          badge.className = 'admin-badge';
          badge.setAttribute('aria-label', n + ' pendente(s)');
          badge.textContent = n > 99 ? '99+' : String(n);
        } else if (badge) {
          badge.remove();
        }
      };
      menu.querySelectorAll('a[href]').forEach(function (link) {
        set(link, Number(counts[link.getAttribute('href')]) || 0);
      });
      menu.querySelectorAll('details').forEach(function (group) {
        var sum = 0;
        group.querySelectorAll('a[href]').forEach(function (link) { sum += Number(counts[link.getAttribute('href')]) || 0; });
        set(group.querySelector('summary'), sum);
      });
    })
    .catch(function () {});
}
document.getElementById('admin-menu') && setInterval(refreshMenuBadges, 15000);

// the conversation opens on its newest message
document.querySelectorAll('.message-bubbles').forEach(function (list) { list.scrollTop = list.scrollHeight; });

// Configurações: groups are <details>. The search shows only the matching fields (and opens their
// groups), "Abrir/Fechar todos" toggle every group, a changed field gets a dot and its group a chip,
// and the save bar floats only while there are unsaved changes ("Descartar" is the form's reset).
// The open groups are remembered across the save, so the page comes back as it was.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-config-form]');
  if (!form) {
    return;
  }
  var groups = Array.prototype.slice.call(form.querySelectorAll('details.config-group'));
  var bar = form.querySelector('[data-config-savebar]');
  var storeKey = 'iter-config-open:' + location.search;
  bar.classList.add('is-floating');

  try {
    var saved = JSON.parse(sessionStorage.getItem(storeKey) || 'null');
    sessionStorage.removeItem(storeKey);
    if (saved) {
      groups.forEach(function (g) { g.open = saved.indexOf(g.id) !== -1; });
    }
  } catch (e) { /* no storage: the first group stays open */ }

  document.querySelectorAll('[data-config-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      var open = button.getAttribute('data-config-open') === '1';
      groups.forEach(function (g) { g.open = open; });
    });
  });

  // a field is changed when it differs from what the page loaded with (defaultValue/defaultChecked/defaultSelected)
  function changed(el) {
    if (el.type === 'checkbox' || el.type === 'radio') {
      return el.checked !== el.defaultChecked;
    }
    if (el.type === 'file') {
      return el.files && el.files.length > 0;
    }
    if (el.tagName === 'SELECT') {
      return Array.prototype.some.call(el.options, function (o) { return o.selected !== o.defaultSelected; });
    }
    return el.value !== el.defaultValue;
  }
  function refresh() {
    var total = 0;
    groups.forEach(function (group) {
      var count = 0;
      group.querySelectorAll('[data-config-item]').forEach(function (item) {
        var dirty = Array.prototype.some.call(item.querySelectorAll('input:not([type="hidden"]), select, textarea'), changed);
        item.classList.toggle('is-dirty', dirty);
        count += dirty ? 1 : 0;
      });
      total += count;
      var chip = group.querySelector('.config-chip.is-dirty');
      if (count && !chip) {
        chip = document.createElement('span');
        chip.className = 'config-chip is-dirty';
        group.querySelector('.config-chips').insertBefore(chip, group.querySelector('.config-chips').firstChild);
      }
      if (chip) {
        if (count) {
          chip.textContent = count + (count > 1 ? ' alterados' : ' alterado');
        } else {
          chip.remove();
        }
      }
    });
    form.querySelector('[data-config-dirty-text]').textContent = total + (total > 1 ? ' alterações não salvas' : ' alteração não salva');
    bar.classList.toggle('is-shown', total > 0);
  }
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  form.addEventListener('reset', function () {
    setTimeout(function () {
      form.querySelectorAll('[data-image-preview]').forEach(function (img) { img.hidden = true; });
      refresh();
    }, 0);
  });
  form.addEventListener('submit', function () {
    try {
      sessionStorage.setItem(storeKey, JSON.stringify(groups.filter(function (g) { return g.open; }).map(function (g) { return g.id; })));
    } catch (e) { /* no storage */ }
  });
  // a required field inside a closed group or hidden by the search: show it, so the browser can point at it
  form.addEventListener('invalid', function (event) {
    var group = event.target.closest('details.config-group');
    if (search.value !== '') {
      search.value = '';
      filter();
    }
    if (group) {
      group.open = true;
    }
  }, true);

  var search = document.querySelector('[data-config-search]');
  var empty = form.querySelector('[data-config-empty]');
  function filter() {
    var term = search.value.trim().toLowerCase();
    var any = false;
    groups.forEach(function (group) {
      var titleHit = term !== '' && group.querySelector('summary').textContent.toLowerCase().indexOf(term) !== -1;
      var found = 0;
      group.querySelectorAll('[data-config-item]').forEach(function (item) {
        var hit = term === '' || titleHit || item.textContent.toLowerCase().indexOf(term) !== -1;
        item.classList.toggle('is-search-hidden', !hit);
        found += hit ? 1 : 0;
      });
      // a block shows when one of its fields matches, or its name does (then all of it)
      group.querySelectorAll('.config-block').forEach(function (block) {
        var head = block.querySelector('.config-block-head, .config-switch');
        var blockHit = term !== '' && head.textContent.toLowerCase().indexOf(term) !== -1;
        if (blockHit) {
          block.querySelectorAll('[data-config-item]').forEach(function (item) { item.classList.remove('is-search-hidden'); });
          found += 1;
        }
        block.classList.toggle('is-search-hidden', term !== '' && !blockHit && !block.querySelector('[data-config-item]:not(.is-search-hidden)'));
        if (head.classList.contains('config-block-head')) {
          head.classList.remove('is-search-hidden');
        }
      });
      var show = found > 0;
      group.classList.toggle('is-search-hidden', !show);
      if (term !== '' && show) {
        group.open = true;
      }
      any = any || show;
    });
    if (term === '') {
      groups.forEach(function (g, i) { g.open = i === 0; });
    }
    // a section title goes away with all of its groups
    form.querySelectorAll('.config-section').forEach(function (title) {
      var next = title.nextElementSibling;
      var visible = false;
      while (next && next.matches('details.config-group')) {
        visible = visible || !next.classList.contains('is-search-hidden');
        next = next.nextElementSibling;
      }
      title.classList.toggle('is-search-hidden', !visible);
    });
    empty.hidden = any;
  }
  if (search) {
    search.addEventListener('input', filter);
  }
});

// Product form: under "Custo", the margin of the typed price (REPORT-PLAN); a cost above the price is a warning.
// Report forms: picking a period preset fills the dates, typing a date goes back to "Personalizado".
document.addEventListener('DOMContentLoaded', function () {
  var cost = document.querySelector('input[name="attributes[cost]"]');
  var price = document.querySelector('input[name="attributes[price]"]');
  if (cost && price) {
    var hint = document.createElement('small');
    hint.className = 'catalog-cost-margin';
    cost.insertAdjacentElement('afterend', hint);
    var number = function (input) { return parseFloat(String(input.value).replace(/\./g, input.type === 'number' ? '.' : '').replace(',', '.')); };
    var money = function (v) { return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); };
    var update = function () {
      var p = number(price);
      var c = number(cost);
      hint.hidden = !(p > 0 && c >= 0);
      if (hint.hidden) {
        return;
      }
      hint.classList.toggle('is-negative', c > p);
      hint.textContent = (c > p ? 'Custo acima do preço: ' : 'Margem com o preço atual: ') + money(p - c)
        + ' (' + ((p - c) / p * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%) · só no painel, não aparece no app';
    };
    cost.addEventListener('input', update);
    price.addEventListener('input', update);
    update();
  }

  document.querySelectorAll('[data-report-period]').forEach(function (form) {
    var preset = form.querySelector('[name="preset"]');
    form.querySelectorAll('input[type="date"]').forEach(function (date) {
      ['input', 'change'].forEach(function (type) { date.addEventListener(type, function () { preset.value = ''; }); });
    });
    preset.addEventListener('change', function () {
      var option = preset.selectedOptions[0];
      if (option.dataset.from) {
        form.querySelector('[name="from"]').value = option.dataset.from;
        form.querySelector('[name="to"]').value = option.dataset.to;
      }
    });
  });
});

// Catálogo → Web ([data-web-home]): sections and their cards are sortable lists (above). "+ Seção" and
// "+ Card" copy the <template>s with new keys (the server keeps the posted order); ✕ takes the item out.
// A card only offers the subcategories of its section's category, and a section takes up to data-card-limit.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-web-home]');
  if (!form) {
    return;
  }
  var limit = Number(form.dataset.cardLimit) || 4;
  var serial = 0;
  var key = function (prefix) { serial += 1; return prefix + Date.now().toString(36) + serial; };
  var fromTemplate = function (selector, replace) {
    var html = form.querySelector(selector).innerHTML;
    Object.keys(replace).forEach(function (from) { html = html.split(from).join(replace[from]); });
    var holder = document.createElement('ol');
    holder.innerHTML = html.trim();
    return holder.firstElementChild;
  };
  // the card's select: only the categories below the section's one; a choice that no longer fits is cleared
  var filterCards = function (section) {
    var parent = section.querySelector('[data-web-section-category]').value;
    section.querySelectorAll('[data-web-card-category]').forEach(function (select) {
      Array.prototype.forEach.call(select.options, function (option) {
        var fits = option.value === '' || (parent !== '' && option.dataset.parents.split(' ').indexOf(parent) !== -1);
        option.hidden = !fits;
        option.disabled = !fits;
        if (!fits && option.selected) {
          select.value = '';
        }
      });
    });
  };
  var cardButton = function (section) {
    section.querySelector('[data-web-add-card]').disabled = section.querySelectorAll('[data-web-cards] > [data-web-card]').length >= limit;
  };

  form.addEventListener('click', function (event) {
    var section;
    if (event.target.closest('[data-web-add-section]')) {
      section = fromTemplate('[data-web-section-template]', { __S__: key('n') });
      form.querySelector('[data-web-sections]').appendChild(section);
      section.querySelector('[data-web-section-category]').focus();
    } else if (event.target.closest('[data-web-add-card]')) {
      section = event.target.closest('[data-web-section]');
      if (section.querySelectorAll('[data-web-cards] > [data-web-card]').length >= limit) {
        return;
      }
      var card = fromTemplate('[data-web-card-template]', { __S__: section.dataset.key, __C__: key('n') });
      section.querySelector('[data-web-cards]').appendChild(card);
      filterCards(section);
      cardButton(section);
      card.querySelector('select').focus();
    } else if (event.target.closest('[data-web-remove]')) {
      var item = event.target.closest('[data-sort-item]');
      section = item.matches('[data-web-card]') ? item.closest('[data-web-section]') : null;
      item.remove();
      section && cardButton(section);
    }
  });
  form.addEventListener('change', function (event) {
    if (event.target.matches('[data-web-section-category]')) {
      filterCards(event.target.closest('[data-web-section]'));
    }
  });
  form.querySelectorAll('[data-web-section]').forEach(filterCards);
});

// Catálogo → Web, WEB-HOME-2-PLAN: the top as banner or carousel ([data-web-top] radios show one block), the
// slides (+ Slide from the <template>, up to data-slide-limit), and each section's products chosen by hand as in
// Catálogo → Mobile: the list is built from [data-web-data] (the products of every category), so a section whose
// category changes starts over with that category's products. Names and SKUs go in as text, never as HTML.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('[data-web-home]');
  var dataNode = form && form.querySelector('[data-web-data]');
  if (!form) {
    return;
  }
  var data = dataNode ? JSON.parse(dataNode.textContent) : { products: {}, banners: {} };
  var slides = form.querySelector('[data-web-slides]');
  var slideLimit = Number(slides && slides.dataset.slideLimit) || 5;
  var serial = 0;

  var showTop = function () {
    var mode = (form.querySelector('[data-web-top]:checked') || {}).value;
    form.querySelectorAll('[data-web-top-block]').forEach(function (block) { block.hidden = block.dataset.webTopBlock !== mode; });
  };
  var slideButton = function () {
    form.querySelector('[data-web-add-slide]').disabled = slides.querySelectorAll('[data-web-slide]').length >= slideLimit;
  };

  var productsOf = function (section) {
    return data.products[section.querySelector('[data-web-section-category]').value] || [];
  };
  var el = function (tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (name) { node.setAttribute(name, attrs[name]); });
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  };
  var item = function (section, p) {
    var li = el('li', { 'class': 'mobile-product', 'data-sort-item': '', 'data-product-id': p[0], 'data-active': p[3] ? '1' : '0' });
    li.append(el('span', { 'class': 'mobile-handle', 'data-drag-handle': '', title: 'Arrastar' }, '⠿'),
      el('button', { type: 'button', 'data-move': '-1', 'aria-label': 'Mover para cima' }, '↑'),
      el('button', { type: 'button', 'data-move': '1', 'aria-label': 'Mover para baixo' }, '↓'),
      el('input', { type: 'hidden', name: 'sections[' + section.dataset.key + '][product_ids][]', value: p[0] }),
      p[4] ? el('img', { 'class': 'eav-file-preview mobile-thumb', src: p[4], alt: '' }) : el('img', { 'class': 'eav-file-preview mobile-thumb eav-file-placeholder', src: '/imagem/logo.png', alt: 'Sem imagem' }),
      el('code', {}, p[1]), ' ', el('span', { 'class': 'mobile-product-name' }, p[2]));
    if (!p[3]) {
      li.append(' ', el('small', {}, 'inativo, não aparece na loja'));
    }
    li.append(el('button', { type: 'button', 'data-web-remove-product': '', 'aria-label': 'Tirar dos escolhidos' }, '✕'));
    return li;
  };
  // the chosen list, the "Adicionar" options (the category's products not chosen), the counts and the banner note
  var refresh = function (section) {
    var box = section.querySelector('[data-web-products-box]');
    var list = box.querySelector('[data-web-chosen]');
    var limit = Number(section.querySelector('[data-web-limit]').value) || 0;
    var products = productsOf(section);
    var chosen = Array.prototype.map.call(list.children, function (li) { return li.dataset.productId; });
    var select = box.querySelector('[data-web-add-select]');
    select.replaceChildren(el('option', { value: '' }, products.length ? 'Escolha um produto desta categoria…' : 'A categoria não tem produtos neste website'));
    products.forEach(function (p) {
      if (chosen.indexOf(String(p[0])) === -1) {
        select.append(el('option', { value: p[0] }, p[1] + ' — ' + p[2] + (p[3] ? '' : ' (inativo)')));
      }
    });
    var shown = list.querySelectorAll('[data-active="1"]').length;
    var active = products.filter(function (p) { return p[3]; }).length;
    var newest = Math.max(0, Math.min(limit - Math.min(shown, limit), active - shown));
    box.querySelector('[data-web-count]').textContent = 'Escolhidos à mão (' + chosen.length + ' de ' + limit + ')';
    box.querySelector('[data-web-note]').textContent = newest ? '+ ' + newest + ' mais novos da categoria completam os ' + limit : '';
    box.querySelector('[data-web-summary]').textContent = '· exibe ' + Math.min(limit, active) + ' de ' + active + ' · ' + chosen.length + ' escolhidos';
    select.disabled = chosen.length >= limit;
    box.querySelector('[data-web-add-product]').disabled = chosen.length >= limit;
    var banners = data.banners[section.querySelector('[data-web-section-category]').value] || 0;
    section.querySelector('[data-web-banner-note]').textContent = banners ? '(' + banners + (banners > 1 ? ' banners, em carrossel)' : ' banner)') : '(a categoria não tem banner: envie em Catálogo → Categorias)';
  };
  var start = function (section, ids) {
    var list = section.querySelector('[data-web-chosen]');
    var byId = {};
    productsOf(section).forEach(function (p) { byId[p[0]] = p; });
    list.replaceChildren();
    ids.forEach(function (id) { byId[id] && list.append(item(section, byId[id])); });
    refresh(section);
  };

  form.querySelectorAll('[data-web-section]').forEach(function (section) {
    start(section, JSON.parse(section.querySelector('[data-web-products-box]').dataset.chosen || '[]'));
  });
  showTop();
  slides && slideButton();

  form.addEventListener('change', function (event) {
    if (event.target.matches('[data-web-top]')) {
      showTop();
    } else if (event.target.matches('[data-web-section-category]')) {
      start(event.target.closest('[data-web-section]'), []);
    }
  });
  form.addEventListener('input', function (event) {
    if (event.target.matches('[data-web-limit]')) {
      refresh(event.target.closest('[data-web-section]'));
    }
  });
  form.addEventListener('sort', function (event) {
    var section = event.target.closest('[data-web-section]');
    section && refresh(section);
  });
  form.addEventListener('click', function (event) {
    var button = event.target.closest('button[type="button"]');
    var section = button && button.closest('[data-web-section]');
    if (!button) {
      return;
    }
    if ('webAddSection' in button.dataset) { // the section was added by the listener above, which runs first
      var added = form.querySelector('[data-web-sections]').lastElementChild;
      added && start(added, []);
    } else if ('webAddSlide' in button.dataset) {
      if (slides.querySelectorAll('[data-web-slide]').length < slideLimit) {
        serial += 1;
        var holder = document.createElement('ol');
        holder.innerHTML = form.querySelector('[data-web-slide-template]').innerHTML.split('__L__').join('n' + Date.now().toString(36) + serial).trim();
        slides.appendChild(holder.firstElementChild);
      }
      slideButton();
    } else if ('webAddProduct' in button.dataset) {
      var select = section.querySelector('[data-web-add-select]');
      var p = productsOf(section).filter(function (x) { return String(x[0]) === select.value; })[0];
      p && section.querySelector('[data-web-chosen]').append(item(section, p));
      refresh(section);
    } else if ('webRemoveProduct' in button.dataset) {
      button.closest('[data-sort-item]').remove();
      refresh(section);
    } else if ('webRemove' in button.dataset && slides) {
      slideButton(); // the listener above already took the slide out
    }
  });

});
