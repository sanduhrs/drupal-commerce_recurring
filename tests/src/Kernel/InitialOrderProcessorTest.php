<?php

namespace Drupal\Tests\commerce_recurring\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_recurring\Entity\BillingSchedule;

/**
 * @coversDefaultClass \Drupal\commerce_recurring\InitialOrderProcessor
 * @group commerce_recurring
 */
class InitialOrderProcessorTest extends RecurringKernelTestBase {

  /**
   * @covers ::process
   */
  public function testPostpaidProcess() {
    $order_item = OrderItem::create([
      'type' => 'default',
      'title' => $this->variation->getOrderItemTitle(),
      'purchased_entity' => $this->variation->id(),
      'unit_price' => $this->variation->getPrice(),
    ]);
    $order_item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->user->id(),
      'order_items' => [$order_item],
      'state' => 'draft',
    ]);
    $order->save();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->reloadEntity($order);
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $this->reloadEntity($order_item);

    $this->assertEquals($this->variation->getPrice(), $order_item->getUnitPrice());
    $this->assertTrue($order_item->getAdjustedUnitPrice()->isZero());

    $this->assertEquals($this->variation->getPrice(), $order->getSubtotalPrice());
    $this->assertTrue($order->getTotalPrice()->isZero());
  }

  /**
   * @covers ::process
   */
  public function testPrepaidProcess() {
    $this->billingSchedule->setBillingType(BillingSchedule::BILLING_TYPE_PREPAID);
    $this->billingSchedule->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'title' => $this->variation->getOrderItemTitle(),
      'purchased_entity' => $this->variation->id(),
      'unit_price' => $this->variation->getPrice(),
    ]);
    $order_item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->user->id(),
      'order_items' => [$order_item],
      'state' => 'draft',
    ]);
    $order->save();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->reloadEntity($order);
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
    $order_item = $this->reloadEntity($order_item);

    $this->assertEquals($this->variation->getPrice(), $order_item->getUnitPrice());
    $this->assertNotEquals($order_item->getUnitPrice(), $order_item->getAdjustedUnitPrice());
    $adjustments = $order_item->getAdjustments();
    $adjustment = reset($adjustments);
    $this->assertEquals('subscription', $adjustment->getType());
    $this->assertEquals(t('Proration'), $adjustment->getLabel());
  }

  /**
   * @covers ::process
   */
  public function testFreeTrial() {
    $configuration = $this->billingSchedule->getPluginConfiguration();
    $configuration['trial_interval'] = [
      'number' => $configuration['interval']['number'],
      'unit' => $configuration['interval']['unit'],
    ];
    $this->billingSchedule->setPluginConfiguration($configuration);
    $this->billingSchedule->save();
    foreach ([BillingSchedule::BILLING_TYPE_PREPAID, BillingSchedule::BILLING_TYPE_POSTPAID] as $billing_type) {
      if ($this->billingSchedule->getBillingType() != $billing_type) {
        $this->billingSchedule->setBillingType(BillingSchedule::BILLING_TYPE_PREPAID);
        $this->billingSchedule->save();
      }
      $order_item = OrderItem::create([
        'type' => 'default',
        'title' => $this->variation->getOrderItemTitle(),
        'purchased_entity' => $this->variation->id(),
        'unit_price' => $this->variation->getPrice(),
      ]);
      $order_item->save();
      $order = Order::create([
        'type' => 'default',
        'store_id' => $this->store->id(),
        'uid' => $this->user->id(),
        'order_items' => [$order_item],
        'state' => 'draft',
      ]);
      $order->save();
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $this->reloadEntity($order);
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $order_item */
      $order_item = $this->reloadEntity($order_item);

      $this->assertEquals($this->variation->getPrice(), $order_item->getUnitPrice());
      $this->assertTrue($order_item->getAdjustedUnitPrice()->isZero());

      $this->assertEquals($this->variation->getPrice(), $order->getSubtotalPrice());
      $this->assertTrue($order->getTotalPrice()->isZero());
    }
  }

}
