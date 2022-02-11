<?php

namespace Drupal\commerce_pickup\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\Profile;

/**
 * Provides a Commerce Pickup API form.
 */
class ConfigActionPickupApi extends ConfigActionPickupFormBase {

  /**
   * The omniva week days map.
   *
   * @var array
   */
  protected static $weekdays = [
    'P' => 0,
    'E' => 1,
    'T' => 2,
    'K' => 3,
    'N' => 4,
    'R' => 5,
    'L' => 6,
  ];

  /**
   * {@inheritdoc}
   */
  protected function buildConfigForm(array $form, FormStateInterface $form_state, array $ids) {
    $id = reset($ids);
    $form_state->set('profile', Profile::load($id));
    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The external url to fetch the data for pickup points.'),
      '#required' => TRUE,
      '#default_value' => '/dev/itella-posti-EE.json',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBatchArgs(array &$form, FormStateInterface $form_state) {
    $client = \Drupal::httpClient();
    $serializer = \Drupal::service('serializer');
    $url = $form_state->getValue('url');
    preg_match('/^\/dev\/(\w+)\-(\w+)(.*)\.(\w+)$/', $url, $matches);
    $vendor = $matches[1];
    $ext = array_pop($matches);
    $path = \Drupal::moduleHandler()->getModuleDirectories()['commerce_pickup'] . $url;

    if ($vendor == 'itella') {
      // $response = $client->request('get', 'https://locationservice.posti.com/api/2/location?countryCode=FI&types=POSTOFFICE,PICKUPPOINT,SMARTPOST,LOCKER');
      // $data = $response->getBody()->__toString();
      // file_put_contents($path, $data);
      $data = $serializer->decode(file_get_contents($path), $ext);
      $data = isset($data['locations']) ? $data['locations'] : [];
    }
    elseif ($vendor == 'etella' || $vendor == 'omniva') {
      // $response = $client->request('get', 'https://www.omniva.ee/locations.xml');
      // $data = $response->getBody()->__toString();
      // file_put_contents($path, $data);
      $data = $serializer->decode(file_get_contents($path), $ext);
      if (isset($data['item'])) {
        $data = $data['item'];
      }
      elseif ($data['LOCATION']) {
        $data = $data['LOCATION'];
      }
      else {
        $data = [];
      }
    }

    return [
      'profile' => $form_state->get('profile'),
      'data' => $data,
      'max' => count($data),
      'vendor' => $vendor,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static function execute(array $args, array &$context, int $i) {
    return self::{$args['vendor']}($args, $context, $i);
  }

  /**
   * {@inheritdoc}
   */
  protected static function getHours(string $str) {
    if (!$str) {
      return self::getUnknownHours();
    }
    elseif (str_contains($str, '24t') || str_contains($str, '24h')) {
      return self::getUnknownHours($str);
    }

    $hours = [];
    $str = str_replace(['.', ',', ':', ';'], '', $str);
    $str = str_replace([' -', '- '], '-', $str);
    $parts = array_reverse(explode(' ', $str));
    $days = [];
    foreach ($parts as $index => $part) {
      if (preg_match('/^([0-9]{1,4})(-+)([0-9]{1,4})/', $part, $matches)) {
        if (isset($parts[$index + 1]) && ($wdays = $parts[$index + 1])) {
          $range = strlen($wdays) == 3 && $wdays[1] == '-';
          if ($range && isset(self::$weekdays[$wdays[0]]) && isset(self::$weekdays[$wdays[2]])) {
            $start = self::$weekdays[$wdays[0]];
            $end = self::$weekdays[$wdays[2]] ?: 7;
            $flipped = array_flip(self::$weekdays);
            $range = '';
            foreach (range($start, $end) as $day) {
              $day = $day == 7 ? 0 : $day;
              if (isset($flipped[$day])) {
                $range .= $flipped[$day];
              }
            }
            $wdays = $range ?: $wdays;
          }

          foreach (str_split($wdays) as $day) {
            if (isset(self::$weekdays[$day]) && !isset($days[self::$weekdays[$day]])) {
              $days[self::$weekdays[$day]] = TRUE;
              $hours[] = [
                'day' => self::$weekdays[$day],
                'starthours' => $matches[1] + 0,
                'endhours' => $matches[3] + 0,
                'comment' => '',
              ];
            }
          }
        }
      }
    }

    return $hours ?: self::getUnknownHours();
  }

  /**
   * {@inheritdoc}
   */
  protected static function getUnknownHours($comment = '') {
    $hours = [];
    foreach (range(0, 6) as $day) {
      $hours[] = [
        'day' => $day,
        'starthours' => $comment ? 0 : -1,
        'endhours' => $comment ? 0 : -1,
        'comment' => $comment ?: t('unknown service hours'),
      ];
    }

    return $hours;
  }

  /**
   * {@inheritdoc}
   */
  protected static function omniva(array $args, array &$context, int $i) {
    $pickup = $args['data'][$i];
    unset($args['data'][$i]);

    $profile = $args['profile']->createDuplicate();
    $profile->pickup_hours->setValue(self::getHours($pickup['SERVICE_HOURS']));
    $profile->pickup_gmap->value = "{$pickup['X_COORDINATE']}, {$pickup['X_COORDINATE']}";

    $address = $profile->pickup_address->first()->getValue();
    $address['additional_name'] = $pickup['ZIP'];
    $comment = $pickup['COMMENT_ENG'] ? ", {$pickup['COMMENT_ENG']}" : '';
    $add_comment = $pickup['TEMP_SERVICE_HOURS'] ? " ({$pickup['TEMP_SERVICE_HOURS']})" : '';
    $address['given_name'] = $pickup['NAME'] . $comment . $add_comment;
    $address['address_line1'] = "{$pickup['A5_NAME']} {$pickup['A7_NAME']}";
    $address['locality'] = $pickup['A2_NAME'];
    $address['postal_code'] = $pickup['ZIP'];
    $address['country_code'] = $pickup['A0_NAME'];
    $address['administrative_area'] = $pickup['A1_NAME'];
    $profile->pickup_address->first()->setValue($address);

    $profile->save();
    return $profile->id();
  }

  /**
   * {@inheritdoc}
   */
  protected static function etella(array $args, array &$context, int $i) {
    $pickup = $args['data'][$i];
    unset($args['data'][$i]);

    $profile = $args['profile']->createDuplicate();
    $profile->pickup_hours->setValue(self::getHours($pickup['availability']));
    $profile->pickup_gmap->value = "{$pickup['lat']}, {$pickup['lng']}";

    $address = $profile->pickup_address->first()->getValue();
    $address['additional_name'] = $pickup['place_id'];
    $address['sorting_code'] = $pickup['routingcode'];
    $address['given_name'] = $pickup['name'] . ', ' . $pickup['description'];
    $address['address_line1'] = $pickup['address'];
    $address['locality'] = $pickup['city'];
    $address['postal_code'] = $pickup['postalcode'];
    $address['country_code'] = $pickup['country'];
    $profile->pickup_address->first()->setValue($address);

    $profile->save();
    return $profile->id();
  }

  /**
   * {@inheritdoc}
   */
  protected static function itella(array $args, array &$context, int $i) {
    $pickup = $args['data'][$i];
    unset($args['data'][$i]);

    $hours = [];
    if (!empty($pickup['openingTimes'])) {
      foreach ($pickup['openingTimes'] as $slot) {
        $hours[] = [
          'day' => $slot['weekday'] == 7 ? 0 : $slot['weekday'],
          'starthours' => str_replace(':', '', $slot['timeFrom']) + 0,
          'endhours' => str_replace(':', '', $slot['timeTo']) + 0,
          'comment' => '',
        ];
      }
    }
    elseif (str_contains($pickup['availability'], '24t') || str_contains($pickup['availability'], '24h')) {
      $hours = self::getUnknownHours($pickup['availability']);
    }
    else {
      $hours = self::getUnknownHours();
    }

    $profile = $args['profile']->createDuplicate();

    $profile->pickup_hours->setValue($hours);
    $profile->pickup_gmap->value = implode(', ', array_values($pickup['location']));
    $address = $profile->pickup_address->first()->getValue();
    $address['additional_name'] = $pickup['id'];
    $address['sorting_code'] = $pickup['routingServiceCode'];
    $address['given_name'] = $pickup['publicName']['en'] . ', ' . $pickup['additionalInfo']['en'];
    $address['address_line1'] = $pickup['address']['en']['address'];
    $address['locality'] = $pickup['address']['en']['postalCodeName'];
    $address['postal_code'] = $pickup['postalCode'];
    $address['country_code'] = $pickup['countryCode'];
    $address['administrative_area'] = $pickup['address']['en']['municipality'];
    $profile->pickup_address->first()->setValue($address);

    $profile->save();
    return $profile->id();
  }

}
