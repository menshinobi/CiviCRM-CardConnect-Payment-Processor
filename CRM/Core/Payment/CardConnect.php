<?php

/*
 * Payment Processor class for CardConnect
 */
require_once(dirname(__FILE__) . '/../../../packages/CardConnectRestClient.php');
require_once "CRM/Core/DAO.php";

define('COMPLETED_CONTRIBUTION_STATUS_ID', 1);
define('PENDING_CONTRIBUTION_STATUS_ID', 2);
define('CANCELLED_CONTRIBUTION_STATUS_ID', 3);
define('FAILED_CONTRIBUTION_STATUS_ID', 4);
define('IN_PROGRESS_CONTRIBUTION_STATUS_ID', 5);

class CRM_Core_Payment_CardConnect extends CRM_Core_Payment
{

    /**
     * We only need one instance of this object. So we use the singleton
     * pattern and cache the instance in this variable
     *
     * @var object
     * @static
     */
    static private $_singleton = NULL;

    /**
     * Mode of operation: live or test.
     *
     * @var object
     */
    protected $_mode = NULL;

    /**
     * Constructor
     *
     * @param string $mode
     *   The mode of operation: live or test.
     *
     * @return void
     */
    function __construct($mode, &$paymentProcessor)
    {
        $this->_mode = $mode;
        $this->_islive = ($mode == 'live' ? 1 : 0);
        $this->_paymentProcessor = $paymentProcessor;
        $this->_processorName = ts('CardConnect');
    }

    /**
     * This function checks to see if we have the right config values.
     *
     * @return string
     *   The error message if any.
     *
     * @public
     */
    function checkConfig()
    {
        $config = CRM_Core_Config::singleton();
        $error = array();

        if (empty($this->_paymentProcessor['user_name'])) {
            $error[] = ts('The "Username" is not set in the CardConnect Payment Processor settings.');
        }

        if (empty($this->_paymentProcessor['password'])) {
            $error[] = ts('The "Password" is not set in the CardConnect Payment Processor settings.');
        }

        if (!empty($error)) {
            return implode('<p>', $error);
        } else {
            return NULL;
        }
    }

    /**
     * Function to handle various errors for CardConnect
     */
    function handleCardConnectError($error_type = 'no_response', $request, $params, $payment_response)
    {
        $error_url = $params['cardconnect_error_url'];
        switch ($error_type) {
            case 'no_response':
                $error_message = 'CardConnect does not respond';
                break;
            case 'declined':
                $error_message = 'CardConnect declines transaction. Response: ' . $payment_response['resptext'];
                break;
            case 'retry':
                $error_message = 'CardConnect failed transaction. Response: '. $payment_response['resptext'];
                break;
            case 'invalid':
                $error_message = 'You have invalid CVV or your Zipcode does not match. Please try again';
                break;
            case 'all':
                $error_message = 'Payment failed';
                break;

        }

        if (isset($error_url))
        {
            CRM_Core_Error::statusBounce("Oops!  Looks like there was an error.<br />{$error_message}", $error_url);
        } else {
            $core_err = CRM_Core_Error::singleton();
            $message = 'Oops!  Looks like there was an error. <br />' . $error_message;
            $core_err->push(9000, 0, NULL, $message);

            CRM_Core_Error::debug_log_message('CardConnect Error' . $error_message);
            return $core_err;
        }
    }

    /*
     * This function verifies if the contribution has AVS and CVV match and void the order if the
     * user sets so
     */
    function verifyOrder($payment_response)
    {
        $sql = 'SELECT * FROM civicrm_cardconnect_settings WHERE id = %1';
        $values = CRM_Core_DAO::executeQuery($sql, array(1 => array(1, 'Int')));
        while ($values->fetch())
        {
            if ($values->void_cvv == 1 && $payment_response['cvvresp'] === 'N')
            {
                return false;
            }

            if ($values->void_avs == 1 && $payment_response['avsresp'] === 'N')
            {
                return false;
            }
        }

        return true;
    }


    /**
     * Submit a payment using CardConnect REST Api
     *
     * @public
     */
    function doDirectPayment(&$params)
    {

        $api_credentials = array(
            'url' => $this->_paymentProcessor['url_site'] . ":6443/cardconnect/rest",
            'user' => $this->_paymentProcessor['user_name'],
            'pass' => $this->_paymentProcessor['password'],
            'mid' => $this->_paymentProcessor['signature'],
        );

        $payment_mode = 'C';

        $sql = 'SELECT * FROM civicrm_cardconnect_settings WHERE id = %1';
        $values = CRM_Core_DAO::executeQuery($sql, array(1 => array(1, 'Int')));
        while ($values->fetch())
        {
            $payment_mode = $values->payment_mode;
        }

        // Let a $0 transaction pass.
        if (empty($params['amount']) || $params['amount'] == 0) {
            return $params;
        }

        if (!(array_key_exists('qfKey', $params))) {
            $params['cardconnect_error_url'] = $error_url = null;
        } else {
            $qfKey = $params['qfKey'];
            $parsed_url = parse_url($params['entryURL']);
            $url_path = substr($parsed_url['path'], 1);
            $params['cardconnect_error_url'] = $error_url = CRM_Utils_System::url($url_path,
                $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
        }

        $cc_client = new CardConnectRestClient($api_credentials['url'], $api_credentials['user'], $api_credentials['pass']);

        $cs_client = new Pest($this->_paymentProcessor['url_site'] . ":6443");
        $cs_result = $cs_client->get("/cardsecure/cs?action=CE&data=" . $params['credit_card_number']);

        $cs_result_parts = explode("&", $cs_result);
        $data = explode("=", $cs_result_parts[1]);
        $card_details = $data[1];

        $cc_name = $params['first_name'] . " ";
        if (strlen($params['middle_name']) > 0) {
            $cc_name .= $params['middle_name'] . " ";
        }
        $cc_name .= $params['last_name'];

        $request = array(
            'merchid' => $api_credentials['mid'],
            'cvv2' => $params['cvv2'],
            'amount' => number_format($params['amount'], 2, '.', ''),
            'currency' => $params['currencyID'],
            'orderid' => CRM_Utils_Array::value('invoiceID', $params),
            'name' => $cc_name,
            'address' => $params['street_address'],
            'city' => $params['city'],
            'region' => $params['state_province'],
            'country' => $params['country'],
            'postal' => $params['postal_code'],
            'capture' => $payment_mode == 'C' ? 'Y' : '',
            'expiry' => date('my', strtotime($params['month'] . '/01/' . $params['year'])),
            'account' => $card_details
        );

        // Handle recurring payments in doRecurPayment().
        if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID'])
        {
            CRM_Core_DAO::setFieldValue(
                'CRM_Contribute_DAO_Contribution',
                $params['contributionID'],
                'contribution_status_id',
                PENDING_CONTRIBUTION_STATUS_ID
            );

            CRM_Core_DAO::setFieldValue(
                'CRM_Contribute_DAO_ContributionRecur',
                $params['contributionRecurID'],
                'processor_id',
                $params['month'].'/'.$params['year'].'_'.$card_details
            );

            CRM_Core_DAO::setFieldValue(
                'CRM_Contribute_DAO_ContributionRecur',
                $params['contributionRecurID'],
                'create_date',
                CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'))
            );
        } else {

            if (!is_null($cc_client)) {
                $payment_response = $cc_client->authorizeTransaction($request);
            } else {
                CRM_Core_Error::fatal(ts('There is a problem connecting to CardConnect server'));
            }

            if ((!$payment_response) || ($payment_response === '')) {
                return $this->handleCardConnectError('no_response', $request, $params, $payment_response);
            } elseif (($payment_response['respstat']) && ('A' === $payment_response['respstat'])) //if the payment is successful
            {
                $order_verification = $this->verifyOrder($payment_response);

                if (!$order_verification)
                {
                    return $this->handleCardConnectError('invalid', $request, $params, $payment_response);
                }

                $params['trxn_id'] = $payment_response['retref'];
                return $params;
            } elseif (($payment_response['respstat']) && ('C' === $payment_response['respstat'])) {
                return $this->handleCardConnectError('declined', $request, $params, $payment_response);
            } elseif (($payment_response['respstat']) && ('B' === $payment_response['respstat'])) {
                return $this->handleCardConnectError('retry', $request, $params, $payment_response);
            } else {
                return $this->handleCardConnectError('all', $request, $params, $payment_response);
            }
        }
    }

    function handlePaymentCron()
    {
        $payment_processor =  civicrm_api3('PaymentProcessor', 'get', array('id' => $this->_paymentProcessor['id']));
        $payment_processor = $payment_processor['values'][$this->_paymentProcessor['id']];

        $this->processPendingContributions($payment_processor);
        $this->processScheduledContributions($payment_processor);
    }

    /*
     * This function will process today contributions
     */
    public function processPendingContributions($payment_processor)
    {
        CRM_Core_Error::debug_log_message('processing pending contributions');

        $api_credentials = array(
            'url' => $payment_processor['url_site'] . ":6443/cardconnect/rest",
            'user' => $payment_processor['user_name'],
            'pass' => $payment_processor['password'],
            'mid' => $payment_processor['signature'],
        );

        $pending_contributions = $this->getPendingContributions($payment_processor);

        foreach ($pending_contributions as $pending_contribution) {
            if ($pending_contribution['contribution_recur']->contribution_status_id == CANCELLED_CONTRIBUTION_STATUS_ID) {
                continue;
            }

            $amount = number_format($pending_contribution['contribution']->total_amount, 2, '.', '');
            $parts = explode("_", $pending_contribution['contribution_recur']->processor_id);
            $token = $parts[1];
            $month_year = explode("/", $parts[0]);

            $request = array(
                'merchid' => $api_credentials['mid'],
                'amount' => $amount,
                'currency' => $pending_contribution['contribution']->currency,
                'orderid' => $pending_contribution['contribution']->invoice_id,
                'capture' => 'Y',
                'expiry' => date('my', strtotime($month_year[0] . '/01/' . $month_year[1])),
                'account' => $token,
                'ecomind' => 'R',
            );

            $cc_client = new CardConnectRestClient($api_credentials['url'], $api_credentials['user'], $api_credentials['pass']);

            if (!is_null($cc_client)) {
                $payment_response = $cc_client->authorizeTransaction($request);
            } else {
                CRM_Core_Error::fatal(ts('There is a problem connecting to CardConnect server'));
            }

            if (isset($payment_response['respstat']) && ($payment_response['respstat'] === 'A'))
            {
                $this->saveSuccessTransaction($pending_contribution['contribution']->id, $payment_response['retref'], COMPLETED_CONTRIBUTION_STATUS_ID);
            } else {
                $this->saveSuccessTransaction($pending_contribution['contribution']->id, null, FAILED_CONTRIBUTION_STATUS_ID);
                CRM_Core_Error::debug_log_message('Failed process pending transaction' . json_encode($payment_response));
            }

            $next_sched = date('Y-m-d 00:00:00', strtotime("+{$pending_contribution['contribution_recur']->frequency_interval} " . "{$pending_contribution['contribution_recur']->frequency_unit}s"));

            CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
                $pending_contribution['contribution_recur']->id,
                'next_sched_contribution_date',
                CRM_Utils_Date::isoToMysql($next_sched));

            CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur',
                $pending_contribution['contribution_recur']->id,
                'contribution_status_id',
                IN_PROGRESS_CONTRIBUTION_STATUS_ID);
        }
    }

    /*
     * This function will process today recurring payments
     */
    public function processScheduledContributions($payment_processor)
    {
        $api_credentials = array(
            'url' => $payment_processor['url_site'] . ":6443/cardconnect/rest",
            'user' => $payment_processor['user_name'],
            'pass' => $payment_processor['password'],
            'mid' => $payment_processor['signature'],
        );

        $scheduled_contributions = $this->getScheduledContributions($payment_processor);

        foreach ($scheduled_contributions as $contribution) {
            if ($contribution->payment_processor_id != $payment_processor['id'])
                continue;

            $next_sched = CRM_Core_DAO::getFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
                $contribution->id,
                'next_sched_contribution_date',
                'id',
                TRUE);

            if (strtotime($next_sched) > time()) {
                break;  // Assume parallel cron job called, exit loop.
            }

            $ccount = civicrm_api3('Contribution', 'getcount', array(
                'options' => array('limit' => 0),
                'contribution_recur_id' => $contribution->id,
            ));

            if (($contribution->installments <= 0) || ($contribution->installments > $ccount + 1)) {
                    $next_sched = date('Y-m-d 00:00:00', strtotime("+{$contribution->frequency_interval} {$contribution->frequency_unit}s"));
            } else {
                $next_sched = NULL;
                civicrm_api(
                    'ContributionRecur', 'create',
                    array(
                        'version' => '3',
                        'id' => $contribution->id,
                        'contribution_recur_status_id' => COMPLETED_CONTRIBUTION_STATUS_ID)
                );
            }

            $addresses = civicrm_api('Address', 'get',
                array('version' => '3',
                    'contact_id' => $contribution->contact_id));

            $billing_address = array_shift($addresses['values']);

            $invoice_id = md5(uniqid(rand(), TRUE));

            if($next_sched){
                CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
                    $contribution->id,
                    'next_sched_contribution_date',
                    CRM_Utils_Date::isoToMysql ($next_sched) );
            } else {
                CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
                    $contribution->id,
                    'contribution_status_id',
                    COMPLETED_CONTRIBUTION_STATUS_ID);
                CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur',
                    $contribution->id,
                    'end_date',
                    CRM_Utils_Date::isoToMysql (date('Y-m-d 00:00:00', time())) );
            }

            $parts = explode("_", $contribution->processor_id);
            $token = $parts[1];
            $month_year = explode("/", $parts[0]);

            $request = array(
                'merchid' => $api_credentials['mid'],
                'amount' => number_format($contribution->amount, 2, '.', ''),
                'currency' => strtoupper($contribution->currency),
                'orderid' => $invoice_id,
                'capture' => 'Y',
                'expiry' => date('my', strtotime($month_year[0] . '/01/' . $month_year[1])),
                'account' => $token,
                'ecomind' => 'R',
            );

            $new_contribution_record = array();
            $new_contribution_record['contact_id'] = $contribution->contact_id;
            $new_contribution_record['receive_date'] = CRM_Utils_Date::isoToMysql(date('Y-m-d H:i:s'));
            $new_contribution_record['total_amount'] = $contribution->amount;
            $new_contribution_record['contribution_recur_id'] = $contribution->id;
            $new_contribution_record['payment_instrument_id'] = $contribution->payment_instrument_id;
            $new_contribution_record['address_id'] = $billing_address['id'];
            $new_contribution_record['invoice_id'] = $invoice_id;
            $new_contribution_record['campaign_id'] = $contribution->campaign_id;
            $new_contribution_record['payment_processor'] = $contribution->payment_processor_id;

            $contributions = civicrm_api3(
                'Contribution', 'get', array(
                    'sequential' => 1,
                    'contribution_recur_id' => $contribution->id,
                    'options' => array('sort' => "id ASC"),
                )
            );

            $sample_contribution = array_shift($contributions['values']);

            if(empty($sample_contribution)) {
                $sample_contribution = array ('contribution_source' => '', 'is_test' => 0);
            }

            $new_contribution_record['source'] = "CardConnect Recurring Payment";
            $new_contribution_record['contribution_page_id'] = isset($sample_contribution['contribution_page_id']) ? $sample_contribution['contribution_page_id'] : 0;
            $new_contribution_record['is_test'] = $sample_contribution['is_test'];

            $cc_client = new CardConnectRestClient($api_credentials['url'], $api_credentials['user'], $api_credentials['pass']);

            if (!is_null($cc_client)) {
                $payment_response = $cc_client->authorizeTransaction($request);
            } else {
                CRM_Core_Error::debug_log_message('There is an error connecting to CardConnect when processing contribution' . $contribution->id);
                $new_contribution_record['contribution_status_id'] = FAILED_CONTRIBUTION_STATUS_ID;
                $created = civicrm_api3('Contribution', 'create', $new_contribution_record);
                $new_contribution_record = reset($created['values']);
                $this->createContributitionNote('There is an error connecting to CardConnect when processing contribution' . $contribution->id);
            }

            if ((!$payment_response) || ($payment_response === '')) {
                CRM_Core_Error::debug_log_message('Empty CardConnect Response when processing contribution ' . $contribution->id);
            } elseif (($payment_response['respstat']) && ('A' === $payment_response['respstat'])) //if the payment is successful
            {

                $transaction_id = $payment_response['retref'];
                $new_contribution_record['trxn_id'] = $transaction_id;
                $created = civicrm_api3('Contribution', 'create', $new_contribution_record);
                $new_contribution_record = reset($created['values']);
            } else {
                CRM_Core_Error::debug_log_message('CardConnect returns error when processing contribution ' . $contribution->id . '. Error ' . $payment_response['respstat']);

                $new_contribution_record['contribution_status_id'] = FAILED_CONTRIBUTION_STATUS_ID;
                $created = civicrm_api3('Contribution', 'create', $new_contribution_record);
                $new_contribution_record = reset($created['values']);
                $this->createContributitionNote($contribution, $new_contribution_record, 'CardConnect returns error when processing contribution ' . $contribution->id . '. Error ' . $payment_response['respstat']);
            }
        }
    }

    /*
     * Function to get all of the pending contributions
     */
    function getPendingContributions($payment_processor)
    {
        $result = array();

        $pending_contributions = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_contribution WHERE contribution_status_id = 2');
        while ($pending_contributions->fetch())
        {
            if ($pending_contributions->contribution_recur_id) {
                $recurring = new CRM_Contribute_BAO_ContributionRecur();
                $recurring->id = $pending_contributions->contribution_recur_id;

                if ($recurring->find(true) && $recurring->processor_id
                    && ($recurring->payment_processor_id == $payment_processor['id'])) {
                    $result[$pending_contributions->contribution_recur_id] = array(
                        'contribution' => $pending_contributions,
                        'contribution_recur' => $recurring
                    );
                }
            }
        }
        return $result;
    }

    /*
     * Function to get all of the scheduled contributions
     */
    function getScheduledContributions($payment_processor)
    {
        $scheduled_contributions = CRM_Core_DAO::executeQuery('SELECT * FROM civicrm_contribution_recur WHERE next_sched_contribution_date <= NOW() AND contribution_status_id IN (2,5)');

        $result = array();

        while ($scheduled_contributions->fetch()) {
            if($scheduled_contributions->payment_processor_id != $payment_processor['id']){
                continue;
            }

            $contribution = new CRM_Contribute_BAO_Contribution();
            $contribution->contribution_recur_id = $scheduled_contributions->id;
            $contribution->whereAdd
            ("`receive_date` >= '{$scheduled_contributions->next_sched_contribution_date}'");

            if (!($contribution->find())) {
                $result[] = clone $scheduled_contributions;
            }
        }

        return $result;
    }


    /*
     * Save success recurring transaction into CiviCRM
     */
    function saveSuccessTransaction($contribution_id, $transaction_id = null, $status_id = null, $transaction_date = null)
    {
        $params = array(
            'id' => $contribution_id,
            'contribution_status_id' => $status_id,
        );

        if (empty($transaction_date))
        {
            $transaction_date = time();
        }

        if ($status_id == CANCELLED_CONTRIBUTION_STATUS_ID)
        {
            $params['cancel_date'] = date('Y-m-d H:i:s', $transaction_date);
        } else {
            $params['receive_date'] = date('Y-m-d H:i:s', $transaction_date);
        }

        if (!empty($transaction_id))
        {
            $params['trxn_id'] = $transaction_id;
        }

        try {
            $result= civicrm_api3('Contribution', 'create', $params);
            return $result['is_error'];
        } catch (CiviCRM_API3_Exception $e)
        {
            CRM_Core_Error::debug_log_message($e->getMessage());
        }
    }

    /*
     * Save a note to a contribution if an error occurs
     */
    public function createContributitionNote($contribution, $new_contribution_record, $error_message)
    {
        $note = new CRM_Core_BAO_Note();

        $note->entity_table = 'civicrm_contribution';
        $note->contact_id = $contribution->contact_id;
        $note->entity_id = $new_contribution_record['id'];
        $note->subject = ts('Transaction Error');
        $note->note = $error_message;

        $note->save();
    }
}
