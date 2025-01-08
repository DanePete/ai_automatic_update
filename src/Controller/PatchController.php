<?php

namespace Drupal\ai_upgrade_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_upgrade_assistant\Service\PatchGenerator;
use Drupal\Core\State\StateInterface;

/**
 * Controller for handling patch operations.
 */
class PatchController extends ControllerBase {

  /**
   * The patch generator service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\PatchGenerator
   */
  protected $patchGenerator;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new PatchController object.
   *
   * @param \Drupal\ai_upgrade_assistant\Service\PatchGenerator $patch_generator
   *   The patch generator service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    PatchGenerator $patch_generator,
    StateInterface $state
  ) {
    $this->patchGenerator = $patch_generator;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_upgrade_assistant.patch_generator'),
      $container->get('state')
    );
  }

  /**
   * Displays a list of patches for a module.
   *
   * @param string $module
   *   The machine name of the module.
   *
   * @return array
   *   A render array for the patches list page.
   */
  public function modulePatchList($module) {
    $patches = $this->state->get('ai_upgrade_assistant.module_patches.' . $module, []);
    
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['module-patches']],
      'title' => [
        '#markup' => '<h2>' . $this->t('Patches for @module', ['@module' => $module]) . '</h2>',
      ],
    ];

    if (empty($patches)) {
      $build['content'] = [
        '#markup' => $this->t('No patches have been generated for this module.'),
      ];
      return $build;
    }

    // Patches table
    $headers = [
      $this->t('Description'),
      $this->t('Status'),
      $this->t('Created'),
      $this->t('Files Changed'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($patches as $patch_id => $patch) {
      $rows[] = [
        $patch['description'],
        $this->getPatchStatus($patch['status']),
        date('Y-m-d H:i:s', $patch['created']),
        count($patch['files']),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'view' => [
                'title' => $this->t('View Changes'),
                'url' => Url::fromRoute('ai_upgrade_assistant.view_patch', [
                  'module' => $module,
                  'patch_id' => $patch_id,
                ]),
              ],
              'apply' => [
                'title' => $this->t('Apply Patch'),
                'url' => Url::fromRoute('ai_upgrade_assistant.apply_patch', [
                  'module' => $module,
                  'patch_id' => $patch_id,
                ]),
              ],
              'download' => [
                'title' => $this->t('Download'),
                'url' => Url::fromRoute('ai_upgrade_assistant.download_patch', [
                  'module' => $module,
                  'patch_id' => $patch_id,
                ]),
              ],
            ],
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No patches available.'),
    ];

    // Generate patch button
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['patch-actions']],
      'generate' => [
        '#type' => 'link',
        '#title' => $this->t('Generate New Patch'),
        '#url' => Url::fromRoute('ai_upgrade_assistant.generate_patch', ['module' => $module]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Gets a formatted patch status.
   *
   * @param string $status
   *   The patch status.
   *
   * @return array
   *   A render array for the status.
   */
  protected function getPatchStatus($status) {
    $statuses = [
      'pending' => [
        'label' => $this->t('Pending'),
        'class' => 'status-pending',
      ],
      'applied' => [
        'label' => $this->t('Applied'),
        'class' => 'status-applied',
      ],
      'failed' => [
        'label' => $this->t('Failed'),
        'class' => 'status-failed',
      ],
    ];

    $info = $statuses[$status] ?? [
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

}
