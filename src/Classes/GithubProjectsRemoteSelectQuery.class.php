<?php

/**
 * @file
 * Contains the GithubProjectsRemoteSelectQuery class.
 */

/**
 * Select query for our remote data.
 *
 * @todo Make vars protected once no longer developing.
 */
class GithubProjectsRemoteSelectQuery extends RemoteEntityQuery {

  /**
   * Determines whether the query is RetrieveMultiple or Retrieve.
   *
   * The query is Multiple by default, until an ID condition causes it to be
   * single.
   *
   * @var bool
   */
  public $retrieve_multiple = TRUE;

  /**
   * An array of conditions on the query. These are grouped by the table they
   * are on.
   *
   * @var array
   */
  public $conditions = array();

  /**
   * The from date filter for event searches.
   *
   * @var object
   */
  public $from_date = NULL;

  /**
   * The to date filter for event searches.
   *
   * @var object
   */
  public $to_date = NULL;

  /**
   * The user id.
   *
   * @var object
   */
  public $user_id = NULL;

  /**
   * Constructor to generically set up the user id condition if
   * there is a current user.
   *
   * @param object $connection
   *   Connection used to make REST requests.
   */
  public function __construct($connection) {
    parent::__construct($connection);
  }

  /**
   * Add a condition to the query.
   *
   * Originally based on the entityCondition() method in EntityFieldQuery, but
   * largely from USDARemoteSelectQuery (Programming Drupal 7 Entities) and
   * MSDynamicsSoapSelectQuery.
   *
   * @param string $name
   *   The name of the entity property.
   * @param string $value
   *   The value of the entity property.
   * @param string $operator
   *   The comparison operator.
   */
  public function entityCondition($name, $value, $operator = NULL) {

    // We only support the entity ID for now.
    if ($name == 'entity_id') {

      // Get the remote field name of the entity ID.
      $field = $this->entity_info['remote entity keys']['remote id'];

      // Set the remote ID field to the passed value.
      $this->conditions[$this->remote_base][] = array(
        'field' => $field,
        'value' => $value,
        'operator' => $operator,
      );

      // Record that we'll only be retrieving a single item.
      if (is_null($operator) || ($operator == '=')) {
        $this->retrieve_multiple = FALSE;
      }
    }
    else {

      // Report an invalid entity condition.
      $this->throwException(
        'GITHUBPROJECTSREMOTESELECTQUERY_INVALID_ENTITY_CONDITION',
        'The query object can only accept the \'entity_id\' condition.'
      );
    }
  }

  /**
   * Add a condition to the query, using local property keys.
   *
   * Based on MSDynamicsSoapSelectQuery::propertyCondition().
   *
   * @param string $property_name
   *   The name of the property.
   * @param string $value
   *   The value of the property.
   * @param string $operator
   *
   *   A local property. Ie, a key in the $entity_info 'property map' array.
   */
  public function propertyCondition($property_name, $value, $operator = NULL) {

    // Make sure the entity base has been set up.
    if (!isset($this->entity_info)) {
      $this->throwException(
      'GITHUBPROJECTSREMOTESELECTQUERY_ENTITY_BASE_NOT_SET',
      'The query object was not set with an entity type.'
      );
    }

    // Make sure that the provided property is valid.
    if (!isset($this->entity_info['property map'][$property_name])) {
      $this->throwException(
      'GITHUBPROJECTSREMOTESELECTQUERY_INVALID_PROPERY',
      'The query object cannot set a non-existent property.'
      );
    }

    // Adding a field condition (probably) automatically makes this a multiple.
    // TODO: figure this out for sure!
    $this->retrieve_multiple = TRUE;

    // Use the property map to determine the remote field name.
    $remote_field_name = $this->entity_info['property map'][$property_name];

    // Set the condition for use during execution.
    $this->conditions[$this->remote_base][] = array(
      'field' => $remote_field_name,
      'value' => $value,
      'operator' => $operator,
    );
  }

  /**
   * Run the query and return a result.
   *
   * Uses  makeRequest('event?eventId=ID', 'GET');.
   *
   * @return array
   *   Remote entity objects as retrieved from the remote connection.
   */
  public function execute() {
    $entities = [];

    // If there are any validation errors, don't perform a search.
    if (form_set_error()) {
      return array();
    }

    $path = "users/" . variable_get("github_projects.login", "") . "/starred";

    // Make the request.
    try {
      $response = $this->connection->makeRequest($path, 'GET', array('Accept' => 'application/vnd.github.mercy-preview+json'));
    }
    catch (Exception $e) {
      drupal_set_message($e->getMessage());
    }

    switch ($this->base_entity_type) {
      case 'github_projects_remote_repository':
        $entities = $this->parseEventResponse($response);
        break;
    }

    if (isset($this->conditions[$this->remote_base])) {
      foreach ($this->conditions[$this->remote_base] as $condition) {
        switch ($condition['field']) {
          case 'repository_id':
            $repository_id = $condition['value'];
            $entities = array_filter($entities, function ($objects) use ($repository_id) {
              return ($object->repository_id == $repository_id);
            });
            break;

          case 'repository_fullname':
            $repository_fullname = $condition['value'];
            $entities = array_filter($entities, function ($objects) use ($repository_fullname) {
              return ($objects->repository_fullname == $repository_fullname);
            });
            break;
        }
      }
    }
    // Return the list of results.
    return $entities;
  }

  /**
   * Helper for execute() which parses the JSON response for event entities.
   *
   * May also set the $total_record_count property on the query, if applicable.
   *
   * @param object $response
   *   The JSON/XML/whatever response from the REST server.
   *
   * @return array
   *   An list of entity objects, keyed numerically.
   *   An empty array is returned if the response contains no entities.
   *
   * @throws
   *  Exception if a fault is received when the REST call was made.
   */
  public function parseEventResponse($response) {

    // Fetch the list of events.
    if ($response->code == 404) {
      // No data was returned so let's provide an empty list.
      $repositories = array();
    }
    else /* We have response data */ {
      // Convert the JSON (assuming that's what we're getting) into a PHP array.
      // Do any unmarshalling to convert the response data into a PHP array.
      $repositories = json_decode($response->data, TRUE);
    }

    // Initialize an empty list of entities for returning.
    $entities = array();

    // Iterate through each event.
    foreach ($repositories as $repository) {
      $readmePath = "repos/" . $repository['full_name'] . "/readme";

      // Make the request.
      try {
        $readmeResponse = $this->connection->makeRequest($readmePath, 'GET', array('Accept' => 'application/vnd.github.v3.html'));
      }
      catch (Exception $e) {
        drupal_set_message($e->getMessage());
      }

      $licensePath = "repos/" . $repository['full_name'] . "/license";

      // Make the request.
      try {
        $licenseResponse = $this->connection->makeRequest($licensePath, 'GET', array('Accept' => 'application/vnd.github.drax-preview+json'));
      }
      catch (Exception $e) {
        drupal_set_message($e->getMessage());
      }

      $vocabulary = taxonomy_vocabulary_machine_name_load('github_projects_topics');

      $terms = array();

      foreach ($repository['topics'] as $topic) {
        $term = (object) array(
          'name' => $topic,
          'description' => $topic,
          'vid' => $vocabulary->vid,
        );
        taxonomy_term_save($term);
        $terms[] = $term;
      }

      $readme = $this->parseReadmeResponse($readmeResponse);

      $license = "undefined";
      $license = $this->parseLicenseResponse($licenseResponse);

      $entities[] = (object) array(
        // Set repository information.
        'repository_id' => isset($repository['id']) ? $repository['id'] : NULL,
        'repository_name' => isset($repository['name']) ? $repository['name'] : NULL,
        'repository_fullname' => isset($repository['full_name']) ? $repository['full_name'] : NULL,
        'repository_description' => isset($repository['description']) ? $repository['description'] : NULL,
        'repository_readme' => $readme,
        'repository_license' => $license,
        'repository_url' => isset($repository['html_url']) ? $repository['html_url'] : NULL,
        'repository_topics' => isset($terms) ? $terms : NULL,
        'repository_createddate' => isset($repository['created_at']) ? $repository['created_at'] : NULL,
        'repository_updateddate' => isset($repository['updated_at']) ? $repository['updated_at'] : NULL,
        'repository_pusheddate' => isset($repository['pushed_at']) ? $repository['pushed_at'] : NULL,
        'repository_forks' => isset($repository['forks_count']) ? $repository['forks_count'] : NULL,
        'repository_stargazers' => isset($repository['stargazers_count']) ? $repository['stargazers_count'] : NULL,
        'repository_watchers' => isset($repository['watchers_count']) ? $repository['watchers_count'] : NULL,
        'repository_size' => isset($repository['size']) ? $repository['size'] : NULL,
      );
    }

    // Return the newly-created list of entities.
    return $entities;
  }

  /**
   * Helper for execute() which parses the response for repository readmes.
   *
   * May also set the $total_record_count property on the query, if applicable.
   *
   * @param object $response
   *   The response from the REST server.
   *
   * @return string
   *   Readme string.
   *
   * @throws
   *  Exception if a fault is received when the REST call was made.
   */
  public function parseReadmeResponse($response) {

    // Fetch the list of events.
    if ($response->code == 404) {
      // No data was returned so let's provide an empty list.
      $readme = NULL;
    }
    else /* We have response data */ {
      // Convert the JSON (assuming that's what we're getting) into a PHP array.
      // Do any unmarshalling to convert the response data into a PHP array.
      $readme = $response->data;
    }
    return $readme;
  }

  /**
   * Helper for execute() which parses the response for repository readmes.
   *
   * May also set the $total_record_count property on the query, if applicable.
   *
   * @param object $response
   *   The response from the REST server.
   *
   * @return string
   *   Readme string.
   *
   * @throws
   *  Exception if a fault is received when the REST call was made.
   */
  public function parseLicenseResponse($response) {

    // Fetch the list of events.
    if ($response->code == 404) {
      // No data was returned so let's provide an empty list.
      $readme = NULL;
    }
    else /* We have response data */ {
      // Convert the JSON (assuming that's what we're getting) into a PHP array.
      // Do any unmarshalling to convert the response data into a PHP array.
      $license = json_decode($response->data, TRUE)['license']['name'];
    }
    return $license;
  }

  /**
   * Throw an exception when there's a problem.
   *
   * @param string $code
   *   The error code.
   * @param string $message
   *   A user-friendly message describing the problem.
   *
   * @throws Exception
   */
  public function throwException($code, $message) {

    // Report error to the logs.
    watchdog('github_projects', 'ERROR: GithubProjectsRemoteSelectQuery: "@code", "@message".', array(
      '@code' => $code,
      '@message' => $message,
    ));

    // Throw an error with which callers must deal.
    throw new Exception(t("GithubProjectsRemoteSelectQuery error, got message '@message'.", array(
      '@message' => $message,
    )), $code);
  }

  /**
   * Build the query from an EntityFieldQuery object.
   *
   * To have our query work with Views using the EntityFieldQuery Views module,
   * which assumes EntityFieldQuery query objects, it's necessary to convert
   * from the EFQ so that we may execute this one instead.
   *
   * @param object $efq
   *   The built-up EntityFieldQuery object.
   *
   * @return object
   *   The current object.  Helpful for chaining methods.
   */
  public function buildFromEFQ($efq) {

    // Copy all of the conditions.
    foreach ($efq->propertyConditions as $condition) {

      // Handle various conditions in different ways.
      switch ($condition['column']) {

        // Get the from date.
        case 'from_date':
          $from_date = $condition['value'];
          // Convert the date to the correct format for the REST service.
          $result = $from_date->format('Y/m/d');
          // The above format() can return FALSE in some cases, so add a check.
          if ($result) {
            $this->from_date = $result;
          }
          break;

        // Get the to date.
        case 'to_date':
          $to_date = $condition['value'];
          // Convert the date to the correct format for the REST service.
          $result = $to_date->format('Y/m/d');
          // The above format() can return FALSE in some cases, so add a check.
          if ($result) {
            $this->to_date = $result;
          }
          break;

        // Get the user ID.
        case 'user_id':
          $this->user_id = $condition['value'];
          break;

        default:
          $this->conditions[$this->remote_base][] = array(
            'field' => $condition['column'],
            'value' => $condition['value'],
            'operator' => isset($condition['operator']) ? $condition['operator'] : NULL,
          );
          break;
      }
    }

    return $this;
  }

}
