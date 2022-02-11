<?php

namespace Drupal\commerce_pickup\Plugin\Commerce\Condition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\commerce\PurchasableEntityInterface;

/**
 * Provides the shippable purchased entity SKU condition for orders.
 *
 * @CommerceCondition(
 *   id = "pickup_order_purchased_entity_sku",
 *   label = @Translation("Shippable purchased entity SKU"),
 *   display_label = @Translation("Order contains specific shippable purchased item SKU"),
 *   category = @Translation("Purchased items"),
 *   entity_type = "commerce_order",
 *   weight = -10,
 *   deriver = "Drupal\commerce_pickup\Plugin\Commerce\Condition\PickupPurchasedEntitySkuConditionDeriver"
 * )
 */
class PickupOrderPurchasedEntitySku extends PickupPurchasedEntityConditionBase {

  /**
   * {@inheritdoc}
   */
  protected function isValid(PurchasableEntityInterface $purchasable_entity = NULL): bool {
    if ($purchasable_entity !== NULL &&
      $purchasable_entity->getEntityTypeId() === $this->getPurchasableEntityType()) {
      $sku = $purchasable_entity->getSku();
      foreach (explode(PHP_EOL, $this->configuration['skus']) as $pattern) {
        $str = trim($pattern);
        if (str_contains($sku, $str) || preg_match("~{$str}~", $sku)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'skus' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultValue(string $type): string {
    return $this->configuration['skus'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['setup']['#description'] = $this->t('Insert product variation SKU or a part of the SKU, one per each line. You can also use the <a href=":pcre">PCRE regex syntax</a> for SKUs. Note the regex delimiter characters will be added automatically so omit them in regular expressions. Also, when using expression do not forget to escape <a href=":meta">Meta-characters</a> in the SKU string if that contains such characters.', [
      ':pcre' => 'https://www.php.net/manual/en/reference.pcre.pattern.syntax.php',
      ':meta' => 'https://www.php.net/manual/en/regexp.reference.meta.php',
    ]);
    $title = &$form['setup']['search_entities']['#title'];
    $args = $title->getArguments();
    $args['@entity'] = $args['@entity'] . ' SKU';
    $title = new $title($title->getUntranslatedString(), $args);
    $title = &$form['setup']['entities']['#title'];
    $title = new $title($title->getUntranslatedString() . ' SKU');
    $form['setup']['#open'] = !$this->configuration['skus'];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue(array_merge($form['#parents'], ['setup']));

    if (empty($values['entities'])) {
      $state_values = $form_state->getValues();
      $parents = $form['#parents'];
      array_pop($parents);
      array_pop($parents);
      NestedArray::setValue($state_values, array_merge($parents, ['enable']), 0);
      $form_state->setValues($state_values);
      $values['shippable_only'] = NULL;
    }

    $this->configuration['shippable_only'] = $values['shippable_only'];
    $this->configuration['skus'] = $values['entities'];
  }

  /**
   * {@inheritdoc}
   */
  public static function ajaxRefreshForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#parents'];
    array_shift($parents);
    $parents = array_merge(['conditions', 'widget'], $parents);

    $sku = explode(':', NestedArray::getValue($form, array_merge($parents, ['#value'])));
    NestedArray::setValue($form, array_merge($parents, ['#value']), '');

    array_pop($parents);
    $value = NestedArray::getValue($form, array_merge($parents, ['entities', '#value']));
    $value .= empty($sku[0]) ? '' : PHP_EOL . $sku[0];
    NestedArray::setValue($form, array_merge($parents, ['entities', '#value']), $value);

    $element = NestedArray::getValue($form, $parents);
    $element['#open'] = TRUE;
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('[data-drupal-selector="' . $element['#attributes']['data-drupal-selector'] . '"]', $element));
    $response->addCommand(new PrependCommand('[data-drupal-selector="' . $element['#attributes']['data-drupal-selector'] . '"]', ['#type' => 'status_messages']));

    return $response;
  }

}
