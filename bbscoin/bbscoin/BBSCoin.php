<?php
/**********************************************************************************
* BBSCoin: a new cryptocurrency built for forums                                  *
***********************************************************************************
* This program is free software; you may redistribute it and/or modify it under   *
* the terms of the provided license as published by Simple Machines LLC.          *
*                                                                                 *
* This program is distributed in the hope that it is and will be useful, but      *
* WITHOUT ANY WARRANTIES; without even any implied warranty of MERCHANTABILITY    *
* or FITNESS FOR A PARTICULAR PURPOSE.                                            *
*                                                                                 *
* See the "license.txt" file for details of the Simple Machines license.          *
* The latest version of the license can always be found at                        *
* http://www.simplemachines.org.                                                  *
**********************************************************************************/

// If file is not called by SMF, don't let them get anywhere!
if (!defined('SMF'))
	die('Hacking attempt...');

function BBSCoin()
{
	global $context, $modSettings, $scripturl, $smcFunc;
	global $txt, $item_info, $boardurl, $sourcedir, $user_info;
	
	// Various things we need
	include_once($sourcedir . '/Subs-Post.php');       // Sending PM's 
	include_once($sourcedir . '/Subs-Auth.php');       // 'Find Members' stuff
	
	// During testing, caching was causing many problems. So, we try to disable the caching here.
	header("Expires: Fri, 1 Jun 1990 00:00:00 GMT"); // My birthday ;)
	header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
	header("Pragma: no-cache");

	loadLanguage('BBSCoin');
	loadTemplate('BBSCoin');
	is_not_guest($txt['bbscoin_guest_message']);
	isAllowedTo('bbscoin_main');

    if (!$modSettings['bbscoinWalletAddress']) {
        fatal_error($txt['bbscoin_no_address']);
    }

    if ($_GET['do'] == "deposit") 
    {
        checkSubmitOnce('check');

        $amount = $_POST['amount'];

        if($amount < 1) {
        	fatal_error($txt['bbscoin_least']);
        }

		$result = $smcFunc['db_query']('', "
			SELECT * FROM {db_prefix}bbscoin_locks
			WHERE uid = {int:id}
			LIMIT 1",
			array(
				'id' => $context['user']['id'],
				));
        $lockinfo = $smcFunc['db_fetch_assoc']($result);
    	if ($lockinfo) {
            if (time() - $lockinfo['dateline'] > 10) {
        		$smcFunc['db_query']('', "
        			DELETE FROM {db_prefix}bbscoin_locks
        			WHERE uid = {int:id}",
        			array(
        				'id' => $context['user']['id'],
        				));
            }
            fatal_error($txt['bbscoin_cc']);
    	} else {
        	$smcFunc['db_insert']('insert', '{db_prefix}bbscoin_locks',
        		array(
        			'uid' => 'int',
        			'dateline' => 'int',
        			),
        		array(
        			'variable' => $context['user']['id'],
        			'value' => time(),
        			),
        		array()
        	);
        }

        $orderid = date('YmdHis').rand(100,999);
        $transaction_hash = trim($_POST['transaction_hash']);

		$result = $smcFunc['db_query']('', "
			SELECT * FROM {db_prefix}bbscoin_orders
			WHERE transaction_hash = {string:transaction_hash}
			LIMIT 1",
			array(
				'transaction_hash' => $transaction_hash,
				));
        $db_assoc = $smcFunc['db_fetch_assoc']($result);
    	if ($db_assoc) {
    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));
        	fatal_error($txt['bbscoin_used']);
    	}

        $need_bbscoin = ceil((($amount / $modSettings['bbscoinPayRatio']) * 100)) / 100;

        $orderinfo = array(
        	'uid' => $context['user']['id'],
        	'amount' => $amount,
        	'price' => $need_bbscoin,
        );

        $rsp_data = BBSCoinApi::getTransaction($modSettings['bbscoinWalletd'], $transaction_hash); 
        $status_rsp_data = BBSCoinApi::getStatus($modSettings['bbscoinWalletd']); 

        $blockCount = $status_rsp_data['result']['blockCount'];
        $transactionBlockIndex = $rsp_data['result']['transaction']['blockIndex'];
        $confirmed = $blockCount - $transactionBlockIndex + 1;
        if ($blockCount <= 0 || $transactionBlockIndex <= 0 || $confirmed <= $modSettings['bbscoinConfirmedBlocks']) {
    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));
        	fatal_error(sprintf($txt['bbscoin_notconfirmed'], $modSettings['bbscoinConfirmedBlocks']));
        }

        $trans_amount = 0;
        if ($rsp_data['result']['transaction']['transfers']) {
            foreach ($rsp_data['result']['transaction']['transfers'] as $transfer_item) {
                if ($transfer_item['address'] == $modSettings['bbscoinWalletAddress']) {
                    $trans_amount += $transfer_item['amount'];
                }
            }
        }

        $trans_amount = $trans_amount / 100000000;
        if ($trans_amount == $need_bbscoin) {
        	$smcFunc['db_insert']('insert', '{db_prefix}bbscoin_orders',
        		array(
        			'orderid' => 'int',
        			'transaction_hash' => 'string',
        			'address' => 'string',
        			'dateline' => 'int',
        			),
        		array(
        			'orderid' => $orderid,
        			'transaction_hash' => $transaction_hash,
        			'address' => '',
        			'dateline' => time(),
        			),
        		array()
        	);

			$smcFunc['db_query']('', "
				UPDATE {db_prefix}members
				SET money = money + {int:amount}
				WHERE id_member = {int:id}
				LIMIT 1",
				array(
					'amount' => $_POST['amount'],
					'id' => $context['user']['id'],
					));

            log_error('Deposit From BBSCoin', 'Points:'.$orderinfo['amount'].', BBSCoin: '.$need_bbscoin.', transaction_hash:'.$transaction_hash);

    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));

        	$context['template_layers'][] = 'bbscoin';
        	$context['page_title'] = $txt['bbscoin_usercp_nav_name'];
            $context['sub_template'] = 'message';
        	$context['bbscoin_message'] = $txt['bbscoin_succ'];
        } else {
    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));

            fatal_error($txt['bbscoin_amount_error']);
        }
    } elseif ($_GET['do'] == "withdraw") 
    {
        checkSubmitOnce('check');

        if(!$modSettings['bbscoinPayToBbscoin']) {
        	fatal_error($txt['bbscoin_close_withdraw']);
        }

        $amount = $_POST['amount'];
        $need_point = ceil((($amount / $modSettings['bbscoinPayToCoinRatio']) * 100)) / 100;

        if ($need_point < 1) {
        	fatal_error($txt['bbscoin_least']);
        }

        $walletaddress = trim($_POST['walletaddress']);

        if ($modSettings['bbscoinWalletAddress'] == $walletaddress) {
            fatal_error($txt['bbscoin_withdraw_error']);
        }

        $real_price = $amount * 100000000 - 50000000;

        if ($real_price <= 0) {
            fatal_error($txt['bbscoin_withdraw_too_low']);
        }

		$result = $smcFunc['db_query']('', "
			SELECT * FROM {db_prefix}bbscoin_locks
			WHERE uid = {int:id}
			LIMIT 1",
			array(
				'id' => $context['user']['id'],
				));
        $lockinfo = $smcFunc['db_fetch_assoc']($result);
    	if ($lockinfo) {
            if (time() - $lockinfo['dateline'] > 10) {
        		$smcFunc['db_query']('', "
        			DELETE FROM {db_prefix}bbscoin_locks
        			WHERE uid = {int:id}",
        			array(
        				'id' => $context['user']['id'],
        				));
            }
            fatal_error($txt['bbscoin_cc']);
    	} else {
        	$smcFunc['db_insert']('insert', '{db_prefix}bbscoin_locks',
        		array(
        			'uid' => 'int',
        			'dateline' => 'int',
        			),
        		array(
        			'variable' => $context['user']['id'],
        			'value' => time(),
        			),
        		array()
        	);
        }

        if ($need_point > $context['user']['money']) {
    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));
        	fatal_error($txt['bbscoin_no_enough']);
        }

        $orderid = date('YmdHis').rand(100,999);

        $rsp_data = BBSCoinApi::sendTransaction($modSettings['bbscoinWalletd'], $modSettings['bbscoinWalletAddress'], $real_price, $walletaddress);

        $trans_amount = 0;
        if ($rsp_data['result']['transactionHash']) {
        	$smcFunc['db_insert']('insert', '{db_prefix}bbscoin_orders',
        		array(
        			'orderid' => 'int',
        			'transaction_hash' => 'string',
        			'address' => 'string',
        			'dateline' => 'int',
        			),
        		array(
        			'orderid' => $orderid,
        			'transaction_hash' => $rsp_data['result']['transactionHash'],
        			'address' => $walletaddress,
        			'dateline' => time(),
        			),
        		array()
        	);

			$smcFunc['db_query']('', "
				UPDATE {db_prefix}members
				SET money = money - {int:amount}
				WHERE id_member = {int:id}
				LIMIT 1",
				array(
					'amount' => $need_point,
					'id' => $context['user']['id'],
					));

    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));

        	$context['template_layers'][] = 'bbscoin';
        	$context['page_title'] = $txt['bbscoin_usercp_nav_name'];
            $context['sub_template'] = 'message';
        	$context['bbscoin_message'] = sprintf($txt['bbscoin_withdraw_succ'], $rsp_data['result']['transactionHash']);

            log_error('Withdraw To BBSCoin', 'Points:'.$need_point.', BBSCoin:'.$amount.', address:'.$walletaddress);
        } else {
    		$smcFunc['db_query']('', "
    			DELETE FROM {db_prefix}bbscoin_locks
    			WHERE uid = {int:id}",
    			array(
    				'id' => $context['user']['id'],
    				));

            fatal_error($txt['bbscoin_fail']);
        }

    } else {
    	checkSubmitOnce('register');
    	
    	$context['template_layers'][] = 'bbscoin';

    	// Set the page title
    	$context['page_title'] = $txt['bbscoin_usercp_nav_name'];
    	// Main template for the main page :)	
    	$context['sub_template'] = 'main';
    }
}
function bbsCoinFormatMoney($money)
{
	global $modSettings;

	// Cast to float
	$money = (float) $money;
	// Return amount with prefix and suffix added
	return $modSettings['shopCurrencyPrefix'] . $money . $modSettings['shopCurrencySuffix'];
}

class BBSCoinApi {

    public static function getUrlContent($url, $data_string) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BBSCoin');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $data = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $data;
    }

    public static function sendTransaction($walletd, $address, $real_price, $sendto) {
        $req_data = array(
          'params' => array(
              'anonymity' => 5,
              'fee' => 50000000,
              'unlockTime' => 0,
              'changeAddress' => $address,
              "transfers" => array(
               0 => array(
                    'amount' => $real_price,
                    'address' => $sendto,
                )
              )
          ),
          "jsonrpc" => "2.0",
          "method" => "sendTransaction"
        );

        $result = self::getUrlContent($walletd, json_encode($req_data)); 
        $rsp_data = json_decode($result, true);
        
        return $rsp_data;
    }

    public static function getStatus($walletd) {
        $status_req_data = array(
          "jsonrpc" => "2.0",
          "method" => "getStatus"
        );

        $result = self::getUrlContent($walletd, json_encode($status_req_data)); 
        $status_rsp_data = json_decode($result, true);
        return $status_rsp_data;
    }

    public static function getTransaction($walletd, $transaction_hash) {
        $req_data = array(
          "params" => array(
          	"transactionHash" => $transaction_hash
          ),
          "jsonrpc" => "2.0",
          "method" => "getTransaction"
        );

        $result = self::getUrlContent($walletd, json_encode($req_data)); 
        $rsp_data = json_decode($result, true);

        return $rsp_data;
    }

}


