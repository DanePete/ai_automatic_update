<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the AI Upgrade Assistant.
 */
class UpgradeController extends ControllerBase {

  /**
   * Displays the upgrade assistant overview.
   */
  public function overview() {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['upgrade-assistant-container']],
    ];

    // Welcome message
    $build['welcome'] = [
      '#markup' => '<h2>' . $this->t('AI Upgrade Assistant') . '</h2>' .
        '<p>' . $this->t('Here are the recommended commands to run for upgrading your site:') . '</p>',
    ];

    // Commands section
    $build['commands'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['upgrade-commands']],
    ];

    $commands = [
      [
        'title' => $this->t('Update Drupal core and all dependencies'),
        'command' => 'ddev composer update drupal/core --with-dependencies',
        'description' => $this->t('Updates Drupal core and all required dependencies'),
      ],
      [
        'title' => $this->t('Update all modules'),
        'command' => 'ddev composer update',
        'description' => $this->t('Updates all modules to their latest compatible versions'),
      ],
      [
        'title' => $this->t('Run database updates'),
        'command' => 'ddev drush updb -y',
        'description' => $this->t('Applies any pending database updates'),
      ],
      [
        'title' => $this->t('Clear all caches'),
        'command' => 'ddev drush cr',
        'description' => $this->t('Clears all caches to ensure changes take effect'),
      ],
    ];

    foreach ($commands as $command) {
      $build['commands'][] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['upgrade-command']],
        'title' => [
          '#markup' => '<h3>' . $command['title'] . '</h3>',
        ],
        'description' => [
          '#markup' => '<p>' . $command['description'] . '</p>',
        ],
        'command' => [
          '#markup' => '<pre>' . $command['command'] . '</pre>',
        ],
        'button' => [
          '#type' => 'button',
          '#value' => $this->t('Run Command'),
          '#attributes' => [
            'class' => ['button', 'run-command'],
            'data-command' => $command['command'],
            'onclick' => 'return confirm("' . $this->t('Are you sure you want to run this command?') . '")',
          ],
        ],
      ];
    }

    // Add our CSS
    $build['#attached']['library'][] = 'ai_upgrade_assistant/upgrade_assistant';

    return $build;
  }
}
