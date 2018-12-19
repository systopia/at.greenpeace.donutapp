<?php
use CRM_Donutapp_ExtensionUtil as E;

/**
 * DonutPetition.Import API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_donut_petition_import_spec(&$spec) {
  $spec['limit'] = [
    'name'         => 'limit',
    'title'        => 'Maximum number of petitions to process',
    'type'         => CRM_Utils_TYPE::T_INT,
    'api.required' => 0,
    'api.default'  => 0,
  ];

  $spec['client_id'] = [
    'name'         => 'client_id',
    'title'        => 'Donutapp Client ID',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['client_secret'] = [
    'name'         => 'client_secret',
    'title'        => 'Donutapp Client Secret',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
  ];

  $spec['confirm'] = [
    'name'         => 'confirm',
    'title'        => 'Confirm petition retrieval?',
    'type'         => CRM_Utils_TYPE::T_BOOLEAN,
    'api.required' => 0,
    'api.default'  => 1,
  ];
}

/**
 * DonutPetition.import API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_donut_petition_import($params) {
  if (array_key_exists('limit', $params)) {
    $params['limit'] = abs($params['limit']);
    $processor = new CRM_Donutapp_Processor_Petition($params);
    $processor->process();
    return civicrm_api3_create_success();
  }
  else {
    throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
  }
}
