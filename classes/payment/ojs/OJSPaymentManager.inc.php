<?php

/**
 * @file classes/payment/ojs/OJSPaymentManager.inc.php
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OJSPaymentManager
 * @ingroup payment
 * @see OJSQueuedPayment
 *
 * @brief Provides payment management functions.
 *
 */

import('classes.payment.ojs.OJSQueuedPayment');
import('lib.pkp.classes.payment.PaymentManager');

define('PAYMENT_TYPE_MEMBERSHIP',		0x000000001);
define('PAYMENT_TYPE_RENEW_SUBSCRIPTION',	0x000000002);
define('PAYMENT_TYPE_PURCHASE_ARTICLE',		0x000000003);
define('PAYMENT_TYPE_DONATION',			0x000000004);
define('PAYMENT_TYPE_SUBMISSION',		0x000000005);
define('PAYMENT_TYPE_FASTTRACK',		0x000000006);
define('PAYMENT_TYPE_PUBLICATION',		0x000000007);
define('PAYMENT_TYPE_PURCHASE_SUBSCRIPTION',	0x000000008);
define('PAYMENT_TYPE_PURCHASE_ISSUE',		0x000000009);

class OJSPaymentManager extends PaymentManager {
	/**
	 * Constructor
	 * @param $request PKPRequest
	 */
	function __construct($request) {
		parent::__construct($request);
	}

	/**
	 * Determine whether the payment system is configured.
	 * @return boolean true iff configured
	 */
	function isConfigured() {
		$journal = $this->request->getJournal();
		return parent::isConfigured() && $journal->getSetting('journalPaymentsEnabled');
	}

	/**
	 * Create a queued payment.
	 * @param $journalId int ID of journal payment applies under
	 * @param $type int PAYMENT_TYPE_...
	 * @param $userId int ID of user responsible for payment
	 * @param $assocId int ID of associated entity
	 * @param $amount numeric Amount of currency $currencyCode
	 * @param $currencyCode string optional ISO 4217 currency code
	 * @return QueuedPayment
	 */
	function createQueuedPayment($journalId, $type, $userId, $assocId, $amount, $currencyCode = null) {
		$journalSettingsDao = DAORegistry::getDAO('JournalSettingsDAO');
		if (is_null($currencyCode)) $currencyCode = $journalSettingsDao->getSetting($journalId, 'currency');
		$payment = new OJSQueuedPayment($amount, $currencyCode, $userId, $assocId);
		$payment->setJournalId($journalId);
		$payment->setType($type);
		$router = $this->request->getRouter();
		$dispatcher = $router->getDispatcher();

		switch ($type) {
			case PAYMENT_TYPE_PURCHASE_ARTICLE:
				$payment->setRequestUrl($dispatcher->url($this->request, ROUTE_PAGE, null, 'article', 'view', $assocId));
				break;
			case PAYMENT_TYPE_PURCHASE_ISSUE:
				$payment->setRequestUrl($dispatcher->url($this->request, ROUTE_PAGE, null, 'issue', 'view', $assocId));
				break;
			case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
				$payment->setRequestUrl($dispatcher->url($this->request, ROUTE_PAGE, null, 'issue', 'current'));
			case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
				$payment->setRequestUrl($dispatcher->url($this->request, ROUTE_PAGE, null, 'user', 'subscriptions'));
				break;
			case PAYMENT_TYPE_PUBLICATION:
				$submissionDao = Application::getSubmissionDAO();
				$submission = $submissionDao->getById($assocId);
				if ($submission->getSubmissionProgress()!=0) {
					$payment->setRequestUrl($dispatcher->url($this->request, ROUTE_PAGE, null, 'submission', 'wizard', $submission->getSubmissionProgress(), array('submissionId' => $assocId)));
				} else {
					$payment->setRequestUrl($dispatcher->url($this->request, ROUTE_PAGE, null, 'author'));
				}
				break;
			case PAYMENT_TYPE_MEMBERSHIP: // Deprecated
			case PAYMENT_TYPE_DONATION: // Deprecated
			case PAYMENT_TYPE_FASTTRACK: // Deprecated
			case PAYMENT_TYPE_SUBMISSION: // Deprecated
			default:
				// Invalid payment type
				error_log('Invalid payment type "' . $type . '"');
				assert(false);
				break;
		}

		return $payment;
	}

	/**
	 * Create a completed payment from a queued payment.
	 * @param $queuedPayment QueuedPayment Payment to complete.
	 * @param $payMethod string Name of payment plugin used.
	 * @return CompletedPayment
	 */
	function createCompletedPayment($queuedPayment, $payMethod) {
		import('lib.pkp.classes.payment.CompletedPayment');
		$payment = new CompletedPayment();
		$payment->setContextId($queuedPayment->getJournalId());
		$payment->setType($queuedPayment->getType());
		$payment->setAmount($queuedPayment->getAmount());
		$payment->setCurrencyCode($queuedPayment->getCurrencyCode());
		$payment->setUserId($queuedPayment->getUserId());
		$payment->setAssocId($queuedPayment->getAssocId());
		$payment->setPayMethodPluginName($payMethod);

		return $payment;
	}

	/**
	 * Determine whether publication fees are enabled.
	 * @return boolean true iff this fee is enabled.
	 */
	function publicationEnabled() {
		$journal = $this->request->getJournal();
		return $this->isConfigured() && $journal->getSetting('publicationFee') > 0;
	}

	/**
	 * Determine whether publication fees are enabled.
	 * @return boolean true iff this fee is enabled.
	 */
	function membershipEnabled() {
		$journal = $this->request->getJournal();
		return $this->isConfigured() && $journal->getSetting('membershipFee') > 0;
	}

	/**
	 * Determine whether article purchase fees are enabled.
	 * @return boolean true iff this fee is enabled.
	 */
	function purchaseArticleEnabled() {
		$journal = $this->request->getJournal();
		return $this->isConfigured() && $journal->getSetting('purchaseArticleFee') > 0;
	}

	/**
	 * Determine whether issue purchase fees are enabled.
	 * @return boolean true iff this fee is enabled.
	 */
	function purchaseIssueEnabled() {
		$journal = $this->request->getJournal();
		return $this->isConfigured() && $journal->getSetting('purchaseIssueFee') > 0;
	}

	/**
	 * Determine whether PDF-only article purchase fees are enabled.
	 * @return boolean true iff this fee is enabled.
	 */
	function onlyPdfEnabled() {
		$journal = $this->request->getJournal();
		return $this->isConfigured() && $journal->getSetting('restrictOnlyPdf');
	}

	/**
	 * Get the payment plugin.
	 * @return PaymentPlugin
	 */
	function getPaymentPlugin() {
		$journal = $this->request->getJournal();
		$paymentMethodPluginName = $journal->getSetting('paymentPluginName');
		$paymentMethodPlugin = null;
		if (!empty($paymentMethodPluginName)) {
			$plugins = PluginRegistry::loadCategory('paymethod');
			if (isset($plugins[$paymentMethodPluginName])) $paymentMethodPlugin = $plugins[$paymentMethodPluginName];
		}
		return $paymentMethodPlugin;
	}

	/**
	 * Fulfill a queued payment.
	 * @param $request PKPRequest
	 * @param $queuedPayment QueuedPayment
	 * @param $payMethodPluginName string Name of payment plugin.
	 * @return mixed Dependent on payment type.
	 */
	function fulfillQueuedPayment($request, $queuedPayment, $payMethodPluginName = null) {
		$returner = false;
		if ($queuedPayment) switch ($queuedPayment->getType()) {
			case PAYMENT_TYPE_MEMBERSHIP:
				$userDao = DAORegistry::getDAO('UserDAO');
				$user = $userDao->getById($queuedPayment->getuserId());
				$userDao->renewMembership($user);
				$returner = true;
				break;
			case PAYMENT_TYPE_PURCHASE_SUBSCRIPTION:
				$subscriptionId = $queuedPayment->getAssocId();
				$institutionalSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
				$individualSubscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
				if ($institutionalSubscriptionDao->subscriptionExists($subscriptionId)) {
					$subscription = $institutionalSubscriptionDao->getById($subscriptionId);
					$institutional = true;
				} else {
					$subscription = $individualSubscriptionDao->getById($subscriptionId);
					$institutional = false;
				}
				if (!$subscription || $subscription->getUserId() != $queuedPayment->getUserId() || $subscription->getJournalId() != $queuedPayment->getJournalId()) {
					fatalError('Subscription integrity checks fail!');
					return false;
				}
				// Update subscription end date now that payment is completed
				if ($institutional) {
					// Still requires approval from JM/SM since includes domain and IP ranges
					import('classes.subscription.InstitutionalSubscription');
					$subscription->setStatus(SUBSCRIPTION_STATUS_NEEDS_APPROVAL);
					if ($subscription->isNonExpiring()) {
						$institutionalSubscriptionDao->updateObject($subscription);
					} else {
						$institutionalSubscriptionDao->renewSubscription($subscription);
					}

					// Notify JM/SM of completed online purchase
					$journalSettingsDao = DAORegistry::getDAO('JournalSettingsDAO');
					if ($journalSettingsDao->getSetting($subscription->getJournalId(), 'enableSubscriptionOnlinePaymentNotificationPurchaseInstitutional')) {
						import('classes.subscription.SubscriptionAction');
						SubscriptionAction::sendOnlinePaymentNotificationEmail($request, $subscription, 'SUBSCRIPTION_PURCHASE_INSTL');
					}
				} else {
					import('classes.subscription.IndividualSubscription');
					$subscription->setStatus(SUBSCRIPTION_STATUS_ACTIVE);
					if ($subscription->isNonExpiring()) {
						$individualSubscriptionDao->updateObject($subscription);
					} else {
						$individualSubscriptionDao->renewSubscription($subscription);
					}
					// Notify JM/SM of completed online purchase
					$journalSettingsDao = DAORegistry::getDAO('JournalSettingsDAO');
					if ($journalSettingsDao->getSetting($subscription->getJournalId(), 'enableSubscriptionOnlinePaymentNotificationPurchaseIndividual')) {
						import('classes.subscription.SubscriptionAction');
						SubscriptionAction::sendOnlinePaymentNotificationEmail($request, $subscription, 'SUBSCRIPTION_PURCHASE_INDL');
					}
				}
				$returner = true;
				break;
			case PAYMENT_TYPE_RENEW_SUBSCRIPTION:
				$subscriptionId = $queuedPayment->getAssocId();
				$institutionalSubscriptionDao = DAORegistry::getDAO('InstitutionalSubscriptionDAO');
				$individualSubscriptionDao = DAORegistry::getDAO('IndividualSubscriptionDAO');
				if ($institutionalSubscriptionDao->subscriptionExists($subscriptionId)) {
					$subscription = $institutionalSubscriptionDao->getById($subscriptionId);
					$institutional = true;
				} else {
					$subscription = $individualSubscriptionDao->getById($subscriptionId);
					$institutional = false;
				}
				if (!$subscription || $subscription->getUserId() != $queuedPayment->getUserId() || $subscription->getJournalId() != $queuedPayment->getJournalId()) {
					// FIXME: Is this supposed to be here?
					error_log(print_r($subscription, true));
					return false;
				}
				if ($institutional) {
					$institutionalSubscriptionDao->renewSubscription($subscription);

					// Notify JM/SM of completed online purchase
					$journalSettingsDao = DAORegistry::getDAO('JournalSettingsDAO');
					if ($journalSettingsDao->getSetting($subscription->getJournalId(), 'enableSubscriptionOnlinePaymentNotificationRenewInstitutional')) {
						import('classes.subscription.SubscriptionAction');
						SubscriptionAction::sendOnlinePaymentNotificationEmail($request, $subscription, 'SUBSCRIPTION_RENEW_INSTL');
					}
				} else {
					$individualSubscriptionDao->renewSubscription($subscription);

					// Notify JM/SM of completed online purchase
					$journalSettingsDao = DAORegistry::getDAO('JournalSettingsDAO');
					if ($journalSettingsDao->getSetting($subscription->getJournalId(), 'enableSubscriptionOnlinePaymentNotificationRenewIndividual')) {
						import('classes.subscription.SubscriptionAction');
						SubscriptionAction::sendOnlinePaymentNotificationEmail($request, $subscription, 'SUBSCRIPTION_RENEW_INDL');
					}
				}
				$returner = true;
				break;
			case PAYMENT_TYPE_DONATION:
				assert(false); // Deprecated
				$returner = true;
				break;
			case PAYMENT_TYPE_PURCHASE_ARTICLE:
			case PAYMENT_TYPE_PURCHASE_ISSUE:
			case PAYMENT_TYPE_SUBMISSION:
			case PAYMENT_TYPE_PUBLICATION:
				$returner = true;
				break;
			default:
				// Invalid payment type
				assert(false);
		}
		$completedPaymentDao = DAORegistry::getDAO('OJSCompletedPaymentDAO');
		$completedPayment = $this->createCompletedPayment($queuedPayment, $payMethodPluginName);
		$completedPaymentDao->insertObject($completedPayment);

		$queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO');
		$queuedPaymentDao->deleteById($queuedPayment->getId());

		return $returner;
	}
}

?>
