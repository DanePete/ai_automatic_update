<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Service for handling batch analysis of code.
 */
class BatchAnalyzer {
  use StringTranslationTrait;

  /**
   * The project analyzer service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer
   */
  protected $projectAnalyzer;

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
   * Constructs a new BatchAnalyzer object.
   *
   * @param \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer $project_analyzer
   *   The project analyzer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(
    ProjectAnalyzer $project_analyzer,
    StateInterface $state,
    TranslationInterface $string_translation,
    ModuleHandlerInterface $module_handler
  ) {
    $this->projectAnalyzer = $project_analyzer;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
    $this->setStringTranslation($string_translation);
  }

  /**
   * Creates a batch for analyzing the project.
   *
   * @return array
   *   The batch definition.
   */
  public function createAnalysisBatch() {
    $project_info = $this->projectAnalyzer->getProjectInfo();
    $operations = [];
    $config = \Drupal::config('ai_upgrade_assistant.settings');

    // Add core analysis
    $operations[] = [
      [$this, 'analyzeDrupalCore'],
      [$project_info['drupal_version']],
    ];

    // Add custom module analysis
    if ($config->get('scan_custom_modules')) {
      foreach ($project_info['custom_modules'] as $name => $info) {
        $module_path = $this->moduleHandler->getModule($name)->getPath();
        if ($module_path && is_dir($module_path)) {
          $files = $this->projectAnalyzer->findPhpFiles($module_path);
          foreach ($files as $file) {
            $operations[] = [
              [$this, 'analyzeFile'],
              [
                $file,
                $name,
                'custom_module',
              ],
            ];
          }
        }
      }
    }

    // Add contributed module analysis
    if ($config->get('scan_contrib_modules')) {
      foreach ($project_info['installed_modules'] as $name => $info) {
        $operations[] = [
          [$this, 'analyzeModule'],
          [$name, $info],
        ];
      }
    }

    // Add theme analysis
    if ($config->get('scan_themes')) {
      // TODO: Implement theme analysis
    }

    return [
      'operations' => $operations,
      'finished' => [$this, 'finishBatch'],
      'title' => $this->t('Analyzing project for upgrade recommendations'),
      'init_message' => $this->t('Starting analysis...'),
      'progress_message' => $this->t('Analyzed @current out of @total items.'),
      'error_message' => $this->t('An error occurred during analysis.'),
    ];
  }

  /**
   * Batch operation: Analyze Drupal core compatibility.
   */
  public function analyzeDrupalCore($version, &$context) {
    $context['message'] = $this->t('Analyzing Drupal core compatibility...');
    $context['results']['core'] = [
      'version' => $version,
      'compatible' => version_compare($version, '10.0.0', '>='),
    ];
  }

  /**
   * Batch operation: Analyze a single file.
   */
  public function analyzeFile($file_path, $module_name, $type, &$context) {
    $context['message'] = $this->t('Analyzing @file...', ['@file' => basename($file_path)]);
    
    $code = file_get_contents($file_path);
    $analysis_context = [
      'type' => $type,
      'module_name' => $module_name,
      'drupal_version' => \Drupal::VERSION,
      'target_version' => '10.0.0',
      'file_path' => str_replace(DRUPAL_ROOT . '/', '', $file_path),
    ];

    $analysis = $this->projectAnalyzer->getOpenAI()->analyzeCode($code, $analysis_context);

    if (!isset($context['results']['files'])) {
      $context['results']['files'] = [];
    }
    $context['results']['files'][$file_path] = $analysis;

    // Store partial results in state for progress tracking
    $this->state->set('ai_upgrade_assistant.analysis_results', $context['results']);
  }

  /**
   * Batch operation: Analyze a module.
   */
  public function analyzeModule($name, $info, &$context) {
    $context['message'] = $this->t('Analyzing module @name...', ['@name' => $name]);
    
    if (!isset($context['results']['modules'])) {
      $context['results']['modules'] = [];
    }

    $context['results']['modules'][$name] = [
      'info' => $info,
      'compatible' => isset($info['core_version_requirement']) && 
        $this->projectAnalyzer->isCompatibleWithDrupal10($info['core_version_requirement']),
    ];
  }

  /**
   * Batch finish callback.
   */
  public function finishBatch($success, $results, $operations) {
    if ($success) {
      // Store the final results
      $this->state->set('ai_upgrade_assistant.analysis_results', $results);
      $this->state->set('ai_upgrade_assistant.last_analysis', \Drupal::time()->getRequestTime());
      
      \Drupal::messenger()->addStatus(t('Project analysis completed successfully.'));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during project analysis.'));
    }
  }

  /**
   * Creates a batch for analyzing multiple modules.
   *
   * @param array $modules
   *   Array of module names to analyze.
   *
   * @return array
   *   Batch definition array.
   */
  public function createAnalysisBatchForModules(array $modules) {
    $operations = [];
    $total_files = 0;

    // First pass - count total files
    foreach ($modules as $module) {
      $module_path = $this->moduleHandler->getPath($module);
      if ($module_path) {
        $files = $this->projectAnalyzer->findPhpFiles($module_path);
        $total_files += count($files);
      }
    }

    // Create batch operations
    foreach ($modules as $module) {
      $operations[] = [
        [$this, 'processModule'],
        [
          $module,
          \Drupal::currentUser()->id(),
        ],
      ];
    }

    return [
      'operations' => $operations,
      'finished' => [$this, 'finishModuleAnalysis'],
      'title' => $this->t('Analyzing modules for upgrade compatibility'),
      'init_message' => $this->t('Starting code analysis...'),
      'progress_message' => $this->t('Analyzed @current out of @total modules.'),
      'error_message' => $this->t('Error analyzing modules.'),
    ];
  }

  /**
   * Processes a single module in the batch.
   *
   * @param string $module
   *   Module name.
   * @param int $uid
   *   User ID who started the analysis.
   * @param array $context
   *   Batch context.
   */
  public function processModule($module, $uid, array &$context) {
    $logger = \Drupal::logger('ai_upgrade_assistant');
    
    try {
      // Initialize progress information
      if (!isset($context['sandbox']['progress'])) {
        $context['sandbox']['progress'] = 0;
        $context['sandbox']['current_module'] = $module;
        $context['sandbox']['start_time'] = microtime(TRUE);
        $context['results']['modules_processed'] = 0;
        $context['results']['files_processed'] = 0;
      }

      $logger->info('Starting analysis of module: @module', ['@module' => $module]);
      
      // Get module path
      $module_path = $this->moduleHandler->getPath($module);
      if (!$module_path) {
        throw new \Exception("Module path not found for $module");
      }

      // Analyze the module
      $results = $this->projectAnalyzer->analyzeCustomModule($module, $module_path);

      // Store results
      $existing_results = $this->state->get('ai_upgrade_assistant.analysis_results', []);
      $existing_results[$module] = [
        'results' => $results,
        'timestamp' => time(),
        'analyzed_by' => $uid,
      ];
      $this->state->set('ai_upgrade_assistant.analysis_results', $existing_results);

      // Update progress
      $context['sandbox']['progress']++;
      $context['results']['modules_processed']++;
      $context['results']['files_processed'] += count($results['files'] ?? []);
      
      // Calculate time taken
      $time_taken = microtime(TRUE) - $context['sandbox']['start_time'];
      $context['results']['time_taken'][$module] = $time_taken;

      // Set message
      $context['message'] = $this->t('Analyzed module @module (@time seconds)', [
        '@module' => $module,
        '@time' => round($time_taken, 2),
      ]);

      // Store any warnings or errors
      if (!empty($results['errors'])) {
        $context['results']['errors'][$module] = $results['errors'];
      }
      if (!empty($results['warnings'])) {
        $context['results']['warnings'][$module] = $results['warnings'];
      }

      $logger->info('Completed analysis of module: @module in @time seconds', [
        '@module' => $module,
        '@time' => round($time_taken, 2),
      ]);
    }
    catch (\Exception $e) {
      $logger->error('Error analyzing module @module: @error', [
        '@module' => $module,
        '@error' => $e->getMessage(),
      ]);
      
      // Store error but continue with next module
      $context['results']['errors'][$module] = $e->getMessage();
    }
  }

  /**
   * Finish callback for module analysis batch.
   */
  public function finishModuleAnalysis($success, $results, $operations) {
    $logger = \Drupal::logger('ai_upgrade_assistant');
    
    if ($success) {
      $message = $this->t('Analyzed @modules modules (@files files) in @time seconds', [
        '@modules' => $results['modules_processed'],
        '@files' => $results['files_processed'],
        '@time' => round(array_sum($results['time_taken']), 2),
      ]);
      \Drupal::messenger()->addStatus($message);
      
      $logger->info('Batch analysis completed successfully: @message', [
        '@message' => $message,
      ]);

      // Update last analysis time
      $this->state->set('ai_upgrade_assistant.last_analysis', time());
    }
    else {
      $error_message = $this->t('Some errors occurred during analysis.');
      \Drupal::messenger()->addError($error_message);
      
      $logger->error('Batch analysis completed with errors: @errors', [
        '@errors' => print_r($results['errors'] ?? [], TRUE),
      ]);
    }
  }
}
