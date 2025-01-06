<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer;

/**
 * Controller for the AI Upgrade Assistant dashboard.
 */
class DashboardController extends ControllerBase {

  /**
   * The project analyzer service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer
   */
  protected $projectAnalyzer;

  /**
   * Constructs a DashboardController object.
   *
   * @param \Drupal\ai_upgrade_assistant\Service\ProjectAnalyzer $project_analyzer
   *   The project analyzer service.
   */
  public function __construct(ProjectAnalyzer $project_analyzer) {
    $this->projectAnalyzer = $project_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_upgrade_assistant.project_analyzer')
    );
  }

  /**
   * Displays the upgrade assistant dashboard.
   *
   * @return array
   *   A render array representing the dashboard.
   */
  public function dashboard() {
    // Get project information
    $project_info = $this->projectAnalyzer->getProjectInfo();

    // Build the dashboard render array
    $build = [
      '#theme' => 'upgrade_dashboard',
      '#project_info' => [
        'drupal_version' => $project_info['drupal_version'],
        'installed_modules' => $project_info['installed_modules'],
        'custom_modules' => $project_info['custom_modules'],
        'upgrade_status' => $project_info['upgrade_status'],
      ],
      '#recommendations' => $this->projectAnalyzer->getRecommendations(),
      '#terminal_output' => [],
      '#attached' => [
        'library' => ['ai_upgrade_assistant/dashboard'],
      ],
    ];

    return $build;
  }

}
