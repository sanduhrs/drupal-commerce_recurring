<?php

namespace Drupal\commerce_recurring\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'commerce_billing_cycle' widget.
 *
 * @FieldWidget(
 *   id = "commerce_billing_cycle_default",
 *   label = @Translation("Billing cycle"),
 *   field_types = {
 *     "commerce_billing_cycle"
 *   },
 *  )
 */
class BillingCycleDefaultWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $start_date = NULL;
    $end_date = NULL;
    if (!$items[$delta]->isEmpty()) {
      /** @var \Drupal\commerce_recurring\BillingCycle $billing_cycle */
      $billing_cycle = $items[$delta]->toBillingCycle();
      $start_date = $billing_cycle->getStartDate();
      $end_date = $billing_cycle->getEndDate();
    }

    $element['starts'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Start date'),
      '#default_value' => $start_date,
      '#date_date_element' => 'date',
      '#date_year_range' => '2016:2038',
      '#date_increment' => 5,
      '#date_timezone' => $start_date ? $start_date->getTimezone()->getName() : NULL,
      '#required' => $element['#required'],
    ];
    $element['ends'] = [
      '#type' => 'datetime',
      '#title' => $this->t('End date'),
      '#default_value' => $end_date,
      '#date_date_element' => 'date',
      '#date_year_range' => '2016:2038',
      '#date_increment' => 5,
      '#date_timezone' => $end_date ? $end_date->getTimezone()->getName() : NULL,
      '#required' => $element['#required'],
    ];

    return $element;
  }

}
