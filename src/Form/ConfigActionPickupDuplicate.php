<?php

namespace Drupal\commerce_pickup\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\Profile;

/**
 * Provides a Commerce Pickup Duplicate form.
 */
class ConfigActionPickupDuplicate extends ConfigActionPickupFormBase {

  /**
   * {@inheritdoc}
   */
  protected function buildConfigForm(array $form, FormStateInterface $form_state, array $ids) {
    $id = reset($ids);
    $form_state->set('profile', Profile::load($id));
    $form['max'] = [
      '#type' => 'number',
      '#title' => $this->t('The number of the pickup point duplicates to create'),
      '#required' => TRUE,
      '#default_value' => '1',
      '#min' => '1',
      '#step' => '1',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBatchArgs(array &$form, FormStateInterface $form_state) {
    return [
      'profile' => $form_state->get('profile'),
      'max' => $form_state->getValue('max'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static function execute(array $args, array &$context, int $i) {
    $profile = $args['profile']->createDuplicate();
    $profile->save();
    return $profile->id();
  }

}
