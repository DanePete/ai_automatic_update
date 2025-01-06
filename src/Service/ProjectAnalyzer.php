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
        if (!empty($results['recommendations'])) {
          $recommendations = array_merge($recommendations, $results['recommendations']);
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
   * @param string $module
   *   The module name.
   * @param string $module_path
   *   The module path.
   *
   * @return array
   *   Analysis results.
   */
  public function analyzeCustomModule($module, $module_path) {
    $results = [
      'module' => $module,
      'files' => [],
      'errors' => [],
      'warnings' => [],
      'recommendations' => [],
    ];

    try {
      // First, get deprecation analysis from Upgrade Status
      $deprecation_results = $this->deprecationAnalyzer->analyze($module_path);
      
      // Process deprecation results
      foreach ($deprecation_results as $file => $issues) {
        $file_results = [
          'path' => $file,
          'issues' => [],
        ];

        foreach ($issues as $issue) {
          $file_results['issues'][] = [
            'line' => $issue['line'],
            'message' => $issue['message'],
            'severity' => $issue['severity'],
          ];
        }

        $results['files'][$file] = $file_results;
      }

      // For each file with issues, get AI recommendations
      foreach ($results['files'] as $file => $file_results) {
        if (!empty($file_results['issues'])) {
          $file_path = $module_path . '/' . $file;
          if (file_exists($file_path)) {
            $code = file_get_contents($file_path);
            
            // Prepare context for AI analysis
            $context = [
              'module' => $module,
              'file' => $file,
              'issues' => $file_results['issues'],
              'drupal_version' => \Drupal::VERSION,
            ];

            // Get AI recommendations
            $ai_analysis = $this->openai->analyzeCode($code, $context);
            if ($ai_analysis) {
              $results['files'][$file]['ai_recommendations'] = $ai_analysis;
              
              // Add overall recommendations
              if (!empty($ai_analysis['recommendations'])) {
                $results['recommendations'] = array_merge(
                  $results['recommendations'],
                  $ai_analysis['recommendations']
                );
              }
            }
          }
        }
      }

      return $results;
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_upgrade_assistant')->error('Error analyzing module @module: @error', [
        '@module' => $module,
        '@error' => $e->getMessage(),
      ]);
      
      $results['errors'][] = $e->getMessage();
      return $results;
    }
  }

  /**
   * Analyzes code for upgrade compatibility.
   *
   * @param string $code
   *   The code to analyze.
   * @param array $context
   *   Analysis context.
   *
   * @return array
   *   Analysis results.
   */
  protected function analyzeCode($code, $context) {
    try {
      // Try OpenAI analysis first
      $response = $this->openai->analyzeCode($code, $context);
      if (!empty($response)) {
        return $this->processAnalysisResponse($response);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_upgrade_assistant')->warning('OpenAI analysis failed: @error. Falling back to static analysis.', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Fallback to static analysis if OpenAI fails
    return $this->performStaticAnalysis($code);
  }

  /**
   * Performs static code analysis without OpenAI.
   *
   * @param string $code
   *   The code to analyze.
   *
   * @return array
   *   Analysis results.
   */
  protected function performStaticAnalysis($code) {
    $issues = [];
    
    // Check for common deprecated functions
    $deprecated_functions = [
      'drupal_get_path' => [
        'replacement' => '\Drupal::service(\'extension.list.module\')->getPath()',
        'description' => 'drupal_get_path() is deprecated in Drupal 9.3.0 and will be removed in Drupal 10.0.0. Use the extension.list.module_type service instead.',
      ],
      'file_create_url' => [
        'replacement' => '\Drupal\Core\Url::fromUri(\'public://example.txt\')->toString()',
        'description' => 'file_create_url() is deprecated in Drupal 8.0.0 and will be removed before Drupal 9.0.0. Use URL generation instead.',
      ],
      'drupal_render' => [
        'replacement' => '\Drupal::service(\'renderer\')->render($elements)',
        'description' => 'drupal_render() is deprecated in Drupal 8.0.0 and will be removed before Drupal 9.0.0. Use the renderer service instead.',
      ],
      'drupal_set_message' => [
        'replacement' => '\Drupal::messenger()->addMessage()',
        'description' => 'drupal_set_message() is deprecated in Drupal 8.5.0 and will be removed before Drupal 9.0.0. Use Drupal\Core\Messenger\MessengerInterface instead.',
      ],
    ];

    foreach ($deprecated_functions as $function => $info) {
      if (preg_match("/$function\s*\(/", $code)) {
        $issues[] = [
          'type' => 'deprecation',
          'description' => $info['description'],
          'priority' => 'critical',
          'current_code' => "$function()",
          'code_example' => $info['replacement'],
          'line_number' => 0, // Would need better parsing to get actual line numbers
        ];
      }
    }

    // Check for deprecated constants
    $deprecated_constants = [
      'DRUPAL_ROOT' => [
        'replacement' => 'Use dependency injection or the app.root service',
        'description' => 'DRUPAL_ROOT constant usage is discouraged. Use dependency injection instead.',
      ],
      'REQUEST_TIME' => [
        'replacement' => '\Drupal::time()->getRequestTime()',
        'description' => 'REQUEST_TIME constant is deprecated. Use the time service instead.',
      ],
    ];

    foreach ($deprecated_constants as $constant => $info) {
      if (strpos($code, $constant) !== FALSE) {
        $issues[] = [
          'type' => 'deprecation',
          'description' => $info['description'],
          'priority' => 'warning',
          'current_code' => $constant,
          'code_example' => $info['replacement'],
          'line_number' => 0,
        ];
      }
    }

    // Check for global function usage
    if (preg_match_all('/\\\\?Drupal::[a-zA-Z_]+\(\)/', $code, $matches)) {
      $issues[] = [
        'type' => 'best_practice',
        'description' => 'Direct service calls using \Drupal should be avoided in favor of dependency injection.',
        'priority' => 'warning',
        'current_code' => implode(', ', $matches[0]),
        'code_example' => "// Inject services in constructor instead:\nprivate \$someService;\n\npublic function __construct(SomeServiceInterface \$some_service) {\n  \$this->someService = \$some_service;\n}",
        'line_number' => 0,
      ];
    }

    // Check for proper namespacing
    if (!preg_match('/^namespace\s+Drupal/', $code)) {
      $issues[] = [
        'type' => 'standards',
        'description' => 'Drupal code should be properly namespaced under the Drupal namespace.',
        'priority' => 'warning',
        'current_code' => '// No namespace declaration found',
        'code_example' => "namespace Drupal\\module_name\\SubNamespace;",
        'line_number' => 0,
      ];
    }

    return ['issues' => $issues];
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
