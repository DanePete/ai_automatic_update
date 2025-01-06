<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Process\Process;
use Drupal\ai_upgrade_assistant\Service\OpenAIService;
use Drupal\upgrade_status\DeprecationAnalyzer;

/**
 * Service for analyzing Drupal project for upgrades.
 */
class ProjectAnalyzer {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The OpenAI service.
   *
   * @var \Drupal\ai_upgrade_assistant\Service\OpenAIService
   */
  protected $openai;

  /**
   * The upgrade status analyzer service.
   *
   * @var \Drupal\upgrade_status\DeprecationAnalyzer
   */
  protected $deprecationAnalyzer;

  /**
   * Constructs a ProjectAnalyzer object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\ai_upgrade_assistant\Service\OpenAIService $openai
   *   The OpenAI service.
   * @param \Drupal\upgrade_status\DeprecationAnalyzer $deprecation_analyzer
   *   The deprecation analyzer service.
   */
  public function __construct(
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    OpenAIService $openai,
    DeprecationAnalyzer $deprecation_analyzer
  ) {
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->openai = $openai;
    $this->deprecationAnalyzer = $deprecation_analyzer;
  }

  /**
   * Gets information about the current project.
   *
   * @return array
   *   An array of project information.
   */
  public function getProjectInfo() {
    $info = [];
    
    // Get Drupal core version
    $info['drupal_version'] = \Drupal::VERSION;
    
    // Get installed modules
    $modules = $this->moduleHandler->getModuleList();
    $info['installed_modules'] = [];
    $info['custom_modules'] = [];
    
    foreach ($modules as $name => $extension) {
      if ($extension instanceof \Drupal\Core\Extension\Extension) {
        $module_info = \Drupal::service('extension.list.module')->getExtensionInfo($name);
        if (!$module_info) {
          $module_info = [];
        }
        
        if (isset($module_info['package']) && $module_info['package'] === 'Custom') {
          $info['custom_modules'][$name] = $module_info;
        } else {
          $info['installed_modules'][$name] = $module_info;
        }
      }
    }
    
    // Get composer.json contents
    $composer_path = DRUPAL_ROOT . '/../composer.json';
    if (file_exists($composer_path)) {
      $composer_json = json_decode(file_get_contents($composer_path), TRUE);
      $info['composer'] = $composer_json;
    }
    
    // Check for upgrade status module
    $info['upgrade_status'] = [
      'installed' => $this->moduleHandler->moduleExists('upgrade_status'),
      'results' => $this->getUpgradeStatusResults(),
    ];
    
    return $info;
  }

  /**
   * Gets upgrade recommendations based on project analysis.
   *
   * @return array
   *   An array of recommendations.
   */
  public function getRecommendations() {
    $recommendations = [];
    $project_info = $this->getProjectInfo();
    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    
    // Basic Drupal core version check
    $current_version = $project_info['drupal_version'];
    if (version_compare($current_version, '10.0.0', '<')) {
      $recommendations[] = [
        'type' => 'core_upgrade',
        'priority' => 'high',
        'message' => t('Upgrade to Drupal 10 recommended. Current version: @version', ['@version' => $current_version]),
        'actions' => [
          [
            'label' => t('View upgrade guide'),
            'url' => 'https://www.drupal.org/docs/upgrading-drupal',
          ],
        ],
      ];
    }

    // Analyze custom modules if enabled
    if ($config->get('scan_custom_modules')) {
      foreach ($project_info['custom_modules'] as $name => $info) {
        $module_path = $this->moduleHandler->getModule($name)->getPath();
        $results = $this->analyzeCustomModule($name, $module_path);
        if (!empty($results['summary'])) {
          $recommendations[] = [
            'type' => 'custom_module_analysis',
            'priority' => 'medium',
            'message' => t('Custom module @name analyzed', ['@name' => $name]),
            'actions' => [
              [
                'label' => t('View analysis results'),
                'url' => '#',
              ],
            ],
            'results' => $results['summary'],
          ];
        }
      }
    }

    // Check contributed modules if enabled
    if ($config->get('scan_contrib_modules')) {
      foreach ($project_info['installed_modules'] as $name => $info) {
        if (isset($info['core_version_requirement'])) {
          if (!$this->isCompatibleWithDrupal10($info['core_version_requirement'])) {
            $recommendations[] = [
              'type' => 'module_compatibility',
              'priority' => 'medium',
              'message' => t('Module @name may not be compatible with Drupal 10', ['@name' => $name]),
              'actions' => [
                [
                  'label' => t('Check module page'),
                  'url' => "https://www.drupal.org/project/$name",
                ],
              ],
            ];
          }
        }
      }
    }
    
    return $recommendations;
  }

  /**
   * Analyzes a custom module for upgrade compatibility.
   *
   * @param string $module_name
   *   The name of the module to analyze.
   * @param string $module_path
   *   The path to the module.
   *
   * @return array
   *   Analysis results.
   */
  public function analyzeCustomModule($module_name, $module_path) {
    $results = [
      'module' => $module_name,
      'path' => $module_path,
      'files' => [],
      'errors' => [],
      'warnings' => [],
      'summary' => [],
    ];

    try {
      // Get module info
      $module_info = \Drupal::service('extension.list.module')->getExtensionInfo($module_name);
      $results['info'] = $module_info;

      // Check core compatibility
      if (isset($module_info['core_version_requirement'])) {
        $results['core_compatible'] = $this->isCompatibleWithDrupal10($module_info['core_version_requirement']);
      }

      // Find PHP files
      $files = $this->findPhpFiles($module_path);
      
      // Analyze each file
      foreach ($files as $file) {
        $code = file_get_contents($file);
        if ($code === FALSE) {
          $results['errors'][] = "Could not read file: $file";
          continue;
        }

        $relative_path = str_replace(DRUPAL_ROOT . '/', '', $file);
        
        try {
          $file_analysis = $this->analyzeFileContent($code, [
            'file' => $relative_path,
            'module' => $module_name,
          ]);
          
          $results['files'][$relative_path] = $file_analysis;

          // Aggregate warnings and errors
          if (!empty($file_analysis['warnings'])) {
            $results['warnings'] = array_merge(
              $results['warnings'],
              array_map(
                function($warning) use ($relative_path) {
                  return "$relative_path: $warning";
                },
                $file_analysis['warnings']
              )
            );
          }
        }
        catch (\Exception $e) {
          $results['errors'][] = "Error analyzing $relative_path: " . $e->getMessage();
        }
      }

      // Generate summary
      $results['summary'] = [
        'files_analyzed' => count($results['files']),
        'warnings_found' => count($results['warnings']),
        'errors_found' => count($results['errors']),
      ];
    }
    catch (\Exception $e) {
      $results['errors'][] = "Module analysis error: " . $e->getMessage();
    }

    return $results;
  }

  /**
   * Analyzes a single file's content.
   *
   * @param string $code
   *   The code content to analyze.
   * @param array $context
   *   Analysis context.
   *
   * @return array
   *   Analysis results.
   */
  protected function analyzeFileContent($code, array $context) {
    $analysis = [
      'warnings' => [],
      'deprecated_functions' => [],
      'suggestions' => [],
    ];

    // Use OpenAI for deeper analysis
    try {
      $ai_analysis = $this->openai->analyzeCode($code, [
        'type' => 'file',
        'context' => $context,
        'drupal_version' => \Drupal::VERSION,
        'target_version' => '10.0.0',
      ]);

      if (!empty($ai_analysis['warnings'])) {
        $analysis['warnings'] = array_merge($analysis['warnings'], $ai_analysis['warnings']);
      }
      if (!empty($ai_analysis['suggestions'])) {
        $analysis['suggestions'] = $ai_analysis['suggestions'];
      }
    }
    catch (\Exception $e) {
      $analysis['warnings'][] = "AI analysis failed: " . $e->getMessage();
    }

    return $analysis;
  }

  /**
   * Finds all PHP files in a directory recursively.
   *
   * @param string $dir
   *   Directory to search in.
   * @param array $exclude_dirs
   *   Directories to exclude.
   *
   * @return array
   *   Array of file paths.
   */
  public function findPhpFiles($dir, array $exclude_dirs = ['tests', 'vendor']) {
    $files = [];
    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    
    // Get file patterns from config or use defaults
    $include_patterns = $config->get('file_patterns') ?: '*.php,*.module,*.inc,*.install';
    $exclude_patterns = $config->get('exclude_patterns') ?: '*.test.php,*/tests/*,*/vendor/*';
    
    $include_patterns = array_map('trim', explode(',', $include_patterns));
    $exclude_patterns = array_map('trim', explode(',', $exclude_patterns));

    try {
      $directory = new \RecursiveDirectoryIterator($dir);
      $iterator = new \RecursiveIteratorIterator($directory);
      $regex = new \RegexIterator($iterator, '/\.(php|module|inc|install)$/i');

      foreach ($regex as $file) {
        $path = $file->getPathname();
        $relative_path = str_replace($dir . '/', '', $path);
        
        // Skip excluded directories
        $skip = false;
        foreach ($exclude_dirs as $exclude_dir) {
          if (strpos($relative_path, $exclude_dir . '/') === 0) {
            $skip = true;
            break;
          }
        }
        if ($skip) {
          continue;
        }

        // Check include patterns
        $include = false;
        foreach ($include_patterns as $pattern) {
          if (fnmatch($pattern, $relative_path)) {
            $include = true;
            break;
          }
        }

        // Check exclude patterns
        foreach ($exclude_patterns as $pattern) {
          if (fnmatch($pattern, $relative_path)) {
            $include = false;
            break;
          }
        }

        if ($include) {
          $files[] = $path;
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_upgrade_assistant')->error(
        'Error scanning directory @dir: @error', [
          '@dir' => $dir,
          '@error' => $e->getMessage(),
        ]
      );
    }

    return $files;
  }

  /**
   * Gets results from the upgrade status module if available.
   *
   * @return array|null
   *   The upgrade status results or null if not available.
   */
  protected function getUpgradeStatusResults() {
    if (!$this->moduleHandler->moduleExists('upgrade_status')) {
      return NULL;
    }
    
    // TODO: Implement integration with upgrade_status module
    return [];
  }

  /**
   * Checks if a version requirement is compatible with Drupal 10.
   *
   * @param string $version_requirement
   *   The version requirement string.
   *
   * @return bool
   *   TRUE if compatible, FALSE otherwise.
   */
  protected function isCompatibleWithDrupal10($version_requirement) {
    return strpos($version_requirement, '^10') !== FALSE || 
           strpos($version_requirement, '~10') !== FALSE ||
           strpos($version_requirement, '>=10') !== FALSE;
  }

  /**
   * Processes the OpenAI analysis response.
   *
   * @param array $response
   *   The OpenAI response.
   *
   * @return array
   *   Processed analysis results.
   */
  protected function processAnalysisResponse($response) {
    // Implement response processing logic here
    return $response;
  }

}
