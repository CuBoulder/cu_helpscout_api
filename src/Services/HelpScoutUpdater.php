<?php
/**
 * @file
 * Class HelpScoutUpdater
 */

class HelpScoutUpdater
{

  /**
   * @var String
   */
  private $api_url;

  /**
   * @var array
   */
  private $auth;

  /**
   * @var array
   */
  private $mappings;

  /**
   * HelpScoutUpdater constructor.
   *
   * @param String $api_url
   *   The URL to connect to the HelpScout API.
   * @param array $auth
   *   The username and password needed for an API request.
   */
  public function __construct(String $api_url, Array $auth)
  {
    $this->api_url = $api_url;
    $this->auth = $auth;
  }

  /**
   * @return array
   */
  public function getMappings(): array
  {
    return $this->mappings;
  }

  /**
   * @param array $mappings
   */
  public function setMappings(array $mappings): void
  {
    $this->mappings = $mappings;
  }

  /**
   * Makes a request to the HelpScout API.
   *
   * @param string $uri
   *   The endpoint to send a request to.
   * @param string $method
   *   HTTP verb if not GET, e.g. POST, PUT, DELETE.
   * @param string|null $body
   *   A body to include in a POST or PUT request.
   *
   * @return array|bool
   *   The API will return an "items" array or a failure.
   */
  private function request(string $uri, string $method = 'GET', string $body = null)
  {
    // The API needs to have the content type specified.
    // The auth is passed in from an environmental variable $_SERVER['HSC_API_ACCESS_KEY'].
    // You can get an API key from your HelpScout profile dashboard.
    $headers = [
      "Authorization: Basic " . base64_encode($this->auth['user'] . ":" . $this->auth['password']),
      'Content-Type: application/json',
    ];

    $opt_array = [
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_URL => $this->api_url . '/' . $uri,
      CURLOPT_HTTPHEADER => $headers,
    ];

    // Add method if not GET.
    if ($method !== 'GET') {
      $opt_array[CURLOPT_CUSTOMREQUEST] = $method;
    }

    // Add a body, if it exists.
    if ($body) {
      // @todo May need to format body?
      $opt_array[CURLOPT_POSTFIELDS] = $body;
    }

    // Setup cURL request.
    $ch = curl_init();

    // Add options.
    curl_setopt_array($ch, $opt_array);

    // @todo Throw exception if no results or return false.
    $results = curl_exec($ch);

    // Need to decode string.
    $results = json_decode($results);

    // The result can have a singular or plural form of "items".
    return $results->items ? $results->items : $results->item;
  }

  /**
   * Returns a list of conversations grouped by query.
   *
   * @param string $query
   *
   * @return array
   */
  public function getConversationsByQuery(string $query)
  {
    // The request will always be at the "/search" endpoint.
    $uri = "search/conversations.json?$query";
    $method = 'GET';
    $results = $this->request($uri, $method);

    // Filter out already updated conversations.
    $updated_convos = db_query('SELECT * FROM {hsc_updated_conversations}')->fetchAllAssoc('convo_id');
    $filtered_convos = array_filter($results, function($el) use($updated_convos) {
      return !in_array($el->id, array_keys($updated_convos));
    });

    return $filtered_convos;
  }

  /**
   * Get a conversation from an ID.
   *
   * @param $el
   *   A conversation object.
   *
   * @return mixed
   *   The result of the GET request to HelpScout.
   */
  private function getConversation($el)
  {
    // The request will always be at the "/search" endpoint.
    $uri = "conversations/" . $el->id . ".json";
    $method = 'GET';

    return $this->request($uri, $method);
  }

  /**
   * Take Beacon's identify information section and add to a conversation.
   *
   * @param array $conversations
   *   The list of conversations to be updated.
   *
   * @return array
   *   A list of conversations with updated custom fields.
   */
  public function mapBeaconFieldsToConversations(array $conversations)
  {
    // Parse the conversation's threads for Beacon note.
    return array_map(function ($el) {
      // Get individual conversation.
      $conversation = $this->getConversation($el);
      return $this->parseThreadsForBeaconData($conversation);
    }, $conversations);
  }

  /**
   * Pass along a conversation to be parsed if it has a Beacon note.
   *
   * @param $conversation
   *   Conversation to parse for Beacon notes.
   *
   * @return array|bool
   *   Either a conversation with update custom fields or false as failure.
   */
  private function parseThreadsForBeaconData($conversation)
  {
    // If no threads then return early.
    if (!isset($conversation->threads)) {
      // Maybe throw exception?
      return false;
    }

    // If threads, then create array just of the ones with Beacon information.
    foreach ($conversation->threads as $thread) {
      // Type of "note" and source of "embed-form" are used to uniquely identify Beacon notes.
      if ($thread->type === 'note' && $thread->source->type === 'embed-form') {
        if ($parsed_data = $this->parseBeaconBodyFields($thread->body)) {
          return [
            $conversation->id => $parsed_data,
          ];
        }
        break;
      }
    };
    return false;
  }

  /**
   * Takes a string of HTML and returns an array of Beacon fields.
   *
   * @param string $body
   *   The thread body containing variables in a table.
   *
   * @return array|bool
   *   Either an array of data or false for failure.
   */
  private function parseBeaconBodyFields(string $body)
  {
    $dom = new DOMDocument();
    $dom->loadHTML($body);

    $strong_tag = $dom->getElementsByTagName('strong');
    $tables = $dom->getElementsByTagName('table');
    $table_key = null;

    // Grab the key of the list of tables containing Beacon data.
    // Table "titles" are stored in <strong> tags above the table.
    foreach ($strong_tag as $key => $s) {
      $f = $s->textContent;
      // The table we want has this header.
      if ($f === 'Customer Information') {
        $table_key = $key;
      }
    }

    // Parse HTML nodes if the table contains Beacon data.
    foreach ($tables as $key => $h) {
      if ($key === $table_key) {
        // Grab the table content. It will concatenate the innerText of all the <td> tags.
        $d = $h->textContent;

        // We will start by assuming an array structure based on values separated by "$$" like the following string:
        // $$roles$$authenticated user, developer$$site_name$$University of Colorado Boulder$$site_url$$
        $parts = explode('$$', $d);

        // The data will always start at $parts[1] since the string begins with the delimiter "$$".
        array_shift($parts);

        // Loop through parts and assign to key value pairs.
        $final_fields = [];
        foreach ($parts as $key2 => $val) {
          // Even are keys; odd are values.
          if ($key2 % 2 == 0) {
            $final_fields[$val] = $parts[$key2 + 1];
          }
        }
        return $final_fields;
      }
    }
    // If no table matches, return false.
    return false;
  }

  /**
   * Make requests to HelpScout to update conversations.
   *
   * @param array $modified_conversations
   *
   * @return string|bool
   *
   * @throws Exception
   * @throws InvalidMergeQueryException
   */
  public function updateConversations(array $modified_conversations)
  {
    $conversations_to_update = [];
    foreach ($modified_conversations as $key => $c) {
      // Remove any null elements.
      // @todo These elements should never be added in a previous step.
      if ($c !== null) {
        // Grab conversation ID.
        $conversation_id = array_keys($c)[0];

        // There will be only one Beacon fields element in the array.
        $el = array_shift($c);

        // Start out body field.
        $body = '{"customFields": [';
        foreach ($el as $key2 => $val) {
          // If the Beacon field is in the mappings, then map it.
          if (in_array($key2, array_keys($this->mappings))) {
            $body .= '{ "fieldId": '. $this->mappings[$key2]. ', "value": "'. $el[$key2]. '"},';
          }
        }

        // Remove last comma.
        $body = substr($body, 0, -1);

        // Add last part of body.
        $body .= ']}';

        array_push($conversations_to_update, [
          'id' => $conversation_id,
          'body' => $body,
        ]);
      }
    }

    // Send update to HelpScout.
    foreach ($conversations_to_update as $g) {
      $uri = 'conversations/'. $g['id']. '.json';
      $method = 'PUT';
      $request_body = $g['body'];

      $this->request($uri, $method, $request_body);

      // Need to store conversation ID if request is successful to skip updating next time.
      db_merge('hsc_updated_conversations')
        ->key(['convo_id' => $g['id']])
        ->fields([
          'convo_id' => $g['id'],
          'payload_sent' => $request_body,
          'updated_on' => time(),
        ])
        ->execute();

      // Store result in watchdog message.
      watchdog('cu_helpscout_api', 'Updated conversation '. $g['id']);

      // Also create watchdog message for failure.
    }
  }
}
