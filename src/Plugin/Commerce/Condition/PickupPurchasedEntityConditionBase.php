<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\commerce_order\Plugin\Commerce\Condition\PurchasedEntityConditionBase;
use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\commerce_product\Entity\ProductTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\commerce\PurchasableEntityInterface;

/**
 * Provides base class for pickup conditions.
 */
abstract class PickupPurchasedEntityConditionBase extends PurchasedEntityConditionBase {

  /**
   * The shippable purchasable entity bundles.
   *
   * @var array
   */
  protected $shippableBundles = [];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'shippable_only' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $entity;

    foreach ($order->getItems() as $order_item) {
      if (!$this->isValid($order_item->getPurchasedEntity())) {
        if ($this->configuration['shippable_only']) {
          return FALSE;
        }
        continue;
      }
      $has_variation = TRUE;
      if (!$this->configuration['shippable_only']) {
        break;
      }
    }

    return !empty($has_variation);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $type = $this->getPurchasableEntityType();
    $entity_type = $this->entityTypeManager->getDefinition($type);
    assert($entity_type !== NULL);
    $plural = $entity_type->getPluralLabel();
    $singular = $entity_type->getSingularLabel();
    $search_title = $this->t('Search @entity', [
      '@entity' => $singular,
    ]);
    $title = $entity_type->getCollectionLabel();
    $storage = $this->entityTypeManager->getStorage($entity_type->getBundleEntityType());
    $entity_bundles = [];
    $shippable = 'purchasable_entity_shippable';

    foreach ($storage->loadMultiple() as $bundle_id => $bundle) {
      $entity_bundle = NULL;
      if ($bundle instanceof ProductVariationTypeInterface && $bundle->hasTrait($shippable)) {
        $entity_bundles[] = $bundle_id;
      }
      elseif ($bundle instanceof ProductTypeInterface) {
        if (in_array($bundle->getVariationTypeId(), $this->getShippableBundles($shippable))) {
          $entity_bundles[] = $bundle_id;
        }
      }
    }

    if ($entity_bundles) {
      $default_value = $this->getDefaultValue($type);
    }
    elseif (!$entity_bundles) {
      $form['setup']['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'mark',
        '#value' => $this->t('No shippable purchasable item types are found. Make sure that at least on one of the types the %shippable checkbox is checked.', [
          '%shippable' => $this->t('Shippable'),
        ]),
      ];

      return $form;
    }

    $form['setup'] = [
      '#type' => 'details',
      '#title' => $this->t('Set up'),
      '#open' => empty($this->configuration['entities']),
      '#description' => $this->t("Start typing in the %search_title field to find shippable @entities. To copy selected @entity to the %title field just click somewhere outside of the %search_title field. You can also manually populate the %title field following the %pattern pattern.", [
        '%search_title' => $search_title,
        '%title' => $title,
        '@entities' => $plural,
        '@entity' => $singular,
        '%pattern' => $this->t('Any Title (@id)', [
          '@id' => str_replace(' ', '_', strtoupper($singular)) . '_ID',
        ]),
      ]),
      '#weight' => -2,
    ];

    $form['setup']['shippable_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Shippable @entities only", [
        '@entities' => $plural,
      ]),
      '#description' => $this->t("Hide this shipping method when order contains some @entity other than in the list below.", [
        '@entity' => $singular,
      ]),
      '#default_value' => $this->configuration['shippable_only'],
    ];

    $form['setup']['search_entities'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $search_title,
      '#target_type' => $entity_type->id(),
      '#selection_settings' => [
        'target_bundles' => $entity_bundles,
      ],
      '#ajax' => [
        'callback' => [static::class, 'ajaxRefreshForm'],
        'event' => 'change',
      ],
      '#maxlength' => 1024,
      '#tags' => TRUE,
    ];

    $form['setup']['entities'] = [
      '#type' => 'textarea',
      '#title' => $title,
      '#default_value' => $default_value,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue(array_merge($form['#parents'], ['setup']));
    if (!empty($values['entities'])) {
      $element = $form['setup']['search_entities'];
      $element['#value'] = implode(', ', explode(PHP_EOL, $values['entities']));
      EntityAutocomplete::validateEntityAutocomplete($element, $form_state, $form);
      $entities = $form_state->getValue($element['#parents']);
    }
    if (empty($entities)) {
      $values = $form_state->getValues();
      $parents = $form['#parents'];
      array_pop($parents);
      array_pop($parents);
      NestedArray::setValue($values, array_merge($parents, ['enable']), 0);
      $form_state->setValues($values);
      $this->configuration['shippable_only'] = NULL;
      $this->configuration['entities'] = [];

      return;
    }

    $this->configuration['shippable_only'] = $values['shippable_only'];
    $entity_ids = array_column($entities, 'target_id');
    $this->configuration['entities'] = $this->entityUuidMapper->mapFromIds($this->getPurchasableEntityType(), $entity_ids);
  }

  /**
   * {@inheritdoc}
   */
  public static function ajaxRefreshForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#parents'];
    array_shift($parents);
    $parents = array_merge(['conditions', 'widget'], $parents);

    $autocomplete = NestedArray::getValue($form, array_merge($parents, ['#value']));
    NestedArray::setValue($form, array_merge($parents, ['#value']), '');

    array_pop($parents);
    $value = NestedArray::getValue($form, array_merge($parents, ['entities', '#value']));
    $value .= PHP_EOL . $autocomplete;
    NestedArray::setValue($form, array_merge($parents, ['entities', '#value']), $value);

    $element = NestedArray::getValue($form, $parents);
    $element['#open'] = TRUE;
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $element['#attributes']['data-drupal-selector'] . '"]', $element));
    $response->addCommand(new PrependCommand('[data-drupal-selector="' . $element['#attributes']['data-drupal-selector'] . '"]', ['#type' => 'status_messages']));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultValue(string $type): string {
    $default_value = '';
    $entity_ids = $this->entityUuidMapper->mapToIds($type, $this->configuration['entities']);
    if (!empty($entity_ids)) {
      $entities = $this->entityTypeManager->getStorage($type)->loadMultiple($entity_ids);
      $default_value = EntityAutocomplete::getEntityLabels($entities);
      $default_value = implode(PHP_EOL, explode(', ', $default_value));
    }

    return $default_value;
  }

  /**
   * {@inheritdoc}
   */
  protected function getShippableBundles(string $shippable): array {
    if (!$this->shippableBundles) {
      $purchasable_types = array_filter($this->entityTypeManager->getDefinitions(), static function (EntityTypeInterface $entity_type) {
        return $entity_type->entityClassImplements(PurchasableEntityInterface::class);
      });

      foreach ($purchasable_types as $type) {
        $bundle_types = $this->entityTypeManager->getStorage($type->getBundleEntityType())->loadMultiple();
        $this->shippableBundles += array_keys(array_filter($bundle_types, function ($type) use ($shippable) {
          return $type->hasTrait($shippable);
        }));
      }
    }

    return $this->shippableBundles;
  }

}
