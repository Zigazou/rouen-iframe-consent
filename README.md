# Rouen Iframe Consent

Rouen Iframe Consent is a Drupal 11.2+/12 field formatter that prevents a
third-party iframe from loading until the visitor explicitly accepts it. It is
intended for sites whose only consent-requiring storage comes from embedded
third-party content.

The formatter:

- extracts the first iframe from a `text`, `text_long`, or `text_with_summary`
  field;
- discards every other HTML element and reconstructs the iframe from an
  allowlist of safe attributes;
- reserves the iframe's aspect ratio to avoid layout shift;
- shows a local thumbnail, an explanatory message, the provider name, and an **I
  accept** button;
- creates the iframe in the browser only after the button is selected;
- does not persist consent in cookies or browser storage;
- loads configured trusted hosts immediately without displaying the consent
  prompt.

## Installation and setup

1. Place the module in the site's custom module directory and enable **Rouen
   Iframe Consent**.
2. On the *Manage display* page for a formatted HTML text field, select **Iframe
   with individual consent**.
3. Configure trusted hosts and the optional generic preview image at
   `/admin/config/media/rouen-iframe-consent`.
4. Run Drupal cron regularly.

Each field item is expected to contain one iframe embed code. The first valid
iframe is used and all surrounding markup is omitted from output. The formatter
never renders invalid iframe source URLs.

## Thumbnail lifecycle

When an entity containing a protected iframe is saved or first rendered, the
module queues an oEmbed lookup. Cron downloads supported JPEG, PNG, GIF, or WebP
thumbnails to `public://rouen_iframe_consent/thumbnails`. Downloads are limited
to 5 MiB and retried up to three times. Unsupported providers use the globally
configured image or the bundled fallback.

Thumbnail records and files are removed when the iframe field item disappears,
the iframe URL changes, the formatter no longer applies on the next entity save,
or the owning entity is deleted. Queue items referring to deleted records are
ignored safely.

## Security and privacy notes

Only HTTP(S) iframe sources without embedded credentials are accepted. The
module ignores event handlers, `srcdoc`, inline styles, classes, IDs, and
arbitrary attributes. It retains only dimensions, title, `allow`,
`allowfullscreen`, a validated referrer policy, and recognized sandbox tokens.
Attribute values are escaped by Drupal, and JavaScript applies only this
server-generated attribute set.

The preview image is downloaded server-side through Drupal core's oEmbed
provider registry. Viewing a placeholder therefore makes no browser request to
the iframe provider. Administrators remain responsible for verifying that
trusted hosts do not require consent and that the rest of the site matches the
stated privacy model.
