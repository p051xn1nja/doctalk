(function () {
  var passwordInput = document.getElementById('password');
  var toggleButton = document.getElementById('passwordToggle');
  if (!passwordInput || !toggleButton) {
    return;
  }

  toggleButton.addEventListener('click', function () {
    var revealing = passwordInput.type === 'password';
    passwordInput.type = revealing ? 'text' : 'password';
    toggleButton.textContent = revealing ? '🙈' : '👁';
    toggleButton.setAttribute('aria-pressed', revealing ? 'true' : 'false');
    toggleButton.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');
  });
}());
