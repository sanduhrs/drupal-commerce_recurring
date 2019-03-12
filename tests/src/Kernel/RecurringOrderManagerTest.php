<?php

namespace Drupal\Tests\commerce_recurring\Kernel;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_price\Price;
use Drupal\commerce_recurring\BillingPeriod;
use Drupal\commerce_recurring\Entity\BillingScheduleInterface;
use Drupal\commerce_recurring\Entity\Subscription;
use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * @coversDefaultClass \Drupal\commerce_recurring\RecurringOrderManager
 * @group commerce_recurring
 */
class RecurringOrderManagerTest extends RecurringKernelTestBase {

  /**
   * A trial subscription.
   *
   * @var \Drupal\commerce_recurring\Entity\SubscriptionInterface
   */
  protected $trialSubscription;

  /**
   * An active subscription.
   *
   * @var \Drupal\commerce_recurring\Entity\SubscriptionInterface
   */
  protected $activeSubscription;

  /**
   * The recurring order manager.
   *
   * @var \Drupal\commerce_recurring\RecurringOrderManagerInterface
   */
  protected $recurringOrderManager;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $trial_subscription = Subscription::create([
      'type' => 'product_variation',
      'store_id' => $this->store->id(),
      'billing_schedule' => $this->billingSchedule,
      'uid' => $this->user,
      'payment_method' => $this->paymentMethod,
      'purchased_entity' => $this->variation,
      'title' => $this->variation->getOrderItemTitle(),
      'quantity' => '2',
      'unit_price' => new Price('2', 'USD'),
      'state' => 'trial',
      'trial_starts' => strtotime('2017-02-24 17:30:00'),
    ]);
    $trial_subscription->save();
    $this->trialSubscription = $this->reloadEntity($trial_subscription);

    $active_subscription = Subscription::create([
      'type' => 'product_variation',
      'store_id' => $this->store->id(),
      'billing_schedule' => $this->billingSchedule,
      'uid' => $this->user,
      'payment_method' => $this->paymentMethod,
      'purchased_entity' => $this->variation,
      'title' => $this->variation->getOrderItemTitle(),
      'quantity' => '2',
      'unit_price' => new Price('2', 'USD'),
      'state' => 'active',
      'starts' => strtotime('2017-02-24 17:30:00'),
    ]);
    $active_subscription->save();
    $this->activeSubscription = $this->reloadEntity($active_subscription);

    $this->recurringOrderManager = $this->container->get('commerce_recurring.order_manager');
  }

  /**
   * @covers ::startTrial
   */
  public function testStartTrialWithInvalidState() {
    $this->setExpectedException(\InvalidArgumentException::class, 'Unexpected subscription state "active".');
    $order = $this->recurringOrderManager->startTrial($this->activeSubscription);
  }

  /**
   * @covers ::startTrial
   */
  public function testStartTrialWithInvalidBillingSchedule() {
    $configuration = $this->billingSchedule->getPluginConfiguration();
    unset($configuration['trial_interval']);
    $this->billingSchedule->setPluginConfiguration($configuration);
    $this->billingSchedule->save();

    $this->setExpectedException(\InvalidArgumentException::class, 'The billing schedule "test_id" does not allow trials.');
    $order = $this->recurringOrderManager->startTrial($this->trialSubscription);
  }

  /**
   * @covers ::startTrial
   * @covers ::collectSubscriptions
   */
  public function testStartTrial() {
    $order = $this->recurringOrderManager->startTrial($this->trialSubscription);
    $expected_billing_period = new BillingPeriod($this->trialSubscription->getTrialStartDate(), $this->trialSubscription->getTrialEndDate());
    /** @var \Drupal\commerce_recurring\Plugin\Field\FieldType\BillingPeriodItem $billing_period_item */
    $billing_period_item = $order->get('billing_period')->first();
    $billing_period = $billing_period_item->toBillingPeriod();

    $this->assertEquals($expected_billing_period, $billing_period);
    $this->assertTrue($this->trialSubscription->hasOrder($order));
    $this->assertEmpty($this->trialSubscription->getRenewedTime());
    $this->assertOrder($order, $this->trialSubscription);
    $this->assertTrue($order->hasItems());
    $order_items = $order->getItems();
    $order_item = reset($order_items);
    /** @var \Drupal\commerce_recurring\BillingPeriod $order_item_billing_period */
    $order_item_billing_period = $order_item->get('billing_period')->first()->toBillingPeriod();

    $this->assertEquals('recurring_product_variation', $order_item->bundle());
    $this->assertEquals($this->trialSubscription->getTitle(), $order_item->getTitle());
    $this->assertEquals($this->trialSubscription->getQuantity(), $order_item->getQuantity());
    $this->assertEquals($this->trialSubscription->getPurchasedEntityId(), $order_item->getPurchasedEntityId());
    $this->assertTrue($order_item->getTotalPrice()->isZero());
    $this->assertEquals($billing_period, $order_item_billing_period);
    $this->assertEquals($this->trialSubscription->id(), $order_item->get('subscription')->target_id);
  }

  /**
   * @covers ::startRecurring
   */
  public function testStartRecurringWithInvalidState() {
    $this->setExpectedException(\InvalidArgumentException::class, 'Unexpected subscription state "trial".');
    $order = $this->recurringOrderManager->startRecurring($this->trialSubscription);
  }

  /**
   * @covers ::startRecurring
   * @covers ::collectSubscriptions
   */
  public function testStartRecurring() {
    $order = $this->recurringOrderManager->startRecurring($this->activeSubscription);
    $billing_schedule_plugin = $this->activeSubscription->getBillingSchedule()->getPlugin();
    $expected_billing_period = $billing_schedule_plugin->generateFirstBillingPeriod($this->activeSubscription->getStartDate());
    /** @var \Drupal\commerce_recurring\Plugin\Field\FieldType\BillingPeriodItem $billing_period_item */
    $billing_period_item = $order->get('billing_period')->first();
    $billing_period = $billing_period_item->toBillingPeriod();

    $this->assertEquals($expected_billing_period, $billing_period);
    $this->assertTrue($this->activeSubscription->hasOrder($order));
    $this->assertEmpty($this->activeSubscription->getRenewedTime());
    $this->assertOrder($order, $this->activeSubscription);
    $this->assertTrue($order->hasItems());
    $order_items = $order->getItems();
    $order_item = reset($order_items);
    /** @var \Drupal\commerce_recurring\BillingPeriod $order_item_billing_period */
    $order_item_billing_period = $order_item->get('billing_period')->first()->toBillingPeriod();

    $this->assertEquals('recurring_product_variation', $order_item->bundle());
    $this->assertEquals($this->activeSubscription->getTitle(), $order_item->getTitle());
    $this->assertEquals($this->activeSubscription->getQuantity(), $order_item->getQuantity());
    $this->assertEquals($this->activeSubscription->getPurchasedEntityId(), $order_item->getPurchasedEntityId());
    // The subscription was created mid-cycle, the unit price should be
    // half the usual due to proration.
    $this->assertEquals($this->activeSubscription->getUnitPrice()->divide('2'), $order_item->getUnitPrice());
    $this->assertEquals(new DrupalDateTime('2017-02-24 17:30:00'), $order_item_billing_period->getStartDate());
    $this->assertEquals($billing_period->getEndDate(), $order_item_billing_period->getEndDate());
    $this->assertEquals(3600, $billing_period->getDuration());
    $this->assertEquals($this->activeSubscription->id(), $order_item->get('subscription')->target_id);
  }

  /**
   * @covers ::refreshOrder
   */
  public function testRefreshOrder() {
    $order = $this->recurringOrderManager->startRecurring($this->activeSubscription);
    $order_items = $order->getItems();
    $order_item = reset($order_items);
    $previous_order_item_id = $order_item->id();

    $this->activeSubscription->set('payment_method', NULL);
    $this->activeSubscription->setUnitPrice(new Price('3', 'USD'));
    $this->activeSubscription->save();
    $this->recurringOrderManager->refreshOrder($order);

    $this->assertEmpty($order->get('billing_profile')->target_id);
    $this->assertEmpty($order->get('payment_method')->target_id);
    $this->assertEmpty($order->get('payment_gateway')->target_id);
    $order_items = $order->getItems();
    $order_item = reset($order_items);
    $this->assertEquals($previous_order_item_id, $order_item->id());
    $this->assertEquals($this->activeSubscription->getUnitPrice()->divide('2'), $order_item->getUnitPrice());

    // Confirm that the order is canceled on refresh if no charges remain.
    $this->billingSchedule->setBillingType(BillingScheduleInterface::BILLING_TYPE_PREPAID);
    $this->billingSchedule->save();
    $this->activeSubscription = $this->reloadEntity($this->activeSubscription);
    $this->activeSubscription->cancel();
    $this->activeSubscription->save();
    $this->reloadEntity($order_item);
    $this->recurringOrderManager->refreshOrder($order);

    $this->assertEquals('canceled', $order->getState()->getId());
    $this->assertEmpty($order->getItems());
  }

  /**
   * @covers ::renewOrder
   */
  public function testCloseOrderWithoutPaymentMethod() {
    $this->activeSubscription->set('payment_method', NULL);
    $this->activeSubscription->save();
    $order = $this->recurringOrderManager->startRecurring($this->activeSubscription);

    $this->setExpectedException(HardDeclineException::class, 'Payment method not found.');
    $this->recurringOrderManager->closeOrder($order);
  }

  /**
   * @covers ::closeOrder
   */
  public function testCloseOrder() {
    $order = $this->recurringOrderManager->startRecurring($this->activeSubscription);
    $this->recurringOrderManager->closeOrder($order);

    $this->assertEquals('completed', $order->getState()->getId());
    // Re-enable after #3011667 is fixed.
    // $this->assertTrue($order->isPaid());
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $payments = $payment_storage->loadMultipleByOrder($order);
    $this->assertCount(1, $payments);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = reset($payments);
    $this->assertEquals('completed', $payment->getState()->getId());
    $this->assertEquals($this->paymentGateway->id(), $payment->getPaymentGatewayId());
    $this->assertEquals($this->paymentMethod->id(), $payment->getPaymentMethodId());
    $this->assertEquals($order->id(), $payment->getOrderId());
    $this->assertEquals($order->getTotalPrice(), $payment->getAmount());
  }

  /**
   * @covers ::renewOrder
   */
  public function testRenewOrder() {
    $order = $this->recurringOrderManager->startRecurring($this->activeSubscription);
    $next_order = $this->recurringOrderManager->renewOrder($order);
    /** @var \Drupal\commerce_recurring\Plugin\Field\FieldType\BillingPeriodItem $billing_period_item */
    $billing_period_item = $order->get('billing_period')->first();
    $billing_period = $billing_period_item->toBillingPeriod();
    /** @var \Drupal\commerce_recurring\Plugin\Field\FieldType\BillingPeriodItem $next_billing_period_item */
    $next_billing_period_item = $next_order->get('billing_period')->first();
    $next_billing_period = $next_billing_period_item->toBillingPeriod();

    $this->activeSubscription = $this->reloadEntity($this->activeSubscription);
    $this->assertTrue($this->activeSubscription->hasOrder($order));
    $this->assertTrue($this->activeSubscription->hasOrder($next_order));
    $this->assertNotEmpty($this->activeSubscription->getRenewedTime());
    $this->assertEquals($billing_period->getEndDate(), $next_billing_period->getStartDate());
    $this->assertOrder($next_order, $this->activeSubscription);
    $this->assertTrue($next_order->hasItems());

    $order_items = $next_order->getItems();
    $order_item = reset($order_items);
    $this->assertEquals('recurring_product_variation', $order_item->bundle());
    $this->assertEquals($this->activeSubscription->getTitle(), $order_item->getTitle());
    $this->assertEquals($this->activeSubscription->getQuantity(), $order_item->getQuantity());
    $this->assertEquals($this->activeSubscription->getUnitPrice(), $order_item->getUnitPrice());
    $this->assertEquals($this->variation, $order_item->getPurchasedEntity());
    $this->assertEquals($next_billing_period, $order_item->get('billing_period')->first()->toBillingPeriod());
    $this->assertEquals(3600, $next_billing_period->getDuration());
    $this->assertEquals($this->activeSubscription->id(), $order_item->get('subscription')->target_id);

    // Confirm that no renewal occurs for canceled subscriptions.
    $this->activeSubscription->cancel(FALSE)->save();
    $result = $this->recurringOrderManager->renewOrder($next_order);
    $this->assertNull($result);
  }

  /**
   * Asserts that the recurring order fields have the expected values.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The recurring order.
   * @param \Drupal\commerce_recurring\Entity\SubscriptionInterface $subscription
   *   The subscription.
   */
  protected function assertOrder(OrderInterface $order, SubscriptionInterface $subscription) {
    $this->assertEquals('recurring', $order->bundle());
    $this->assertEquals('draft', $order->getState()->getId());
    $this->assertEquals($subscription->getStoreId(), $order->getStoreId());
    $this->assertEquals($subscription->getCustomerId(), $order->getCustomerId());
    $this->assertEquals($subscription->getBillingSchedule()->id(), $order->get('billing_schedule')->target_id);
    $payment_method = $subscription->getPaymentMethod();
    $this->assertEquals($payment_method->id(), $order->get('payment_method')->target_id);
    $this->assertEquals($payment_method->getPaymentGatewayId(), $order->get('payment_gateway')->target_id);
    $this->assertEquals($payment_method->getBillingProfile(), $order->getBillingProfile());
  }

}
