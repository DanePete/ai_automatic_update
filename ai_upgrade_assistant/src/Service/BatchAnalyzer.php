<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\State\StateInterface;

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
   * Constructs a new BatchAnalyzer object.
   *
   * @param \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer $project_analyzer
   *   The project analyzer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    ProjectAnalyzer $project_analyzer,
    StateInterface $state,
    TranslationInterface $string_translation
  ) {
    $this->projectAnalyzer = $project_analyzer;
    $this->state = $state;
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
        $module_path = drupal_get_path('module', $name);
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

}
