<?php

/**
 * @file
 * Commerce Pickup - admin pages/forms
 */


/**
 * Commerce pickup settings form.
 */
function commerce_pickup_settings_form($form, &$form_state) {

  // Pickup shipping service display title.
  $form['commerce_pickup_service_display_title'] = array(
    '#type' => 'textfield',
    '#title' => t('Pickup service display title'),
    '#description' => t('The shipping service title displayed in checkout.'),
    '#default_value' => variable_get('commerce_pickup_service_display_title', 'Pickup'),
    '#required' => TRUE,
  );

  // Location list description.
  $form['commerce_pickup_location_list_description'] = array(
    '#type' => 'textfield',
    '#title' => t('Pickup location list description'),
    '#description' => t('A description of the pickup locations displayed with them in checkout.'),
    '#default_value' => variable_get('commerce_pickup_location_list_description', ''),
  );

  return system_settings_form($form);
}
