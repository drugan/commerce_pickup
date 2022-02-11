<?php

namespace Drupal\commerce_pickup\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\Profile;

/**
 * Provides a Commerce Pickup Default form.
 */
class ConfigActionPickupDefault extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_pickup_config_action_pickup_default';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#weight' => 0,
    ];

    if ($ids = explode('|', $this->getRequest()->query->get('ids'))) {
      $options = $profiles = [];

      foreach (Profile::loadMultiple($ids) as $id => $profile) {
        $profiles[$id] = $profile;
        $options[$id] = $profile->pickup_address->first()->getAddressLine1();
      }
      $form_state->set('pickup_profiles', $profiles);
      $form['default'] = [
        '#type' => 'radios',
        '#title' => $this->t('Select pickup point'),
        '#description' => $this->t("Default one appears at the top of a vendor's pickup points"),
        '#options' => $options,
        '#default_value' => key($options),
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#button_type' => 'primary',
        '#value' => $this->t('Save'),
        '#weight' => -1,
      ];
      // Delete the "Action was applied to N items" message.
      $this->messenger()->deleteByType('status');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#id'] != 'edit-cancel') {
      $profiles = $form_state->get('pickup_profiles');
      $profile = $profiles[$form_state->getValue('default')];

      if (!$profile->isDefault()) {
        $profile->setDefault(TRUE)->save();
      }

      $message = $this->t('The %address pickup point was marked as default.', [
        '%address' => $profile->pickup_address->first()->getAddressLine1(),
      ]);
      $this->logger('commerce_pickup')->notice($message);
      $this->messenger()->addMessage($message);
    }
  }

}
