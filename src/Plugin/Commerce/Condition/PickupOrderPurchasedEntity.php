<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

/**
 * Provides the shippable purchased entity condition for orders.
 *
 * @CommerceCondition(
 *   id = "pickup_order_purchased_entity",
 *   label = @Translation("Shippable purchased entity"),
 *   display_label = @Translation("Order contains specific shippable purchased item"),
 *   category = @Translation("Purchased items"),
 *   entity_type = "commerce_order",
 *   weight = -10,
 *   deriver = "Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupPurchasedEntityConditionDeriver"
 * )
 */
class PickupOrderPurchasedEntity extends PickupPurchasedEntityConditionBase {

}
