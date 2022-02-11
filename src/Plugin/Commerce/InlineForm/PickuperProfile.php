<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\InlineForm;

use Drupal\commerce\Plugin\Commerce\InlineForm\EntityInlineFormBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;

/**
 * Overrides the customer profile.
 *
 * @CommerceInlineForm(
 *   id = "pickuper_profile",
 *   label = @Translation("Pickuper profile"),
 * )
 */
class PickuperProfile extends EntityInlineFormBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // Unique. Passed along to field widgets. Examples: 'billing', 'shipping'.
      'profile_scope' => 'shipping',
      // If empty, all countries will be available.
      'available_countries' => [],
      // The uid of the customer whose address book will be used.
      // 'address_book_uid' => 0,.
      // Whether profile should be copied to the address book after saving.
      // Pass FALSE if copying is done at a later point (e.g. order placement).
      'copy_on_save' => FALSE,
      // Whether the customer profile is being managed by an administrator.
      'admin' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function requiredConfiguration() {
    return ['profile_scope'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildInlineForm(array $inline_form, FormStateInterface $form_state) {
    $form = parent::buildInlineForm($inline_form, $form_state);
    if (!$pickup = $this->getPickupOptions($form['#pickup_config'])) {
      $form['no_pickup'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t("Currently there is no pickup points available for this %store store's shipping method. Contact the site administrator.", [
          '%store' => $form['#pickup_store']->label(),
        ]),
        '#attributes' => [
          'class' => [
            'messages', 'messages--error',
          ],
        ],
      ];

      return $form;
    }

    $options = $pickup['options'];
    $default_value = key($pickup['addresses']);
    if (isset($pickup['addresses'][$form['#pickup_profile_id']])) {
      $default_value = $form['#pickup_profile_id'];
    }

    if (count($options) == 1 && ($option = reset($options)) && count($option) == 1) {
      $city = key($options);
      $form['pickup_rendered'] = [
        '#type' => 'markup',
        '#weight' => -999,
        '#markup' => $this->t('<strong>@title</strong> @point', [
          '@title' => $this->t('Pickup point:'),
          '@point' => "{$option[$default_value]} {$city}",
        ]),
      ];
      $form['pickup_select_address'] = [
        '#type' => 'value',
        '#value' => $default_value,
      ];
    }
    else {
      $form['pickup_select_address'] = [
        '#type' => 'select',
        '#title' => $this->t('Select a pickup point'),
        '#options' => $options,
        '#default_value' => $default_value,
        '#weight' => -999,
        '#ajax' => [
          'callback' => [$this, 'ajaxRefreshPickupHours'],
          'element' => $form['#parents'],
        ],
      ];
    }

    $form['pickup_hours'] = $this->getPickupCheckoutViewMode($default_value);

    if ($form['#pickup_config']['rate_phone']) {
      $form['pickuper_phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone'),
        '#required' => $form['#pickup_config']['rate_phone_required'],
        '#description' => $this->t('Enter you phone number so we can inform you when your purchase is ready to pickup.'),
        '#default_value' => $form['#pickuper_phone'],
        '#attributes' => [
          // +(####)  # .-# Three groups form 1 to 11 numbers which might be
          // separated by a space, dot or dash (looks like a phone number).
          'pattern' => '^[ ]{0,2}[+]{0,1}[(]{0,1}[0-9]{0,4}[)]{0,1}[-]{0,1}[ ]{0,2}[0-9]{1,11}[ ]{0,2}[.]{0,1}[-]{0,1}[0-9]{1,11}[ ]{0,2}[.]{0,1}[-]{0,1}[0-9]{1,11}[ ]{0,2}[.]{0,1}[-]{0,1}[0-9][ ]{0,2}$',
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateInlineForm(array &$form, FormStateInterface $form_state) {
    parent::validateInlineForm($form, $form_state);
    $values = $form_state->getValues();
    $parents = array_merge($form['#parents'], ['pickup_select_address']);
    $profile_id = NestedArray::getValue($values, $parents);
    $storage = \Drupal::entityTypeManager()->getStorage('profile');
    $profile = $storage->loadByProperties([
      'profile_id' => $profile_id,
      'status' => 1,
      'pickup_stores' => $form['#pickup_store']->id(),
    ]);
    if ($profile && $owner = $profile[$profile_id]->getOwner()) {
      if ($owner->isBlocked() || !$owner->hasRole('pickup_vendor')) {
        $profile = NULL;
      }
    }
    if (!$profile) {
      $this->messenger()->addError($this->t('The pickup point is not available anymore.'));
      $form_state->setRebuild(TRUE);
    }
    else {
      $form_state->set('pickup_selected_profile', reset($profile));
    }

    $parents = array_merge($form['#parents'], ['pickuper_phone']);
    $phone = str_replace('  ', ' ', trim(NestedArray::getValue($values, $parents)));
    $form_state->set('pickup_phone_number', $phone);
    $validate = $form['#pickup_config']['rate_phone_validate'];

    if (!$validate || !$phone || !class_exists(PhoneNumberUtil::class)) {
      return;
    }

    $util = PhoneNumberUtil::getInstance();
    try {
      // The 'US' just a default country though all countries will be checked.
      $phone_number_obj = $util->parse($phone, 'US');
      if (!$util->isValidNumber($phone_number_obj)) {
        $form_state->setError($form['pickuper_phone'], 'Seems that is not a valid phone number. Check the format of the number.');
      }
    }
    catch (NumberParseException $e) {
      $form_state->setError($form['pickuper_phone'], 'This does not seem like a phone number.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitInlineForm(array &$form, FormStateInterface $form_state) {
    parent::submitInlineForm($form, $form_state);
    $profile = $form_state->get('pickup_selected_profile');
    $address = $profile->pickup_address->first()->getValue();
    $phone = $form_state->get('pickup_phone_number');
    $address['address_line2'] = $phone;
    $pickup_hours = $profile->pickup_hours->getValue();
    $this->entity
      ->setData('pickup_profile_id', $profile->id())
      ->setData('pickup_hours', $pickup_hours)
      ->setData('pickuper_phone', $phone)
      ->set('address', $address)
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function getPickupOptions(array $config) {
    $options = [];

    foreach ($config['addresses'] as $profile_id => $data) {
      $addr = $data['address'];
      $options['addresses'][$profile_id] = $addr;
      $city = $addr['locality'];
      $area = empty($addr['administrative_area']) ? '' : ", {$addr['administrative_area']}";
      $vendor = $config['pickup']['vendor'] ? " ({$addr['organization']})" : '';
      $optgroup = "{$city}{$area} {$addr['country_code']}";
      $option = "{$addr['address_line1']}{$vendor}";
      $options['options'][$optgroup][$profile_id] = $option;
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function ajaxRefreshPickupHours(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $element = &NestedArray::getValue($form, $triggering_element['#ajax']['element']);
    $element['pickup_hours'] = $this->getPickupCheckoutViewMode($triggering_element['#value']);

    $parent_class = parent::class;
    return $parent_class::ajaxRefreshForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getPickupCheckoutViewMode(int $profile_id) {
    $manager = \Drupal::entityTypeManager();
    $profile = $manager->getStorage('profile')->load($profile_id);
    $storage = $manager->getStorage('entity_view_display');
    $components = $storage->load("profile.pickup.pickup_checkout")->getComponents();
    if (isset($components['pickup_gmap']) && $address = $profile->pickup_address) {
      $addr = $address->first()->getvalue();
      $addr = "{$addr['address_line1']}, {$addr['locality']} {$addr['postal_code']} {$addr['country_code']}";
      $profile->pickup_gmap->value = $addr;
    }

    $pickup_hours = $manager->getViewBuilder('profile')
      ->view($profile, 'pickup_checkout');
    $pickup_hours['#weight'] = -998;
    if (!empty($components)) {
      $pickup_hours['#prefix'] = $pickup_hours['#suffix'] = '<hr class="pickup-checkout-hrule">';
    }

    return $pickup_hours;
  }

}
