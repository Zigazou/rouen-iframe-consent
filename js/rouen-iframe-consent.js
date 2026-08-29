/**
 * @file
 * Provides JavaScript behavior for replacing consent placeholders with actual
 * iframes.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Set of allowed iframe attributes to ensure security and prevent XSS
   * attacks.
   *
   * @type {Set<string>}
   */
  const allowedAttributes = new Set([
    'src',
    'width',
    'height',
    'loading',
    'title',
    'allow',
    'allowfullscreen',
    'referrerpolicy',
    'sandbox',
  ]);

  /**
   * Drupal behavior for handling Rouen iframe consent interactions.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches click event listeners to iframe consent buttons.
   */
  Drupal.behaviors.rouenIframeConsent = {
    /**
     * Attaches the consent button event handlers.
     *
     * @param {HTMLElement} context
     *   The context element.
     */
    attach(context) {
      once('rouen-iframe-consent', '[data-rouen-iframe-consent]', context)
        .forEach((button) => {
          button.addEventListener('click', () => {
            const container = button.closest('.rouen-iframe-consent');
            if (!container) {
              return;
            }

            try {
              // Decode base64-encoded JSON attributes stored in
              // data-iframe-attributes.
              const bytes = Uint8Array.from(
                window.atob(button.dataset.iframeAttributes),
                (character) => character.charCodeAt(0)
              );
              const attributes = JSON.parse(
                new TextDecoder('utf-8', { fatal: true }).decode(bytes)
              );

              // Apply each attribute to the created iframe element.
              const iframe = document.createElement('iframe');

              Object.entries(attributes).forEach(([name, value]) => {
                if (!allowedAttributes.has(name)) {
                  return;
                }

                if (value === true) {
                  iframe.setAttribute(name, '');
                }
                else if (value !== false && value !== null) {
                  iframe.setAttribute(name, String(value));
                }
              });

              // Replace placeholder container content with the loaded iframe.
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
