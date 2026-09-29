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
        iconColor: inkColor(),
        title: message.dataset.label,
        text: message.textContent,
        showConfirmButton: false,
        showCloseButton: true,
        // Success fades out by itself; errors and warnings stay until closed.
        timer: type === 'success' ? 4000 : undefined,
        timerProgressBar: type === 'success',
        customClass: { popup: 'admin-swal admin-swal-toast' }
      });
    });
  }, Promise.resolve());
});

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
  row.querySelectorAll('input').forEach(function (input) {
    input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
    if (input.type === 'checkbox') {
      input.checked = false;
    } else {
      input.value = '';
    }
  });
  rows.appendChild(row);
  row.querySelector('input').focus();
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
  var useDefault = name.indexOf('config[') === 0 && event.target.closest('p')
    ? event.target.closest('p').querySelector('input[name^="config_default["]')
    : null;
  if (useDefault) {
    useDefault.checked = false;
  }
});

// Image settings and EAV image fields: show the chosen file right away (it's only uploaded on "Salvar")
document.addEventListener('change', function (event) {
  if (!event.target.matches('input[data-image-input]')) {
    return;
  }
  var preview = event.target.closest('p').querySelector('[data-image-preview]');
  var file = event.target.files[0];
  if (!preview) {
    return;
  }
  var placeholder = preview.getAttribute('data-placeholder');
  preview.hidden = !file && !placeholder;
  preview.classList.toggle('eav-file-placeholder', !file && !!placeholder);
  if (file) {
    preview.src = URL.createObjectURL(file);
  } else if (placeholder) {
    preview.src = placeholder;
  }
});

// CEP lookup (ViaCEP): 8 digits in [data-cep="lookup"] fill the form's [data-cep="<ViaCEP field>"] inputs
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
      cep.form.querySelectorAll('[data-cep]:not([data-cep="lookup"])').forEach(function (input) {
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
