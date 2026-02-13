document.addEventListener('DOMContentLoaded', function () {
  function cleanSpaces(value) {
    return value.replace(/^\s+|\s+$/g, '').replace(/\s+/g, ' ');
  }

  function hasClass(el, className) {
    return new RegExp('(^|\\s)' + className + '(\\s|$)').test(el.className);
  }

  function addClass(el, className) {
    if (!hasClass(el, className)) {
      el.className = cleanSpaces(el.className + ' ' + className);
    }
  }

  function removeClass(el, className) {
    el.className = cleanSpaces(el.className.replace(new RegExp('(^|\\s)' + className + '(?=\\s|$)', 'g'), ' '));
  }

  function setDetailsOpen(toggle, details, open) {
    if (open) {
      addClass(details, 'is-open');
      details.style.display = 'block';
    } else {
      removeClass(details, 'is-open');
      details.style.display = 'none';
    }

    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? '▴' : '▾';
  }

  var items = document.querySelectorAll('.task-item');

  for (var i = 0; i < items.length; i += 1) {
    var item = items[i];
    var slider = item.querySelector('.js-progress-slider');
    var valueText = item.querySelector('.js-progress-value');
    var bar = item.querySelector('.js-progress-bar');
    var toggle = item.querySelector('.js-accordion-toggle');
    var details = item.querySelector('.js-task-details');

    if (toggle && details) {
      setDetailsOpen(toggle, details, hasClass(details, 'is-open'));

      toggle.addEventListener('click', function (currentToggle, currentDetails) {
        return function () {
          var open = hasClass(currentDetails, 'is-open');
          setDetailsOpen(currentToggle, currentDetails, !open);
        };
      }(toggle, details), false);
    }

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
