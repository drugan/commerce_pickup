<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\ShippingMethod;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\FlatRate;
use libphonenumber\PhoneNumberUtil;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\ShippingService;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_price\Price;
use Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupAddress;

/**
 * Provides the Pickup shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "commerce_pickup_shipping_rate",
 *   label = @Translation("Pickup shipping rate"),
 * )
 */
class PickupShippingRate extends FlatRate implements PickupShippingRateInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'rate_phone' => '0',
      'rate_phone_required' => '0',
      'rate_phone_validate' => '0',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $profiles = $vendors = $options = [];
    $form = parent::buildConfigurationForm($form, $form_state);
    $value = $form_state->getValue(['stores', 'target_id', 'value']);

    $form['rate_phone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collect customer phone number on the %pickup checkout pane.', [
        '%pickup' => $this->t('Pickup Information'),
      ]),
      '#weight' => -1,
      '#default_value' => $this->configuration['rate_phone'],
    ];
    $form['rate_phone_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required field.'),
      '#weight' => -1,
      '#default_value' => $this->configuration['rate_phone_required'],
      '#states' => [
        'visible' => [
          ':input[name^="plugin[0][target_plugin_configuration][commerce_pickup_shipping_rate][rate_phone]"]' => ['checked' => TRUE],
        ],
      ],
      '#attributes' => [
        'style' => 'margin-left:1em;',
      ],
    ];
    $util = class_exists(PhoneNumberUtil::class);
    $form['rate_phone_validate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Validate phone.'),
      '#description' => $this->t('To ensure validity of a phone number the <a href=":url">libphonenumber for PHP</a> library needs to be installed. Otherwise, only the basic validation will be performed.', [
        ':url' => 'https://github.com/giggsey/libphonenumber-for-php',
      ]),
      '#weight' => -1,
      '#default_value' => $util ? $this->configuration['rate_phone_validate'] : 0,
      '#disabled' => !$util,
      '#states' => [
        'visible' => [
          ':input[name^="plugin[0][target_plugin_configuration][commerce_pickup_shipping_rate][rate_phone]"]' => ['checked' => TRUE],
        ],
      ],
      '#attributes' => [
        'style' => 'margin-left:1em;',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $parents = ['conditions', 'form', 'customer', 'pickup_address', 'enable'];
    if (!$form_state->getValue($parents, FALSE)) {
      $this->messenger()->addError($this->t('The Customer -> Pickup address condition is required.'));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $phone = $values['rate_phone'];
      $this->configuration['rate_phone'] = $phone;
      $this->configuration['rate_phone_required'] = $phone ? $values['rate_phone_required'] : $phone;
      $this->configuration['rate_phone_validate'] = $phone ? $values['rate_phone_validate'] : $phone;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $condition = current(array_filter($this->parentEntity->getConditions(), function ($obj) {
      return $obj instanceof PickupAddress;
    }));
    $pickup_manager = \Drupal::service('commerce_pickup.options_manager');
    $order = $shipment->getOrder();
    $addresses = $pickup_manager->getPickupAddresses($order, $order->getStore(), $condition);

    $profile = $shipment->getShippingProfile();
    if ($profile && (($profile_id = $profile->getData('pickup_profile_id'))
      || ($profile_id = $profile->getData('pickup_select_address')))) {
      $address = [$profile_id => $addresses[$profile_id]['address']];
    }
    else {
      $profile_id = key($addresses);
      $address = [$profile_id => $addresses[$profile_id]['address']];
    }

    if (empty($shipment->getPackageType())) {
      $shipment->setPackageType($this->getDefaultPackageType());
    }

    $rates = [];
    foreach ($this->getServices() as $service) {
      $rates[] = new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => $service,
        'amount' => $this->getVendorRateAmount($shipment, $service, $address),
      ]);
    }

    return $rates;
  }

  /**
   * {@inheritdoc}
   */
  private function getVendorRateAmount(ShipmentInterface $shipment, ShippingService $service, array $address) {
    // Override this method to request a vendor rate instead of the flat rate.
    $rate = $this->configuration['rate_amount'];

    return Price::fromArray($rate);
  }

}
