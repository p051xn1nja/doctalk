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


  function syncYearToggle(section) {
    var toggle = section.querySelector('.js-year-toggle');
    var list = section.querySelector('.js-year-months');

    if (!toggle || !list) {
      return;
    }

    var open = hasClass(list, 'is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? 'Year ▴' : 'Year ▾';
  }

  function syncMonthToggle(section) {
    var toggle = section.querySelector('.js-month-toggle');
    var list = section.querySelector('.js-month-days');

    if (!toggle || !list) {
      return;
    }

    var open = hasClass(list, 'is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? 'Month ▴' : 'Month ▾';
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

  function setOpenState(element, shouldOpen) {
    if (!element) {
      return;
    }

    if (shouldOpen) {
      addClass(element, 'is-open');
    } else {
      removeClass(element, 'is-open');
    }
  }

  function expandAllGroups() {
    var years = document.querySelectorAll('.year-group');
    for (var yIndex = 0; yIndex < years.length; yIndex += 1) {
      var year = years[yIndex];
      setOpenState(year.querySelector('.js-year-months'), true);
      syncYearToggle(year);
    }

    var months = document.querySelectorAll('.month-group');
    for (var mIndex = 0; mIndex < months.length; mIndex += 1) {
      var month = months[mIndex];
      setOpenState(month.querySelector('.js-month-days'), true);
      syncMonthToggle(month);
    }

    var days = document.querySelectorAll('.day-group');
    for (var dIndex = 0; dIndex < days.length; dIndex += 1) {
      var day = days[dIndex];
      setOpenState(day.querySelector('.js-day-tasks'), true);
      syncDayToggle(day);
    }
  }

  function expandCurrentMonth() {
    var currentMonthKey = new Date().toISOString().slice(0, 7);
    var targetMonth = document.querySelector('.month-group[data-month-key="' + currentMonthKey + '"]');
    if (!targetMonth) {
      return;
    }

    var parentYear = targetMonth.closest('.year-group');
    if (parentYear) {
      setOpenState(parentYear.querySelector('.js-year-months'), true);
      syncYearToggle(parentYear);
    }

    setOpenState(targetMonth.querySelector('.js-month-days'), true);
    syncMonthToggle(targetMonth);

    var dayGroups = targetMonth.querySelectorAll('.day-group');
    for (var index = 0; index < dayGroups.length; index += 1) {
      setOpenState(dayGroups[index].querySelector('.js-day-tasks'), true);
      syncDayToggle(dayGroups[index]);
    }
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


  var groupStateStorageKey = 'taskflow-group-open-state';

  function saveGroupOpenState() {
    if (typeof window === 'undefined' || !window.sessionStorage) {
      return;
    }

    var yearOpen = [];
    var yearGroups = document.querySelectorAll('.year-group');
    for (var yIndex = 0; yIndex < yearGroups.length; yIndex += 1) {
      var yearGroup = yearGroups[yIndex];
      var yearKey = yearGroup.getAttribute('data-year-key') || '';
      var yearMonths = yearGroup.querySelector('.js-year-months');
      if (yearKey !== '' && yearMonths && hasClass(yearMonths, 'is-open')) {
        yearOpen.push(yearKey);
      }
    }

    var monthOpen = [];
    var monthGroups = document.querySelectorAll('.month-group');
    for (var mIndex = 0; mIndex < monthGroups.length; mIndex += 1) {
      var monthGroup = monthGroups[mIndex];
      var monthKey = monthGroup.getAttribute('data-month-key') || '';
      var monthDays = monthGroup.querySelector('.js-month-days');
      if (monthKey !== '' && monthDays && hasClass(monthDays, 'is-open')) {
        monthOpen.push(monthKey);
      }
    }

    var dayOpen = [];
    var dayGroups = document.querySelectorAll('.day-group');
    for (var dIndex = 0; dIndex < dayGroups.length; dIndex += 1) {
      var dayGroup = dayGroups[dIndex];
      var dayKey = dayGroup.getAttribute('data-day-key') || '';
      var parentMonth = dayGroup.closest('.month-group');
      var monthKeyForDay = parentMonth ? (parentMonth.getAttribute('data-month-key') || '') : '';
      var dayTasks = dayGroup.querySelector('.js-day-tasks');
      if (dayKey !== '' && monthKeyForDay !== '' && dayTasks && hasClass(dayTasks, 'is-open')) {
        dayOpen.push(monthKeyForDay + '|' + dayKey);
      }
    }

    var payload = {
      yearOpen: yearOpen,
      monthOpen: monthOpen,
      dayOpen: dayOpen,
    };

    window.sessionStorage.setItem(groupStateStorageKey, JSON.stringify(payload));
  }

  function restoreGroupOpenState() {
    if (typeof window === 'undefined' || !window.sessionStorage) {
      return;
    }

    var raw = window.sessionStorage.getItem(groupStateStorageKey);
    if (!raw) {
      return;
    }

    var payload;
    try {
      payload = JSON.parse(raw);
    } catch (error) {
      return;
    }

    var yearOpen = payload && Array.isArray(payload.yearOpen) ? payload.yearOpen : [];
    var monthOpen = payload && Array.isArray(payload.monthOpen) ? payload.monthOpen : [];
    var dayOpen = payload && Array.isArray(payload.dayOpen) ? payload.dayOpen : [];

    for (var yRestore = 0; yRestore < yearOpen.length; yRestore += 1) {
      var yearNode = document.querySelector('.year-group[data-year-key="' + yearOpen[yRestore] + '"]');
      if (yearNode) {
        setOpenState(yearNode.querySelector('.js-year-months'), true);
      }
    }

    for (var mRestore = 0; mRestore < monthOpen.length; mRestore += 1) {
      var monthNode = document.querySelector('.month-group[data-month-key="' + monthOpen[mRestore] + '"]');
      if (monthNode) {
        setOpenState(monthNode.querySelector('.js-month-days'), true);
      }
    }

    for (var dRestore = 0; dRestore < dayOpen.length; dRestore += 1) {
      var parts = String(dayOpen[dRestore]).split('|');
      if (parts.length !== 2) {
        continue;
      }
      var monthNodeForDay = document.querySelector('.month-group[data-month-key="' + parts[0] + '"]');
      if (!monthNodeForDay) {
        continue;
      }
      var dayNode = monthNodeForDay.querySelector('.day-group[data-day-key="' + parts[1] + '"]');
      if (dayNode) {
        setOpenState(dayNode.querySelector('.js-day-tasks'), true);
      }
    }
  }

  restoreGroupOpenState();

  var yearSections = document.querySelectorAll('.year-group');
  for (var y = 0; y < yearSections.length; y += 1) {
    syncYearToggle(yearSections[y]);
  }

  var monthSections = document.querySelectorAll('.month-group');
  for (var m = 0; m < monthSections.length; m += 1) {
    syncMonthToggle(monthSections[m]);
  }

  var daySections = document.querySelectorAll('.day-group');
  for (var d = 0; d < daySections.length; d += 1) {
    syncDayToggle(daySections[d]);
  }

  var expandAllButton = document.querySelector('.js-expand-all-groups');
  if (expandAllButton) {
    expandAllButton.addEventListener('click', function (event) {
      expandAllGroups();
      saveGroupOpenState();
      event.preventDefault();
    }, false);
  }

  var expandCurrentMonthButton = document.querySelector('.js-expand-current-month');
  if (expandCurrentMonthButton) {
    expandCurrentMonthButton.addEventListener('click', function (event) {
      expandCurrentMonth();
      saveGroupOpenState();
      event.preventDefault();
    }, false);
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

    if (hasClass(target, 'js-year-toggle')) {
      var yearSection = target.closest('.year-group');
      if (!yearSection) {
        return;
      }

      var yearMonths = yearSection.querySelector('.js-year-months');
      if (!yearMonths) {
        return;
      }

      if (hasClass(yearMonths, 'is-open')) {
        removeClass(yearMonths, 'is-open');
      } else {
        addClass(yearMonths, 'is-open');
      }

      syncYearToggle(yearSection);
      saveGroupOpenState();
      event.preventDefault();
      return;
    }

    if (hasClass(target, 'js-month-toggle')) {
      var monthSection = target.closest('.month-group');
      if (!monthSection) {
        return;
      }

      var monthDays = monthSection.querySelector('.js-month-days');
      if (!monthDays) {
        return;
      }

      if (hasClass(monthDays, 'is-open')) {
        removeClass(monthDays, 'is-open');
      } else {
        addClass(monthDays, 'is-open');
      }

      syncMonthToggle(monthSection);
      saveGroupOpenState();
      event.preventDefault();
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
      saveGroupOpenState();
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


  function setupAttachmentPicker(input, selectedContainer, addButton, maxFiles, uploadButton) {
    if (!input || !selectedContainer || typeof DataTransfer === 'undefined') {
      return;
    }

    var selectedFilesTransfer = new DataTransfer();

    var renderSelectedFiles = function () {
      selectedContainer.innerHTML = '';

      if (uploadButton) {
        uploadButton.style.display = (input.files && input.files.length > 0) ? '' : 'none';
      }

      if (!input.files || input.files.length === 0) {
        return;
      }

      for (var fileIndex = 0; fileIndex < input.files.length; fileIndex += 1) {
        var file = input.files[fileIndex];
        var row = document.createElement('div');
        row.className = 'selected-file';

        var nameSpan = document.createElement('span');
        nameSpan.textContent = file.name;

        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'danger-btn';
        removeButton.style.padding = '6px 10px';
        removeButton.setAttribute('data-remove-selected-file', String(fileIndex));
        removeButton.textContent = 'Remove';

        row.appendChild(nameSpan);
        row.appendChild(removeButton);
        selectedContainer.appendChild(row);
      }
    };

    var syncInputFiles = function () {
      input.files = selectedFilesTransfer.files;
      renderSelectedFiles();
    };

    if (addButton) {
      addButton.addEventListener('click', function (event) {
        input.click();
        event.preventDefault();
      }, false);
    }

    input.addEventListener('change', function () {
      if (!input.files || input.files.length === 0) {
        return;
      }

      for (var addIndex = 0; addIndex < input.files.length; addIndex += 1) {
        if (selectedFilesTransfer.files.length >= maxFiles) {
          break;
        }

        selectedFilesTransfer.items.add(input.files[addIndex]);
      }

      syncInputFiles();
    }, false);

    selectedContainer.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.getAttribute) {
        return;
      }

      var indexRaw = target.getAttribute('data-remove-selected-file');
      if (indexRaw === null) {
        return;
      }

      var removeIndex = Number(indexRaw);
      if (!input.files || isNaN(removeIndex)) {
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

  var newTaskInput = document.querySelector('.js-new-task-attachments');
  var newTaskSelected = document.getElementById('new-task-selected-files');
  var newTaskAddButton = document.querySelector('.js-new-task-add-files');
  setupAttachmentPicker(newTaskInput, newTaskSelected, newTaskAddButton, 10);

  var editForms = document.querySelectorAll('.js-edit-form');
  for (var editIndex = 0; editIndex < editForms.length; editIndex += 1) {
    var editForm = editForms[editIndex];
    var editInput = editForm.querySelector('.js-edit-task-attachments');
    var editSelected = editForm.querySelector('.js-edit-selected-files');
    var editAddButton = editForm.querySelector('.js-edit-add-files');
    setupAttachmentPicker(editInput, editSelected, editAddButton, 10);
  }

  var quickAttachForms = document.querySelectorAll('.js-quick-attach-form');
  for (var quickIndex = 0; quickIndex < quickAttachForms.length; quickIndex += 1) {
    var quickForm = quickAttachForms[quickIndex];
    var quickInput = quickForm.querySelector('.js-quick-task-attachments');
    var quickSelected = quickForm.querySelector('.js-quick-selected-files');
    var quickAddButton = quickForm.querySelector('.js-quick-add-files');
    var quickUploadButton = quickForm.querySelector('.js-quick-upload-files');
    setupAttachmentPicker(quickInput, quickSelected, quickAddButton, 10, quickUploadButton);
  }

  function applyWrapFormat(textarea, prefix, suffix, placeholder) {
    if (!textarea) {
      return;
    }

    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var value = textarea.value || '';
    var selected = value.slice(start, end);
    if (selected === '') {
      selected = placeholder;
    }

    var next = value.slice(0, start) + prefix + selected + suffix + value.slice(end);
    textarea.value = next;

    var cursorStart = start + prefix.length;
    var cursorEnd = cursorStart + selected.length;
    textarea.focus();
    textarea.setSelectionRange(cursorStart, cursorEnd);
  }

  function applyLinePrefixFormat(textarea, prefix, placeholder) {
    if (!textarea) {
      return;
    }

    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var value = textarea.value || '';
    var selected = value.slice(start, end);
    if (selected === '') {
      selected = placeholder;
    }

    var lines = selected.split('\n');
    for (var lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
      lines[lineIndex] = prefix + lines[lineIndex];
    }

    var replaced = lines.join('\n');
    var next = value.slice(0, start) + replaced + value.slice(end);
    textarea.value = next;
    textarea.focus();
    textarea.setSelectionRange(start, start + replaced.length);
  }

  var descriptionToolbars = document.querySelectorAll('.desc-toolbar');
  for (var toolbarIndex = 0; toolbarIndex < descriptionToolbars.length; toolbarIndex += 1) {
    descriptionToolbars[toolbarIndex].addEventListener('click', function (event) {
      var target = event.target;
      if (!target) {
        return;
      }

      var button = target.closest('[data-format-action]');
      if (!button) {
        return;
      }

      var editor = button.closest('.desc-editor');
      var textarea = editor ? editor.querySelector('.js-format-description') : null;
      if (!textarea) {
        return;
      }

      var action = button.getAttribute('data-format-action');
      if (action === 'bold') {
        applyWrapFormat(textarea, '**', '**', 'bold text');
      } else if (action === 'italic') {
        applyWrapFormat(textarea, '*', '*', 'italic text');
      } else if (action === 'h2') {
        applyLinePrefixFormat(textarea, '## ', 'Heading');
      } else if (action === 'ul') {
        applyLinePrefixFormat(textarea, '- ', 'List item');
      } else if (action === 'ol') {
        applyLinePrefixFormat(textarea, '1. ', 'List item');
      } else if (action === 'quote') {
        applyLinePrefixFormat(textarea, '> ', 'Quoted text');
      } else if (action === 'code') {
        applyWrapFormat(textarea, '`', '`', 'code');
      } else if (action === 'link') {
        applyWrapFormat(textarea, '[', '](https://)', 'link text');
      }

      event.preventDefault();
    }, false);
  }

  var dateOpenButtons = document.querySelectorAll('.js-date-open');
  for (var dateButtonIndex = 0; dateButtonIndex < dateOpenButtons.length; dateButtonIndex += 1) {
    dateOpenButtons[dateButtonIndex].addEventListener('click', function (event) {
      var wrapper = this.parentNode;
      var dateInput = wrapper ? wrapper.querySelector('.js-date-picker') : null;
      if (dateInput) {
        dateInput.focus();
        if (typeof dateInput.showPicker === 'function') {
          dateInput.showPicker();
        }
      }
      event.preventDefault();
    }, false);
  }

  var dateInputs = document.querySelectorAll('.js-date-picker');
  for (var dateIndex = 0; dateIndex < dateInputs.length; dateIndex += 1) {
    var dateInput = dateInputs[dateIndex];

    dateInput.addEventListener('click', function () {
      if (typeof this.showPicker === 'function') {
        this.showPicker();
      }
    }, false);

    dateInput.addEventListener('focus', function () {
      if (typeof this.showPicker === 'function') {
        this.showPicker();
      }
    }, false);
  }

  var preserveGroupForms = document.querySelectorAll('.js-preserve-groups');
  for (var preserveIndex = 0; preserveIndex < preserveGroupForms.length; preserveIndex += 1) {
    preserveGroupForms[preserveIndex].addEventListener('submit', function () {
      saveGroupOpenState();
    }, false);
  }


  var activeEditForm = document.querySelector('.js-edit-form[data-focus-edit-description="true"]');
  if (activeEditForm) {
    var editDescription = activeEditForm.querySelector('textarea[name="description"]');
    if (editDescription) {
      window.requestAnimationFrame(function () {
        if (typeof editDescription.focus === 'function') {
          editDescription.focus();
        }
        if (typeof editDescription.setSelectionRange === 'function') {
          var end = editDescription.value.length;
          editDescription.setSelectionRange(end, end);
        }
      });
    }
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

  window.addEventListener('beforeunload', function () {
    saveGroupOpenState();
  }, false);
});
