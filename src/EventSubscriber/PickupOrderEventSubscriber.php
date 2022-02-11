<?php

namespace Drupal\commerce_pickup\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\commerce_pickup\PickupOptionsmanager;

/**
 * Commerce Pickup event subscriber.
 */
class PickupOrderEventSubscriber implements EventSubscriberInterface {

  /**
   * TThe pickup options manager.
   *
   * @var \Drupal\commerce_pickup\PickupOptionsmanager
   */
  protected $pickupManager;

  /**
   * Constructs a PickupOptionsmanager object.
   *
   * @param \Drupal\commerce_pickup\PickupOptionsmanager $pickup_manager
   *   The pickup options manager.
   */
  public function __construct(PickupOptionsmanager $pickup_manager) {
    $this->pickupManager = $pickup_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlace'],
      OrderEvents::ORDER_PREDELETE => ['onOrderPreDelete'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    $order = $event->getEntity();
    $this->pickupManager->flushOrderPickupAddresses($order->id());
  }

  /**
   * {@inheritdoc}
   */
  public function onOrderPreDelete(OrderEvent $event) {
    $order = $event->getOrder();
    $this->pickupManager->flushOrderPickupAddresses($order->id());
  }

}
