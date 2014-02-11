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

if (!defined('_PS_VERSION_')) die();

class Barzahlen extends PaymentModule {

  private $_html = '';

  protected $_sandbox = false;
  protected $_shopid = '';
  protected $_paymentkey = '';
  protected $_notificationkey = '';
  protected $_debug = false;

  /**
   * Constructor is used to load all necessary payment module information.
   */
  public function __construct() {

    $this->name = 'barzahlen';
    $this->tab = 'payments_gateways';
    $this->version = '1.0.0';
    $this->author = 'Zerebro Internet GmbH';
    $this->currencies = true;
    $this->currencies_mode = 'checkbox';

    $config = Configuration::getMultiple(array($this->name.'_sandbox', $this->name.'_shopid', $this->name.'_paymentkey', $this->name.'_notificationkey', $this->name.'_debug'));
    if (isset($config[$this->name.'_sandbox']))  $this->_sandbox = $config[$this->name.'_sandbox'] == 'on' ? true : false;
    if (isset($config[$this->name.'_shopid'])) $this->_shopid = $config[$this->name.'_shopid'];
    if (isset($config[$this->name.'_paymentkey'])) $this->_paymentkey = $config[$this->name.'_paymentkey'];
    if (isset($config[$this->name.'_notificationkey'])) $this->_notificationkey = $config[$this->name.'_notificationkey'];
    if (isset($config[$this->name.'_debug'])) $this->_debug = $config[$this->name.'_debug'] == 'on' ? true : false;

    parent::__construct();

    $this->page = basename(__FILE__, '.php');
    $this->displayName = $this->l('Barzahlen');
    $this->description = $this->l('Barzahlen let\'s your customers pay cash online. You get a payment confirmation in real-time and you benefit from our payment guarantee and new customer groups. See how Barzahlen works: <a href=http://www.barzahlen.de/partner/funktionsweise target="_blank">http://www.barzahlen.de/partner/funktionsweise</a>');
  }

  /**
   * Core install method.
   *
   * @return boolean
   */
  public function install() {

    if (!parent::install() || !$this->registerHook('payment') || !$this->registerHook('paymentReturn')){
      return false;
    }
    $this->createTables();
    $this->createOrderState();
    return true;
  }

  /**
   * Creates the Barzahlen transaction table.
   */
  protected function createTables() {

    Db::getInstance()->Execute("
    CREATE TABLE IF NOT EXISTS `"._DB_PREFIX_."barzahlen_transactions` (
      `transaction_id` int(11) NOT NULL DEFAULT 0,
      `order_id` int(11) NOT NULL,
      `transaction_state` ENUM( 'pending', 'paid', 'expired' ) NOT NULL,
      PRIMARY KEY (`transaction_id`)
    ) ENGINE="._MYSQL_ENGINE_." DEFAULT CHARSET=utf8");
  }

  /**
   * Creates the new order states for the Barzahlen transaction states.
   */
  protected function createOrderState() {

    // pending state
    if (!Configuration::get('BARZAHLEN_PENDING')) {
      $orderState = new OrderState();
      $orderState->name = array();

      foreach (Language::getLanguages() as $language) {
        if (strtolower($language['iso_code']) == 'de') {
          $orderState->name[$language['id_lang']] = 'Warten auf Zahlungseingang von Barzahlen';
        }
        else {
          $orderState->name[$language['id_lang']] = 'Awaiting Barzahlen Payment';
        }
      }

      $orderState->send_email = false;
      $orderState->color = '#ADD8E6';
      $orderState->hidden = false;
      $orderState->delivery = false;
      $orderState->logable = true;
      $orderState->invoice = false;

      if ($orderState->add()) {
        $source = dirname(__FILE__).'/../../img/admin/gold.gif';
        $destination = dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif';
        copy($source, $destination);
      }
      Configuration::updateValue('BARZAHLEN_PENDING', (int)$orderState->id);
    }

    // paid state
    if (!Configuration::get('BARZAHLEN_PAID')) {
      $orderState = new OrderState();
      $orderState->name = array();

      foreach (Language::getLanguages() as $language) {
        if (strtolower($language['iso_code']) == 'de') {
          $orderState->name[$language['id_lang']] = 'Zahlungseingang von Barzahlen';
        }
        else {
          $orderState->name[$language['id_lang']] = 'Received Barzahlen Payment';
        }
      }

      $orderState->send_email = false;
      $orderState->color = '#DDEEFF';
      $orderState->hidden = false;
      $orderState->delivery = true;
      $orderState->logable = true;
      $orderState->invoice = true;

      if ($orderState->add()) {
        $source = dirname(__FILE__).'/../../img/os/2.gif';
        $destination = dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif';
        copy($source, $destination);
      }
      Configuration::updateValue('BARZAHLEN_PAID', (int)$orderState->id);
    }

    // expired state
    if (!Configuration::get('BARZAHLEN_EXPIRED')) {
      $orderState = new OrderState();
      $orderState->name = array();

      foreach (Language::getLanguages() as $language) {
        if (strtolower($language['iso_code']) == 'de') {
          $orderState->name[$language['id_lang']] = 'Barzahlen-Zahlschein abgelaufen';
        }
        else {
          $orderState->name[$language['id_lang']] = 'Barzahlen Payment Expired';
        }
      }

      $orderState->send_email = false;
      $orderState->color = '#FFDFDF';
      $orderState->hidden = false;
      $orderState->delivery = false;
      $orderState->logable = true;
      $orderState->invoice = false;

      if ($orderState->add()) {
        $source = dirname(__FILE__).'/../../img/os/6.gif';
        $destination = dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif';
        copy($source, $destination);
      }
      Configuration::updateValue('BARZAHLEN_EXPIRED', (int)$orderState->id);
    }
  }

  /**
   * Uninstaller. Extends parent and removes Barzahlen settings. Transaction
   * table and order states remain.
   *
   * @return boolean
   */
  public function uninstall() {

    if (!Configuration::deleteByName($this->name . '_sandbox')
        || !Configuration::deleteByName($this->name . '_shopid')
        || !Configuration::deleteByName($this->name . '_paymentkey')
        || !Configuration::deleteByName($this->name . '_notificationkey')
        || !Configuration::deleteByName($this->name . '_debug')
        || !parent::uninstall()) {
      return false;
    }
    return true;
  }

  /**
   * Saves new settings and calls html output method.
   *
   * @return string with html code
   */
  public function getContent() {

    if (Tools::isSubmit('btnSubmit'))
    {
      Configuration::updateValue($this->name.'_sandbox', Tools::getValue($this->name.'_sandbox'));
      Configuration::updateValue($this->name.'_shopid', Tools::getValue($this->name.'_shopid'));
      Configuration::updateValue($this->name.'_paymentkey', Tools::getValue($this->name.'_paymentkey'));
      Configuration::updateValue($this->name.'_notificationkey', Tools::getValue($this->name.'_notificationkey'));
      Configuration::updateValue($this->name.'_debug', Tools::getValue($this->name.'_debug'));
      $this->_sandbox = Tools::getValue($this->name.'_sandbox');
      $this->_debug = Tools::getValue($this->name.'_debug');
    }
    $this->_displayForm();
    return $this->_html;
  }

  /**
   * Prepares the html form for the module configuration.
   */
  private function _displayForm() {

    $sandboxChecked = $this->_sandbox ? 'checked="checked"' : '';
    $debugChecked = $this->_debug ? 'checked="checked"' : '';

    $this->_html .=
    '<form action="'.Tools::htmlentitiesUTF8($_SERVER['REQUEST_URI']).'" method="post">
      <fieldset>
      <legend><img src="../img/admin/prefs.gif" />'.$this->l('Barzahlen Settings').'</legend>
        <table border="0" width="500" cellpadding="0" cellspacing="0" id="form">
          <tr><td width="170" style="height: 35px;">'.$this->l('Sandbox').'</td><td><input type="checkbox" name="'.$this->name.'_sandbox" '.$sandboxChecked.'/></td></tr>
          <tr><td width="170" style="height: 35px;">'.$this->l('Shop ID').'</td><td><input type="text" name="'.$this->name.'_shopid" value="'.htmlentities(Tools::getValue($this->name.'_shopid', $this->_shopid), ENT_COMPAT, 'UTF-8').'" style="width: 300px;" /></td></tr>
          <tr><td width="170" style="height: 35px;">'.$this->l('Payment Key').'</td><td><input type="text" name="'.$this->name.'_paymentkey" value="'.htmlentities(Tools::getValue($this->name.'_paymentkey', $this->_paymentkey), ENT_COMPAT, 'UTF-8').'" style="width: 300px;" /></td></tr>
          <tr><td width="170" style="height: 35px;">'.$this->l('Notification Key').'</td><td><input type="text" name="'.$this->name.'_notificationkey" value="'.htmlentities(Tools::getValue($this->name.'_notificationkey', $this->_notificationkey), ENT_COMPAT, 'UTF-8').'" style="width: 300px;" /></td></tr>
          <tr><td width="170" style="height: 35px;">'.$this->l('Extended Logging').'</td><td><input type="checkbox" name="'.$this->name.'_debug" '.$debugChecked.'/></td></tr>
          <tr><td colspan="2" align="center"><input class="button" name="btnSubmit" value="'.$this->l('Update settings').'" type="submit" /></td></tr>
        </table>
      </fieldset>
    </form>';
  }

  /**
   * Prepares and returns payment template for payment selection hook.
   *
   * @global smarty object $smarty
   * @param array $params order parameters
   * @return boolean | string rendered template output
   */
  public function hookPayment($params) {
    global $smarty;

    if (!$this->active || !$this->_checkCurrency($params['cart'])) {
      return;
    }

    if($params['cart']->getOrderTotal(true, Cart::BOTH) >= 1000) {
      return;
    }

    $smarty->assign(array(
      'this_path' => $this->_path,
      'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/',
      $this->name . '_sandbox' => $this->_sandbox
    ));
    return $this->display(__FILE__, 'payment.tpl');
  }

  /**
   * Prepares and returns payment success template after order completion.
   *
   * @global type $smarty
   * @param type $params
   * @return string rendered template output
   */
  public function hookPaymentReturn($params) {
    global $smarty;

    if (!$this->active) {
      return ;
    }

    session_start();
    $smarty->assign($this->name . '_infotext', $_SESSION['barzahlen_infotext']);
    unset($_SESSION['barzahlen_infotext']);

    return $this->display(__FILE__, 'confirmation.tpl');
  }

  public function execPayment($cart) {
    global $smarty;

    if (!$this->active || !$this->_checkCurrency($cart)) {
      return;
    }

    $smarty->assign(array(
      'nbProducts' => $cart->nbProducts(),
      'isoCode' => $this->context->language->iso_code,
      'barzahlen_sandbox' => Configuration::get('barzahlen_sandbox'),
      'this_path' => $this->_path,
      'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/',
    ));

    return $this->display(__FILE__, 'payment_execution.tpl');
  }

  /**
   * Checks if selected currency is possible with Barzahlen.
   *
   * @param Cart $cart cart object
   * @return boolean
   */
  protected function _checkCurrency($cart) {
    $currency_order = new Currency($cart->id_currency);
    $currencies_module = $this->getCurrency($cart->id_currency);

    if (is_array($currencies_module)) {
      foreach ($currencies_module as $currency_module) {
        if ($currency_order->id == $currency_module['id_currency']) {
          return true;
        }
      }
    }
    return false;
  }
}

?>