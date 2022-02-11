<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_product\Entity\ProductInterface;

/**
 * Provides deriver class for pickup conditions.
 */
class PickupPurchasedEntityProductConditionDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new PurchasedEntityConditionDeriver object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $product_entity_types = array_filter($this->entityTypeManager->getDefinitions(), static function (EntityTypeInterface $entity_type) {
      return $entity_type->entityClassImplements(ProductInterface::class);
    });

    foreach ($product_entity_types as $product_entity_type_id => $product_entity_type) {
      if ($base_plugin_definition['entity_type'] === 'commerce_order') {
        $display_label = new TranslatableMarkup('Order contains specific shippable :item', [':item' => $product_entity_type->getPluralLabel()]);
      }
      else {
        $display_label = new TranslatableMarkup('Specific shippable :item', [':item' => $product_entity_type->getSingularLabel()]);
      }

      $this->derivatives[$product_entity_type_id] = [
        'label' => $product_entity_type->getLabel(),
        'display_label' => $display_label,
        'purchasable_entity_type' => $product_entity_type_id,
      ] + $base_plugin_definition;
    }

    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
