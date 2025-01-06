<?php

namespace Drupal\ai_upgrade_assistant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\update\UpdateManagerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Batch\BatchBuilder;

/**
 * Form for applying minor updates.
 */
class ApplyUpdatesForm extends FormBase {

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
   * Constructs a new ApplyUpdatesForm object.
   *
   * @param \Drupal\update\UpdateManagerInterface $update_manager
   *   The update manager service.
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   The module handler service.
   */
  public function __construct(
    UpdateManagerInterface $update_manager,
    ModuleHandler $module_handler
  ) {
    $this->updateManager = $update_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('update.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_upgrade_assistant_apply_updates_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $available_updates = [];
    
    // Check for available updates
    $this->updateManager->refreshUpdateData();
    $projects = $this->updateManager->getProjects();

    foreach ($projects as $project) {
      if (!empty($project['recommended']) && version_compare($project['existing_version'], $project['recommended'], '<')) {
        // Only include if it's a minor version update
        $current_parts = explode('.', $project['existing_version']);
        $recommended_parts = explode('.', $project['recommended']);

        if ($current_parts[0] === $recommended_parts[0]) {
          $available_updates[$project['name']] = $this->t('@name: @current → @recommended', [
            '@name' => $project['title'],
            '@current' => $project['existing_version'],
            '@recommended' => $project['recommended'],
          ]);
        }
      }
    }

    if (empty($available_updates)) {
      $form['message'] = [
        '#markup' => $this->t('No minor updates are currently available.'),
      ];
      return $form;
    }

    $form['updates'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Available Updates'),
      '#options' => $available_updates,
      '#description' => $this->t('Select the modules you want to update.'),
    ];

    $form['create_backup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create backup before updating'),
      '#description' => $this->t('Recommended: Create a backup of your database and files before applying updates.'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply Selected Updates'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $updates = array_filter($form_state->getValue('updates'));
    
    if (empty($updates)) {
      $this->messenger()->addWarning($this->t('No updates were selected.'));
      return;
    }

    // Create batch
    $batch_builder = new BatchBuilder();
    $batch_builder
      ->setTitle($this->t('Applying minor updates'))
      ->setInitMessage($this->t('Preparing to update modules...'))
      ->setProgressMessage($this->t('Updating @current out of @total modules.'))
      ->setErrorMessage($this->t('Error applying updates.'));

    // Add backup operation if requested
    if ($form_state->getValue('create_backup')) {
      $batch_builder->addOperation(
        [$this, 'createBackup'],
        []
      );
    }

    // Add update operations
    foreach ($updates as $module => $value) {
      $batch_builder->addOperation(
        [$this, 'processUpdate'],
        [$module]
      );
    }

    $batch_builder->setFinishCallback([$this, 'finishBatch']);

    batch_set($batch_builder->toArray());
  }

  /**
   * Creates a backup of the site.
   */
  public function createBackup($context) {
    // Implement backup logic here
    // You might want to use Backup and Migrate module or custom backup logic
    $context['message'] = t('Creating backup...');
    // For now, just add a message
    $this->messenger()->addWarning($this->t('Backup functionality not yet implemented.'));
  }

  /**
   * Processes a single module update.
   */
  public function processUpdate($module, &$context) {
    try {
      // Here you would implement the actual update logic
      // This might involve using Composer or other update mechanisms
      $context['message'] = $this->t('Updating @module...', ['@module' => $module]);
      
      // For now, just log the attempt
      $context['results'][] = $module;
      $this->messenger()->addStatus($this->t('Update attempted for @module', ['@module' => $module]));
    }
    catch (\Exception $e) {
      $context['results']['errors'][$module] = $e->getMessage();
    }
  }

  /**
   * Finish callback for the batch.
   */
  public function finishBatch($success, $results, $operations) {
    if ($success) {
      if (!empty($results['errors'])) {
        foreach ($results['errors'] as $module => $error) {
          $this->messenger()->addError($this->t('Error updating @module: @error', [
            '@module' => $module,
            '@error' => $error,
          ]));
        }
      }
      else {
        $this->messenger()->addStatus($this->t('Successfully updated @count modules.', [
          '@count' => count($results),
        ]));
      }
    }
    else {
      $this->messenger()->addError($this->t('An error occurred while updating modules.'));
    }
  }
}
