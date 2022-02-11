<?php

namespace Drupal\commerce_pickup\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Commerce Pickup Base form.
 */
class ConfigActionPickupFormBase extends FormBase {

  /**
   * The first profile entity.
   *
   * @var \Drupal\profile\Entity\ProfileInterface
   */
  protected $profile;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_pickup_config_action_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Delete the "Action was applied to N items" message.
    $this->messenger()->deleteByType('status');
    $ids = explode('|', $this->getRequest()->query->get('ids'));
    if (!$ids) {
      return $form;
    }
    $form_state->set('ids', $ids);
    $form_state->set('static_class', static::class);
    $form['size'] = [
      '#type' => 'number',
      '#title' => $this->t('The number of entities to apply in one run (batch size).'),
      '#required' => TRUE,
      '#default_value' => '10',
      '#min' => '1',
      '#step' => '1',
      '#weight' => 99,
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Save'),
      '#weight' => -1,
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#weight' => 0,
    ];

    $form = $this->buildConfigForm($form, $form_state, $ids);
    if (($profile = $form_state->get('profile'))
    || ($profile = reset($form_state->get('profiles')))) {
      $this->profile = $profile;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#id'] != 'edit-cancel') {
      $args = $this->getBatchArgs($form, $form_state) + [
        'max' => count($form_state->get('ids')),
        'size' => $form_state->getValue('size'),
        'static_class' => static::class,
      ];

      $batch = [
        'init_message' => $this->t('Prepairing to run...'),
        'progress_message' => $this->t('Processing @remaining of @total. Time to complete: @estimate...'),
        'operations' => [
        [self::class . '::executeMultiple', [$args]],
        ],
        'finished' => self::class . '::finishedBatch',
        'progressive' => TRUE,
      ];
      batch_set($batch);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function executeMultiple($args, &$context) {
    if (empty($context['sandbox'])) {
      $context['results']['static_class'] = $args['static_class'];
      $context['results']['ids'] = '';
      $context['results']['debug'] = [];
      $context['sandbox']['progress'] = 0;
    }

    $max = $context['sandbox']['progress'] + $args['size'];
    $max = $max > $args['max'] ? $args['max'] : $max;

    for ($i = $context['sandbox']['progress']; $i < $max; $i++) {
      if ($profile_id = $args['static_class']::{'execute'}($args, $context, $i)) {
        $profile_id = $context['results']['ids'] ? ", {$profile_id}" : $profile_id;
        $context['results']['ids'] .= $profile_id;
      }
      $context['sandbox']['progress']++;
      unset($args['profiles'][$i], $args['values'][$i]);
    }

    if ($context['sandbox']['progress'] != $args['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $args['max'];
      $context['message'] = t('Finished @progress of @max ....', [
        '@progress' => $context['sandbox']['progress'],
        '@max' => $args['max'],
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function finishedBatch($success, $results, $operations) {
    if ($success) {
      $class = new \ReflectionClass($results['static_class']);
      $message = t('The %action action was applied to the following pickup profiles: @ids.', [
        '%action' => $class->getShortName(),
        '@ids' => $results['ids'],
      ]);
      $logger = \Drupal::logger('commerce_pickup');
      $logger->notice($message);
      if (!empty($results['debug'])) {
        $logger->debug(t('Debug info: @info', [
          '@info' => implode(', ', $results['debug']),
        ]));
      }
    }
    else {
      $message = t('Finished with an error.');
    }

    \Drupal::messenger()->addMessage($message);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity() {
    return $this->profile;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildConfigForm(array $form, FormStateInterface $form_state, array $ids) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBatchArgs(array &$form, FormStateInterface $form_state) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected static function execute(array $args, array &$context, int $i) {
    // Do it!
  }

}
