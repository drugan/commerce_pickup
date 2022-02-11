<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

use Drupal\commerce\PurchasableEntityInterface;

/**
 * Provides the shippable purchased entity product condition for orders.
 *
 * @CommerceCondition(
 *   id = "pickup_order_purchased_entity_product",
 *   label = @Translation("Shippable purchased entity product"),
 *   display_label = @Translation("Order contains specific shippable purchased item product"),
 *   category = @Translation("Products"),
 *   entity_type = "commerce_order",
 *   weight = -10,
 *   deriver = "Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupPurchasedEntityProductConditionDeriver"
 * )
 */
class PickupOrderPurchasedEntityProduct extends PickupPurchasedEntityConditionBase {

  /**
   * {@inheritdoc}
   */
  protected function isValid(PurchasableEntityInterface $purchasable_entity = NULL): bool {
    return $purchasable_entity !== NULL
      && $this->getPurchasableEntityType() === $purchasable_entity->getProduct()->getEntityTypeId()
      && in_array($purchasable_entity->getProduct()->uuid(), $this->configuration['entities'], TRUE);
  }

}
