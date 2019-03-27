<?php
use CRM_Donutapp_ExtensionUtil as E;

/**
 * DonutDonation.Import API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_donut_donation_import_spec(&$spec) {
  $spec['limit'] = [
    'name'         => 'limit',
    'title'        => 'Maximum number of donations to process',
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
    'title'        => 'Confirm donation retrieval?',
    'type'         => CRM_Utils_TYPE::T_BOOLEAN,
    'api.required' => 0,
    'api.default'  => 1,
  ];
}

/**
 * DonutPetition.import API
 *
 * @param $params API parameters
 *
 * @return array API result
 * @throws \Exception
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function civicrm_api3_donut_donation_import($params) {
  $params['limit'] = abs($params['limit']);
  $processor = new CRM_Donutapp_Processor_Greenpeace_Donation($params);
  $processor->process();
  return civicrm_api3_create_success();
}
