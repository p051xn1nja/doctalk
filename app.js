document.addEventListener('DOMContentLoaded', function () {
  var items = document.querySelectorAll('.task-item');

  for (var i = 0; i < items.length; i += 1) {
    var item = items[i];
    var slider = item.querySelector('.js-progress-slider');
    var valueText = item.querySelector('.js-progress-value');
    var bar = item.querySelector('.js-progress-bar');
    var toggle = item.querySelector('.js-accordion-toggle');
    var details = item.querySelector('.js-task-details');

    if (toggle && details) {
      toggle.addEventListener('click', function (currentToggle, currentDetails) {
        return function () {
          var open = currentDetails.classList.contains('is-open');
          currentDetails.classList.toggle('is-open');
          currentToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
          currentToggle.textContent = open ? '▾' : '▴';
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
});
