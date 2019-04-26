<?php

/*
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\apigee_m10n\Entity\Form;

use Apigee\Edge\Api\Monetization\Entity\LegalEntityInterface;
use Apigee\Edge\Exception\ClientErrorException;
use Drupal\apigee_m10n\Form\PrepaidBalanceConfigForm;
use Drupal\apigee_m10n\MonetizationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\apigee_edge\Entity\Developer;

/**
 * Subscription entity form.
 */
class SubscriptionForm extends FieldableMonetizationEntityForm {

  /**
   * Developer legal name attribute name.
   */
  const LEGAL_NAME_ATTR = 'MINT_DEVELOPER_LEGAL_NAME';

  /*
   * Insufficient funds API error code.
   */
  const INSUFFICIENT_FUNDS_ERROR = 'mint.insufficientFunds';

  /**
   * Messanger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Apigee Monetization utility service.
   *
   * @var \Drupal\apigee_m10n\MonetizationInterface
   */
  protected $monetization;

  /**
   * The system theme config object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs a SubscriptionEditForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   * @param \Drupal\apigee_m10n\MonetizationInterface $monetization
   *   Apigee Monetization utility service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(MessengerInterface $messenger = NULL, MonetizationInterface $monetization, ConfigFactoryInterface $config_factory) {
    $this->messenger = $messenger;
    $this->monetization = $monetization;
    $this->config = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('apigee_m10n.monetization'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    // @TODO: Make sure we find a better way to handle names
    // without adding rate plan ID this form is getting cached
    // and when rendered as a formatter.
    // Also known issue in core @see https://www.drupal.org/project/drupal/issues/766146.
    return parent::getFormId() . '_' . $this->entity->getRatePlan()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // Redirect to Rate Plan detail page on submit.
    $form['#action'] = $this->getEntity()->getRatePlan()->url('subscribe');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    // We can't alter the form in the form() method because the actions buttons
    // get added on buildForm().
    $this->insufficientFundsWorkflow($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    // Set the save label if one has been passed into storage.
    if (!empty($actions['submit']) && ($save_label = $form_state->get('save_label'))) {
      $actions['submit']['#value'] = $save_label;
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    try {
      // Auto assign legal name.
      $developer_id = $this->entity->getDeveloper()->getEmail();
      $developer = Developer::load($developer_id);
      // Autopopulate legal name when developer has no legal name attribute set.
      if (empty($developer->getAttributeValue(static::LEGAL_NAME_ATTR))) {
        $developer->setAttribute(static::LEGAL_NAME_ATTR, $developer_id);
        $developer->save();
      }

      $display_name = $this->entity->getRatePlan()->getDisplayName();
      Cache::invalidateTags(['apigee_my_subscriptions']);

      if ($this->entity->save()) {
        $this->messenger->addStatus($this->t('You have purchased %label plan', [
          '%label' => $display_name,
        ]));
        $form_state->setRedirect('entity.subscription.developer_collection', ['user' => $this->entity->getOwnerId()]);
      }
      else {
        $this->messenger->addWarning($this->t('Unable to purchase %label plan', [
          '%label' => $display_name,
        ]));
      }
    }
    catch (\Exception $e) {
      $previous = $e->getPrevious();

      // If insufficient funds error, format nicely and add link to add credit.
      if ($previous instanceof ClientErrorException && $previous->getEdgeErrorCode() === static::INSUFFICIENT_FUNDS_ERROR) {
        preg_match_all('/\[(?\'amount\'.+)\]/', $e->getMessage(), $matches);
        $amount = $matches['amount'][0] ?? NULL;
        $rate_plan = $this->getEntity()->getRatePlan();
        $currency_id = $rate_plan->getCurrency()->id();

        $message = 'You have insufficient funds to purchase plan %plan.';
        $message .= $amount ? ' To purchase this plan you are required to add at least %amount to your account.' : '';
        $message .= ' @link';
        $params = [
          '%plan' => $rate_plan->label(),
          '%amount' => $this->monetization->formatCurrency($matches['amount'][0], $currency_id),
          '@link' => \Drupal::service('link_generator')
            ->generate($this->t('Add credit'), $this->monetization->getAddCreditUrl($currency_id, $this->getEntity()->getOwner())),
        ];

        $this->messenger->addError($this->t($message, $params));
      }
      else {
        $this->messenger->addError($e->getMessage());
      }
    }
  }

  /**
   * Handles the "add credit" link and subscribe button status on subcription
   * to rate plan forms.
   *
   * @param array $form
   *   The form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function insufficientFundsWorkflow(array &$form, FormStateInterface $form_state) {
    // Check if insufficient_funds_workflow is disabled, and do nothing if so.
    $prepaid_balance_config = $this->config->get(PrepaidBalanceConfigForm::CONFIG_NAME);
    if (!$prepaid_balance_config->get('enable_insufficient_funds_workflow')) {
      return;
    }

    /* @var \Drupal\apigee_m10n\Entity\Subscription $subscription */
    $subscription = $form_state->getFormObject()->getEntity();
    $rate_plan = $subscription->getRatePlan();
    $user = $subscription->getOwner();

    /* @var \Drupal\apigee_m10n\ApigeeSdkControllerFactory $sdk */
    $sdk = \Drupal::service('apigee_m10n.sdk_controller_factory');
    try {
      $developer = $sdk->developerController()->load($user->getEmail());
    }
    catch (\Exception $e) {
      $developer = NULL;
    }

    // If developer is prepaid, check if enough balance to subscribe to rate plan.
    if ($developer && $developer->getBillingType() == LegalEntityInterface::BILLING_TYPE_PREPAID) {
      $prepaid_balances = [];
      foreach ($this->monetization->getDeveloperPrepaidBalances($user, new \DateTimeImmutable('now')) as $prepaid_balance) {
        $prepaid_balances[$prepaid_balance->getCurrency()->id()] = $prepaid_balance->getCurrentBalance();
      }

      // Minimum balance needed is at least the setup fee.
      // @see https://docs.apigee.com/api-platform/monetization/create-rate-plans.html#rateplanops
      $min_balance_needed = $rate_plan->getSetUpFee();
      $currency_id = $rate_plan->getCurrency()->id();
      $prepaid_balances[$currency_id] = $prepaid_balances[$currency_id] ?? 0;
      if ($min_balance_needed > $prepaid_balances[$currency_id]) {
        $form['add_credit'] = [
          '#type' => 'container',
        ];

        $form['add_credit']['add_credit_message'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('You have insufficient funds to purchase plan %plan.', [
            '%plan' => $rate_plan->label(),
          ]),
        ];
        $form['add_credit']['add_credit_link'] = [
          '#type' => 'link',
          '#title' => $this->t('Add credit'),
          '#url' => $this->monetization->getAddCreditUrl($currency_id, $user),
        ];

        $form['actions']['submit']['#attributes']['disabled']  = 'disabled';
      }
    }
  }

}
