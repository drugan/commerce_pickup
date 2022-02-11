<?php

namespace Drupal\commerce_pickup;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Swaps the default commerce_shipping.profile_field_copy service class.
 */
class CommercePickupServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('commerce_shipping.profile_field_copy')) {
      $container->getDefinition('commerce_shipping.profile_field_copy')
        ->setClass(PickupProfileFieldCopy::class);
    }
  }

}
