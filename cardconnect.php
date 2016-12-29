<?php

require_once 'cardconnect.civix.php';
require_once "CRM/Core/DAO.php";

function cardconnect_civicrm_buildForm($formName, &$form)
{
  if ($formName == 'CRM_Admin_Form_PaymentProcessor') {
    $form->addElement('select', 'payment_mode', ts('Payment Mode'));
    $payment_mode = $form->getElement('payment_mode');
    $payment_mode->addOption('Capture', 'C');
    $payment_mode->addOption('Authorization','A');

    //$form->addCheckbox('void_avs', ts('Void on AVS failure'), array(0, 1));
    //$form->addCheckbox('void_cvv', ts('Void on CVV failure'), array(0, 1));

    $form->addElement('checkbox', 'void_avs', ts('Void on AVS failure'));
    $form->addElement('checkbox', 'void_cvv', ts('Void on CVV failure'));

    $sql = 'SELECT * FROM civicrm_cardconnect_settings WHERE id = %1';
    $values = CRM_Core_DAO::executeQuery($sql, array(1 => array(1, 'Int')));
    while ($values->fetch())
    {
      $form->setDefaults(array('void_avs' => $values->void_on_avs, 'void_cvv' => $values->void_on_cvv, 'payment_mode' => $values->payment_mode));
    }

  } else if ($formName == 'CRM_Contribute_Form_ContributionPage_Amount') {

    $form->addElement('checkbox', 'void_avs', ts('Void on AVS failure'));
    $form->addElement('checkbox', 'void_cvv', ts('Void on CVV failure'));

    $sql = 'SELECT * FROM civicrm_cardconnect_settings WHERE id = %1';
    $values = CRM_Core_DAO::executeQuery($sql, array(1 => array(1, 'Int')));
    while ($values->fetch())
    {
      $form->setDefaults(array('void_avs' => $values->void_on_avs, 'void_cvv' => $values->void_on_cvv));
    }
  }
}

function cardconnect_civicrm_postProcess ($formName, &$form) {
  if ($formName == 'CRM_Admin_Form_PaymentProcessor') {

    $accepted_cards = json_encode(array_keys($form->getSubmitValue('accept_credit_cards')));
    $void_avs = $form->getSubmitValue('void_avs');
    $void_cvv = $form->getSubmitValue('void_cvv');

    if (!isset($void_avs) || empty($void_avs))
      $void_avs = 0;
    if (!isset($void_cvv) || empty($void_cvv))
      $void_cvv = 0;

    $payment_mode = $form->getSubmitValue('payment_mode');

    $sql = "UPDATE `civicrm_cardconnect_settings` SET payment_mode = '{$payment_mode}', accepted_cards = '{$accepted_cards}', void_on_avs = {$void_avs}, void_on_cvv = {$void_cvv} WHERE id = 1";

    CRM_Core_DAO::executeQuery($sql);

  }
}

/**
 * Implementation of hook_civicrm_config().
 */
function cardconnect_civicrm_config(&$config) {
  _cardconnect_civix_civicrm_config($config);
  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR;
  $include_path = $extRoot . PATH_SEPARATOR . get_include_path( );
  set_include_path( $include_path );
}

/**
 * Implementation of hook_civicrm_xmlMenu().
 *
 * @param $files array(string)
 */
function cardconnect_civicrm_xmlMenu(&$files) {
  _cardconnect_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install().
 */
function cardconnect_civicrm_install() {
  require_once "CRM/Core/DAO.php";
  CRM_Core_DAO::executeQuery("
  CREATE TABLE IF NOT EXISTS `civicrm_cardconnect_settings` (
    `id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
    `payment_mode` varchar(2) DEFAULT NULL,
    `accepted_cards` varchar(255) DEFAULT NULL,
    `void_on_avs` tinyint(1) DEFAULT NULL,
    `void_on_cvv` tinyint(1) DEFAULT NULL,
    UNIQUE KEY `id` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
  ");

  CRM_Core_DAO::executeQuery("INSERT INTO `civicrm_cardconnect_settings` (`id`,`payment_mode`,`void_on_avs`,`void_on_cvv`) VALUES (1, 'C', 0, 0);");

  return _cardconnect_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall().
 */
function cardconnect_civicrm_uninstall() {
  CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS `civicrm_cardconnect_settings`");
  return _cardconnect_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable().
 */
function cardconnect_civicrm_enable() {
  return _cardconnect_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable().
 */
function cardconnect_civicrm_disable() {
  return _cardconnect_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function cardconnect_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _cardconnect_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_validateForm().
 *
 * Prevent server validation of cc fields
 *
 * @param $formName - the name of the form
 * @param $fields - Array of name value pairs for all 'POST'ed form values
 * @param $files - Array of file properties as sent by PHP POST protocol
 * @param $form - reference to the form object
 * @param $errors - Reference to the errors array.
 */
function cardconnect_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if (empty($form->_paymentProcessor['payment_processor_type'])) {
    return;
  }
}

/**
 * Return the CardConnect api public key (aka password)
 *
 * If this form could conceiveably now or at any time in the future
 * contain a CardConnect payment processor, return the api public key for
 * that processor.
 */
function cardconnect_get_key($form) {
  if (empty($form->_paymentProcessor)) {
    return;
  }
  // Only return first value if CardConnect is the only/default.
  if ($form->_paymentProcessor['payment_processor_type'] == 'CardConnect') {
    if (isset($form->_paymentProcessor['password'])) {
      return $form->_paymentProcessor['password'];
    }
  }

  // Otherwise we need to look through all active payprocs and find Stripe.
  $is_test = 0;
  if (isset($form->_mode)) {
    $is_test = $form->_mode == 'live' ? 0 : 1;
  }

  // The _paymentProcessors array seems to be the most reliable way to find
  // if the form is using CardConnect.
  if (!empty($form->_paymentProcessors)) {
    foreach ($form->_paymentProcessors as $pp) {
      if ($pp['payment_processor_type'] == 'CardConnect') {
        if (!empty($pp['password'])) {
          return $pp['password'];
        }
        // We have a match.
        return cardconnect_get_key_for_name($pp['name'], $is_test);
      }
    }
  }
  // Return NULL if this is not a form with CardConnect involved.
  return NULL;
}

/**
 * Given a payment processor name, return the pub key.
 */
function cardconnect_get_key_for_name($name, $is_test) {
  try {
    $params = array('name' => $name, 'is_test' => $is_test);
    $results = civicrm_api3('PaymentProcessor', 'get', $params);
    if ($results['count'] == 1) {
      $result = array_pop($results['values']);
      return $result['password'];
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    return NULL;
  }
}

/**
 * Implementation of hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function cardconnect_civicrm_managed(&$entities) {
  $entities[] = array(
    'module' => 'com.sof.cardconnect',
    'name' => 'CardConnect',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'CardConnect',
      'title' => 'CardConnect',
      'description' => 'CardConnect Payment Processor',
      'class_name' => 'Payment_CardConnect',
      'billing_mode' => 'form',
      'user_name_label' => 'Username',
      'password_label' => 'Password',
      'signature_label' => 'Merchant ID',
      'url_site_default' => 'sample',
      'is_recur' => 1,
      'payment_type' => 1
    ),
  );

  $entities[] = array(
      'module' => 'com.sof.cardconnect',
      'name' => 'CardConnect',
      'entity' => 'Job',
      'update' => 'never', // Ensure local changes are kept, eg. setting the job active
      'params' => array (
          'version' => 3,
          'run_frequency' => 'Always',
          'name' => 'CardConnect Recurring Payments',
          'description' => 'Process pending and scheduled payments in the CardConnect processor',
          'api_entity' => 'Job',
          'api_action' => 'run_payment_cron',
          'parameters' => "processor_name=CardConnect",
          'is_active' => '0'
      ),
  );

  return _cardconnect_civix_civicrm_managed($entities);
}
