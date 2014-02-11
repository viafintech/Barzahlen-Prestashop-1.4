<?php
/**
 * Barzahlen Payment Module (PrestaShop)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@barzahlen.de so we can send you a copy immediately.
 *
 * @copyright   Copyright (c) 2012 Zerebro Internet GmbH (http://www.barzahlen.de)
 * @author      Alexander Diebler
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL-3.0)
 */

require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/api/loader.php';

// let's roll
new BarzahlenCallback;

class BarzahlenCallback {

  const STATE_PENDING = 'pending';
  const STATE_PAID = 'paid';
  const STATE_EXPIRED = 'expired';

  /**
   * @see FrontController::initContent()
   */
  public function __construct() {

    $notification = new Barzahlen_Notification(Configuration::get('barzahlen_shopid'), Configuration::get('barzahlen_notificationkey'), $_GET);

    try {
      $notification->validate();
    }
    catch (Exception $e) {
      LoggerCore::addLog('Barzahlen/Payment: ' . $e, 3, null, null, null, true);
    }

    if(!$notification->isValid()) {
      $this->_sendHeader(400);
    }

    $this->_sendHeader(200);
    $result = $this->_selectTransaction($notification);

    if(count($result) == 0) {
      LoggerCore::addLog('Barzahlen/Callback: No pending transaction found for order ID '.$notification->getOrderId().' and transaction ID '.$notification->getTransactionId().'.', 3, null, null, null, true);
      return;
    }
    else {
      $order = new Order($result[0]['order_id']);
    }

    if($this->_checkOrderValues($notification, $order)) {

      switch ($notification->getState()) {
        case self::STATE_PAID:
          $order->setCurrentState(Configuration::get('BARZAHLEN_PAID'));
          $this->_updateTransactionState($notification->getTransactionId(), self::STATE_PAID);
          break;
        case self::STATE_EXPIRED:
          $order->setCurrentState(Configuration::get('BARZAHLEN_EXPIRED'));
          $this->_updateTransactionState($notification->getTransactionId(), self::STATE_EXPIRED);
          break;
        default:
          LoggerCore::addLog('Barzahlen/Callback: Unable to handle given state '.$notification->getState().'.', 3, null, null, null, true);
      }
    }
  }

  /**
   * Looks up the corresponding from the databse.
   *
   * @param Barzahlen_Notification $notification
   * @return array
   */
  protected function _selectTransaction(Barzahlen_Notification $notification) {

    $sql = "SELECT * FROM `"._DB_PREFIX_."barzahlen_transactions`
             WHERE transaction_id = '".(int)$notification->getTransactionId()."'
               AND transaction_state = '".self::STATE_PENDING."'";

    if($notification->getOrderId() != null) {
      $sql .= " AND order_id = '".(int)$notification->getOrderId()."'";
    }

    return Db::getInstance()->executeS($sql);
  }

  /**
   * Compares amount and currency for order and notification values.
   *
   * @param Barzahlen_Notification $notification
   * @param Order $order
   * @return boolean
   */
  protected function _checkOrderValues(Barzahlen_Notification $notification, Order $order) {

    $currency = new Currency($order->id_currency);

    if($order->total_paid != $notification->getAmount()) {
      LoggerCore::addLog('Barzahlen/Callback: Given amount of '.$notification->getAmount().' doen\'t match amount of order '.$order->id.'.', 3, null, null, null, true);
      return false;
    }

    if($currency->iso_code != $notification->getCurrency()) {
      LoggerCore::addLog('Barzahlen/Callback: Given currency '.$notification->getCurrency().' doen\'t match currency of order '.$order->id.'.', 3, null, null, null, true);
      return false;
    }

    return true;
  }

  /**
   * Updates the transaction in the database.
   *
   * @param integer $transactionId transaction id
   * @param string $state new transaction state
   */
  protected function _updateTransactionState($transactionId, $state) {

    $sql = "UPDATE `"._DB_PREFIX_."barzahlen_transactions`
               SET transaction_state = '".$state."'
             WHERE transaction_id = '".(int)$transactionId."'";

    Db::getInstance()->execute($sql);
  }

  /**
   * Send the header depending on the notification validation.
   *
   * @param integer $code status code
   */
  protected function _sendHeader($code) {

    if($code == 200) {
      header("HTTP/1.1 200 OK");
      header("Status: 200 OK");
    }
    else {
      header("HTTP/1.1 400 Bad Request");
      header("Status: 400 Bad Request");
      die();
    }
  }
}