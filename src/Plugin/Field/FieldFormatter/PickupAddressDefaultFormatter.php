<?php

namespace Drupal\commerce_pickup\Plugin\Field\FieldFormatter;

use Drupal\address\Plugin\Field\FieldFormatter\AddressDefaultFormatter;
use CommerceGuys\Addressing\Locale;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Element;

/**
 * Plugin implementation of the 'pickup_address_default' formatter.
 *
 * @FieldFormatter(
 *   id = "pickup_address_default",
 *   label = @Translation("Pickup Default"),
 *   field_types = {
 *     "address",
 *   },
 * )
 */
class PickupAddressDefaultFormatter extends AddressDefaultFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    $profile = $items->getEntity();
    $elements[0]['#profile_type'] = $profile->bundle();
    $manager = \Drupal::entityTypeManager();

    if ($id = $profile->getData('pickup_profile_id')) {
      $elements[0]['#profile_type'] = 'pickup';
      if (!$profile = $profile->load($id)) {
        $storage = $manager->getStorage('profile');
        $id = $storage->getQuery()
          ->condition('type', 'pickup', '=')
          ->range(0, 1)
          ->execute();
        if ($id) {
          $profile = $storage->load(reset($id));
          // Set the pickup hours value which was active during checkout.
          $profile->pickup_hours->setValue($profile->getData('pickup_hours'));
        }
      }
      if ($profile) {
        $view = $profile->pickup_hours->view('pickup_checkout');
        $elements[0]['pickup_hours'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['pickup-hours']],
          '#value' => '',
          '#placeholder' => '%pickup_hours',
          'child' => $view,
        ];
      }
    }

    $storage = $manager->getStorage('entity_view_display');
    $gmap = $storage->load("profile.pickup.default")->getComponent('pickup_gmap');
    if ($gmap && $address = $profile->pickup_address) {
      $addr = $address->first()->getvalue();
      $addr = "{$addr['address_line1']}, {$addr['locality']} {$addr['postal_code']} {$addr['country_code']}";
      $profile->pickup_gmap->value = $addr;
    }

    $ext = '/(\.png|\.jpg|\.jpeg|\.svg)$/';
    if (($logo = $elements[0]['family_name']['#value']) && preg_match($ext, $logo)) {
      if (!$url = filter_var($logo, FILTER_VALIDATE_URL)) {
        $host = \Drupal::request()->getSchemeAndHttpHost();
        $url = filter_var("{$host}{$logo}", FILTER_VALIDATE_URL);
      }
      if ($url) {
        $class = $elements[0]['family_name']['#attributes']['class'];
        $class[] = 'organization__logo';
        $elements[0]['family_name'] = [
          '#type' => 'html_tag',
          '#tag' => 'img',
          '#attributes' => [
            'src' => $url,
            'style' => 'height:3em;',
            'class' => $class,
          ],
          '#value' => 1,
          '#placeholder' => $elements[0]['family_name']['#placeholder'],
          '#prefix' => '<br>',
        ];
      }
    }

    $phone = $elements[0]['address_line2']['#value'];
    $pattern = '/^[ ]{0,2}[+]{0,1}[(]{0,1}[0-9]{0,4}[)]{0,1}[-]{0,1}[ ]{0,2}[0-9]{1,11}[ ]{0,2}[.]{0,1}[-]{0,1}[0-9]{1,11}[ ]{0,2}[.]{0,1}[-]{0,1}[0-9]{1,11}[ ]{0,2}[.]{0,1}[-]{0,1}[0-9][ ]{0,2}$/';
    if ($phone && preg_match($pattern, $phone)) {
      $elements[0]['phone'] = [
        '#type' => 'html_tag',
        '#prefix' => $this->t('<div class="field__label">@phone</div>', [
          '@phone' => $this->t('Customer phone'),
        ]),
        '#tag' => 'span',
        '#attributes' => ['class' => ['phone']],
        '#value' => "<div class='phone__number'>{$elements[0]['address_line2']['#value']}</div>",
        '#placeholder' => '%phone',
      ];
    }

    $elements[0]['hrule'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
      '#attributes' => ['class' => ['hrule']],
      '#value' => 1,
      '#placeholder' => '%hrule',
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function postRender($content, array $element) {
    /** @var \CommerceGuys\Addressing\AddressFormat\AddressFormat $address_format */
    $address_format = $element['#address_format'];
    $locale = $element['#locale'];
    // Add the country to the bottom or the top of the format string,
    // depending on whether the format is minor-to-major or major-to-minor.
    if ($is_locale = Locale::matchCandidates($address_format->getLocale(), $locale)) {
      $format_string = '%country' . "\n" . $address_format->getLocalFormat();
    }
    else {
      $format_string = $address_format->getFormat() . "\n" . '%country';
    }

    if (isset($element['#profile_type']) && $element['#profile_type'] == 'pickup') {
      $format_string = str_replace('%organization' . "\n", '', $format_string);
      if ($is_locale) {
        $format_string = str_replace('%country' . "\n", '%country' . "\n" . '%organization' . "\n", $format_string);
      }
      else {
        $format_string = '%organization' . "\n" . $format_string;
      }

      if (isset($element['phone'])) {
        $format_string = str_replace('%addressLine2' . "\n", '', $format_string);
      }
    }

    $replacements = [];
    foreach (Element::getVisibleChildren($element) as $key) {
      $child = $element[$key];
      if (isset($child['#placeholder'])) {
        $replacements[$child['#placeholder']] = $child['#value'] ? $child['#markup'] : '';
      }
    }
    $content = parent::replacePlaceholders($format_string, $replacements);
    $content = nl2br($content, FALSE);

    if (!empty($element['pickup_hours']['child']['#markup'])) {
      $format_string = "%pickup_hours%hrule";
      $replacements = [
        '%hrule' => $element['hrule']['#markup'],
        '%pickup_hours' => $element['pickup_hours']['#markup'],
      ];
      $content .= parent::replacePlaceholders($format_string, $replacements);
    }

    if (isset($element['phone'])) {
      $format_string = "%phone";
      $replacements = [
        '%phone' => $element['phone']['#markup'],
      ];
      $content .= parent::replacePlaceholders($format_string, $replacements);
    }

    return $content;
  }

}
