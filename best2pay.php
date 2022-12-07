<?php

/**
 * @author Best2Pay
 * @version 1.1.0
 * @package VirtueMart
 * @subpackage payment
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 *
 * Best2Pay payment plugin for VirtueMart 3, 4
 *
 * http://www.best2pay.net
 *
 * tested on Joomla 4.1.5 and VirtueMart 4.0.4
 */

use Joomla\CMS\Factory;

defined('_JEXEC') or die('Restricted access');
if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentBest2Pay extends vmPSPlugin {
	
	function __construct(&$subject, $config) {
		parent::__construct($subject, $config);
		$this->_loggable = TRUE;
		$this->_debug = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array(
			'payment_logos' => array('', 'char'),
			'sector_id' => array('', 'char'),
			'password' => array('', 'char'),
			'test_mode' => array('', 'char'),
			'payment_currency' => array('', 'char'),
			'status_pending' => array('', 'char')
		);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}
	
	public function getTableSQLFields() {
		return array(
			'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(1) UNSIGNED',
			'order_number' => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name' => 'varchar(5000)',
			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency' => 'char(3)',
			'cost_per_transaction' => 'decimal(10,2)',
			'cost_percent_total' => 'decimal(10,2)',
			'tax_id' => 'smallint(1)',
			'user_session' => 'varchar(255)'
		);
	}
	
	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Best2Pay Table');
	}
	
	protected function displayLogos($logo_list) {
		$img = '';
		if(!$logo_list) return $img;
		$url = JURI::root() . str_replace('\\', '/', str_replace(JPATH_ROOT, '', dirname(__FILE__))) . '/';
		if (!is_array($logo_list))
			$logo_list = (array) $logo_list;
		foreach ($logo_list as $logo) {
			$alt_text = substr($logo, 0, strpos($logo, '.'));
			$img .= '<img align="middle" src="' . $url . $logo . '"  alt="' . $alt_text . '" /> ';
		}
		return $img;
	}
	
	function plgVmConfirmedOrder($cart, $order) {
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		return $this->sendTransactionRequest($method,$cart, $order);
	}
	
	function sendTransactionRequest($method, $cart, $order) {
		$lang = JFactory::getLanguage ();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);
		
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		
		$this->getPaymentCurrency($method);
		$currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
		$currency = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_numeric_code');
		
		$totalInPaymentCurrency = plgVmPaymentBest2Pay::getAmountInCurrency($order['details']['BT']->order_total,$method->payment_currency);
		$price = $totalInPaymentCurrency['value'];
		
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
		$dbValues['payment_currency'] = $currency_code_3;
		$this->storePSPluginInternalData($dbValues);
		
		if ($method->test_mode == '0')
			$best2pay_url = 'https://pay.best2pay.net';
		else
			$best2pay_url = 'https://test.best2pay.net';
		$url = $best2pay_url.'/webapi/Register';
		
		$signature  = base64_encode(md5($method->sector_id . ($price * 100) . $currency . $method->password));
		
		$data = array(
			'sector' => $method->sector_id,
			'reference' => $order['details']['BT']->order_number,
			'amount' => $price * 100,
			'description' => '#' . $order['details']['BT']->order_number,
			'email' => htmlspecialchars($order['details']['BT']->email, ENT_QUOTES),
			'currency' => $currency,
			'mode' => 1,
			'url' => $this->getRedirectURL($order),
			'signature' => $signature
		);
		$options = array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data),
			),
		);
		
		$context = stream_context_create($options);
		$best2pay_id = file_get_contents($url, false, $context);
		
		$mainframe = JFactory::getApplication();
		if (intval($best2pay_id) == 0) {
			$mainframe = JFactory::getApplication();
			$mainframe->enqueueMessage(JText::_('VMPAYMENT_BEST2PAY_ERROR', false), 'warning');
			$mainframe->enqueueMessage($best2pay_id, 'warning');
			$mainframe->redirect(JURI::root() . 'index.php?option=com_virtuemart&view=cart');
		}
		
		$signature = base64_encode(md5($method->sector_id . $best2pay_id . $method->password));
		$redirect_url = $best2pay_url
			. '/webapi/Purchase'
			. '?sector=' . $method->sector_id
			. '&id=' . $best2pay_id
			. '&signature=' . $signature;
		
		$mainframe->redirect($redirect_url);
		
		return true;
	}
	
	function getNewStatus($method) {
		if (isset($method->status_pending) && $method->status_pending != '') {
			return $method->status_pending;
		} else {
			return 'P';
		}
	}
	
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return NULL;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
			return NULL;
		}
		VmConfig::loadJLang('com_virtuemart');
		
		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";
		return $html;
	}
	
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		return 0;
	}
	
	public function checkConditions($cart, $method, $cart_prices) {
		return true;
	}
	
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}
	
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		return $this->OnSelectCheck($cart);
	}
	
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}
	
	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}
	
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}
	
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array()/*, &$paymentCounter*/) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices/*, $paymentCounter*/);
	}
	
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}
	
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}
	
	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams ('payment', $name, $id, $data);
	}
	
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}
	
	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	
	static function getAmountInCurrency($amount, $currencyId) {
		if (method_exists('vmPSPlugin', 'getAmountInCurrency')) {
			return vmPSPlugin::getAmountInCurrency($amount, $currencyId);
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		
		$return = array();
		$paymentCurrency = CurrencyDisplay::getInstance($currencyId);
		$return['value'] = round($paymentCurrency->convertCurrencyTo($currencyId, $amount, false), 2);
		$return['display'] = $paymentCurrency->priceDisplay($amount, $currencyId);
		return $return;
	}
	
	public function plgVmOnPaymentResponseReceived(&$html) {
		$lang = JFactory::getLanguage();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);
		
		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
		$order_number = vRequest::getString('reference', 0);
		
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return false;
		}
		if (!($paymentTables = $this->getDatasByOrderId($virtuemart_order_id))) {
			return false;
		}
		
		$signature = base64_encode(md5($method->sector_id . vRequest::getInt('id', 0) . vRequest::getInt('operation', 0) . $method->password));
		
		if ($method->test_mode == '0')
			$best2pay_url = 'https://pay.best2pay.net';
		else
			$best2pay_url = 'https://test.best2pay.net';
		$url = $best2pay_url . '/webapi/Operation';
		
		$context = stream_context_create(array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query(array(
					'sector' => $method->sector_id,
					'id' => vRequest::getInt('id', 0),
					'operation' => vRequest::getInt('operation', 0),
					'signature' => $signature
				)),
			)
		));
		
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);
		
		$repeat = 3;
		try {
			while ($repeat) {
				$repeat--;
				sleep(2);
				
				$xml = file_get_contents($url, false, $context);
				if (!$xml)
					throw new Exception("Empty data");
				$xml = simplexml_load_string($xml);
				if (!$xml)
					throw new Exception("Non valid XML was received");
				$response = json_decode(json_encode($xml), true);
				if (!$response)
					throw new Exception("Non valid XML was received");
				
				$tmp_response = (array)$response;
				unset($tmp_response['signature'], $tmp_response['ofd_state']);
				$signature = base64_encode(md5(implode('', $tmp_response) . $method->password));
				if ($signature !== $response['signature'])
					throw new Exception("Invalid signature");
				
				if (($response['type'] !== 'PURCHASE' && $response['type'] !== 'PURCHASE_BY_QR' && $response['type'] !== 'AUTHORIZE') || $response['state'] != 'APPROVED')
					continue;
				
				$totalInPaymentCurrency = plgVmPaymentBest2Pay::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);
				$totalInPaymentCurrency = $totalInPaymentCurrency['value'];
				if ($response['amount'] != intval($totalInPaymentCurrency * 100) || $response['amount'] <= 0)
					throw new Exception("Invalid price");
				
				if ($order['order_status'] != 'C'){
					$order['order_status'] = 'C';
					$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
				}
				$cart = VirtueMartCart::getCart();
				$cart->emptyCart();
				
				$html = '<table>' . "\n";
				$html .= $this->getHtmlRow('BEST2PAY_ORDER_NUMBER', $order_number);
				$html .= $this->getHtmlRow('BEST2PAY_ORDER_ID', $virtuemart_order_id);
				$html .= $this->getHtmlRow('BEST2PAY_PAYMENT_ID', $response['id']);
				$html .= $this->getHtmlRow('BEST2PAY_STATUS', JText::_('VMPAYMENT_BEST2PAY_SUCCESS'));
				$html .= '</table>' . "\n";
				
				return true;
			}
			
			throw new Exception('Unknown error');
			
		} catch (Exception $ex) {
			error_log($ex->getMessage());
			$mainframe = JFactory::getApplication();
			$mainframe->enqueueMessage(JText::_('VMPAYMENT_BEST2PAY_ERROR', false), 'warning');
			$mainframe->redirect(JURI::root() . 'index.php?option=com_virtuemart&view=cart');
			return false;
		}
	}
	
	function plgVmOnUserPaymentCancel () {
		
		if (!class_exists ('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		
		$order_number = vRequest::getString ('on', '');
		$virtuemart_paymentmethod_id = vRequest::getInt ('pm', '');
		if (empty($order_number) or
			empty($virtuemart_paymentmethod_id) or
			!$this->selectedThisByMethodId ($virtuemart_paymentmethod_id)
		) {
			return NULL;
		}
		
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}
		
		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			return NULL;
		}
		
		$session = JFactory::getSession ();
		$return_context = $session->getId ();
		if (strcmp ($paymentTable->user_session, $return_context) === 0) {
			$this->handlePaymentUserCancel ($virtuemart_order_id);
		}
		
		return TRUE;
	}
	
	public function plgVmOnPaymentNotification() {
		$lang = JFactory::getLanguage();
		$filename = 'com_virtuemart';
		$lang->load($filename, JPATH_ADMINISTRATOR);
		
		if (!class_exists('shopFunctionsF')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}
		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		if (!class_exists('VirtueMartCart')) {
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
		$order_number = vRequest::getString('reference', 0);
		
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return false;
		}
		if (!($paymentTables = $this->getDatasByOrderId($virtuemart_order_id))) {
			return false;
		}
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);
		
		$xml = file_get_contents('php://input');
		if (!$xml)
			die('error 1');
		$xml = simplexml_load_string($xml);
		if (!$xml)
			die('error 2');
		$response = json_decode(json_encode($xml), true);
		if (!$response)
			die('error 3');
		
		header('Content-type: text/plain');
		
		if (($response['type'] !== 'PURCHASE' && $response['type'] !== 'PURCHASE_BY_QR' && $response['type'] !== 'AUTHORIZE') || $response['state'] != 'APPROVED')
			die('error 4');
		
		$signature = $response['signature'];
		unset($response['signature']);
		$str = implode('', (array)$response) . $method->password;
		$my_signature = base64_encode(md5($str));
		if ($my_signature !== $signature)
			die('error 4');
		
		$totalInPaymentCurrency = plgVmPaymentBest2Pay::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);
		$totalInPaymentCurrency = $totalInPaymentCurrency['value'];
		if ($response['amount'] != intval($totalInPaymentCurrency * 100) || $response['amount'] <= 0)
			die('error 5');
		
		if ($order['order_status'] != 'C'){
			$order['order_status'] = 'C';
			$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order, TRUE);
		}
		
		die('ok');
	}
	
	private function getRedirectURL($order) {
		return JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm="
			. $order['details']['BT']->virtuemart_paymentmethod_id;
	}
	
}