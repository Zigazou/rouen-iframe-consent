<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\rouen_iframe_consent\RouenIframeConsentServiceProvider;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler;
use Drupal\Tests\UnitTestCase;

/**
 * Tests thumbnail lookup URL handler discovery.
 *
 * @group rouen_iframe_consent
 */
final class ThumbnailLookupUrlHandlerDiscoveryTest extends UnitTestCase {

  /**
   * Tests that every concrete handler subclass is registered and tagged.
   */
  public function testHandlerDiscovery(): void {
    $container = new ContainerBuilder();
    (new RouenIframeConsentServiceProvider())->register($container);

    $service_ids = array_keys($container->findTaggedServiceIds(
      'rouen_iframe_consent.thumbnail_lookup_url_handler',
    ));

    self::assertNotEmpty($service_ids);
    foreach ($service_ids as $service_id) {
      self::assertTrue(is_subclass_of(
        $service_id,
        ThumbnailLookupUrlHandler::class,
      ));
    }
  }

}
