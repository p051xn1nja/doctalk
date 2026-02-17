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


  function syncDayToggle(section) {
    var toggle = section.querySelector('.js-day-toggle');
    var list = section.querySelector('.js-day-tasks');

    if (!toggle || !list) {
      return;
    }

    var open = hasClass(list, 'is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? 'Day ▴' : 'Day ▾';
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

  var daySections = document.querySelectorAll('.day-group');
  for (var d = 0; d < daySections.length; d += 1) {
    syncDayToggle(daySections[d]);
  }

  var items = document.querySelectorAll('.task-item');

  for (var i = 0; i < items.length; i += 1) {
    var item = items[i];
    var slider = item.querySelector('.js-progress-slider');
    var valueText = item.querySelector('.js-progress-value');
    var bar = item.querySelector('.js-progress-bar');

    syncToggle(item);

    if (!slider || !valueText) {
      continue;
    }

    var sync = function (currentSlider, currentValueText, currentBar) {
      return function () {
        var value = Number(currentSlider.value || 0);
        currentValueText.textContent = value + '%';
        if (currentBar) {
          currentBar.value = value;
        }
      };
    }(slider, valueText, bar);

    slider.addEventListener('input', sync, false);
    slider.addEventListener('change', sync, false);
    sync();
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target) {
      return;
    }

    if (hasClass(target, 'js-day-toggle')) {
      var section = target.closest('.day-group');
      if (!section) {
        return;
      }

      var dayTasks = section.querySelector('.js-day-tasks');
      if (!dayTasks) {
        return;
      }

      if (hasClass(dayTasks, 'is-open')) {
        removeClass(dayTasks, 'is-open');
      } else {
        addClass(dayTasks, 'is-open');
      }

      syncDayToggle(section);
      event.preventDefault();
      return;
    }

    if (!hasClass(target, 'js-details-toggle')) {
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


  var newTaskInput = document.querySelector('.js-new-task-attachments');
  var newTaskSelected = document.getElementById('new-task-selected-files');

  if (newTaskInput && newTaskSelected && typeof DataTransfer !== 'undefined') {
    var selectedFilesTransfer = new DataTransfer();

    var renderSelectedFiles = function () {
      newTaskSelected.innerHTML = '';

      if (!newTaskInput.files || newTaskInput.files.length === 0) {
        return;
      }

      for (var fileIndex = 0; fileIndex < newTaskInput.files.length; fileIndex += 1) {
        var file = newTaskInput.files[fileIndex];
        var row = document.createElement('div');
        row.className = 'selected-file';

        var nameSpan = document.createElement('span');
        nameSpan.textContent = file.name;

        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'danger-btn';
        removeButton.style.padding = '6px 10px';
        removeButton.setAttribute('data-remove-new-file', String(fileIndex));
        removeButton.textContent = 'Remove';

        row.appendChild(nameSpan);
        row.appendChild(removeButton);
        newTaskSelected.appendChild(row);
      }
    };

    var syncInputFiles = function () {
      newTaskInput.files = selectedFilesTransfer.files;
      renderSelectedFiles();
    };

    newTaskInput.addEventListener('change', function () {
      if (!newTaskInput.files || newTaskInput.files.length === 0) {
        return;
      }

      for (var addIndex = 0; addIndex < newTaskInput.files.length; addIndex += 1) {
        if (selectedFilesTransfer.files.length >= 10) {
          break;
        }

        selectedFilesTransfer.items.add(newTaskInput.files[addIndex]);
      }

      syncInputFiles();
    }, false);

    newTaskSelected.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.getAttribute) {
        return;
      }

      var indexRaw = target.getAttribute('data-remove-new-file');
      if (indexRaw === null) {
        return;
      }

      var removeIndex = Number(indexRaw);
      if (!newTaskInput.files || isNaN(removeIndex)) {
        return;
      }

      var dt = new DataTransfer();
      for (var i = 0; i < selectedFilesTransfer.files.length; i += 1) {
        if (i !== removeIndex) {
          dt.items.add(selectedFilesTransfer.files[i]);
        }
      }

      selectedFilesTransfer = dt;
      syncInputFiles();
      event.preventDefault();
    }, false);

    renderSelectedFiles();
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
