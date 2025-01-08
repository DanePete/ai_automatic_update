<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer;
use Drupal\ai_upgrade_assistant\Service\AnalysisTracker;

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
   * The analysis tracker service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\AnalysisTracker
   */
  protected $analysisTracker;

  /**
   * Batch operation data.
   *
   * @var array
   */
  protected $batchData;

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
   * @param \Drupal\ai_upgrade_assistant\Service\AnalysisTracker $analysis_tracker
   *   The analysis tracker service.
   */
  public function __construct(
    ProjectAnalyzer $project_analyzer,
    StateInterface $state,
    TranslationInterface $string_translation,
    ModuleHandlerInterface $module_handler,
    AnalysisTracker $analysis_tracker
  ) {
    $this->projectAnalyzer = $project_analyzer;
    $this->state = $state;
    $this->setStringTranslation($string_translation);
    $this->moduleHandler = $module_handler;
    $this->analysisTracker = $analysis_tracker;
  }

  /**
   * Creates a batch for analyzing the project.
   *
   * @param array $options
   *   Analysis options:
   *   - modules: Array of module names to analyze.
   *   - type: Type of analysis (e.g., 'drupal11', 'security').
   *   - batch_size: Number of files per batch operation.
   *
   * @return array
   *   The batch definition.
   */
  public function createBatch(array $options = []) {
    $this->batchData = [
      'current_module' => '',
      'files_processed' => 0,
      'total_files' => 0,
      'errors' => [],
      'results' => [],
    ];

    // Store batch data in state for resume capability
    $batch_id = uniqid('ai_upgrade_', TRUE);
    $this->state->set('ai_upgrade_assistant.current_batch', $batch_id);
    $this->state->set("ai_upgrade_assistant.batch.$batch_id", $this->batchData);

    $batch_builder = new BatchBuilder();
    $batch_builder
      ->setTitle($this->t('Analyzing project code'))
      ->setInitMessage($this->t('Starting code analysis...'))
      ->setProgressMessage($this->t('Analyzed @current out of @total files.'))
      ->setErrorMessage($this->t('Error analyzing code.'))
      ->setFinishCallback([$this, 'batchFinished']);

    // Add setup operation
    $batch_builder->addOperation(
      [$this, 'batchSetup'],
      [$options, $batch_id]
    );

    // Get modules to analyze
    $modules = $options['modules'] ?? $this->getModulesToAnalyze();

    foreach ($modules as $module) {
      $files = $this->projectAnalyzer->getModuleFiles($module);
      $chunks = array_chunk($files, $options['batch_size'] ?? 50);

      foreach ($chunks as $chunk) {
        $batch_builder->addOperation(
          [$this, 'batchProcessFiles'],
          [
            $module,
            $chunk,
            $options['type'] ?? 'general',
            $batch_id,
          ]
        );
      }
    }

    return $batch_builder->toArray();
  }

  /**
   * Setup batch process.
   *
   * @param array $options
   *   Batch options.
   * @param string $batch_id
   *   Unique batch ID.
   * @param array $context
   *   Batch context.
   */
  public function batchSetup(array $options, $batch_id, array &$context) {
    $modules = $options['modules'] ?? $this->getModulesToAnalyze();
    $total_files = 0;

    foreach ($modules as $module) {
      $files = $this->projectAnalyzer->getModuleFiles($module);
      $total_files += count($files);
    }

    $this->batchData['total_files'] = $total_files;
    $this->state->set("ai_upgrade_assistant.batch.$batch_id", $this->batchData);

    $context['results']['batch_id'] = $batch_id;
    $context['results']['total_files'] = $total_files;
    $context['results']['start_time'] = time();
  }

  /**
   * Process a batch of files.
   *
   * @param string $module
   *   Module name.
   * @param array $files
   *   Files to process.
   * @param string $type
   *   Analysis type.
   * @param string $batch_id
   *   Unique batch ID.
   * @param array $context
   *   Batch context.
   */
  public function batchProcessFiles($module, array $files, $type, $batch_id, array &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($files);
    }

    // Load current batch data
    $this->batchData = $this->state->get("ai_upgrade_assistant.batch.$batch_id", []);
    $this->batchData['current_module'] = $module;

    foreach ($files as $file) {
      try {
        // Analyze file
        $result = $this->projectAnalyzer->analyzeFile($file, [
          'module' => $module,
          'type' => $type,
        ]);

        // Track results
        $this->batchData['results'][$file] = $result;
        $this->batchData['files_processed']++;

        // Update progress
        $context['sandbox']['progress']++;
        $context['results']['processed'][] = $file;

        // Calculate progress percentage
        $progress = ($this->batchData['files_processed'] / $this->batchData['total_files']) * 100;
        $context['message'] = $this->t('Analyzing @module: @file (@progress%)', [
          '@module' => $module,
          '@file' => basename($file),
          '@progress' => round($progress, 1),
        ]);

      }
      catch (\Exception $e) {
        $this->batchData['errors'][$file] = $e->getMessage();
        watchdog_exception('ai_upgrade_assistant', $e);
      }

      // Save batch data after each file
      $this->state->set("ai_upgrade_assistant.batch.$batch_id", $this->batchData);
    }

    // Update batch status
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }

  /**
   * Batch finish callback.
   *
   * @param bool $success
   *   Whether the batch succeeded.
   * @param array $results
   *   Batch results.
   * @param array $operations
   *   Remaining operations.
   */
  public function batchFinished($success, array $results, array $operations) {
    if ($success) {
      $batch_id = $results['batch_id'];
      $batch_data = $this->state->get("ai_upgrade_assistant.batch.$batch_id", []);

      // Calculate statistics
      $duration = time() - $results['start_time'];
      $files_processed = count($results['processed']);
      $error_count = count($batch_data['errors']);

      // Log completion
      \Drupal::logger('ai_upgrade_assistant')->info(
        'Analysis completed: @files files processed in @time seconds with @errors errors.',
        [
          '@files' => $files_processed,
          '@time' => $duration,
          '@errors' => $error_count,
        ]
      );

      // Clean up state
      $this->state->delete("ai_upgrade_assistant.batch.$batch_id");
      $this->state->delete('ai_upgrade_assistant.current_batch');

      // Set message
      \Drupal::messenger()->addStatus(t(
        'Analysis completed. Processed @files files in @time seconds with @errors errors.',
        [
          '@files' => $files_processed,
          '@time' => $duration,
          '@errors' => $error_count,
        ]
      ));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during analysis.'));
    }
  }

  /**
   * Gets the list of modules to analyze.
   *
   * @return array
   *   Array of module names.
   */
  protected function getModulesToAnalyze() {
    $modules = [];
    $config = \Drupal::config('ai_upgrade_assistant.settings');

    // Get custom modules
    if ($config->get('scan_custom_modules')) {
      $custom_modules = $this->projectAnalyzer->getCustomModules();
      $modules = array_merge($modules, array_keys($custom_modules));
    }

    // Get contributed modules
    if ($config->get('scan_contrib_modules')) {
      $contrib_modules = $this->projectAnalyzer->getContribModules();
      $modules = array_merge($modules, array_keys($contrib_modules));
    }

    return array_unique($modules);
  }

  /**
   * Resumes a previously interrupted batch process.
   *
   * @param string $batch_id
   *   The batch ID to resume.
   *
   * @return array|null
   *   The batch definition if resumable, NULL otherwise.
   */
  public function resumeBatch($batch_id) {
    $batch_data = $this->state->get("ai_upgrade_assistant.batch.$batch_id");
    if (!$batch_data) {
      return NULL;
    }

    // Create new batch starting from where we left off
    $options = [
      'modules' => [$batch_data['current_module']],
      'batch_size' => 50,
    ];

    return $this->createBatch($options);
  }

  /**
   * Gets the current batch progress.
   *
   * @return array
   *   Progress information:
   *   - current_module: Current module being processed.
   *   - files_processed: Number of files processed.
   *   - total_files: Total number of files.
   *   - progress: Progress percentage.
   *   - errors: Array of errors encountered.
   */
  public function getBatchProgress() {
    $batch_id = $this->state->get('ai_upgrade_assistant.current_batch');
    if (!$batch_id) {
      return [];
    }

    $batch_data = $this->state->get("ai_upgrade_assistant.batch.$batch_id", []);
    if (empty($batch_data)) {
      return [];
    }

    $progress = ($batch_data['files_processed'] / $batch_data['total_files']) * 100;

    return [
      'current_module' => $batch_data['current_module'],
      'files_processed' => $batch_data['files_processed'],
      'total_files' => $batch_data['total_files'],
      'progress' => round($progress, 1),
      'errors' => $batch_data['errors'],
    ];
  }
}
