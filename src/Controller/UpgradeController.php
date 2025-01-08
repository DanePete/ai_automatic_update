<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer;
use Drupal\ai_upgrade_assistant\Service\AnalysisTracker;
use Drupal\ai_upgrade_assistant\Service\BatchAnalyzer;

/**
 * Controller for the AI Upgrade Assistant.
 */
class UpgradeController extends ControllerBase {

  /**
   * The project analyzer service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer
   */
  protected $projectAnalyzer;

  /**
   * The analysis tracker service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\AnalysisTracker
   */
  protected $analysisTracker;

  /**
   * The batch analyzer service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\BatchAnalyzer
   */
  protected $batchAnalyzer;

  /**
   * Constructs a new UpgradeController object.
   *
   * @param \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer $project_analyzer
   *   The project analyzer service.
   * @param \Drupal\ai_upgrade_assistant\Service\AnalysisTracker $analysis_tracker
   *   The analysis tracker service.
   * @param \Drupal\ai_upgrade_assistant\Service\BatchAnalyzer $batch_analyzer
   *   The batch analyzer service.
   */
  public function __construct(
    ProjectAnalyzer $project_analyzer,
    AnalysisTracker $analysis_tracker,
    BatchAnalyzer $batch_analyzer
  ) {
    $this->projectAnalyzer = $project_analyzer;
    $this->analysisTracker = $analysis_tracker;
    $this->batchAnalyzer = $batch_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_upgrade_assistant.project_analyzer'),
      $container->get('ai_upgrade_assistant.analysis_tracker'),
      $container->get('ai_upgrade_assistant.batch_analyzer')
    );
  }

  /**
   * Displays the upgrade assistant overview.
   *
   * @return array
   *   A render array representing the upgrade assistant dashboard.
   */
  public function overview() {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['upgrade-assistant-dashboard']],
    ];

    // Status summary section
    $build['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['status-summary']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Upgrade Status') . '</h2>',
      ],
    ];

    // Get project analysis data
    $project_info = $this->projectAnalyzer->getProjectInfo();
    $stats = $this->analysisTracker->getAnalysisStats();

    // Project overview
    $build['status']['overview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['project-overview']],
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Current Drupal Version: @version', ['@version' => $project_info['drupal_version']]),
          $this->t('Total Modules: @total', ['@total' => $stats['total_analyzed'] + $stats['never_analyzed']]),
          $this->t('Modules Analyzed: @analyzed', ['@analyzed' => $stats['total_analyzed']]),
          $this->t('Modules Needing Review: @review', ['@review' => $stats['needs_reanalysis']]),
        ],
      ],
    ];

    // Action buttons
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['upgrade-actions']],
      'analyze' => [
        '#type' => 'link',
        '#title' => $this->t('Start Analysis'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.start_analysis'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'analyze-button'],
        ],
      ],
      'generate_report' => [
        '#type' => 'link',
        '#title' => $this->t('Generate Report'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.generate_report'),
        '#attributes' => [
          'class' => ['button', 'report-button'],
        ],
      ],
      'settings' => [
        '#type' => 'link',
        '#title' => $this->t('Settings'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.settings'),
        '#attributes' => [
          'class' => ['button', 'settings-button'],
        ],
      ],
    ];

    // Analysis results
    if ($stats['total_analyzed'] > 0) {
      $build['results'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['analysis-results']],
        'title' => [
          '#markup' => '<h3>' . $this->t('Analysis Results') . '</h3>',
        ],
      ];

      // Module analysis table
      $headers = [
        $this->t('Module'),
        $this->t('Status'),
        $this->t('Compatibility'),
        $this->t('Issues'),
        $this->t('Actions'),
      ];

      $rows = [];
      $modules = $this->projectAnalyzer->getAnalyzedModules();
      foreach ($modules as $module => $analysis) {
        $rows[] = [
          $module,
          $this->getStatusLabel($analysis['status']),
          $this->getCompatibilityLabel($analysis['compatibility']),
          count($analysis['issues']),
          $this->getModuleActions($module),
        ];
      }

      $build['results']['table'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $rows,
        '#empty' => $this->t('No modules have been analyzed yet.'),
      ];
    }

    // Progress tracker for ongoing analysis
    $progress = $this->batchAnalyzer->getBatchProgress();
    if (!empty($progress['current_module'])) {
      $build['progress'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['analysis-progress'],
          'id' => 'analysis-progress-wrapper',
        ],
        'info' => [
          '#markup' => $this->t('Analyzing @module: @progress%', [
            '@module' => $progress['current_module'],
            '@progress' => $progress['progress'],
          ]),
        ],
        'bar' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['progress-bar'],
            'style' => 'width: ' . $progress['progress'] . '%;',
          ],
        ],
      ];
    }

    // Add our CSS and JS
    $build['#attached']['library'][] = 'ai_upgrade_assistant/upgrade_assistant';

    return $build;
  }

  /**
   * Gets a formatted status label.
   *
   * @param string $status
   *   The status code.
   *
   * @return array
   *   A render array for the status label.
   */
  protected function getStatusLabel($status) {
    $labels = [
      'complete' => [
        'label' => $this->t('Complete'),
        'class' => 'status-complete',
      ],
      'in_progress' => [
        'label' => $this->t('In Progress'),
        'class' => 'status-progress',
      ],
      'needs_review' => [
        'label' => $this->t('Needs Review'),
        'class' => 'status-review',
      ],
      'error' => [
        'label' => $this->t('Error'),
        'class' => 'status-error',
      ],
    ];

    $info = $labels[$status] ?? [
      'label' => $this->t('Unknown'),
      'class' => 'status-unknown',
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $info['label'],
      '#attributes' => ['class' => [$info['class']]],
    ];
  }

  /**
   * Gets a formatted compatibility label.
   *
   * @param string $compatibility
   *   The compatibility level.
   *
   * @return array
   *   A render array for the compatibility label.
   */
  protected function getCompatibilityLabel($compatibility) {
    $labels = [
      'compatible' => [
        'label' => $this->t('Compatible'),
        'class' => 'compat-yes',
      ],
      'partial' => [
        'label' => $this->t('Partial'),
        'class' => 'compat-partial',
      ],
      'incompatible' => [
        'label' => $this->t('Incompatible'),
        'class' => 'compat-no',
      ],
    ];

    $info = $labels[$compatibility] ?? [
      'label' => $this->t('Unknown'),
      'class' => 'compat-unknown',
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $info['label'],
      '#attributes' => ['class' => [$info['class']]],
    ];
  }

  /**
   * Gets action links for a module.
   *
   * @param string $module
   *   The module name.
   *
   * @return array
   *   A render array of action links.
   */
  protected function getModuleActions($module) {
    return [
      '#type' => 'operations',
      '#links' => [
        'view' => [
          'title' => $this->t('View Details'),
          'url' => Url::fromRoute('ai_upgrade_assistant.module_details', ['module' => $module]),
        ],
        'reanalyze' => [
          'title' => $this->t('Reanalyze'),
          'url' => Url::fromRoute('ai_upgrade_assistant.analyze_module', ['module' => $module]),
        ],
        'patches' => [
          'title' => $this->t('View Patches'),
          'url' => Url::fromRoute('ai_upgrade_assistant.module_patches', ['module' => $module]),
        ],
      ],
    ];
  }

  /**
   * Checks the status of the upgrade process.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing the status information.
   */
  public function checkStatus() {
    $progress = $this->batchAnalyzer->getBatchProgress();
    $stats = $this->analysisTracker->getAnalysisStats();

    $response = [
      'status' => 'success',
      'data' => [
        'current_module' => $progress['current_module'] ?? NULL,
        'files_processed' => $progress['files_processed'] ?? 0,
        'total_files' => $progress['total_files'] ?? 0,
        'progress' => $progress['progress'] ?? 0,
        'errors' => $progress['errors'] ?? [],
        'stats' => [
          'total_analyzed' => $stats['total_analyzed'] ?? 0,
          'needs_reanalysis' => $stats['needs_reanalysis'] ?? 0,
          'never_analyzed' => $stats['never_analyzed'] ?? 0,
          'last_analysis' => $stats['last_analysis'] ? date('Y-m-d H:i:s', $stats['last_analysis']) : NULL,
        ],
      ],
    ];

    if (!empty($progress['errors'])) {
      $response['status'] = 'error';
      $response['errors'] = array_values($progress['errors']);
    }

    return new JsonResponse($response);
  }

}
