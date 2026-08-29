(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.rouenIframeConsent = {
    attach(context) {
      once('rouen-iframe-consent', '[data-rouen-iframe-consent]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const container = button.closest('.rouen-iframe-consent');
          if (!container) {
            return;
          }

          try {
            const attributes = JSON.parse(window.atob(button.dataset.iframeAttributes));
            const iframe = document.createElement('iframe');

            Object.entries(attributes).forEach(([name, value]) => {
              if (value === true) {
                iframe.setAttribute(name, '');
              }
              else if (value !== false && value !== null) {
                iframe.setAttribute(name, String(value));
              }
            });

            container.replaceChildren(iframe);
          }
          catch (error) {
            Drupal.throwError(error);
          }
        });
      });
    },
  };
})(Drupal, once);

