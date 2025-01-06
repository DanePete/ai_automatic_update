<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for tracking module analysis history and status.
 */
class AnalysisTracker {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new AnalysisTracker.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    StateInterface $state,
    ConfigFactoryInterface $config_factory
  ) {
    $this->state = $state;
    $this->configFactory = $config_factory;
  }

  /**
   * Records the start of an analysis.
   *
   * @param string $module
   *   The module being analyzed.
   * @param string $type
   *   The type of analysis (e.g., 'drupal11', 'minor_update').
   * @param array $context
   *   Additional context about the analysis.
   */
  public function startAnalysis($module, $type, array $context = []) {
    $history = $this->getAnalysisHistory($module);
    
    $analysis = [
      'type' => $type,
      'start_time' => time(),
      'status' => 'in_progress',
      'context' => $context,
    ];
    
    array_unshift($history, $analysis);
    $this->saveAnalysisHistory($module, $history);
  }

  /**
   * Records the completion of an analysis.
   *
   * @param string $module
   *   The module being analyzed.
   * @param array $results
   *   The analysis results.
   * @param bool $success
   *   Whether the analysis was successful.
   */
  public function completeAnalysis($module, array $results, $success = TRUE) {
    $history = $this->getAnalysisHistory($module);
    
    if (!empty($history)) {
      $history[0]['end_time'] = time();
      $history[0]['status'] = $success ? 'completed' : 'failed';
      $history[0]['results'] = $results;
      
      $this->saveAnalysisHistory($module, $history);
    }
  }

  /**
   * Gets the analysis history for a module.
   *
   * @param string $module
   *   The module name.
   *
   * @return array
   *   The analysis history.
   */
  public function getAnalysisHistory($module) {
    $key = "ai_upgrade_assistant.analysis_history.$module";
    return $this->state->get($key, []);
  }

  /**
   * Saves the analysis history for a module.
   *
   * @param string $module
   *   The module name.
   * @param array $history
   *   The analysis history to save.
   */
  protected function saveAnalysisHistory($module, array $history) {
    $key = "ai_upgrade_assistant.analysis_history.$module";
    
    // Keep only the last 10 analyses
    $history = array_slice($history, 0, 10);
    
    $this->state->set($key, $history);
  }

  /**
   * Gets the last analysis time for a module.
   *
   * @param string $module
   *   The module name.
   * @param string $type
   *   Optional analysis type to filter by.
   *
   * @return int|null
   *   The timestamp of the last analysis, or NULL if never analyzed.
   */
  public function getLastAnalysisTime($module, $type = NULL) {
    $history = $this->getAnalysisHistory($module);
    
    if (empty($history)) {
      return NULL;
    }

    if ($type) {
      foreach ($history as $analysis) {
        if ($analysis['type'] === $type && $analysis['status'] === 'completed') {
          return $analysis['end_time'];
        }
      }
      return NULL;
    }

    return $history[0]['end_time'] ?? NULL;
  }

  /**
   * Checks if a module needs reanalysis.
   *
   * @param string $module
   *   The module name.
   * @param string $type
   *   The type of analysis.
   *
   * @return bool
   *   TRUE if the module should be reanalyzed.
   */
  public function needsReanalysis($module, $type) {
    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    $recheck_interval = $config->get('recheck_interval') ?? 604800; // Default 1 week
    
    $last_analysis = $this->getLastAnalysisTime($module, $type);
    if (!$last_analysis) {
      return TRUE;
    }

    return (time() - $last_analysis) > $recheck_interval;
  }

  /**
   * Gets analysis statistics.
   *
   * @return array
   *   Statistics about analyses performed.
   */
  public function getAnalysisStats() {
    $modules = \Drupal::service('module_handler')->getModuleList();
    $stats = [
      'total_analyzed' => 0,
      'needs_reanalysis' => 0,
      'never_analyzed' => 0,
      'last_analysis' => NULL,
      'by_type' => [],
    ];

    foreach ($modules as $module => $info) {
      $history = $this->getAnalysisHistory($module);
      
      if (!empty($history)) {
        $stats['total_analyzed']++;
        
        if ($this->needsReanalysis($module, $history[0]['type'])) {
          $stats['needs_reanalysis']++;
        }

        foreach ($history as $analysis) {
          $type = $analysis['type'];
          if (!isset($stats['by_type'][$type])) {
            $stats['by_type'][$type] = 0;
          }
          $stats['by_type'][$type]++;
        }

        // Track most recent analysis
        $end_time = $history[0]['end_time'] ?? NULL;
        if ($end_time && (!$stats['last_analysis'] || $end_time > $stats['last_analysis'])) {
          $stats['last_analysis'] = $end_time;
        }
      }
      else {
        $stats['never_analyzed']++;
      }
    }

    return $stats;
  }
}
