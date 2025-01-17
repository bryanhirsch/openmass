<?php

namespace Drupal\mass_caching\EventSubscriber;

use Drupal\Core\PageCache\ResponsePolicyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response subscriber to add stale cache headers.
 */
class StaleResponseSubscriber implements EventSubscriberInterface {

  const DURATION = 604800;

  /**
   * Add stale http headers.
   */
  public function onKernelResponse(FilterResponseEvent $event) {
    $response = $event->getResponse();
    if (!$response->headers->hasCacheControlDirective('private')) {
      $response->headers->addCacheControlDirective('stale-if-error', self::DURATION);
      $response->headers->addCacheControlDirective('stale-while-revalidate', self::DURATION);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Act after the FinishResponseSubscriber.
    $events[KernelEvents::RESPONSE][] = ['onKernelResponse', -18];
    return $events;
  }

}
