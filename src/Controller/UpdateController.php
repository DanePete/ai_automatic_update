<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\update\UpdateManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Controller for handling module updates.
 */
class UpdateController extends ControllerBase {

  /**
   * The update manager.
   *
   * @var \Drupal\update\UpdateManagerInterface
   */
  protected $updateManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Constructs a new UpdateController object.
   *
   * @param \Drupal\update\UpdateManagerInterface $update_manager
   *   The update manager service.
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    UpdateManagerInterface $update_manager,
    ModuleHandler $module_handler,
    ConfigFactoryInterface $config_factory
  ) {
    $this->updateManager = $update_manager;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('update.manager'),
      $container->get('module_handler'),
      $container->get('config.factory')
    );
  }

  /**
   * Displays available minor updates.
   *
   * @return array
   *   A render array.
   */
  public function minorUpdates() {
    $build = [];
    $available_updates = [];

    // Check for available updates
    $this->updateManager->refreshUpdateData();
    $projects = $this->updateManager->getProjects();

    foreach ($projects as $project) {
      if (!empty($project['recommended']) && version_compare($project['existing_version'], $project['recommended'], '<')) {
        // Only include if it's a minor version update
        $current_parts = explode('.', $project['existing_version']);
        $recommended_parts = explode('.', $project['recommended']);

        // Check if it's a minor update (same major version)
        if ($current_parts[0] === $recommended_parts[0]) {
          $available_updates[$project['name']] = [
            'name' => $project['title'],
            'current_version' => $project['existing_version'],
            'recommended_version' => $project['recommended'],
            'status' => $project['status'],
          ];
        }
      }
    }

    if (!empty($available_updates)) {
      $rows = [];
      foreach ($available_updates as $name => $update) {
        $rows[] = [
          $update['name'],
          $update['current_version'],
          $update['recommended_version'],
          $this->t('Minor update available'),
        ];
      }

      $build['updates_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Module'),
          $this->t('Current Version'),
          $this->t('Recommended Version'),
          $this->t('Status'),
        ],
        '#rows' => $rows,
      ];

      $build['apply_updates'] = [
        '#type' => 'link',
        '#title' => $this->t('Apply Updates'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.apply_updates'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }
    else {
      $build['no_updates'] = [
        '#markup' => $this->t('No minor updates are currently available.'),
      ];
    }

    return $build;
  }
}
