<?php

namespace Drupal\commerce_pickup;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\commerce_pickup\Plugin\Commerce\ShippingMethod\PickupShippingRateInterface;
use Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupConditionInterface;
use Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupAddress;
use Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupHours;
use CommerceGuys\Addressing\Zone\Zone;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * PickupOptionsmanager service.
 */
class PickupOptionsmanager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The shared tempstore factory.
   *
   * @var \Drupal\Core\TempStore\SharedTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The pickup tempstore.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * The pickup dateTime.
   *
   * @var Drupal\Core\Datetime\DrupalDateTime
   */
  protected $date;

  /**
   * The pickup day.
   *
   * @var int
   */
  protected $day;

  /**
   * The pickup time.
   *
   * @var int
   */
  protected $time;

  /**
   * The pickup timezone.
   *
   * @var int
   */
  protected $timezone;

  /**
   * Constructs a PickupOptionsmanager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   *   The shared tempstore factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, SharedTempStoreFactory $temp_store_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tempStoreFactory = $temp_store_factory;
    $this->tempStore = $this->tempStoreFactory->get('store_pickup_addresses');
    $this->date = new DrupalDateTime();
    $this->day = $this->date->format('w');
    $this->time = $this->date->format('Hi');
    $timezone = $this->date->format('e');
    $this->timezone = $timezone ?: 'GMT';
  }

  /**
   * {@inheritdoc}
   */
  public function getPickupAddresses(OrderInterface $order, StoreInterface $store, PickupAddress $condition) {
    if (!$pickup_addresses = $this->getOrderPickupAddresses($order, $condition)) {
      if ($pickup_addresses = $this->getPickupAddressAddresses([$store->id()], $condition)) {
        $this->setOrderPickupAddresses($order, $condition, $pickup_addresses);
      }
    }

    return $pickup_addresses;
  }

  /**
   * {@inheritdoc}
   */
  public function getPickupHoursAddresses(OrderInterface $order, PickupHours $condition) {
    // @TODO Add support for the pickup point timezone.
    // @see https://stackoverflow.com/a/34329033/3944647
    // @see https://www.drupal.org/node/1962888
    // @see https://drupal.org/node/1925272
    // Get user device timezone:
    // @see https://git.drupalcode.org/project/clock/-/blob/7.x-1.x/clock.js
    // @see http://www.onlineaspect.com/2007/06/08/auto-detect-a-time-zone-with-javascript/
    // @see https://stackoverflow.com/a/2705087/3944647
    $addresses = $original = $this->getOriginalOrderPickupAddresses($order, $condition);
    $config = $condition->getConfiguration();
    $current_day = $config['pickup']['current'] == 'day';
    $day_plus = $config['pickup']['current'] == 'day_plus';
    $days = $config['pickup']['days'];
    $timezones = [];

    foreach ($addresses as $profile_id => $data) {
      $open = FALSE;
      foreach ($data['hours'] as $slot) {
        $start = $slot['starthours'];
        $end = $slot['endhours'];

        $day = $this->day;
        $time = $this->time;
        $next_day = !$current_day && !($day == $slot['day']);
        if ($next_day && ($slot['day']) <= $days) {
          $open = TRUE;
        }
        elseif ($current_day || $day_plus) {
          if ($this->timezone != $data['timezone']) {
            if (!isset($timezones[$data['timezone']])) {
              $remote = new DrupalDateTime('now', $data['timezone']);
              $timezones[$data['timezone']]['day'] = $remote->format('w');
              $timezones[$data['timezone']]['time'] = $remote->format('Hi');
            }
            $day = $timezones[$data['timezone']]['day'];
            $time = $timezones[$data['timezone']]['time'];
          }
          if ($day == $slot['day']) {
            if (($time >= $start && ($time <= $end || !$end)) || (!$start && !$end)) {
              $open = TRUE;
            }
          }
        }
      }

      if (!$open) {
        unset($addresses[$profile_id]);
      }
    }

    if ($addresses != $original) {
      $this->setOrderPickupAddresses($order, $condition, $addresses);
    }

    return $addresses;
  }

  /**
   * {@inheritdoc}
   */
  public function getOriginalOrderPickupAddresses(OrderInterface $order, PickupConditionInterface $condition) {
    $condition_addresses = [];
    $hash = $condition->getConfiguration()['hash'];
    $addresses = (array) $this->tempStore->get('pickup_order-' . $order->id());
    if (isset($addresses["{$hash}-original"])) {
      return $addresses["{$hash}-original"];
    }
    if (isset($addresses[$hash])) {
      $condition_addresses = $addresses["{$hash}-original"] = $addresses[$hash];
      $this->tempStore->set('pickup_order-' . $order->id(), $addresses);
    }

    return $condition_addresses;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrderPickupAddresses(OrderInterface $order, PickupConditionInterface $condition) {
    $condition_addresses = [];
    $hash = $condition->getConfiguration()['hash'];
    $addresses = (array) $this->tempStore->get('pickup_order-' . $order->id());
    if (isset($addresses[$hash])) {
      $condition_addresses = $addresses[$hash];
    }

    return $condition_addresses;
  }

  /**
   * {@inheritdoc}
   */
  public function setOrderPickupAddresses(OrderInterface $order, PickupConditionInterface $condition, array $pickup_addresses) {
    $hash = $condition->getConfiguration()['hash'];
    $addresses = (array) $this->tempStore->get('pickup_order-' . $order->id());
    $addresses[$hash] = $pickup_addresses;
    $this->tempStore->set('pickup_order-' . $order->id(), $addresses);

    return $addresses;
  }

  /**
   * {@inheritdoc}
   */
  public function getPickupAddressAddresses(array $store_ids, PickupAddress $condition) {
    $profile_ids = $vendor_ids = $profiles = $data = $default = [];
    $config = $condition->getConfiguration();
    $pickup = $config['pickup'];
    $storage = $this->entityTypeManager->getStorage('user');

    foreach ($pickup as $key => $value) {
      if (preg_match('/^uid_(\d+)$/', $key, $matches)) {
        $vendor_id = $matches[1];
        if ($vendor = $storage->load($vendor_id)) {
          if (!$vendor->hasRole('pickup_vendor') || $vendor->isBlocked()) {
            $vendor = NULL;
          }
        }
        if (!$vendor || (!$pickup[$key]['all'] && !$pickup[$key]['not_all'])) {
          continue;
        }
        if (!$pickup[$key]['all'] && $pickup[$key]['not_all']) {
          $profile_ids += $pickup[$key]['not_all'];
        }
        elseif ($pickup[$key]['all']) {
          $vendor_ids[] = $vendor_id;
        }
      }
    }

    if (!$profile_ids && !$vendor_ids) {
      return $profiles;
    }

    $storage = $this->entityTypeManager->getStorage('profile');

    if ($profile_ids) {
      $profiles += $storage->loadByProperties([
        'profile_id' => $profile_ids,
        'status' => 1,
        'pickup_stores' => $store_ids,
      ]);
    }
    if ($vendor_ids) {
      $profiles += $storage->loadByProperties([
        'type' => 'pickup',
        'status' => 1,
        'pickup_stores' => $store_ids,
        'uid' => $vendor_ids,
      ]);
    }

    if ($zone = !empty($config['pickup']['zone']['territories'])) {
      $zone = new Zone([
        'id' => 'shipping',
        'label' => 'N/A',
      ] + $config['pickup']['zone']);
    }

    foreach ($profiles as $profile_id => $profile) {
      $address = $profile->pickup_address->first();
      if ($zone) {
        if ($config['pickup']['negate']) {
          if ($zone->match($address)) {
            $address = NULL;
          }
        }
        elseif (!$zone->match($address)) {
          $address = NULL;
        }
      }
      if ($address && $address = $address->getValue()) {
        $hours = $profile->pickup_hours->getValue();
        $timezone = $profile->pickup_timezone;
        $timezone = $timezone ? $timezone->value : $this->timezone;
        if ($profile->isDefault()) {
          $default[$profile_id]['address'] = $address;
          $default[$profile_id]['hours'] = $hours;
          $default[$profile_id]['timezone'] = $timezone;
        }
        else {
          $data[$profile_id]['address'] = $address;
          $data[$profile_id]['hours'] = $hours;
          $data[$profile_id]['timezone'] = $timezone;
        }
      }
    }

    return $default + $data;
  }

  /**
   * Flush tempstorage for pickup addresses.
   */
  public function flushPickupAddresses(array $profiles) {
    if (!$profiles) {
      return;
    }
    $stores = [];
    foreach ($profiles as $profile) {
      foreach ($profile->pickup_stores as $store) {
        $stores[$store->target_id] = $store->target_id;
      }
      if ($profile->original) {
        foreach ($profile->original->pickup_stores as $store) {
          $stores[$store->target_id] = $store->target_id;
        }
      }
    }

    $vendor_id = $profile->getOwnerId();
    $method_storage = $this->entityTypeManager->getStorage('commerce_shipping_method');
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $methods = $method_storage->loadMultiple();
    $week_ago = time() - 604800;
    static $ids;

    foreach ($methods as $method_id => $method) {
      $method_stores = array_intersect($stores, $method->getStoreIds());
      if ($method_stores && $method->getPlugin() instanceof PickupShippingRateInterface) {
        $condition = current(array_filter($method->getConditions(), function ($obj) {
          return $obj instanceof PickupAddress;
        }));
        $pickup = $condition->getConfiguration()['pickup'];

        foreach ($pickup as $key => $value) {
          if ($key != "uid_{$vendor_id}"
            || (!$pickup[$key]['all'] && !$pickup[$key]['not_all'])) {
            continue;
          }

          foreach ($method_stores as $store_id) {
            if (isset($ids['stores'][$store_id])) {
              continue;
            }
            $ids['stores'][$store_id] = TRUE;
            $order_ids = $order_storage->getQuery()
              ->condition('cart', '1', '=')
              ->condition('store_id', $store_id, '=')
              ->condition('changed', $week_ago, '>')
              ->execute();

            foreach ($order_ids as $order_id) {
              if (!isset($ids['carts'][$order_id])) {
                $ids['carts'][$order_id] = TRUE;
                $this->flushOrderPickupAddresses($order_id);
              }
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function flushOrderPickupAddresses(int $order_id) {
    return $this->tempStore->delete('pickup_order-' . $order_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getUserTimezoneOffset(string $any_timezone) {
    $user_date = $this->date->getPhpDateTime();
    $user_timezone = $user_date->getTimezone();
    $any = new DrupalDateTime('now', $any_timezone);
    $any_date = $any->getPhpDateTime();
    $any_timezone = $any->getTimezone();
    $offset = $user_timezone->getOffset($user_date) - $any_timezone->getOffset($any_date);

    return [
      'date' => $any_date,
      'day' => $any->format('w'),
      'time' => $any->format('Hi'),
      'offset' => $offset / 3600,
    ];
  }

}
