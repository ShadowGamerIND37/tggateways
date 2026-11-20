/* The reusable link's lifetime is distinct from each checkout's countdown. */
(function () {
  const choice = document.getElementById('link-expiry');
  const group = document.getElementById('link-expiry-custom');
  const input = document.getElementById('link-expiry-value');
  const label = document.getElementById('link-expiry-value-label');
  const help = document.getElementById('link-expiry-value-help');
  if (!choice || !group || !input || !label || !help) return;

  function update() {
    const seconds = choice.value === 'custom_seconds';
    const minutes = choice.value === 'custom_minutes';
    const custom = seconds || minutes;
    group.hidden = !custom;
    input.disabled = !custom;
    input.required = custom;
    input.max = minutes ? '10080' : '604800';
    label.textContent = seconds ? 'Expiry in seconds' : minutes ? 'Expiry in minutes' : 'Custom expiry amount';
    help.textContent = seconds ? 'Enter 1–604800 seconds (up to 7 days).' :
      minutes ? 'Enter 1–10080 minutes (up to 7 days).' : 'Only used for custom expiry. Maximum: 7 days.';
  }
  choice.addEventListener('change', update);
  update();
})();
