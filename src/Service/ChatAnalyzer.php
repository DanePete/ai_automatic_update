<?php 

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for analyzing command outputs and providing intelligent responses.
 */
class ChatAnalyzer {
  use StringTranslationTrait;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The OpenAI service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\OpenAIService
   */
  protected $openai;

  /**
   * Constructs a new ChatAnalyzer object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\ai_upgrade_assistant\Service\OpenAIService $openai
   *   The OpenAI service.
   */
  public function __construct(
    StateInterface $state,
    TranslationInterface $string_translation,
    ModuleHandlerInterface $module_handler,
    LoggerChannelFactoryInterface $logger_factory,
    OpenAIService $openai
  ) {
    $this->state = $state;
    $this->moduleHandler = $module_handler;
    $this->setStringTranslation($string_translation);
    $this->loggerFactory = $logger_factory;
    $this->openai = $openai;
  }

  /**
   * Analyzes the command output to determine next steps.
   *
   * @param array $output
   *   The output from the command execution.
   * @param array $context
   *   Additional context about the command execution.
   *   - command: The command that was executed.
   *   - module: The module being analyzed.
   *   - phase: The current upgrade phase.
   *
   * @return array
   *   Analysis results containing:
   *   - next_step: Recommended next step.
   *   - explanation: Explanation of the recommendation.
   *   - severity: The severity level of any issues found.
   *   - suggestions: Array of specific suggestions.
   */
  public function analyzeCommandOutput(array $output, array $context = []) {
    $logger = $this->loggerFactory->get('ai_upgrade_assistant');
    
    try {
      // Prepare the analysis prompt
      $prompt = $this->prepareAnalysisPrompt($output, $context);
      
      // Get AI analysis
      $analysis = $this->openai->analyzeCode($prompt, [
        'type' => 'command_analysis',
        'context' => $context,
      ]);
      
      if (empty($analysis)) {
        $logger->warning('No analysis results returned for command output');
        return $this->getDefaultAnalysis();
      }
      
      return $this->processAnalysisResults($analysis);
    }
    catch (\Exception $e) {
      $logger->error('Error analyzing command output: @message', ['@message' => $e->getMessage()]);
      return $this->getDefaultAnalysis();
    }
  }

  /**
   * Prepares the analysis prompt for the command output.
   *
   * @param array $output
   *   The command output.
   * @param array $context
   *   The execution context.
   *
   * @return string
   *   The prepared prompt.
   */
  protected function prepareAnalysisPrompt(array $output, array $context) {
    $outputText = implode("\n", $output);
    
    $prompt = "Analyze the following Drupal command output and provide upgrade recommendations:\n\n";
    $prompt .= "Command: {$context['command']}\n";
    $prompt .= "Module: {$context['module']}\n";
    $prompt .= "Phase: {$context['phase']}\n\n";
    $prompt .= "Output:\n$outputText\n\n";
    $prompt .= "Please provide:\n";
    $prompt .= "1. Next recommended step\n";
    $prompt .= "2. Explanation of any issues found\n";
    $prompt .= "3. Severity level of issues (none, low, medium, high, critical)\n";
    $prompt .= "4. Specific suggestions for resolving any issues\n";
    
    return $prompt;
  }

  /**
   * Processes the AI analysis results.
   *
   * @param array $analysis
   *   The raw analysis results.
   *
   * @return array
   *   Processed analysis results.
   */
  protected function processAnalysisResults(array $analysis) {
    // Extract key information from the analysis
    return [
      'next_step' => $analysis['next_step'] ?? $this->t('Continue with the upgrade process'),
      'explanation' => $analysis['explanation'] ?? $this->t('No issues detected in the command output'),
      'severity' => $analysis['severity'] ?? 'none',
      'suggestions' => $analysis['suggestions'] ?? [],
    ];
  }

  /**
   * Gets default analysis results when AI analysis fails.
   *
   * @return array
   *   Default analysis structure.
   */
  protected function getDefaultAnalysis() {
    return [
      'next_step' => $this->t('Continue with standard upgrade process'),
      'explanation' => $this->t('Unable to perform detailed analysis of the command output'),
      'severity' => 'unknown',
      'suggestions' => [
        $this->t('Review the command output manually'),
        $this->t('Check the error logs for more information'),
        $this->t('Consider running the command again if it failed'),
      ],
    ];
  }

  /**
   * Executes a command and interprets the result.
   *
   * @param string $command
   *   The command to execute.
   * @param array $context
   *   Additional context for the command execution.
   *
   * @return array
   *   The result of the command execution with analysis.
   */
  public function executeCommand($command, array $context = []) {
    $logger = $this->loggerFactory->get('ai_upgrade_assistant');
    
    try {
      // Execute the command
      $output = [];
      $return_var = 0;
      exec($command, $output, $return_var);
      
      // Log the execution
      $logger->info('Executed command: @command', ['@command' => $command]);
      
      // Analyze the output
      $analysis = $this->analyzeCommandOutput($output, [
        'command' => $command,
        'return_code' => $return_var,
      ] + $context);
      
      return [
        'success' => $return_var === 0,
        'output' => $output,
        'return_code' => $return_var,
        'analysis' => $analysis,
      ];
    }
    catch (\Exception $e) {
      $logger->error('Error executing command: @message', ['@message' => $e->getMessage()]);
      
      return [
        'success' => FALSE,
        'output' => [$e->getMessage()],
        'return_code' => -1,
        'analysis' => $this->getDefaultAnalysis(),
      ];
    }
  }

  /**
   * Executes a Drush command and analyzes the output.
   *
   * @param string $command
   *   The Drush command to execute.
   * @param array $args
   *   Command arguments.
   * @param array $context
   *   Additional context.
   *
   * @return array
   *   Analysis of the command execution.
   */
  public function executeDrushCommand($command, array $args = [], array $context = []) {
    $fullCommand = 'drush ' . $command . ' ' . implode(' ', $args);
    $context['command_type'] = 'drush';
    return $this->executeCommand($fullCommand, $context);
  }

  /**
   * Analyzes SQL query for potential issues.
   *
   * @param string $query
   *   The SQL query to analyze.
   * @param array $context
   *   Additional context about the query.
   *
   * @return array
   *   Analysis results containing potential issues and recommendations.
   */
  public function analyzeSqlQuery($query, array $context = []) {
    $logger = $this->loggerFactory->get('ai_upgrade_assistant');
    
    try {
      $analysis = $this->openai->analyzeCode($query, [
        'type' => 'sql_analysis',
        'context' => array_merge($context, ['language' => 'sql']),
      ]);
      
      if (empty($analysis)) {
        $logger->warning('No analysis results returned for SQL query');
        return $this->getDefaultAnalysis();
      }
      
      return $this->processSqlAnalysisResults($analysis);
    }
    catch (\Exception $e) {
      $logger->error('Error analyzing SQL query: @message', ['@message' => $e->getMessage()]);
      return $this->getDefaultAnalysis();
    }
  }

  /**
   * Processes SQL analysis results.
   *
   * @param array $analysis
   *   The raw analysis results.
   *
   * @return array
   *   Processed analysis results with SQL-specific recommendations.
   */
  protected function processSqlAnalysisResults(array $analysis) {
    $results = $this->processAnalysisResults($analysis);
    
    // Add SQL-specific fields
    $results['query_impact'] = $this->extractQueryImpact($analysis);
    $results['index_suggestions'] = $this->extractIndexSuggestions($analysis);
    $results['optimization_tips'] = $this->extractOptimizationTips($analysis);
    
    return $results;
  }

  /**
   * Extracts query performance impact assessment.
   *
   * @param array $analysis
   *   The analysis results.
   *
   * @return string
   *   Impact assessment (low, medium, high).
   */
  protected function extractQueryImpact(array $analysis) {
    // Implementation details...
    return isset($analysis['query_impact']) ? $analysis['query_impact'] : 'unknown';
  }

  /**
   * Extracts index suggestions from analysis.
   *
   * @param array $analysis
   *   The analysis results.
   *
   * @return array
   *   List of suggested indexes.
   */
  protected function extractIndexSuggestions(array $analysis) {
    return isset($analysis['index_suggestions']) ? $analysis['index_suggestions'] : [];
  }

  /**
   * Extracts query optimization tips.
   *
   * @param array $analysis
   *   The analysis results.
   *
   * @return array
   *   List of optimization tips.
   */
  protected function extractOptimizationTips(array $analysis) {
    return isset($analysis['optimization_tips']) ? $analysis['optimization_tips'] : [];
  }
}
