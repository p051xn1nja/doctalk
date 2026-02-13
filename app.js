document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.task-item').forEach((item) => {
    const slider = item.querySelector('.js-progress-slider');
    const valueText = item.querySelector('.js-progress-value');
    const bar = item.querySelector('.js-progress-bar');

    if (!slider || !valueText || !bar) {
      return;
    }

    const sync = () => {
      const value = Number(slider.value || 0);
      valueText.textContent = `${value}%`;
      bar.value = value;
    };

    slider.addEventListener('input', sync);
    slider.addEventListener('change', sync);
    sync();
  });
});
