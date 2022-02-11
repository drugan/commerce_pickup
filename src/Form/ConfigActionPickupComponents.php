<?php

namespace Drupal\commerce_pickup\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\profile\Entity\Profile;

/**
 * Provides a Commerce Pickup fields' components form.
 */
class ConfigActionPickupComponents extends ConfigActionPickupFormBase {

  /**
   * {@inheritdoc}
   */
  protected function buildConfigForm(array $form, FormStateInterface $form_state, array $ids) {
    $profiles = [];

    foreach (array_values(Profile::loadMultiple($ids)) as $id => $profile) {
      $profiles[$id] = $profile;
      if ($id) {
        continue;
      }
      $form['values'][$id] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t("Selected for editing @count pickup profiles", [
          '@count' => count($ids),
        ]),
        '#description' => $this->t("Populate any of the fields' component(s) below to apply it on all of the selected profiles."),
        '#parents' => ['values', $id],
        '#array_parents' => ['values', $id],
      ];
      $profile = $profile::create([
        'type' => $profile->bundle(),
      ]);
      $form_display = EntityFormDisplay::collectRenderDisplay($profile, 'default');

      foreach ($form_display->getComponents() as $name => $component) {
        if ($name == 'pickup_stores' || $name == 'pickup_address' || $name == 'pickup_timezone' || $name == 'pickup_hours') {
          continue;
        }
        $form_display->removeComponent($name);
      }

      $form_display->buildForm($profile, $form['values'][$id], $form_state);
    }

    $form_state->set('profiles', $profiles);

    $form['values'][0]['pickup_stores']['widget']['#required'] = FALSE;

    $address = &$form['values'][0]['pickup_address']['widget'][0]['address'];
    $address['#field_overrides']['organization'] = 'optional';
    $address['#field_overrides']['addressLine1'] = 'hidden';
    $address['#field_overrides']['locality'] = 'hidden';
    $address['#field_overrides']['postalCode'] = 'hidden';
    $address['#field_overrides']['dependentLocality'] = 'hidden';
    $address['#after_build'][] = 'commerce_pickup_address_customize';
    $address['#after_build'][] = [$this, 'afterBuild'];

    if (isset($form['values'][0]['pickup_timezone'])) {
      $timezone = &$form['values'][0]['pickup_timezone']['widget'];
      $timezone[0]['value']['#required'] = FALSE;
      array_unshift($timezone[0]['value']['#options'], [0 => '']);
      $timezone[0]['value']['#default_value'] = 0;
    }

    return $form;
  }

  /**
   * Customize store pickup fields' components.
   */
  public function afterBuild($element, $form_state) {
    $element['country_code']['#attributes']['style'] = 'display:none;';
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBatchArgs(array &$form, FormStateInterface $form_state) {
    return [
      'profiles' => $form_state->get('profiles'),
      'values' => $form_state->getValue('values')[0],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static function execute(array $args, array &$context, int $i) {
    $profile = $args['profiles'][$i];

    $save = FALSE;
    $old_value = $profile->pickup_stores->getValue();
    $new_value = $args['values']['pickup_stores'];
    if ($new_value && $old_value != $new_value) {
      $profile->pickup_stores->setValue($new_value);
      $save = TRUE;
    }

    $timezone = $args['values']['pickup_timezone'][0]['value'];
    if ($timezone && $timezone != $profile->pickup_timezone->value) {
      $profile->pickup_timezone->value = $timezone;
      $save = TRUE;
    }

    $address = $args['values']['pickup_address'][0]['address'];
    $pickup_address = $profile->pickup_address->first()->getValue();
    foreach (['organization', 'given_name', 'family_name'] as $name) {
      if ($address[$name] && $address[$name] != $pickup_address[$name]) {
        $pickup_address[$name] = $address[$name];
        $save = TRUE;
      }
    }
    $profile->pickup_address->first()->setValue($pickup_address);

    $value = $args['values']['pickup_hours']['value'];
    $old_value = $profile->pickup_hours->getValue();
    $new_value = [];
    foreach ($value as $slot) {
      if (is_numeric($slot['starthours']) && is_numeric($slot['endhours'])) {
        $new_value[] = $slot;
      }
      elseif ($slot['comment']) {
        $slot['starthours'] = $slot['endhours'] = '-1';
        $new_value[] = $slot;
      }
    }

    if ($new_value) {
      foreach ($old_value as $slot) {
        $day_exists = FALSE;
        foreach ($new_value as $new_slot) {
          if ($slot['day'] == $new_slot['day']) {
            $day_exists = TRUE;
            continue 2;
          }
        }
        if (!$day_exists) {
          $new_value[] = $slot;
        }
      }
      $profile->pickup_hours->setValue($new_value);
      $save = TRUE;
    }

    if ($save) {
      $profile->save();
      return $profile->id();
    }
  }

}
