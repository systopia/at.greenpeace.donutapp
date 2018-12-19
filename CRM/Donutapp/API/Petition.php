<?php

class CRM_Donutapp_API_Petition {

  private $data;

  public function __construct($data) {
    $this->data = $data;
  }

  public function __get($property) {
    if (array_key_exists($property, $this->data)) {
      return $this->data[$property];
    }
    return NULL;
  }

  /**
   * Fetch all petitions ready for retrieval
   *
   * @param array $options
   * @return \CRM_Donutapp_API_Petition[]
   */
  public static function all(array $options = []) {
    $hasNextPage = TRUE;
    $nextUri = NULL;
    $page_size = 100;
    $limit = PHP_INT_MAX;
    if (array_key_exists('limit', $options) && $options['limit'] != 0) {
      $limit = $options['limit'];
      if ($limit < $page_size) {
        $page_size = $limit;
      }
    }

    $petitions = [];

    while ($hasNextPage && $limit > 0) {
      if (is_null($nextUri)) {
        $result = CRM_Donutapp_API_Client::get(
          CRM_Donutapp_API_Client::buildUri('petitions', [
            'page_size' => $page_size
          ])
        );
      } else {
        $result = CRM_Donutapp_API_Client::get($nextUri);
      }
      $hasNextPage = !empty($result->next);
      if ($hasNextPage) {
        $nextUri = $result->next;
      }
      if ($result->count > 0) {
        foreach ($result->results as $petition) {
          if ($limit == 0) {
            break;
          }
          $petitions[] = new CRM_Donutapp_API_Petition((array) $petition);
          $limit--;
        }
      }
    }
    return $petitions;
  }

  /**
   * Confirm the retrieval of this petition
   *
   * @return bool
   * @throws \CRM_Donutapp_API_Error_BadResponse
   */
  public function confirm() {
    $response = CRM_Donutapp_API_Client::postJSON(
      CRM_Donutapp_API_Client::buildUri('/petitions/confirm_retrieval'),
      [
        [
          'uid' => $this->uid,
          'status' => 'success',
          'message' => '',
        ],
      ]
    );

    foreach ($response as $confirmation) {
      if ($confirmation->uid != $this->uid) {
        throw new CRM_Donutapp_API_Error_BadResponse(
          'Error confirming petition retrieval: UID "' . $confirmation->uid . '" does not match "' . $this->uid
        );
      }
      if ($confirmation->status != 'success') {
        throw new CRM_Donutapp_API_Error_BadResponse(
          'Error confirming petition retrieval: got status "' . $confirmation->status . '" after confirmation'
        );
      }
    }
  }
}