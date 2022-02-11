<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\CheckoutPane;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_shipping\Plugin\Commerce\CheckoutPane\ShippingInformation;
use Drupal\commerce_pickup\Plugin\Commerce\ShippingMethod\PickupShippingRateInterface;
use Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupAddress;

/**
 * Provides the pickupable shipping information pane.
 *
 * Collects the pickup shipping profile, then the information for each shipment.
 * Assumes that all shipments share the same shipping profile.
 */
class PickupShippingInformation extends ShippingInformation {

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    if ($input = $form_state->getUserInput()) {
      if (!empty($input['shipping_information']['shipping_profile']['pickup_select_address'])) {
        $profile_id = $input['shipping_information']['shipping_profile']['pickup_select_address'];
        foreach ($this->order->get('shipments')->referencedEntities() as &$shipment) {
          $shipping_profile = $shipment->getShippingProfile();
          $shipping_profile->setData('pickup_select_address', $profile_id);
          break;
        }
      }
    }

    $form = parent::buildPaneForm($pane_form, $form_state, $complete_form);
    if (!isset($form['shipments'])) {
      return $form;
    }

    $store = $this->order->getStore();
    $profile = $this->getShippingProfile();
    $pickup_profile_id = $profile->getData('pickup_profile_id');
    $pickuper_phone = $profile->getData('pickuper_phone');
    $has_pickup = $pickup_config = FALSE;
    $method_storage = $this->entityTypeManager->getStorage('commerce_shipping_method');
    $pickup_manager = \Drupal::service('commerce_pickup.options_manager');

    $shipments = array_filter($form['shipments'], function ($key) {
      return is_int($key);
    }, ARRAY_FILTER_USE_KEY);

    foreach ($shipments as $index => $shipment) {
      $shipment = &$form['shipments'][$index];
      $shipment['#process'][] = [
        $this,
        'processForm',
      ];
      $shipment['title']['#disabled'] = TRUE;

      $widgets = array_filter($shipment['shipping_method']['widget'], function ($key) {
        return is_int($key);
      }, ARRAY_FILTER_USE_KEY);

      foreach ($widgets as $ind => $widget) {
        $widget = &$shipment['shipping_method']['widget'][$ind];
        $parents = array_merge($widget['#field_parents'], ['shipping_method']);
        $widget['#ajax'] = [
          'callback' => [static::class, 'ajaxRefreshForm'],
          'element' => $parents,
        ];
        $widget['#limit_validation_errors'] = [
          $parents,
        ];

        foreach ($widget['#options'] as $option => $label) {
          $shipment_obj = $form['shipments'][$index]['#shipment'];
          $checked = $this->isOptionChecked($form_state, $option, $widget);
          $method = $method_storage
            ->load($widget[$option]['#rate']->getShippingMethodId());
          $plugin = $method->getPlugin();

          if ($plugin instanceof PickupShippingRateInterface) {
            $has_pickup = TRUE;
            if ($checked) {
              $condition = current(array_filter($method->getConditions(), function ($obj) {
                return $obj instanceof PickupAddress;
              }));
              $pickup_config = $plugin->getConfiguration() + $condition->getConfiguration();
              $pickup_config['addresses'] = $pickup_manager
                ->getPickupAddresses($this->order, $store, $condition);
            }
          }
          if ($checked) {
            $widget['#default_value'] = $option;
          }
        }

        if ($pickup_config) {
          $form['#title'] = t('Pickup information');
          $form_state->set('pickuped', TRUE);
          if ($pickup_profile_id === NULL) {
            $profile = $profile->create(['type' => $profile->bundle(), 'uid' => 0]);
          }
          $inline_form = $this->inlineFormManager
            ->createInstance('pickuper_profile', [
              'profile_scope' => 'shipping',
            ], $profile);
          $form['shipping_profile'] = [
            '#parents' => array_merge($form['#parents'], ['shipping_profile']),
            '#inline_form' => $inline_form,
            '#pickup_profile_id' => $pickup_profile_id ?? 0,
            '#pickuper_phone' => $pickuper_phone,
            '#pickup_config' => $pickup_config,
            '#pickup_store' => $store,
          ];
          $form['shipping_profile'] = $inline_form
            ->buildInlineForm($form['shipping_profile'], $form_state);

          if (isset($form['shipping_profile']['no_pickup'])) {
            unset($form['recalculate_shipping']);
          }
          $form_state->set('shipping_profile', $inline_form->getEntity());
        }
        elseif ($pickup_profile_id !== NULL) {
          $available_countries = [];
          foreach ($store->get('shipping_countries') as $country_item) {
            $available_countries[] = $country_item->value;
          }
          $inline_form = $this->inlineFormManager->createInstance('customer_profile', [
            'profile_scope' => 'shipping',
            'available_countries' => $available_countries,
            'address_book_uid' => $this->order->getCustomerId(),
            // Don't copy the profile to address book until the order is placed.
            'copy_on_save' => FALSE,
          ], $profile->create(['type' => $profile->bundle(), 'uid' => 0]));

          $form['shipping_profile'] = [
            '#parents' => array_merge($form['#parents'], ['shipping_profile']),
            '#inline_form' => $inline_form,
          ];
          $form['shipping_profile'] = $inline_form->buildInlineForm($form['shipping_profile'], $form_state);
          $form_state->set('shipping_profile', $inline_form->getEntity());
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    parent::validatePaneForm($pane_form, $form_state, $complete_form);
    if (isset($pane_form['shipping_profile']['no_pickup'])) {
      $this->messenger()->addError($this->t('Please select a valid pickup method.'));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * {@inheritdoc}
   */
  private function isOptionChecked($form_state, $option, $widget) {
    $checked = FALSE;
    $default = $widget['#default_value'] == $option;
    $input = (array) $form_state->getUserInput();
    $trigger = $form_state->getTriggeringElement();
    if ($input) {
      $opt = (array) NestedArray::getValue($input, $widget['#ajax']['element']);
      $opt = reset($opt);
      $checked = $opt == $option;
    }
    elseif ($default) {
      $checked = TRUE;
    }
    if (!$checked && $input && $trigger && $default) {
      // As the last resort.
      $checked = empty($opt);
    }

    return $checked;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    if ($summary = parent::buildPaneSummary()) {
      $shipments = $this->order->get('shipments')->referencedEntities();
      $pickup = TRUE;
      foreach ($shipments as $shipment) {
        $id = $shipment->getShippingMethod()->getPlugin()->getPluginId();
        if ($id != 'commerce_pickup_shipping_rate') {
          $pickup = FALSE;
        }
      }

      if ($pickup) {
        $summary['#title'] = $this->t('Pickup information');
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\Form\FormBuilder::processForm()
   */
  public function processForm($element, FormStateInterface $form_state, $form) {
    $form_state->setExecuted();

    return $element;
  }

}
