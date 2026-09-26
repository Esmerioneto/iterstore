/*
 * Admin behaviors. Forms with data-confirm ask before submitting, using
 * SweetAlert2 (vendored in /assets/vendor/sweetalert2). If the library
 * failed to load, falls back to the native confirm() so nothing breaks.
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
    confirmButtonText: 'Remover',
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
