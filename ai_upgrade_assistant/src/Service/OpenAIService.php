<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for interacting with OpenAI API.
 */
class OpenAIService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new OpenAIService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Validates the API key format.
   *
   * @param string $api_key
   *   The API key to validate.
   *
   * @return bool
   *   TRUE if the key format is valid, FALSE otherwise.
   */
  protected function isValidApiKeyFormat($api_key) {
    // Check basic format (starts with 'sk-' followed by alphanumeric/special chars)
    if (!preg_match('/^sk-[a-zA-Z0-9_-]{32,}$/', $api_key)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Verifies API key with OpenAI.
   *
   * @param string $api_key
   *   The API key to verify.
   *
   * @return bool
   *   TRUE if the key is valid, FALSE otherwise.
   */
  protected function verifyApiKey($api_key) {
    try {
      // Make a minimal API call to verify the key
      $response = $this->httpClient->get('https://api.openai.com/v1/models', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
        ],
      ]);
      
      return $response->getStatusCode() === 200;
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('API key verification failed: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Analyzes code for upgrade compatibility.
   *
   * @param string $code
   *   The code to analyze.
   * @param array $context
   *   Additional context about the code.
   *
   * @return array|null
   *   Analysis results or NULL if analysis is not possible.
   *
   * @throws \Exception
   *   If there is an error communicating with OpenAI.
   */
  public function analyzeCode($code, array $context = []) {
    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    $api_key = $config->get('openai_api_key');
    $model = $config->get('model') ?: 'gpt-4';

    // Check if OpenAI integration is configured
    if (empty($api_key)) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->warning('OpenAI API key is not configured.');
      return NULL;
    }

    // Validate API key format
    if (!$this->isValidApiKeyFormat($api_key)) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('Invalid OpenAI API key format.');
      return NULL;
    }

    try {
      // Verify API key on first use
      static $key_verified = FALSE;
      if (!$key_verified && !$this->verifyApiKey($api_key)) {
        throw new \Exception('Invalid OpenAI API key. Please check your API key in the settings.');
      }
      $key_verified = TRUE;

      $response = $this->httpClient->post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $model,
          'messages' => [
            [
              'role' => 'system',
              'content' => $this->getSystemPrompt(),
            ],
            [
              'role' => 'user',
              'content' => $this->prepareCodeAnalysisPrompt($code, $context),
            ],
          ],
          'temperature' => 0.2,
          'max_tokens' => 2000,
        ],
      ]);

      $result = json_decode($response->getBody(), TRUE);
      
      if (empty($result['choices'][0]['message']['content'])) {
        throw new \Exception('Invalid response from OpenAI API');
      }

      return $this->parseAnalysisResponse($result['choices'][0]['message']['content']);
    }
    catch (GuzzleException $e) {
      $status_code = $e->getCode();
      $error_message = 'Error communicating with OpenAI';
      
      switch ($status_code) {
        case 401:
          $error_message = 'Invalid OpenAI API key. Please check your API key in the settings.';
          break;
        case 429:
          $error_message = 'OpenAI API rate limit exceeded. Please try again later.';
          break;
        case 500:
        case 502:
        case 503:
        case 504:
          $error_message = 'OpenAI API is currently unavailable. Please try again later.';
          break;
      }
      
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('@error: @message', [
          '@error' => $error_message,
          '@message' => $e->getMessage(),
        ]);
      
      throw new \Exception($error_message);
    }
  }

  /**
   * Gets the system prompt for code analysis.
   *
   * @return string
   *   The system prompt.
   */
  protected function getSystemPrompt() {
    return <<<EOT
You are an expert Drupal developer specializing in code analysis and upgrades.
Your task is to analyze PHP code for Drupal compatibility issues and suggest improvements.
Focus on:
1. Deprecated function usage
2. API changes between Drupal versions
3. Coding standards compliance
4. Security best practices
5. Performance optimizations

For each issue found, provide:
1. Issue type (deprecation, security, performance, etc.)
2. Description of the problem
3. Priority level (critical, warning, suggestion)
4. Current problematic code
5. Suggested replacement code

Format your response as a JSON array of issues, each containing:
{
  "type": "string",
  "description": "string",
  "priority": "string",
  "current_code": "string",
  "code_example": "string",
  "line_number": number
}
EOT;
  }

  /**
   * Prepares the code analysis prompt.
   *
   * @param string $code
   *   The code to analyze.
   * @param array $context
   *   Additional context about the code.
   *
   * @return string
   *   The prepared prompt.
   */
  protected function prepareCodeAnalysisPrompt($code, array $context = []) {
    return <<<EOT
Please analyze the following Drupal code for compatibility issues and improvement opportunities:

```php
$code
```

Provide your analysis in the specified JSON format.
EOT;
  }

  /**
   * Parses the analysis response from OpenAI.
   *
   * @param string $response
   *   The response content.
   *
   * @return array
   *   Parsed analysis results.
   */
  protected function parseAnalysisResponse($response) {
    try {
      // Extract JSON from the response
      if (preg_match('/```json\s*(.*?)\s*```/s', $response, $matches)) {
        $json = $matches[1];
      }
      else {
        $json = $response;
      }

      $data = json_decode($json, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
      }

      // Ensure the response is an array of issues
      if (!is_array($data)) {
        $data = [$data];
      }

      // Validate and normalize each issue
      return array_map(function ($issue) {
        return [
          'type' => $issue['type'] ?? 'unknown',
          'description' => $issue['description'] ?? '',
          'priority' => $issue['priority'] ?? 'normal',
          'current_code' => $issue['current_code'] ?? '',
          'code_example' => $issue['code_example'] ?? '',
          'line_number' => $issue['line_number'] ?? 0,
        ];
      }, $data);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('Error parsing OpenAI response: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

}
