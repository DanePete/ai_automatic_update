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
   * Whether we're in test mode.
   *
   * @var bool
   */
  protected $testMode = FALSE;

  /**
   * Maximum number of retries for rate limit errors.
   *
   * @var int
   */
  protected $maxRetries = 3;

  /**
   * Base delay between retries in seconds.
   *
   * @var int
   */
  protected $baseDelay = 7;

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
   * Set test mode.
   *
   * @param bool $enabled
   *   Whether test mode should be enabled.
   */
  public function setTestMode($enabled = TRUE) {
    $this->testMode = $enabled;
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
    // Accept both standard and project API key formats
    return (bool) preg_match('/^(sk-|sk-proj-)[\w-]+$/', trim($api_key));
  }

  /**
   * Gets mock analysis results for test mode.
   *
   * @return array
   *   Mock analysis results.
   */
  protected function getMockAnalysisResults() {
    return [
      'issues' => [
        [
          'type' => 'deprecation',
          'description' => 'Using deprecated function drupal_get_path()',
          'priority' => 'high',
          'current_code' => 'drupal_get_path("module", "example")',
          'code_example' => '\Drupal::service("extension.list.module")->getPath("example")',
          'line_number' => 42,
        ],
        [
          'type' => 'best_practice',
          'description' => 'Direct service container usage detected',
          'priority' => 'medium',
          'current_code' => '\Drupal::service("example")',
          'code_example' => 'Use dependency injection instead',
          'line_number' => 86,
        ],
      ],
      'warnings' => [
        'Consider using strict typing',
        'Add return type hints',
      ],
      'summary' => 'Code analysis completed with 2 issues found.',
    ];
  }

  /**
   * Makes an API call with retry logic for rate limits.
   *
   * @param array $payload
   *   The request payload.
   * @param string $api_key
   *   The API key to use.
   * @param int $attempt
   *   Current attempt number.
   *
   * @return array
   *   Decoded API response.
   *
   * @throws \Exception
   *   If all retries fail.
   */
  protected function makeApiCallWithRetry(array $payload, $api_key, $attempt = 1) {
    $logger = $this->loggerFactory->get('ai_upgrade_assistant');

    try {
      $response = $this->httpClient->post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . trim($api_key),
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => 30,
        'connect_timeout' => 10,
        'http_errors' => false,
        'verify' => true,
      ]);

      $status_code = $response->getStatusCode();
      $body = (string) $response->getBody();
      $result = json_decode($body, TRUE);

      // Handle rate limit error
      if ($status_code === 429 && $attempt <= $this->maxRetries) {
        $error = $result['error'] ?? [];
        $wait_time = 0;

        // Parse wait time from error message
        if (!empty($error['message'])) {
          if (preg_match('/Please try again in ([\d.]+)s/', $error['message'], $matches)) {
            $wait_time = ceil((float) $matches[1]);
          }
        }

        // Use exponential backoff if no wait time provided
        if (!$wait_time) {
          $wait_time = $this->baseDelay * pow(2, $attempt - 1);
        }

        $logger->warning('Rate limit hit, attempt @attempt of @max. Waiting @seconds seconds...', [
          '@attempt' => $attempt,
          '@max' => $this->maxRetries,
          '@seconds' => $wait_time,
        ]);

        // Wait before retry
        sleep($wait_time);

        // Retry the request
        return $this->makeApiCallWithRetry($payload, $api_key, $attempt + 1);
      }

      // Handle other errors
      if ($status_code !== 200) {
        throw new \Exception("OpenAI API returned status code: $status_code with body: $body");
      }

      return $result;
    }
    catch (\Exception $e) {
      if ($e->getCode() === 429 && $attempt <= $this->maxRetries) {
        // Wait using exponential backoff
        $wait_time = $this->baseDelay * pow(2, $attempt - 1);
        sleep($wait_time);
        return $this->makeApiCallWithRetry($payload, $api_key, $attempt + 1);
      }
      throw $e;
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
    $logger = $this->loggerFactory->get('ai_upgrade_assistant');
    
    // Return mock results in test mode
    if ($this->testMode) {
      $logger->info('Running in test mode, returning mock results');
      return $this->getMockAnalysisResults();
    }

    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    $api_key = $config->get('openai_api_key');
    $model = $config->get('model') ?: 'gpt-4';

    // Log key details (safely)
    $logger->debug('API key format check: Key starts with: @start, length: @length', [
      '@start' => substr($api_key, 0, 8),
      '@length' => strlen($api_key),
    ]);

    // Check if OpenAI integration is configured
    if (empty($api_key)) {
      $logger->warning('OpenAI API key is not configured.');
      return NULL;
    }

    try {
      $logger->debug('Preparing OpenAI API request with model: @model', [
        '@model' => $model,
      ]);

      // Prepare request payload
      $payload = [
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
      ];

      $logger->debug('Request payload prepared: @payload', [
        '@payload' => json_encode($payload),
      ]);

      $result = $this->makeApiCallWithRetry($payload, $api_key);
      
      if (empty($result['choices'][0]['message']['content'])) {
        $logger->error('Invalid response structure: @response', [
          '@response' => json_encode($result),
        ]);
        throw new \Exception('Invalid response structure from OpenAI API');
      }

      return $this->parseAnalysisResponse($result['choices'][0]['message']['content']);
    }
    catch (\Exception $e) {
      $error_message = $e->getMessage();
      
      // Log detailed error information
      $logger->error('OpenAI API error: @message', [
        '@message' => $error_message,
      ]);

      // Try fallback if configured
      if ($config->get('fallback_to_mock')) {
        $logger->notice('API error occurred, falling back to mock results: @error', [
          '@error' => $error_message,
        ]);
        return $this->getMockAnalysisResults();
      }
      
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
You are an expert Drupal developer analyzing code for compatibility issues and improvements.
Focus on:
1. Deprecated function usage
2. API changes between versions
3. Coding standards compliance
4. Security best practices
5. Performance optimizations

For each issue found, provide:
1. Issue type (deprecation, security, performance, standards)
2. Description of the problem
3. Priority (high, medium, low)
4. Current problematic code
5. Example of the correct code
6. Line number if available

Format your response as JSON with the following structure:
{
  "issues": [
    {
      "type": "string",
      "description": "string",
      "priority": "string",
      "current_code": "string",
      "code_example": "string",
      "line_number": number
    }
  ],
  "warnings": ["string"],
  "summary": "string"
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
    $prompt = "Please analyze the following Drupal code:\n\n";
    
    if (!empty($context['file_path'])) {
      $prompt .= "File: {$context['file_path']}\n";
    }
    if (!empty($context['module'])) {
      $prompt .= "Module: {$context['module']}\n";
    }
    if (!empty($context['drupal_version'])) {
      $prompt .= "Drupal Version: {$context['drupal_version']}\n";
    }
    
    $prompt .= "\nCode:\n{$code}\n\n";
    $prompt .= "Please identify any compatibility issues, deprecated code, or areas for improvement.";
    
    return $prompt;
  }

  /**
   * Parses the analysis response from OpenAI.
   *
   * @param string $response
   *   The response from OpenAI.
   *
   * @return array
   *   Parsed analysis results.
   */
  protected function parseAnalysisResponse($response) {
    try {
      $data = json_decode($response, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Invalid JSON response');
      }
      
      // Ensure required fields exist
      $data['issues'] = $data['issues'] ?? [];
      $data['warnings'] = $data['warnings'] ?? [];
      $data['summary'] = $data['summary'] ?? 'Analysis completed.';
      
      return $data;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('Error parsing OpenAI response: @error', ['@error' => $e->getMessage()]);
      
      // Return a basic structure if parsing fails
      return [
        'issues' => [],
        'warnings' => ['Error parsing analysis results'],
        'summary' => 'Analysis completed with errors.',
      ];
    }
  }

}
