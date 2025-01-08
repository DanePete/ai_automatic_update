<?php

namespace Drupal\ai_upgrade_assistant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Configuration form for AI Upgrade Assistant settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    FileSystemInterface $file_system
  ) {
    parent::__construct($config_factory);
    $this->moduleHandler = $module_handler;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_upgrade_assistant_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_upgrade_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_upgrade_assistant.settings');

    // API Configuration
    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API Configuration'),
      '#open' => TRUE,
    ];

    $form['api']['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#default_value' => $config->get('openai_api_key'),
      '#description' => $this->t('Enter your OpenAI API key. This is required for AI-powered analysis.'),
      '#required' => TRUE,
    ];

    $form['api']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Model'),
      '#options' => [
        'gpt-4' => 'GPT-4 (Recommended)',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Faster)',
      ],
      '#default_value' => $config->get('model') ?: 'gpt-4',
      '#description' => $this->t('Select the AI model to use for analysis.'),
    ];

    // Analysis Settings
    $form['analysis'] = [
      '#type' => 'details',
      '#title' => $this->t('Analysis Settings'),
      '#open' => TRUE,
    ];

    $form['analysis']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $config->get('batch_size') ?: 50,
      '#description' => $this->t('Number of files to analyze in each batch.'),
      '#min' => 1,
      '#max' => 100,
    ];

    $form['analysis']['file_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('File Patterns'),
      '#default_value' => $config->get('file_patterns') ?: '*.php,*.module,*.inc,*.install',
      '#description' => $this->t('Comma-separated list of file patterns to analyze.'),
    ];

    $form['analysis']['excluded_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded Paths'),
      '#default_value' => $config->get('excluded_paths') ?: 'vendor/,node_modules/,tests/',
      '#description' => $this->t('Comma-separated list of paths to exclude from analysis.'),
    ];

    // Report Settings
    $form['reporting'] = [
      '#type' => 'details',
      '#title' => $this->t('Report Settings'),
      '#open' => TRUE,
    ];

    $form['reporting']['report_format'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Report Formats'),
      '#options' => [
        'html' => $this->t('HTML'),
        'pdf' => $this->t('PDF'),
        'json' => $this->t('JSON'),
      ],
      '#default_value' => $config->get('report_format') ?: ['html'],
      '#description' => $this->t('Select the formats for generated reports.'),
    ];

    $form['reporting']['report_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Directory'),
      '#default_value' => $config->get('report_path') ?: 'public://ai-upgrade-reports',
      '#description' => $this->t('Directory where reports will be saved.'),
    ];

    // Advanced Settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout'),
      '#default_value' => $config->get('timeout') ?: 30,
      '#description' => $this->t('Timeout in seconds for API calls.'),
      '#min' => 5,
      '#max' => 120,
    ];

    $form['advanced']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Retries'),
      '#default_value' => $config->get('max_retries') ?: 3,
      '#description' => $this->t('Maximum number of retries for failed API calls.'),
      '#min' => 0,
      '#max' => 5,
    ];

    $form['advanced']['fallback_to_mock'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Mock Results'),
      '#default_value' => $config->get('fallback_to_mock') ?: FALSE,
      '#description' => $this->t('If enabled, will use mock results when API calls fail. Use for testing only.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate API key format
    $api_key = $form_state->getValue('openai_api_key');
    if (!preg_match('/^sk-[a-zA-Z0-9]{32,}$/', trim($api_key))) {
      $form_state->setErrorByName('openai_api_key', $this->t('Invalid OpenAI API key format.'));
    }

    // Validate report path
    $report_path = $form_state->getValue('report_path');
    if (!$this->fileSystem->prepareDirectory($report_path, FileSystemInterface::CREATE_DIRECTORY)) {
      $form_state->setErrorByName('report_path', $this->t('Unable to create or access report directory.'));
    }

    // Validate file patterns
    $patterns = array_map('trim', explode(',', $form_state->getValue('file_patterns')));
    if (empty($patterns)) {
      $form_state->setErrorByName('file_patterns', $this->t('At least one file pattern is required.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ai_upgrade_assistant.settings');
    
    // Save API settings
    $config->set('openai_api_key', $form_state->getValue('openai_api_key'));
    $config->set('model', $form_state->getValue('model'));
    
    // Save analysis settings
    $config->set('batch_size', $form_state->getValue('batch_size'));
    $config->set('file_patterns', $form_state->getValue('file_patterns'));
    $config->set('excluded_paths', $form_state->getValue('excluded_paths'));
    
    // Save report settings
    $config->set('report_format', $form_state->getValue('report_format'));
    $config->set('report_path', $form_state->getValue('report_path'));
    
    // Save advanced settings
    $config->set('timeout', $form_state->getValue('timeout'));
    $config->set('max_retries', $form_state->getValue('max_retries'));
    $config->set('fallback_to_mock', $form_state->getValue('fallback_to_mock'));
    
    $config->save();

    parent::submitForm($form, $form_state);
  }
}
