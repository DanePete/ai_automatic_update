<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Batch\BatchBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\ai_upgrade_assistant\Service\BatchAnalyzer;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Controller for handling analysis operations.
 */
class AnalysisController extends ControllerBase {

  /**
   * The batch analyzer service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\BatchAnalyzer
   */
  protected $batchAnalyzer;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new AnalysisController object.
   *
   * @param \Drupal\ai_upgrade_assistant\Service\BatchAnalyzer $batch_analyzer
   *   The batch analyzer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    BatchAnalyzer $batch_analyzer,
    StateInterface $state,
    RendererInterface $renderer
  ) {
    $this->batchAnalyzer = $batch_analyzer;
    $this->state = $state;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_upgrade_assistant.batch_analyzer'),
      $container->get('state'),
      $container->get('renderer')
    );
  }

  /**
   * Starts the analysis process.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with batch token.
   */
  public function startAnalysis() {
    $batch = $this->batchAnalyzer->createAnalysisBatch();
    batch_set($batch);
    
    $batch_token = base64_encode(random_bytes(32));
    $this->state->set('ai_upgrade_assistant.current_batch', $batch_token);
    
    return new JsonResponse([
      'status' => 'started',
      'batch_token' => $batch_token,
    ]);
  }

  /**
   * Gets the current analysis progress.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with progress information.
   */
  public function getProgress() {
    $batch = batch_get();
    $results = $this->state->get('ai_upgrade_assistant.analysis_results', []);
    
    if (!$batch) {
      return new JsonResponse([
        'status' => 'complete',
        'current' => 100,
        'total' => 100,
        'message' => $this->t('Analysis complete'),
      ]);
    }

    $current = $batch['sets'][$batch['current_set']]['current'];
    $total = $batch['sets'][$batch['current_set']]['total'];
    
    return new JsonResponse([
      'status' => 'in_progress',
      'current' => $current,
      'total' => $total,
      'message' => $batch['sets'][$batch['current_set']]['message'],
      'terminal_output' => $this->getLatestTerminalOutput(),
    ]);
  }

  /**
   * Gets the latest recommendations.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with rendered recommendations.
   */
  public function getRecommendations() {
    $results = $this->state->get('ai_upgrade_assistant.analysis_results', []);
    
    $build = [
      '#theme' => 'upgrade_recommendations',
      '#recommendations' => $this->processResults($results),
    ];

    return new JsonResponse([
      'content' => $this->renderer->render($build),
    ]);
  }

  /**
   * Gets the code diff view.
   *
   * @return array
   *   Render array for the diff view.
   */
  public function getDiffView($file_path) {
    $results = $this->state->get('ai_upgrade_assistant.analysis_results', []);
    $file_results = $results['files'][$file_path] ?? [];
    
    return [
      '#theme' => 'code_diff_view',
      '#file_path' => $file_path,
      '#analysis' => $file_results,
      '#attached' => [
        'library' => ['ai_upgrade_assistant/diff_view'],
      ],
    ];
  }

  /**
   * Gets the latest terminal output.
   *
   * @return string
   *   The latest terminal output.
   */
  protected function getLatestTerminalOutput() {
    return $this->state->get('ai_upgrade_assistant.terminal_output', '');
  }

  /**
   * Processes analysis results into recommendations.
   *
   * @param array $results
   *   Raw analysis results.
   *
   * @return array
   *   Processed recommendations.
   */
  protected function processResults(array $results) {
    $recommendations = [];

    // Process core analysis
    if (!empty($results['core'])) {
      if (!$results['core']['compatible']) {
        $recommendations[] = [
          'type' => 'core_upgrade',
          'priority' => 'high',
          'message' => $this->t('Upgrade to Drupal 10 required. Current version: @version', 
            ['@version' => $results['core']['version']]),
        ];
      }
    }

    // Process module analysis
    if (!empty($results['modules'])) {
      foreach ($results['modules'] as $name => $module) {
        if (!$module['compatible']) {
          $recommendations[] = [
            'type' => 'module_compatibility',
            'priority' => 'medium',
            'message' => $this->t('Module @name needs to be updated for Drupal 10 compatibility',
              ['@name' => $name]),
            'details' => $module['issues'],
          ];
        }
      }
    }

    // Process file analysis
    if (!empty($results['files'])) {
      foreach ($results['files'] as $file_path => $analysis) {
        if (!empty($analysis['issues'])) {
          foreach ($analysis['issues'] as $issue) {
            $recommendations[] = [
              'type' => $issue['type'],
              'priority' => $issue['priority'],
              'message' => $issue['description'],
              'actions' => [
                [
                  'label' => $this->t('View changes'),
                  'url' => "admin/reports/upgrade-assistant/diff/" . urlencode($file_path),
                ],
              ],
              'code_example' => $issue['code_example'] ?? NULL,
            ];
          }
        }
      }
    }

    return $recommendations;
  }

  /**
   * Analyzes a specific module.
   *
   * @param string $module
   *   The machine name of the module to analyze.
   *
   * @return array
   *   A render array for the analysis page.
   */
  public function analyzeModule($module) {
    $batch = $this->batchAnalyzer->createModuleAnalysisBatch($module);
    batch_set($batch);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['module-analysis']],
      'title' => [
        '#markup' => $this->t('Analyzing module: @module', ['@module' => $module]),
      ],
      'progress' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['analysis-progress'],
          'id' => 'analysis-progress-wrapper',
        ],
      ],
      '#attached' => [
        'library' => ['ai_upgrade_assistant/analysis'],
        'drupalSettings' => [
          'aiUpgradeAssistant' => [
            'moduleBeingAnalyzed' => $module,
          ],
        ],
      ],
    ];
  }

  /**
   * Displays details for a specific module.
   *
   * @param string $module
   *   The machine name of the module to display.
   *
   * @return array
   *   A render array for the module details page.
   */
  public function moduleDetails($module) {
    $module_data = $this->state->get('ai_upgrade_assistant.module_analysis.' . $module, []);
    
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['module-details']],
    ];

    // Module info
    $build['info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['module-info']],
      'title' => [
        '#markup' => '<h2>' . $this->t('@module Analysis Results', ['@module' => $module]) . '</h2>',
      ],
    ];

    if (empty($module_data)) {
      $build['info']['content'] = [
        '#markup' => $this->t('No analysis data available for this module.'),
      ];
      return $build;
    }

    // Status summary
    $build['status'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['status-summary']],
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Compatibility: @status', ['@status' => $module_data['compatibility']]),
          $this->t('Last Analyzed: @date', ['@date' => date('Y-m-d H:i:s', $module_data['last_analysis'])]),
          $this->t('Issues Found: @count', ['@count' => count($module_data['issues'])]),
        ],
      ],
    ];

    // Issues list
    if (!empty($module_data['issues'])) {
      $build['issues'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['issues-list']],
        'title' => [
          '#markup' => '<h3>' . $this->t('Issues Found') . '</h3>',
        ],
      ];

      foreach ($module_data['issues'] as $issue) {
        $build['issues']['list'][] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['issue-item']],
          'title' => [
            '#markup' => '<h4>' . $issue['title'] . '</h4>',
          ],
          'description' => [
            '#markup' => '<p>' . $issue['description'] . '</p>',
          ],
          'file' => [
            '#markup' => '<code>' . $issue['file'] . ':' . $issue['line'] . '</code>',
          ],
          'recommendation' => [
            '#markup' => '<div class="recommendation">' . $issue['recommendation'] . '</div>',
          ],
        ];
      }
    }

    // Add actions
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['module-actions']],
      'reanalyze' => [
        '#type' => 'link',
        '#title' => $this->t('Reanalyze Module'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.analyze_module', ['module' => $module]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ],
      'patches' => [
        '#type' => 'link',
        '#title' => $this->t('View Patches'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.module_patches', ['module' => $module]),
        '#attributes' => [
          'class' => ['button'],
        ],
      ],
    ];

    return $build;
  }
}
