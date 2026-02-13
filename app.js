document.addEventListener('DOMContentLoaded', function () {
  function hasClass(el, className) {
    return new RegExp('(^|\\s)' + className + '(\\s|$)').test(el.className);
  }

  function addClass(el, className) {
    if (!hasClass(el, className)) {
      el.className = (el.className + ' ' + className).replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
    }
  }

  function removeClass(el, className) {
    el.className = el.className.replace(new RegExp('(^|\\s)' + className + '(?=\\s|$)', 'g'), ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
  }

  function syncToggle(item) {
    var toggle = item.querySelector('.js-details-toggle');
    var details = item.querySelector('.js-task-details');

    if (!toggle || !details) {
      return;
    }

    var open = hasClass(details, 'is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? 'Details ▴' : 'Details ▾';
  }

  var items = document.querySelectorAll('.task-item');

  for (var i = 0; i < items.length; i += 1) {
    var item = items[i];
    var slider = item.querySelector('.js-progress-slider');
    var valueText = item.querySelector('.js-progress-value');
    var bar = item.querySelector('.js-progress-bar');

    syncToggle(item);

    if (!slider || !valueText || !bar) {
      continue;
    }

    var sync = function (currentSlider, currentValueText, currentBar) {
      return function () {
        var value = Number(currentSlider.value || 0);
        currentValueText.textContent = value + '%';
        currentBar.value = value;
      };
    }(slider, valueText, bar);

    slider.addEventListener('input', sync, false);
    slider.addEventListener('change', sync, false);
    sync();
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !hasClass(target, 'js-details-toggle')) {
      return;
    }

    var item = target.closest('.task-item');
    if (!item) {
      return;
    }

    var details = item.querySelector('.js-task-details');
    if (!details) {
      return;
    }

    if (hasClass(details, 'is-open')) {
      removeClass(details, 'is-open');
    } else {
      addClass(details, 'is-open');
    }

    syncToggle(item);
    event.preventDefault();
  }, false);

  document.addEventListener('keydown', function (event) {
    var key = event.key || event.keyCode;
    var isEscape = key === 'Escape' || key === 'Esc' || key === 27;

    if (!isEscape) {
      return;
    }

    var editForm = document.querySelector('.js-edit-form');
    if (!editForm) {
      return;
    }

    var cancelLink = editForm.querySelector('.js-cancel-edit');
    if (cancelLink && cancelLink.href) {
      window.location.href = cancelLink.href;
      event.preventDefault();
      return;
    }

    var cancelUrl = editForm.getAttribute('data-cancel-url');
    if (cancelUrl) {
      window.location.href = cancelUrl;
      event.preventDefault();
    }
  }, false);
});
