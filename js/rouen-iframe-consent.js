/**
 * @file
 * Activates external embeds after individual consent.
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
   * Attributes retained in inert form until a blockquote is activated.
   *
   * @type {Map<string, string>}
   */
  const previewAttributes = new Map([
    ['data-rouen-embed-class', 'class'],
    ['data-rouen-embed-cite', 'cite'],
    ['data-rouen-embed-href', 'href'],
    ['data-rouen-embed-instgrm-permalink', 'data-instgrm-permalink'],
    ['data-rouen-embed-instgrm-version', 'data-instgrm-version'],
    ['data-rouen-embed-instgrm-captioned', 'data-instgrm-captioned'],
    ['data-rouen-embed-video-id', 'data-video-id'],
    ['data-rouen-embed-unique-id', 'data-unique-id'],
    ['data-rouen-embed-embed-type', 'data-embed-type'],
    ['data-rouen-embed-embed-from', 'data-embed-from'],
    ['data-rouen-embed-bluesky-uri', 'data-bluesky-uri'],
    ['data-rouen-embed-bluesky-cid', 'data-bluesky-cid'],
    [
      'data-rouen-embed-bluesky-embed-color-mode',
      'data-bluesky-embed-color-mode',
    ],
  ]);

  /**
   * Decodes a base64-encoded UTF-8 JSON value.
   *
   * @param {string} encoded
   *   Encoded JSON.
   *
   * @return {object}
   *   Decoded value.
   */
  function decodeAttributes(encoded) {
    const bytes = Uint8Array.from(
      window.atob(encoded),
      (character) => character.charCodeAt(0)
    );

    return JSON.parse(
      new TextDecoder('utf-8', { fatal: true }).decode(bytes)
    );
  }

  /**
   * Activates a sanitized blockquote and loads its provider script.
   *
   * @param {HTMLElement} container
   *   Embed container.
   */
  function activateBlockquote(container) {
    const attributes = decodeAttributes(container.dataset.scriptAttributes);
    const script = document.createElement('script');
    const allowedScriptAttributes = new Set([
      'src',
      'async',
      'defer',
      'charset',
    ]);

    Object.entries(attributes).forEach(([name, value]) => {
      if (!allowedScriptAttributes.has(name)) {
        return;
      }

      if (value === true) {
        script.setAttribute(name, '');
      }
      else if (value !== false && value !== null) {
        script.setAttribute(name, String(value));
      }
    });

    container.querySelectorAll(Array.from(previewAttributes.keys())
      .map((attribute) => `[${attribute}]`)
      .join(', '))
      .forEach((element) => {
        previewAttributes.forEach((target, source) => {
          if (element.hasAttribute(source)) {
            element.setAttribute(target, element.getAttribute(source));
            element.removeAttribute(source);
          }
        });
      });

    container.querySelector('.rouen-iframe-consent__overlay')?.remove();
    container.appendChild(script);
  }

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
      once(
        'rouen-embed-autoload',
        '[data-rouen-embed-autoload]',
        context
      ).forEach((container) => {
        try {
          activateBlockquote(container);
        }
        catch (error) {
          Drupal.throwError(error);
        }
      });

      once('rouen-iframe-consent', '[data-rouen-iframe-consent]', context)
        .forEach((button) => {
          button.addEventListener('click', () => {
            const container = button.closest('.rouen-iframe-consent');
            if (!container) {
              return;
            }

            try {
              // If the embed type is a blockquote, activate it directly.
              if (button.dataset.embedType === 'blockquote') {
                activateBlockquote(container);
                return;
              }

              // Decode base64-encoded JSON attributes stored in
              // data-iframe-attributes.
              const attributes = decodeAttributes(
                button.dataset.iframeAttributes
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
