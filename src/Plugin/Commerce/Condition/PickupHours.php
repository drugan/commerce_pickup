<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\commerce_pickup\Plugin\Commerce\ShippingMethod\PickupShippingRateInterface;

/**
 * Provides the pickup hours condition for shipments.
 *
 * @CommerceCondition(
 *   id = "pickup_hours",
 *   label = @Translation("Pickup hours"),
 *   category = @Translation("Customer"),
 *   entity_type = "commerce_shipment",
 *   weight = -10,
 * )
 */
class PickupHours extends ConditionBase implements PickupConditionInterface {

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
        'current' => NULL,
        'days' => NULL,
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

    if ($this->pickupAddresses = $manager->getPickupHoursAddresses($order, $this)) {
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

    $form['pickup'] = [
      '#type' => 'details',
      '#title' => $this->t('Set up'),
      '#open' => !$this->configuration['hash'],
      '#description' => $this->t("Note that current user timezone is defined in the site's <a href=':url'>Regional settings</a> or on a user account page and may differ from a timezone from where user currently seeing the site. Assuming that the settings' timezone matches the geolocation of a user then the <em>%hours</em> will be automatically adjusted using both pickup point and the user timezones' offset in order to display only those <em>%pickups</em> which are currently open in the user's timezone.", [
        ':url' => '/admin/config/regional/settings',
        '%hours' => $this->t('Pickup point hours'),
        '%pickups' => $this->t('Pickup points'),
      ]),
    ];

    $form['pickup']['current'] = [
      '#type' => 'radios',
      '#title' => $this->t("Display to customer pickup points available only at the:"),
      '#options' => [
        'day' => $this->t('Current day'),
        'day_plus' => $this->t('Current day plus days ahead'),
        'next_day_plus' => $this->t('Next day plus days ahead'),
      ],
      '#default_value' => $this->configuration['pickup']['current'] ?? 'day',
      '#attributes' => [
        'style' => 'margin-left:1em;',
      ],
    ];

    $form['pickup']['days'] = [
      '#type' => 'number',
      '#title' => $this->t("The number of days"),
      '#min' => 1,
      '#max' => 6,
      '#default_value' => $this->configuration['pickup']['days'] ?? 1,
      '#attributes' => [
        'style' => 'margin-left:1em;width:4em;',
      ],
      '#states' => [
        'invisible' => [
          ':input[value="day"]' => ['checked' => TRUE],
          ':input[name="conditions[form][customer][pickup_hours][configuration][form][pickup][current]"]' => ['value' => 'day'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $method = $this->getShippingMethod($form_state);
    $values = $form_state->getValues();
    $parents = array_merge($form['#parents'], ['pickup']);
    $pickup = NestedArray::getValue($values, $parents);

    if (!$method->getPlugin() instanceof PickupShippingRateInterface) {
      $parents = $form['#parents'];
      array_pop($parents);
      array_pop($parents);
      NestedArray::setValue($values, array_merge($parents, ['enable']), 0);
      $form_state->setValues($values);

      return;
    }

    $parents = [
      'conditions',
      'form',
      'customer',
      'pickup_address',
      'configuration',
      'hash',
    ];
    $hash = NestedArray::getValue($form_state->getValues(), $parents);
    $this->configuration['hash'] = $hash;
    $this->configuration['pickup'] = $pickup;
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
