<?php

defined('_JEXEC') or die('Restricted access');
class plgHikashoppaymentZarinpal extends hikashopPaymentPlugin
{
	var $accepted_currencies = array(
		'IRR', 'TOM'
	);

	var $multiple = true;
	var $name = 'zarinpal';
	var $doc_form = 'zarinpal';

	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config); 
	}

	function onBeforeOrderCreate(&$order,&$do)
	{
		if(parent::onBeforeOrderCreate($order, $do) === true)
			return true;

		if(empty($this->payment_params->merchant))
		{
			$this->app->enqueueMessage('Please check your &quot;Zarinpal&quot; plugin configuration');
			$do = false;
		}
	}

	function onAfterOrderConfirm(&$order, &$methods, $method_id)
	{
		parent::onAfterOrderConfirm($order, $methods, $method_id);
		try
		{
			$client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
		}
		catch (SoapFault $ex)
		{
			die('System Error1: constructor error');
		}
		try
		{
			$callBackUrl = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment='.$this->name.'&tmpl=component&lang='.$this->locale . $this->url_itemid . '&orderID=' . $order->order_id;
			$amount = round($order->cart->full_total->prices[0]->price_value_with_tax,(int)$this->currency->currency_locale['int_frac_digits']);
			$parameters = array(
				'MerchantID'  => $this->payment_params->merchant,
				'Amount'      => $amount,
				'Description' => 'پرداخت سفارش شماره: ' . $order->order_id,
				'Email'       => '',
				'Mobile'      => '',
				'CallbackURL' => $callBackUrl
			);
			$result = $client->PaymentRequest($parameters);
			if($result->Status == 100)
			{
				$this->payment_params->url = 'https://www.zarinpal.com/pg/StartPay/'.$result->Authority;
				return $this->showPage('end');
			}
			else
			{
				echo "<p align=center>Bank Error $result->Status.<br />Order UNSUCCSESSFUL!</p>";
				exit;die;
			}
		}
		catch (SoapFault $ex)
		{
			die('System Error2: error in get data from bank');
		}
	}

	function onPaymentNotification(&$statuses)
	{
		$filter = JFilterInput::getInstance();

		$dbOrder = $this->getOrder($_REQUEST['orderID']);
		$this->loadPaymentParams($dbOrder);
		if(empty($this->payment_params))
			return false;
		$this->loadOrderData($dbOrder);
		if(empty($dbOrder))
		{
			echo 'Could not load any order for your notification ' . $_REQUEST['orderID'];
			return false;
		}
		$order_id = $dbOrder->order_id;

		$url = HIKASHOP_LIVE.'administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=' . $order_id;
		$order_text = "\r\n" . JText::sprintf('NOTIFICATION_OF_ORDER_ON_WEBSITE', $dbOrder->order_number, HIKASHOP_LIVE);
		$order_text .= "\r\n" . str_replace('<br/>', "\r\n", JText::sprintf('ACCESS_ORDER_WITH_LINK', $url));

		if (!empty($_GET['Authority']))
		{
			$history = new stdClass();
			$history->notified = 0;
			$history->amount = round($dbOrder->order_full_price, (int)$this->currency->currency_locale['int_frac_digits']);
			$history->data = ob_get_clean();

			try
			{
				$client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
			}
			catch (SoapFault $ex)
			{
				die('System Error1: constructor error');
			}
			try
			{
				$msg    = '';
				$parameters = array(
					'MerchantID' => $this->payment_params->merchant,
					'Authority'  => $_GET['Authority'],
					'Amount'     => $history->amount
				);
				$result = $client->PaymentVerification($parameters);
				if($result->Status == 100)
				{
					$order_status = $this->payment_params->verified_status;
					$msg = 'پرداخت شما با موفقیت انجام شد.';
				}
				else
				{
					$order_status = $this->payment_params->pending_status;
					$order_text = JText::sprintf('CHECK_DOCUMENTATION',HIKASHOP_HELPURL.'payment-zarinpal-error#verify')."\r\n\r\n".$order_text;
					$msg = $this->getStatusMessage($result->Status);
				}
			}
			catch (SoapFault $ex)
			{
				die('System Error2: error in get data from bank');
			}

			$config =& hikashop_config();
			if($config->get('order_confirmed_status', 'confirmed') == $order_status)
			{
				$history->notified = 1;
			}

			$email = new stdClass();
			$email->subject = JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER','Zarinpal',$order_status,$dbOrder->order_number);
			$email->body = str_replace('<br/>', "\r\n", JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Zarinpal', $order_status)).' '.JText::sprintf('ORDER_STATUS_CHANGED',$order_status)."\r\n\r\n".$order_text;
			$this->modifyOrder($order_id, $order_status, $history, $email);
		}
		else
		{
			$order_status = $this->payment_params->invalid_status;
			$email = new stdClass();
			$email->subject = JText::sprintf('NOTIFICATION_REFUSED_FOR_THE_ORDER','Zarinpal').'invalid transaction';
			$email->body = JText::sprintf("Hello,\r\n A Zarinpal notification was refused because it could not be verified by the zarinpal server (or pay cenceled)")."\r\n\r\n".JText::sprintf('CHECK_DOCUMENTATION',HIKASHOP_HELPURL.'payment-zarinpal-error#invalidtnx');
			$action = false;
			$this->modifyOrder($order_id, $order_status, null, $email);
		}
		header('location: ' . HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order');
		exit;
	}
	
	public function getStatusMessage($status) {
		$status = (string) $status;
		$statusCode = [
			'-1' => 'اطلاعات ارسال شده ناقص است.',
			'-2' => 'IP و یا کد پذیرنده اشتباه است',
			'-3' => 'با توجه به محدودیت های شاپرک امکان پرداخت با رقم درخواست شده میسر نمی باشد',
			'-4' => 'سطح تایید پذیرنده پایین تر از سطح نقره ای است',
			'-11' => 'درخواست موردنظر یافت نشد',
			'-12' => 'امکان ویرایش درخواست میسر نمی باشد',
			'-21' => 'هیچ نوع عملیات مالی برای این تراکنش یافت نشد',
			'-22' => 'تراکنش ناموفق می باشد',
			'-33' => 'رقم تراکنش با رقم پرداخت شده مطابقت ندارد',
			'-34' => 'سقف تقسیم تراکنش از لحاظ تعداد یا رقم عبور نموده است',
			'-40' => 'اجازه دسترسی به متد مربوطه وجود ندارد',
			'-41' => 'اطلاعات ارسالی مربوط به اطلاعات اضافی غیرمعتبر می باشد',
			'-42' => 'مدت زمان معتبر طول عمر شناسه پرداخت باید بین ۳۰ دقیقه تا ۴۵ روز می باشد',
			'-54' => 'درخواست موردنظر آرشیو شده است',
			'100' => 'عملیات با موفقیت انجام شده است',
			'101' => 'عملیات پرداخت موفق بوده و قبلا اعتبارسنجی تراکنش انجام شده است'
		];
		if (isset($statusCode[$status])) {
			return $statusCode[$status];
		}
		return 'خطای نامشخص. کد خطا: ' . $status;
	}

	function onPaymentConfiguration(&$element)
	{
		$subtask = JRequest::getCmd('subtask', '');

		parent::onPaymentConfiguration($element);
	}

	function onPaymentConfigurationSave(&$element)
	{
		return true;
	}

	function getPaymentDefaultValues(&$element)
	{
		$element->payment_name = 'درگاه پرداخت زرين پال';
		$element->payment_description = '';
		$element->payment_images = '';

		$element->payment_params->invalid_status = 'cancelled';
		$element->payment_params->pending_status = 'created';
		$element->payment_params->verified_status = 'confirmed';
	}
}