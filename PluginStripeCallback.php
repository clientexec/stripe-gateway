<?php

require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/admin/models/StatusAliasGateway.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice_EventLog.php';
require_once 'modules/admin/models/Error_EventLog.php';

class PluginStripeCallback extends PluginCallback
{
    public function processCallback()
    {
        CE_Lib::log(4, 'Stripe callback invoked');
        \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripe_Stripe Gateway Secret Key'));
        \Stripe\Stripe::setAppInfo(
            'Clientexec',
            CE_Lib::getAppVersion(),
            'https://www.clientexec.com',
            STRIPE_PARTNER_ID
        );
        \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);

        if (isset($_GET['initStripe']) && $_GET['initStripe']) {
            $totalPay = $_GET['totalPay'];
            $totalAmount = sprintf("%01.2f", round($totalPay, 2));
            $totalAmountCents = $totalAmount * 100;

            $paymentIntentParams = array(
                'amount'                    => $totalAmountCents,
                'currency'                  => $_GET['currency'],
                'automatic_payment_methods' => array(
                    'enabled' => true,
                ),
                'setup_future_usage'        => 'off_session'
            );

            $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentParams);

            header('Content-type: application/json');
            echo json_encode([
                'id' => $paymentIntent->id,
                'secret' => $paymentIntent->client_secret
            ]);
            die();
        } else {
            if (isset($_GET['isElements']) && $_GET['isElements']) {
                $payment_intent_id = $_GET['payment_intent'];
                $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
                $invoiceId = substr($payment_intent->description, 9);
            } else {
                $stripe = new \Stripe\StripeClient($this->settings->get('plugin_stripe_Stripe Gateway Secret Key'));

                if (isset($_GET['isPaymentMethod']) && $_GET['isPaymentMethod']) {
                    //Get client Id from hash
                    $encryptedCustomerID = strtr(urldecode($_GET['clientHash']), '-_', '+/');

                    require_once 'library/encrypted/Clientexec.php';

                    $customerid = Clientexec::decryptString($encryptedCustomerID);

                    if (is_a($customerid, 'CE_Error')) {
                        return $customerid;
                    }
                    //Get client Id from hash

                    $user = new User($customerid);

                    $clientExecURL = CE_Lib::getSoftwareURL();
                    $paymentMethodURL = $clientExecURL."/index.php?fuse=clients&controller=userprofile&view=paymentmethod";

                    $session = false;

                    try {
                        $session = $stripe->checkout->sessions->retrieve(
                            $_GET['session_id'],
                            []
                        );
                    } catch (Exception $e) {
                    }

                    if ($session !== false) {
                        try {
                            $setupIntents = $stripe->setupIntents->retrieve(
                                $session->setup_intent,
                                []
                            );

                            $profile_id = $setupIntents->customer;
                            $payment_method = $setupIntents->payment_method;

                            if ($profile_id != '' && $payment_method != '') {
                                //save profile id
                                $Billing_Profile_ID = '';
                                $profile_id_array = array();

                                if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                                    $profile_id_array = unserialize($Billing_Profile_ID);
                                }

                                if (!is_array($profile_id_array)) {
                                    $profile_id_array = array();
                                }

                                $profile_id_array[basename(dirname(__FILE__))] = $profile_id.'|'.$payment_method;
                                $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                                $user->save();
                                //save profile id

                                $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                                $eventLog->setSubject($user->getId());

                                $oldGateway = $user->getPaymentType();
                                $newGateway = 'stripe';

                                if ($oldGateway != $newGateway) {
                                    $eventLog->setAction(CLIENT_EVENTLOG_CHANGEDPAYMENTTYPE);
                                    $eventLog->setParams($newGateway);
                                    $eventLog->save();

                                    $userInformation = array();
                                    $userInformation['User ID'] = $user->getId();
                                    $userInformation['Status'] = $user->getStatus();

                                    //GET CUSTOMER VIEWABLE CUSTOM FIELDS//
                                    $query = "SELECT id, type, name FROM customuserfields WHERE showCustomer = 1 AND isadminonly = 0 ORDER BY myOrder";
                                    $result = $this->db->query($query);

                                    while (list($tID, $tType, $tName) = $result->fetch()) {
                                        if ($tType == typeDATE) {
                                            if (!$user->getCustomFieldsValue($tID, $userInformation[$tName])) {
                                                $userInformation[$tName] = '';
                                            }

                                            // We store the date as yyyy-mm-dd so convert to the proper date format
                                            $userInformation[$tName] = CE_Lib::db_to_form($userInformation[$tName], $this->settings->get('Date Format'), "/");
                                        } else {
                                            $user->getCustomFieldsValue($tID, $userInformation[$tName]);
                                        }
                                    }

                                    include_once 'library/CE/NE_PluginCollection.php';
                                    $pluginCollection = new NE_PluginCollection('gateways', $this->user);

                                    if ($this->settings->get('plugin_'.$oldGateway.'_Update Gateway')) {
                                        $userInformation['Gateway'] = $oldGateway;
                                        $userInformation['Action'] = 'delete';
                                        $pluginCollection->callFunction($oldGateway, 'UpdateGateway', $userInformation);
                                    }

                                    $userInformation['Gateway'] = $newGateway;
                                    $userInformation['Action'] = 'update';
                                    $pluginCollection->callFunction($newGateway, 'UpdateGateway', $userInformation);

                                    $user->setPaymentType($newGateway);
                                }

                                $user->SetAutoPayment(1);
                                $user->clearCreditCardInfo();
                                $user->save();

                                CE_Lib::addSuccessMessage($user->lang('Your payment method was updated successfully'));
                            } else {
                                CE_Lib::addErrorMessage($user->lang('Your payment method was not updated'));
                            }
                        } catch (Exception $e) {
                            CE_Lib::addErrorMessage($user->lang('Your payment method was not updated'));
                        }
                    } else {
                        CE_Lib::addErrorMessage($user->lang('Your payment method was not updated'));
                    }

                    CE_Lib::redirectPage($paymentMethodURL);
                    exit;
                } else {
                    $session = false;

                    try {
                        $session = $stripe->checkout->sessions->retrieve(
                            $_GET['session_id'],
                            []
                        );
                    } catch (Exception $e) {
                        CE_Lib::log(4, "Invalid Stripe Session: " . $e->getMessage());
                        $this->redirect();
                    }

                    $lineItems = $stripe->checkout->sessions->allLineItems($_GET['session_id'], ['limit' => 5]);
                    $invoiceId = substr($lineItems->data[0]->description, 9);

                    if ($session !== false) {
                        $payment_intent = \Stripe\PaymentIntent::retrieve($session->payment_intent);
                    } else {
                        $this->redirect();
                    }
                }
            }

            $transactionId = $payment_intent->charges->data[0]->balance_transaction;
            $amount = sprintf("%01.2f", round(($payment_intent->charges->data[0]->amount / 100), 2));
            $success = ($payment_intent->status == 'succeeded');

            // Create Plugin class object to interact with CE.
            $cPlugin = new Plugin($invoiceId, basename(dirname(__FILE__)), $this->user);
            $cPlugin->m_TransactionID = $transactionId;
            $cPlugin->setAmount($amount);
            $cPlugin->setAction('charge');
            $cPlugin->m_Last4 = "NA";

            $clientExecURL = CE_Lib::getSoftwareURL();
            $invoiceviewURLSuccess = $clientExecURL."/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=".$invoiceId;
            $invoiceviewURLCancel = $clientExecURL."/index.php?fuse=billing&cancel=1&controller=invoice&view=invoice&id=".$invoiceId;

            //Need to check to see if user is coming from signup
            if ($_GET['isSignup']) {
                // Actually handle the signup URL setting
                if ($this->settings->get('Signup Completion URL') != '') {
                    $return_url = $this->settings->get('Signup Completion URL').'?success=1';
                    $cancel_url = $this->settings->get('Signup Completion URL');
                } else {
                    $return_url = $clientExecURL."/order.php?step=complete&pass=1";
                    $cancel_url = $clientExecURL."/order.php?step=3";
                }
            } else {
                $return_url = $invoiceviewURLSuccess;
                $cancel_url = $invoiceviewURLCancel;
            }

            if ($success) {
                //save profile id
                $profile_id = $payment_intent->customer;
                $payment_method = $payment_intent->payment_method;
                $Billing_Profile_ID = '';
                $profile_id_array = array();
                $customerid = $cPlugin->m_Invoice->getUserID();
                $user = new User($customerid);

                if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                    $profile_id_array = unserialize($Billing_Profile_ID);
                }

                if (!is_array($profile_id_array)) {
                    $profile_id_array = array();
                }

                $profile_id_array[basename(dirname(__FILE__))] = $profile_id.'|'.$payment_method;
                $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                $user->save();
                //save profile id

                $cPlugin->PaymentAccepted($amount, "Stripe payment of {$amount} was accepted. (Transaction ID: {$transactionId})", $transactionId);
                header('Location: '.$return_url);
            } else {
                if (isset($transactionId)) {
                    $cPlugin->PaymentRejected("Stripe payment of {$amount} was rejected. (Transaction ID: {$transactionId})");
                }
                
                header('Location: '.$cancel_url);
            }
            exit;
        }
    }

    private function redirect()
    {
        $clientExecURL = CE_Lib::getSoftwareURL();
        $invoiceviewURLCancel = $clientExecURL."/index.php?fuse=billing&cancel=1&controller=invoice&view=allinvoices";

        //Need to check to see if user is coming from signup
        if ($_GET['isSignup']) {
            // Actually handle the signup URL setting
            if ($this->settings->get('Signup Completion URL') != '') {
                $cancel_url = $this->settings->get('Signup Completion URL');
            } else {
                $cancel_url = $clientExecURL."/order.php?step=3";
            }
        } else {
            $cancel_url = $invoiceviewURLCancel;
        }
        header('Location: '.$cancel_url);
        exit;
    }
}
