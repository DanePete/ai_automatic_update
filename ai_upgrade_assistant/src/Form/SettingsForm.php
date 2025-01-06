<?php

namespace Drupal\ai_upgrade_assistant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AI Upgrade Assistant settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'ai_upgrade_assistant.settings';

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
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $form['api_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('API Settings'),
      '#open' => TRUE,
    ];

    $form['api_settings']['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#default_value' => $config->get('openai_api_key'),
      '#description' => $this->t('Enter your OpenAI API key. You can get one from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI\'s website</a>.'),
      '#required' => TRUE,
    ];

    $form['api_settings']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('OpenAI Model'),
      '#default_value' => $config->get('model') ?: 'gpt-4',
      '#options' => [
        'gpt-4' => 'GPT-4',
        'gpt-4-1106-preview' => 'GPT-4 Turbo',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
      ],
      '#description' => $this->t('Select which OpenAI model to use for analysis.'),
    ];

    $form['analysis_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Analysis Settings'),
      '#open' => TRUE,
    ];

    $form['analysis_settings']['file_filters'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('File Filters'),
    ];

    $form['analysis_settings']['file_filters']['include_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Include Patterns'),
      '#default_value' => $config->get('include_patterns') ?: "*.php\n*.module\n*.inc\n*.theme",
      '#description' => $this->t('Enter file patterns to include, one per line. Example: *.php'),
      '#rows' => 4,
    ];

    $form['analysis_settings']['file_filters']['exclude_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exclude Patterns'),
      '#default_value' => $config->get('exclude_patterns') ?: "vendor/*\ncore/*\nmodules/contrib/*",
      '#description' => $this->t('Enter file patterns to exclude, one per line. Example: vendor/*'),
      '#rows' => 4,
    ];

    $form['analysis_settings']['scan_custom_modules'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Scan Custom Modules'),
      '#default_value' => $config->get('scan_custom_modules') ?? TRUE,
      '#description' => $this->t('Analyze custom modules for compatibility issues and improvement suggestions.'),
    ];

    $form['analysis_settings']['scan_contrib_modules'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Scan Contributed Modules'),
      '#default_value' => $config->get('scan_contrib_modules') ?? TRUE,
      '#description' => $this->t('Check contributed modules for available updates and known compatibility issues.'),
    ];

    $form['analysis_settings']['scan_themes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Scan Themes'),
      '#default_value' => $config->get('scan_themes') ?? TRUE,
      '#description' => $this->t('Analyze themes for compatibility issues and improvement suggestions.'),
    ];

    $form['patch_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Patch Settings'),
      '#open' => TRUE,
    ];

    $form['patch_settings']['auto_generate_patches'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-generate Patches'),
      '#default_value' => $config->get('auto_generate_patches') ?? TRUE,
      '#description' => $this->t('Automatically generate patch files for suggested changes.'),
    ];

    $form['patch_settings']['patch_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Patch Format'),
      '#default_value' => $config->get('patch_format') ?: 'unified',
      '#options' => [
        'unified' => 'Unified (git diff)',
        'context' => 'Context (traditional diff)',
      ],
      '#description' => $this->t('Select the format for generated patch files.'),
      '#states' => [
        'visible' => [
          ':input[name="auto_generate_patches"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['patch_settings']['auto_apply_patches'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-apply Safe Patches'),
      '#default_value' => $config->get('auto_apply_patches') ?? FALSE,
      '#description' => $this->t('Automatically apply patches that are deemed safe by the AI analysis.'),
    ];

    $form['reporting_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Reporting Settings'),
      '#open' => TRUE,
    ];

    $form['reporting_settings']['generate_reports'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate Detailed Reports'),
      '#default_value' => $config->get('generate_reports') ?? TRUE,
      '#description' => $this->t('Generate detailed analysis reports in HTML and PDF formats.'),
    ];

    $form['reporting_settings']['report_format'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Report Formats'),
      '#options' => [
        'html' => 'HTML',
        'pdf' => 'PDF',
        'json' => 'JSON',
      ],
      '#default_value' => $config->get('report_format') ?: ['html', 'json'],
      '#description' => $this->t('Select the formats for generated reports.'),
      '#states' => [
        'visible' => [
          ':input[name="generate_reports"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $api_key = $form_state->getValue('openai_api_key');
    
    // Updated validation for new OpenAI API key format
    if (!preg_match('/^sk-[a-zA-Z0-9-_]{32,}$/', $api_key)) {
      $form_state->setErrorByName('openai_api_key', $this->t('The OpenAI API key appears to be invalid. Please check your key and try again.'));
    }

    // Validate include patterns
    $include_patterns = $form_state->getValue('include_patterns');
    if (!empty($include_patterns)) {
      $patterns = explode("\n", $include_patterns);
      foreach ($patterns as $pattern) {
        $pattern = trim($pattern);
        if (empty($pattern)) {
          continue;
        }
        if (!preg_match('/^[a-zA-Z0-9\/*._-]+$/', $pattern)) {
          $form_state->setErrorByName('include_patterns', $this->t('Invalid include pattern: @pattern', ['@pattern' => $pattern]));
        }
      }
    }

    // Validate exclude patterns
    $exclude_patterns = $form_state->getValue('exclude_patterns');
    if (!empty($exclude_patterns)) {
      $patterns = explode("\n", $exclude_patterns);
      foreach ($patterns as $pattern) {
        $pattern = trim($pattern);
        if (empty($pattern)) {
          continue;
        }
        if (!preg_match('/^[a-zA-Z0-9\/*._-]+$/', $pattern)) {
          $form_state->setErrorByName('exclude_patterns', $this->t('Invalid exclude pattern: @pattern', ['@pattern' => $pattern]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config(static::SETTINGS)
      ->set('openai_api_key', $form_state->getValue('openai_api_key'))
      ->set('model', $form_state->getValue('model'))
      ->set('include_patterns', $form_state->getValue('include_patterns'))
      ->set('exclude_patterns', $form_state->getValue('exclude_patterns'))
      ->set('scan_custom_modules', $form_state->getValue('scan_custom_modules'))
      ->set('scan_contrib_modules', $form_state->getValue('scan_contrib_modules'))
      ->set('scan_themes', $form_state->getValue('scan_themes'))
      ->set('auto_generate_patches', $form_state->getValue('auto_generate_patches'))
      ->set('patch_format', $form_state->getValue('patch_format'))
      ->set('auto_apply_patches', $form_state->getValue('auto_apply_patches'))
      ->set('generate_reports', $form_state->getValue('generate_reports'))
      ->set('report_format', $form_state->getValue('report_format'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
