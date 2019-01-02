<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_j2store
 * @subpackage 	j2stor_Payping
 * @copyright   Erfan Ebrahimi => http://erfanebrahimi.ir
 * @copyright   Copyright (C) 20019 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */


defined('_JEXEC') or die('Restricted access');

require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/plugins/payment.php');

class plgJ2StorePayment_payping extends J2StorePaymentPlugin
{
    var $_element    = 'payment_payping';

	function __construct(& $subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage( 'com_j2store', JPATH_ADMINISTRATOR );
	}


	function onJ2StoreCalculateFees($order) {
		$payment_method = $order->get_payment_method ();

		if ($payment_method == $this->_element) {
			$total = $order->order_subtotal + $order->order_shipping + $order->order_shipping_tax;
			$surcharge = 0;
			$surcharge_percent = $this->params->get ( 'surcharge_percent', 0 );
			$surcharge_fixed = $this->params->get ( 'surcharge_fixed', 0 );
			if (( float ) $surcharge_percent > 0 || ( float ) $surcharge_fixed > 0) {
				// percentage
				if (( float ) $surcharge_percent > 0) {
					$surcharge += ($total * ( float ) $surcharge_percent) / 100;
				}

				if (( float ) $surcharge_fixed > 0) {
					$surcharge += ( float ) $surcharge_fixed;
				}

				$name = $this->params->get ( 'surcharge_name', JText::_ ( 'J2STORE_CART_SURCHARGE' ) );
				$tax_class_id = $this->params->get ( 'surcharge_tax_class_id', '' );
				$taxable = false;
				if ($tax_class_id && $tax_class_id > 0)
					$taxable = true;
				if ($surcharge > 0) {
					$order->add_fee ( $name, round ( $surcharge, 2 ), $taxable, $tax_class_id );
				}
			}
		}
	}

    function _prePayment( $data )
    {
		$app	= JFactory::getApplication();
        $vars = new JObject();
        $vars->order_id = $data['order_id'];
        $vars->orderpayment_id = $data['orderpayment_id'];
        $vars->orderpayment_amount = $data['orderpayment_amount'];
        $vars->orderpayment_type = $this->_element;
        $vars->button_text = $this->params->get('button_text', 'J2STORE_PLACE_ORDER');
		$vars->display_name = 'payping';
		$vars->token = $this->params->get('token', '');
		$vars->currency = $this->params->get('currency', '');
		if ($vars->merchant_id == null || $vars->merchant_id == ''){
			$link = JRoute::_(JURI::root(). "index.php?option=com_j2store" );
			$app->redirect($link, '<h2>لطفا تنظیمات درگاه پی‌پینگ ویژه j2store را بررسی کنید</h2>', $msgType='Error');
		}
		else{
			$Amount = floor(round($vars->orderpayment_amount,0)/ $vars->currency ); // Toman
			$Description = 'خرید محصول از فروشگاه : '.$data['order_id'] ;
			$CallbackURL = JRoute::_(JURI::root(). "index.php?option=com_j2store&view=checkout" ) .'&orderpayment_id='.$vars->orderpayment_id . '&orderpayment_type=' . $vars->orderpayment_type .'&task=confirmPayment' ;
			$dataSend = array('payerName'=>'', 'Amount' => $Amount,'payerIdentity'=> '' , 'returnUrl' => $CallbackURL, 'Description' => $Description , 'clientRefId' => $vars->orderpayment_id  );
			try {

					$curl = curl_init();
					curl_setopt_array($curl, array(
							CURLOPT_URL => "https://api.payping.ir/v1/pay",
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_ENCODING => "",
							CURLOPT_MAXREDIRS => 10,
							CURLOPT_TIMEOUT => 30,
							CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
							CURLOPT_CUSTOMREQUEST => "POST",
							CURLOPT_POSTFIELDS => json_encode($dataSend),
							CURLOPT_HTTPHEADER => array(
								"accept: application/json",
								"authorization: Bearer " .$vars->token,
								"cache-control: no-cache",
								"content-type: application/json"),
						)
					);
					$response = curl_exec($curl);
					$header = curl_getinfo($curl);
					$err = curl_error($curl);
					curl_close($curl);
					if ($err) {
						echo "cURL Error #:" . $err;
					} else {
						if ($header['http_code'] == 200) {
							$response = json_decode($response, true);
							if (isset($response["code"]) and $response["code"] != '') {
								$vars->paypingGoUrl =  sprintf('https://api.payping.ir/v1/pay/gotoipg/%s', $response["code"]) ;
								$html = $this->_getLayout('prepayment', $vars);
								return $html;
							} else {
								$link = JRoute::_( "index.php?option=com_j2store" );
								$app->redirect($link, '<h2> تراکنش ناموفق بود- شرح خطا : عدم وجود کد ارجاع</h2>', $msgType='Error');
							}
						} elseif ($header['http_code'] == 400) {
							$link = JRoute::_( "index.php?option=com_j2store" );
							$app->redirect($link, '<h2> تراکنش ناموفق بود- شرح خطا : '.implode('. ',array_values (json_decode($response,true))).'</h2>', $msgType='Error');
						} else {
							$link = JRoute::_( "index.php?option=com_j2store" );
							$app->redirect($link, '<h2> تراکنش ناموفق بود- شرح خطا : '.$this->getGateMsg($header['http_code']). '(' . $header['http_code'] . ')'.'</h2>', $msgType='Error');
						}
					}
			} catch (Exception $e) {
				$link = JRoute::_("index.php?option=com_j2store");
				$app->redirect($link, '<h2> تراکنش ناموفق بود- شرح خطا سمت برنامه شما : ' . $e->getMessage() .'</h2>', $msgType = 'Error');
			}
		}
    }

	
	function _postPayment($data) {
		$app = JFactory::getApplication(); 
		$jinput = $app->input;
        $html = '';
		$orderpayment_id = $jinput->get->get('clientrefid', '0', 'INT');
        F0FTable::addIncludePath ( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
		$orderpayment = F0FTable::getInstance ( 'Order', 'J2StoreTable' )->getClone ();
	    //$this->getShippingAddress()->phone_2; //mobile
		//==========================================================================
		$refid = $jinput->get->get('refid', '', 'STRING') ;
		if ($orderpayment->load ($orderpayment_id)){
			$customer_note = $orderpayment->customer_note;
			if($orderpayment->j2store_order_id == $orderpayment_id) {
				$dataSend = array('refId' => $refid, 'amount' => floor(round($orderpayment->order_total,0)/$this->params->get('currency', '') ) );
				try {
					$curl = curl_init();
					curl_setopt_array($curl, array(
						CURLOPT_URL => "https://api.payping.ir/v1/pay/verify",
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_ENCODING => "",
						CURLOPT_MAXREDIRS => 10,
						CURLOPT_TIMEOUT => 30,
						CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
						CURLOPT_CUSTOMREQUEST => "POST",
						CURLOPT_POSTFIELDS => json_encode($dataSend),
						CURLOPT_HTTPHEADER => array(
							"accept: application/json",
							"authorization: Bearer ".$this->params->get('token', ''),
							"cache-control: no-cache",
							"content-type: application/json",
						),
					));
					$response = curl_exec($curl);
					$err = curl_error($curl);
					$header = curl_getinfo($curl);
					curl_close($curl);
					if ($err) {
						$msg= 'خطا در ارتباط به پی‌پینگ : شرح خطا '.$err ;
						$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);// error
						$link = JRoute::_( "index.php?option=com_j2store" );
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
					} else {
						if ($header['http_code'] == 200) {
							$response = json_decode($response, true);
							if (isset($_GET["refid"]) and $_GET["refid"] != '') {
								$msg= $this->getGateMsg($header['http_code']);
								$this->saveStatus($msg,1,$customer_note,'ok',$_GET["refid"],$orderpayment);
								$app->enqueueMessage($_GET["refid"] . ' کد پیگیری شما', 'message');
							} else {
								$msg= 'متافسانه سامانه قادر به دریافت کد پیگیری نمی باشد! نتیجه درخواست : ' . $this->getGateMsg($header['http_code']);
								$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);// error
								$link = JRoute::_( "index.php?option=com_j2store" );
								$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
							}
						} elseif ($header['http_code'] == 400) {
							$msg= 'تراکنش ناموفق بود- شرح خطا : ' .  implode('. ',array_values (json_decode($response,true))) ;
							$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);// error
							$link = JRoute::_( "index.php?option=com_j2store" );
							$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
						}  else {
							$msg= ' تراکنش ناموفق بود- شرح خطا : ' . $this->getGateMsg($header['http_code']);
							$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);// error
							$link = JRoute::_( "index.php?option=com_j2store" );
							$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
						}
					}
				} catch (Exception $e){
					$msg= ' تراکنش ناموفق بود- شرح خطا سمت برنامه شما : ' . $e->getMessage();
					$this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);// error
					$link = JRoute::_( "index.php?option=com_j2store" );
					$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
				}
			}
			else {
				$msg= $this->getGateMsg('notff'); 
				$link = JRoute::_( "index.php?option=com_j2store" );
				$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
			}
	    }
		else {
			$msg= $this->getGateMsg('notff'); 
			$link = JRoute::_( "index.php?option=com_j2store" );
			$app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error'); 
		}
	}

    function _renderForm( $data )
    {
    	$user = JFactory::getUser();
        $vars = new JObject();
        $vars->onselection_text = $this->params->get('onselection', '');
        $html = $this->_getLayout('form', $vars);
        return $html;
    }

	function getPaymentStatus($payment_status) {
    	$status = '';
    	switch($payment_status) {
			case '1': $status = JText::_('J2STORE_CONFIRMED'); break;
			case '2': $status = JText::_('J2STORE_PROCESSED'); break;
			case '3': $status = JText::_('J2STORE_FAILED'); break;
			case '4': $status = JText::_('J2STORE_PENDING'); break;
			case '5': $status = JText::_('J2STORE_INCOMPLETE'); break;
			default: $status = JText::_('J2STORE_PENDING'); break;	
    	}
    	return $status;
    }

	function saveStatus($msg,$statCode,$customer_note,$emptyCart,$trackingCode,$orderpayment){
		$html ='<br />';
		$html .='<strong>پی‌پینگ</strong>';
		$html .='<br />';
		if (isset($trackingCode)){
			$html .= '<br />';
			$html .= $trackingCode .'شماره پیگری ';
			$html .= '<br />';
		}
		$html .='<br />' . $msg;
		$orderpayment->customer_note =$customer_note.$html;
		$payment_status = $this->getPaymentStatus($statCode); 
		$orderpayment->transaction_status = $payment_status;
		$orderpayment->order_state = $payment_status;
		$orderpayment->order_state_id = $this->params->get('payment_status', $statCode); 
		
		if ($orderpayment->store()) {
			if ($emptyCart == 'ok'){
				$orderpayment->payment_complete ();
				$orderpayment->empty_cart();
			}
		}
		else
		{
			$errors[] = $orderpayment->getError();
		}
	
 		$vars = new JObject();
		$vars->onafterpayment_text = $msg;
		$html = $this->_getLayout('postpayment', $vars);
		$html .= $this->_displayArticle();
		return $html;
	}

    function getGateMsg ($msgId) {
		switch($msgId){
			case 200 :
				return 'عملیات با موفقیت انجام شد';
				break ;
			case 400 :
				return 'مشکلی در ارسال درخواست وجود دارد';
				break ;
			case 500 :
				return 'مشکلی در سرور رخ داده است';
				break;
			case 503 :
				return 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد';
				break;
			case 401 :
				return 'عدم دسترسی';
				break;
			case 403 :
				return 'دسترسی غیر مجاز';
				break;
			case 404 :
				return 'آیتم درخواستی مورد نظر موجود نمی‌باشد';
				break;
			case	'1':
			case	'error': $out ='خطا غیر منتظره رخ داده است';break;
			case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
			case	'notff': $out = 'سفارش پیدا نشد';break;
			default: $out ='خطا غیر منتظره رخ داده است';break;
		}
		return $out;
	}
	function getShippingAddress() {

		$user =	JFactory::getUser();
		$db = JFactory::getDBO();

		$query = "SELECT * FROM #__j2store_addresses WHERE user_id={$user->id}";
		$db->setQuery($query);
		return $db->loadObject();

	 }

}
