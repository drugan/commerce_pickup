<?php

namespace Drupal\commerce_pickup;

use Drupal\commerce_shipping\ProfileFieldCopy;
use Drupal\Core\Form\FormStateInterface;

/**
 * Removes the billing is the same as shipping if the plugin method is pickuped.
 */
class PickupProfileFieldCopy extends ProfileFieldCopy {

  /**
   * {@inheritdoc}
   */
  public function supportsForm(array &$inline_form, FormStateInterface $form_state) {
    if ($form_state->get('pickuped')) {
      $form_state->set('pickuped', FALSE);

      return $form_state->get('pickuped');
    }

    return parent::supportsForm($inline_form, $form_state);
  }

}
