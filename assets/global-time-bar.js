(() => {
  const formatTime = (timezone) => {
    try {
      return new Intl.DateTimeFormat('en-AU', {
        timeZone: timezone,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
      }).format(new Date()).toLowerCase();
    } catch (error) {
      return '--:--';
    }
  };

  const initialize = (scope = document) => {
    scope.querySelectorAll('.dc-time-bar').forEach((bar) => {
      const refresh = () => bar.querySelectorAll('[data-timezone]').forEach((item) => {
        const value = item.querySelector('.dc-time-bar__value');
        if (value) value.textContent = formatTime(item.dataset.timezone || '');
      });
      refresh();
      if (!bar.dataset.timerStarted) {
        bar.dataset.timerStarted = 'true';
        window.setInterval(refresh, 60000);
      }
    });
  };

  document.addEventListener('DOMContentLoaded', () => initialize());
  window.addEventListener('elementor/frontend/init', () => {
    if (window.elementorFrontend?.hooks) {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/dc-global-time-bar.default', ($scope) => initialize($scope[0]));
    }
  });
})();
