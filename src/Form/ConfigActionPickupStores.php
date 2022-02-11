<?php

namespace Drupal\commerce_pickup\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\profile\Entity\Profile;

/**
 * Provides a Commerce Pickup Stores form.
 */
class ConfigActionPickupStores extends ConfigActionPickupFormBase {

  /**
   * {@inheritdoc}
   */
  protected function buildConfigForm(array $form, FormStateInterface $form_state, array $ids) {
    $module_handler = \Drupal::moduleHandler();
    $profiles = [];

    foreach (array_values(Profile::loadMultiple($ids)) as $id => $profile) {
      $form['values'][$id] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $profile->pickup_address->first()->getAddressLine1(),
        '#parents' => ['values', $id],
        '#array_parents' => ['values', $id],
      ];
      $form_display = EntityFormDisplay::collectRenderDisplay($profile, 'default');

      foreach ($form_display->getComponents() as $name => $component) {
        if ($name == 'pickup_stores') {
          continue;
        }
        $form_display->removeComponent($name);
      }

      $form_display->buildForm($profile, $form['values'][$id], $form_state);
      $profiles[$id] = $profile;
      $form_id = 'profile_pickup_edit_form';
      $module_handler->alter('form', $form['values'][$id], $form_state, $form_id);
    }

    $form_state->set('profiles', $profiles);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBatchArgs(array &$form, FormStateInterface $form_state) {
    return [
      'profiles' => $form_state->get('profiles'),
      'values' => $form_state->getValue('values'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static function execute(array $args, array &$context, int $i) {
    $value = $args['values'][$i];
    $profile = $args['profiles'][$i];
    $old_value = $profile->pickup_stores->getValue();
    if ($old_value != $value['pickup_stores']) {
      $profile->pickup_stores->setValue($value['pickup_stores']);
      $profile->save();
      return $profile->id();
    }
  }

}
