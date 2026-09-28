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

// Product form "Organizar tela": blocks ([data-block]) and the fields inside a
// block ([data-field]) are reordered by native drag and drop, or by the ↑ ↓
// buttons (touch and keyboard); ↔ makes a block span the whole row. The order
// is saved for the logged admin only (POST, JSON reply); the product's values
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
        bar(item, item.dataset.title || item.querySelector('label, legend')?.textContent.trim() || item.dataset.field, 'block' in item.dataset);
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

// Image settings: show the chosen file right away (it's only uploaded on "Salvar")
document.addEventListener('change', function (event) {
  if (!event.target.matches('input[data-image-input]')) {
    return;
  }
  var preview = event.target.closest('p').querySelector('[data-image-preview]');
  var file = event.target.files[0];
  preview.hidden = !file;
  if (file) {
    preview.src = URL.createObjectURL(file);
  }
});
