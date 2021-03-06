<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream
ini_set("soap.wsdl_cache_enabled", 0);

// Link the config params
require_once ("lib/default_conf.php");

setSystemTimeZone();

error_reporting(0);
try {
    // Init connection with WS-API
    apiConnect(null, $GLOBALS['SERVICE_USER'], $GLOBALS['SERVICE_PASSWORD'], 47, $GLOBALS['SERVICE_TEAM'], true);

    // Initialize SOAP Server
    $server = new SoapServer("soap_service.wsdl");
    $server->addFunction("training_summary");

    $server->handle();
} catch (APIException $e) {
    log_trace('UNEXPECTED API ERROR executing SOAP function: ' . $e->getMessage());
    service_log($e->getMessage());
} catch (Exception $e) {
    log_trace('UNEXPECTED ERROR executing SOAP function: ' . $e->getMessage());
    service_log($e->getMessage());
}

/**
 * ******************************** SOAP FUNCTIONS *********************************
 */

/**
 * Generate as summary of the exercices performed by a patient in a date range.
 * The summary is stored in the FORM as an array where each row correponds to a date, and the columns correspond to the exercises performed.
 *
 *
 * @param string $admission
 * @param string $summary_form
 * @param string $from_date
 * @param string $to_date
 * @return string[]
 */
function training_summary($admission, $summary_form, $from_date, $to_date) {
    log_trace("ACTIVITY SUMMARY. Form: $form");
    $errorMsg = null;

    try {
        $resp = trainingSummary($admission, $summary_form, $from_date, $to_date);
        $errorMsg = $resp['ErrorMsg'];
    } catch (APIException $e) {
        $errorMsg = $e->getMessage();
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }

    $result = $errorMsg ? '' : $resp['result'];
    if ($errorMsg) {
        log_trace("ERROR: $errorMsg", 1);
    }
    return ['result' => $result, 'ErrorMsg' => $errorMsg];
}

