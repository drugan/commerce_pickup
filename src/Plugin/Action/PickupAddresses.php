<?php

namespace Drupal\commerce_pickup\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Bulk edit pickup addresses.
 *
 * @Action(
 *   id = "commerce_pickup_addresses",
 *   label = @Translation("Edit pickup addresses"),
 *   type = "profile"
 * )
 */
class PickupAddresses extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $profiles) {
    if ($profiles) {
      $ids = [];
      foreach ($profiles as $profile) {
        $ids[] = $profile->id();
      }
      $url = $profile->toUrl();
      $query = [
        'destination' => \Drupal::request()->getRequestUri(),
        'ids' => implode('|', $ids),
      ];
      $path = $url::fromRoute('commerce_pickup.config_action_pickup_addresses', [], ['query' => $query])->toString();
      $response = new RedirectResponse($path);
      $response->send();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute($profile = NULL) {
    // Do nothing.
  }

  /**
   * {@inheritdoc}
   */
  public function access($profile, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $profile->access('update', $account, TRUE);

    return $return_as_object ? $result : $result->isAllowed();
  }

}
