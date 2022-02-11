<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\commerce_pickup\Plugin\Commerce\ShippingMethod\PickupShippingRateInterface;

/**
 * Provides the pickup address condition for shipments.
 *
 * @CommerceCondition(
 *   id = "pickup_address",
 *   label = @Translation("Pickup address"),
 *   category = @Translation("Customer"),
 *   entity_type = "commerce_shipment",
 *   weight = -10,
 * )
 */
class PickupAddress extends ConditionBase implements PickupConditionInterface {

  /**
   * The pickup profiles.
   *
   * @var \Drupal\profile\Entity\ProfileInterface[]
   */
  public $pickupAddresses = [];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'hash' => NULL,
      'pickup' => [
        'zone' => NULL,
        'negate' => NULL,
        'vendor' => NULL,
      ],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $shipment) {
    $this->assertEntity($shipment);
    $order = $shipment->getOrder();
    $store = $shipment->getOrder()->getStore();
    $manager = \Drupal::service('commerce_pickup.options_manager');

    if ($this->pickupAddresses = $manager->getPickupAddresses($order, $store, $this)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $method = $this->getShippingMethod($form_state);

    if (!$method->getPlugin() instanceof PickupShippingRateInterface) {
      return [];
    }

    $form = parent::buildConfigurationForm($form, $form_state);
    $value = $form_state->getValue(['stores', 'target_id', 'value']);

    $profiles = \Drupal::entityTypeManager()->getStorage('profile')->loadByProperties([
      'type' => 'pickup',
      'status' => 1,
      'pickup_stores' => array_keys($value),
    ]);

    if (!$profiles) {
      $form['pickup']['message'] = [
        '#type' => 'html_tag',
        '#tag' => 'mark',
        '#value' => $this->t('No active pickup points are found. Assign the <strong><a href=":role">%vendor</a></strong> role to some of the users and then add pickup points for the method store(s) on a <strong><a href=":pickup">/user/*USER_ID*/pickup/list</a></strong> page.', [
          '%vendor' => $this->t('Pickup vendor'),
          ':role' => '/admin/people',
          ':pickup' => '/user/***/pickup/list',
        ]),
      ];

      return $form;
    }

    $vendors = $options = [];
    foreach ($profiles as $profile) {
      $vendor = $profile->getOwner();
      if (!$vendor->hasRole('pickup_vendor') || $vendor->isBlocked()) {
        continue;
      }
      $addr = $profile->pickup_address->first()->getValue();
      $city = $addr['locality'];
      $area = empty($addr['administrative_area']) ? '' : ", {$addr['administrative_area']}";
      $org = $addr['organization'];
      $org = preg_match('/(\.png|\.jpg|\.jpeg|\.svg)$/', $org) ? '☺ ' : "{$org}, ";
      $optgroup = "{$city}{$area} {$addr['country_code']}";
      $option = "{$org}{$addr['address_line1']} {$addr['postal_code']}";
      $options[$vendor->id()][$optgroup][$profile->id()] = $option;
      $vendors[$vendor->id()] = $vendor;
    }

    $form['pickup'] = [
      '#type' => 'details',
      '#title' => $this->t('Set up'),
      '#open' => !$this->configuration['hash'],
      '#description' => $this->t("Note selecting some vendor or their pickup point(s) does not guarantee that is currently available for a store or it will be in the future. You should contact a vendor to explicitly enable each of your stores for using their pickup points."),
      '#weight' => -2,
    ];

    foreach ($vendors as $uid => $vendor) {
      $index = "uid_{$uid}";
      $name = "conditions[form][customer][pickup_address][configuration][form][pickup][{$index}][all]";

      $form['pickup'][$index]['all'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Vendor: <a href=":url" target="_blank">%vendor</a>. Accept all the current and future pickup points of this vendor.', [
          '%vendor' => $vendor->getAccountName(),
          ':url' => $vendor->toUrl()->toString(),
        ]),
        '#default_value' => $this->configuration['pickup'][$index]['all'] ?? '1',
      ];
      if (!isset($this->configuration['pickup'][$index]['all'])) {
        $form['pickup'][$index]['all']['#attributes']['checked'] = TRUE;
      }

      $form['pickup'][$index]['not_all'] = [
        '#type' => 'select',
        '#title' => $this->t("Accept only selected %vendor's pickup points:", [
          '%vendor' => $vendor->getAccountName(),
        ]),
        '#description' => $this->t('To select / unselect multiple options press Ctrl or Shift key.'),
        '#multiple' => TRUE,
        '#options' => $options[$uid],
        '#default_value' => $this->configuration['pickup'][$index]['not_all'] ?? [],
        '#states' => [
          'visible' => [
            ':input[name^="' . $name . '"]' => ['checked' => FALSE],
          ],
        ],
      ];
    }

    $form['pickup']['zone'] = [
      '#type' => 'address_zone',
      '#default_value' => $this->configuration['pickup']['zone'],
    ];
    $form['pickup']['negate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negate'),
      '#description' => $this->t('If checked, the territory %and (!sic) postal code setiings should not match.', [
        '%and' => 'AND',
      ]),
      '#default_value' => $this->configuration['pickup']['negate'],
    ];
    $form['pickup']['vendor'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Vendor"),
      '#description' => $this->t("Whether to display a pickup point's vendor name (%vendor component of the field) in the select list of the %pickup checkout pane.", [
        '%vendor' => $this->t('Company'),
        '%pickup' => $this->t('Pickup information'),
      ]),
      '#default_value' => $this->configuration['pickup']['vendor'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $method = $this->getShippingMethod($form_state);

    if (!$method->getPlugin() instanceof PickupShippingRateInterface) {
      $values = $form_state->getValues();
      $parents = $form['#parents'];
      array_pop($parents);
      array_pop($parents);
      NestedArray::setValue($values, array_merge($parents, ['enable']), 0);
      $form_state->setValues($values);

      return;
    }

    $values = $form_state->getValue($form['#parents']);
    $parents = array_merge(['element_state', '#parents'], $form['#parents'], ['pickup', 'zone']);
    $values['pickup']['zone'] = NestedArray::getValue($form_state->getStorage(), $parents);
    // Work around an Address bug where the Remove button value is kept in the
    // array.
    foreach ($values['pickup']['zone']['territories'] ?? [] as $index => &$territory) {
      unset($territory['remove']);
      if (empty($territory['country_code'])) {
        unset($values['pickup']['zone']['territories'][$index]);
      }
    }
    unset($values['pickup']['zone']['label']);
    $this->configuration['pickup'] = $values['pickup'];
    $values = $form_state->getValues();
    $this->configuration['hash'] = sha1(serialize($values));

    if ($method_id = $method->id()) {
      $manager = \Drupal::service('commerce_pickup.options_manager');
      $order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
      $week_ago = time() - 604800;
      $store_ids = $method->getStoreIds();
      $order_ids = $order_storage->getQuery()
        ->condition('cart', '1', '=')
        ->condition('store_id', $store_ids, 'IN')
        ->condition('changed', $week_ago, '>')
        ->execute();
      foreach ($order_ids as $order_id) {
        $manager->flushOrderPickupAddresses($order_id);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getShippingMethod(FormStateInterface $form_state) {
    $method = $form_state->getFormObject()->getEntity();
    if ($method->isNew()) {
      $method = clone $method;
      $parents = [
        'plugin',
        'widget',
        0,
        'target_plugin_id',
        '#default_value',
      ];
      $target_plugin_id = NestedArray::getValue($form_state->getCompleteForm(), $parents);
      $plugin_value = $method->get('plugin')->first()->getValue();
      $plugin_value['target_plugin_id'] = $target_plugin_id;
      $method->set('plugin', $plugin_value);
    }

    return $method;
  }

}
