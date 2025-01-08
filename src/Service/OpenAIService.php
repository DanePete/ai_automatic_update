<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for interacting with OpenAI API for code analysis.
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
   * Maximum retries for API calls.
   *
   * @var int
   */
  protected $maxRetries = 3;

  /**
   * Base delay between retries in seconds.
   *
   * @var int
   */
  protected $baseRetryDelay = 2;

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

    if (empty($api_key) || !$this->isValidApiKeyFormat($api_key)) {
      $logger->error('Invalid or missing OpenAI API key');
      return NULL;
    }

    $prompt = $this->prepareCodeAnalysisPrompt($code, $context);
    
    try {
      $payload = [
        'model' => 'gpt-4',
        'messages' => [
          [
            'role' => 'system',
            'content' => $this->getSystemPrompt(),
          ],
          [
            'role' => 'user',
            'content' => $prompt,
          ],
        ],
        'temperature' => 0.2,
        'max_tokens' => 2000,
      ];

      $response = $this->makeApiCallWithRetry($payload, $api_key);
      return $this->parseAnalysisResponse($response);
    }
    catch (\Exception $e) {
      $logger->error('Error analyzing code: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
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
      ]);

      return json_decode((string) $response->getBody(), TRUE);
    }
    catch (GuzzleException $e) {
      // Handle rate limits with exponential backoff
      if ($e->getCode() === 429 && $attempt < $this->maxRetries) {
        $delay = $this->baseRetryDelay * pow(2, $attempt - 1);
        $logger->warning('Rate limited, retrying in @seconds seconds (attempt @attempt)', [
          '@seconds' => $delay,
          '@attempt' => $attempt,
        ]);
        
        sleep($delay);
        return $this->makeApiCallWithRetry($payload, $api_key, $attempt + 1);
      }
      
      throw new \Exception('OpenAI API request failed: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Gets the system prompt for code analysis.
   *
   * @return string
   *   The system prompt.
   */
  protected function getSystemPrompt() {
    return "You are an expert Drupal developer specializing in code analysis and upgrades. " .
           "Your task is to analyze code and provide detailed, actionable recommendations " .
           "for upgrading and improving Drupal codebases. Focus on:\n\n" .
           "1. Deprecated code and APIs\n" .
           "2. Security best practices\n" .
           "3. Performance optimizations\n" .
           "4. Drupal coding standards\n" .
           "5. Compatibility issues\n\n" .
           "Provide specific, practical recommendations that can be implemented by developers.";
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
    $type = $context['type'] ?? 'general';
    $prompt = '';

    switch ($type) {
      case 'command_analysis':
        $prompt = "Analyze this Drupal command output for upgrade-related issues:\n\n$code";
        break;

      case 'deprecation':
        $prompt = "Identify deprecated code and suggest modern alternatives in this Drupal code:\n\n$code";
        break;

      case 'security':
        $prompt = "Review this Drupal code for security issues and best practices:\n\n$code";
        break;

      case 'performance':
        $prompt = "Analyze this Drupal code for performance optimizations:\n\n$code";
        break;

      default:
        $prompt = "Analyze this Drupal code for upgrade compatibility, security, and best practices:\n\n$code";
    }

    if (!empty($context['drupal_version'])) {
      $prompt .= "\n\nTarget Drupal version: {$context['drupal_version']}";
    }

    return $prompt;
  }

  /**
   * Parses the analysis response from OpenAI.
   *
   * @param array $response
   *   The response from OpenAI.
   *
   * @return array
   *   Parsed analysis results.
   */
  protected function parseAnalysisResponse($response) {
    if (empty($response['choices'][0]['message']['content'])) {
      throw new \Exception('Invalid response format from OpenAI');
    }

    $content = $response['choices'][0]['message']['content'];
    
    // Parse the content into structured data
    // This is a simple implementation - enhance based on actual response format
    return [
      'next_step' => $this->extractNextStep($content),
      'explanation' => $this->extractExplanation($content),
      'severity' => $this->extractSeverity($content),
      'suggestions' => $this->extractSuggestions($content),
    ];
  }

  /**
   * Extracts the next step from the analysis content.
   */
  protected function extractNextStep($content) {
    // Implementation needed
    return 'Continue with upgrade process';
  }

  /**
   * Extracts the explanation from the analysis content.
   */
  protected function extractExplanation($content) {
    // Implementation needed
    return $content;
  }

  /**
   * Extracts the severity level from the analysis content.
   */
  protected function extractSeverity($content) {
    // Implementation needed
    return 'medium';
  }

  /**
   * Extracts specific suggestions from the analysis content.
   */
  protected function extractSuggestions($content) {
    // Implementation needed
    return [];
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
    return (bool) preg_match('/^sk-[a-zA-Z0-9]{32,}$/', trim($api_key));
  }

  /**
   * Gets mock analysis results for test mode.
   *
   * @return array
   *   Mock analysis results.
   */
  protected function getMockAnalysisResults() {
    return [
      'next_step' => 'Continue with upgrade process',
      'explanation' => 'This is a mock analysis result for testing purposes.',
      'severity' => 'low',
      'suggestions' => [
        'Mock suggestion 1',
        'Mock suggestion 2',
      ],
    ];
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
}
