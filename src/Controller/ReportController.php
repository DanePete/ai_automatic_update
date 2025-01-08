<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\upgrade_status\DeprecationAnalyzer;
use Drupal\ai_upgrade_assistant\Service\OpenAIService;

/**
 * Controller for the AI Upgrade Assistant reports.
 */
class ReportController extends ControllerBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The deprecation analyzer.
   *
   * @var \Drupal\upgrade_status\DeprecationAnalyzer
   */
  protected $deprecationAnalyzer;

  /**
   * The OpenAI service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\OpenAIService
   */
  protected $openai;

  /**
   * Constructs a new ReportController.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\upgrade_status\DeprecationAnalyzer $deprecation_analyzer
   *   The deprecation analyzer.
   * @param \Drupal\ai_upgrade_assistant\Service\OpenAIService $openai
   *   The OpenAI service.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    DeprecationAnalyzer $deprecation_analyzer,
    OpenAIService $openai
  ) {
    $this->moduleHandler = $module_handler;
    $this->deprecationAnalyzer = $deprecation_analyzer;
    $this->openai = $openai;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('upgrade_status.deprecation_analyzer'),
      $container->get('ai_upgrade_assistant.openai')
    );
  }

  /**
   * Displays the overview page.
   */
  public function overview() {
    // Get results from Upgrade Status
    $scan_result_service = \Drupal::service('upgrade_status.results');
    $projects = $scan_result_service->getResults();
    
    $rows = [];
    foreach ($projects as $project) {
      if (empty($project['paths'])) {
        continue;
      }

      $status = $this->t('Not analyzed');
      if (!empty($project['data'])) {
        $errors = $project['data']['totals']['errors'] ?? 0;
        $warnings = $project['data']['totals']['warnings'] ?? 0;
        $status = $this->t('@errors errors, @warnings warnings', [
          '@errors' => $errors,
          '@warnings' => $warnings,
        ]);
      }

      $ai_status = '';
      $ai_results = \Drupal::state()->get('ai_upgrade_assistant.analysis_results.' . $project['name'], []);
      if (!empty($ai_results)) {
        $ai_status = $this->t('AI analysis available');
      }

      $rows[] = [
        $project['name'],
        $project['type'],
        $status,
        $ai_status,
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'analyze' => [
                'title' => $this->t('Get AI recommendations'),
                'url' => Url::fromRoute('ai_upgrade_assistant.analyze', ['module' => $project['name']]),
              ],
            ],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Type'),
        $this->t('Status'),
        $this->t('AI Analysis'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No modules found.'),
    ];

    return $build;
  }

  /**
   * Analyzes a specific module with AI assistance.
   *
   * @param string $module
   *   The module to analyze.
   */
  public function analyze($module) {
    $batch = [
      'operations' => [
        [
          [$this, 'processAiAnalysis'],
          [$module],
        ],
      ],
      'finished' => [$this, 'finishAiAnalysis'],
      'title' => $this->t('Analyzing @module with AI assistance', ['@module' => $module]),
      'progress_message' => $this->t('Analyzing files...'),
      'error_message' => $this->t('An error occurred during analysis.'),
    ];

    batch_set($batch);
    return batch_process(Url::fromRoute('ai_upgrade_assistant.report'));
  }

  /**
   * Batch operation to process AI analysis.
   */
  public function processAiAnalysis($module, &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['results'] = [];
      
      // Get Upgrade Status results first
      $scan_result_service = \Drupal::service('upgrade_status.results');
      $project = $scan_result_service->getResults()[$module] ?? NULL;
      
      if (!$project || empty($project['data'])) {
        throw new \Exception('No Upgrade Status data available. Please run Upgrade Status scan first.');
      }
      
      $context['sandbox']['files'] = [];
      foreach ($project['data']['files'] ?? [] as $file => $issues) {
        if (!empty($issues)) {
          $context['sandbox']['files'][] = [
            'file' => $file,
            'issues' => $issues,
          ];
        }
      }
      
      $context['sandbox']['max'] = count($context['sandbox']['files']);
    }

    // Process one file at a time
    if (!empty($context['sandbox']['files'])) {
      $file_data = array_shift($context['sandbox']['files']);
      $file = $file_data['file'];
      $issues = $file_data['issues'];
      
      try {
        // Get file contents
        $module_path = $this->moduleHandler->getModule($module)->getPath();
        $file_path = $module_path . '/' . $file;
        
        if (file_exists($file_path)) {
          $code = file_get_contents($file_path);
          
          // Get AI recommendations
          $ai_analysis = $this->openai->analyzeCode($code, [
            'module' => $module,
            'file' => $file,
            'issues' => $issues,
            'drupal_version' => \Drupal::VERSION,
          ]);
          
          $context['results']['files'][$file] = [
            'issues' => $issues,
            'ai_recommendations' => $ai_analysis,
          ];
        }
      }
      catch (\Exception $e) {
        $context['results']['errors'][] = $e->getMessage();
      }
      
      $context['sandbox']['progress']++;
      $context['message'] = $this->t('Analyzing file @file...', ['@file' => $file]);
    }

    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch finish callback.
   */
  public function finishAiAnalysis($success, $results, $operations) {
    if ($success) {
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
          $this->messenger()->addError($error);
        }
      }
      
      if (!empty($results['files'])) {
        $this->messenger()->addStatus($this->t('AI analysis completed. Found recommendations for @count files.', [
          '@count' => count($results['files']),
        ]));
      }
      
      // Store results
      \Drupal::state()->set('ai_upgrade_assistant.analysis_results', $results);
    }
    else {
      $this->messenger()->addError($this->t('An error occurred during analysis.'));
    }
  }

  /**
   * Generates a comprehensive upgrade report.
   *
   * @return array
   *   A render array for the report page.
   */
  public function generateReport() {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['upgrade-report']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Upgrade Readiness Report') . '</h2>',
      ],
    ];

    // Get all analysis results
    $results = \Drupal::state()->get('ai_upgrade_assistant.analysis_results', []);
    $scan_results = \Drupal::service('upgrade_status.results')->getResults();

    // Summary section
    $total_modules = count($scan_results);
    $analyzed_modules = count(array_filter($scan_results, function ($project) {
      return !empty($project['data']);
    }));
    $ai_analyzed = count($results);

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['report-summary']],
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Total Modules: @total', ['@total' => $total_modules]),
          $this->t('Modules Scanned: @scanned', ['@scanned' => $analyzed_modules]),
          $this->t('Modules with AI Analysis: @ai', ['@ai' => $ai_analyzed]),
        ],
      ],
    ];

    // Compatibility overview
    $compatibility = [
      'compatible' => 0,
      'needs_update' => 0,
      'incompatible' => 0,
    ];

    $issues_by_type = [];
    foreach ($scan_results as $module => $project) {
      if (empty($project['data'])) {
        continue;
      }

      $errors = $project['data']['totals']['errors'] ?? 0;
      $warnings = $project['data']['totals']['warnings'] ?? 0;

      if ($errors === 0 && $warnings === 0) {
        $compatibility['compatible']++;
      }
      elseif ($errors === 0) {
        $compatibility['needs_update']++;
      }
      else {
        $compatibility['incompatible']++;
      }

      // Collect issues by type
      foreach ($project['data']['files'] ?? [] as $file) {
        foreach ($file as $issue) {
          $type = $issue['type'] ?? 'unknown';
          if (!isset($issues_by_type[$type])) {
            $issues_by_type[$type] = 0;
          }
          $issues_by_type[$type]++;
        }
      }
    }

    // Add compatibility chart
    $build['compatibility'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['compatibility-overview']],
      'title' => [
        '#markup' => '<h3>' . $this->t('Compatibility Overview') . '</h3>',
      ],
      'chart' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Compatible: @count', ['@count' => $compatibility['compatible']]),
          $this->t('Needs Update: @count', ['@count' => $compatibility['needs_update']]),
          $this->t('Incompatible: @count', ['@count' => $compatibility['incompatible']]),
        ],
      ],
    ];

    // Issues breakdown
    if (!empty($issues_by_type)) {
      $build['issues'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['issues-breakdown']],
        'title' => [
          '#markup' => '<h3>' . $this->t('Issues by Type') . '</h3>',
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => [],
        ],
      ];

      foreach ($issues_by_type as $type => $count) {
        $build['issues']['list']['#items'][] = $this->t('@type: @count', [
          '@type' => ucfirst(str_replace('_', ' ', $type)),
          '@count' => $count,
        ]);
      }
    }

    // Module-specific analysis
    $headers = [
      $this->t('Module'),
      $this->t('Status'),
      $this->t('Issues'),
      $this->t('AI Recommendations'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($scan_results as $module => $project) {
      if (empty($project['data'])) {
        continue;
      }

      $errors = $project['data']['totals']['errors'] ?? 0;
      $warnings = $project['data']['totals']['warnings'] ?? 0;
      $ai_recommendations = count($results[$module]['files'] ?? []);

      $status = $this->getModuleStatus($errors, $warnings);
      
      $rows[] = [
        $module,
        $status,
        $this->t('@errors errors, @warnings warnings', [
          '@errors' => $errors,
          '@warnings' => $warnings,
        ]),
        $ai_recommendations ? $this->t('@count recommendations', ['@count' => $ai_recommendations]) : $this->t('No analysis'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'details' => [
                'title' => $this->t('View Details'),
                'url' => Url::fromRoute('ai_upgrade_assistant.module_details', ['module' => $module]),
              ],
              'analyze' => [
                'title' => $this->t('AI Analysis'),
                'url' => Url::fromRoute('ai_upgrade_assistant.analyze_module', ['module' => $module]),
              ],
            ],
          ],
        ],
      ];
    }

    $build['modules'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No modules have been analyzed.'),
    ];

    // Export button
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['report-actions']],
      'export' => [
        '#type' => 'link',
        '#title' => $this->t('Export Report'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.export_report'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Gets a formatted module status.
   *
   * @param int $errors
   *   Number of errors.
   * @param int $warnings
   *   Number of warnings.
   *
   * @return array
   *   A render array for the status.
   */
  protected function getModuleStatus($errors, $warnings) {
    if ($errors === 0 && $warnings === 0) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Compatible'),
        '#attributes' => ['class' => ['status-compatible']],
      ];
    }
    elseif ($errors === 0) {
      return [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Needs Update'),
        '#attributes' => ['class' => ['status-needs-update']],
      ];
    }
    else {
      return [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Incompatible'),
        '#attributes' => ['class' => ['status-incompatible']],
      ];
    }
  }
}
