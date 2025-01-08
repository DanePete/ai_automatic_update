<?php

namespace Drupal\ai_upgrade_assistant\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Process\Process;

/**
 * Service for generating and applying patches.
 */
class PatchGenerator {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new PatchGenerator object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    FileSystemInterface $file_system,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Generates a patch for the suggested changes.
   *
   * @param string $file_path
   *   The path to the file to patch.
   * @param array $changes
   *   Array of changes to apply.
   *
   * @return array
   *   Array containing patch information.
   */
  public function generatePatch($file_path, array $changes) {
    $config = $this->configFactory->get('ai_upgrade_assistant.settings');
    $patch_format = $config->get('patch_format') ?: 'unified';
    
    // Create a temporary copy of the original file
    $temp_dir = $this->fileSystem->getTempDirectory();
    $temp_original = $temp_dir . '/' . basename($file_path) . '.orig';
    $temp_modified = $temp_dir . '/' . basename($file_path) . '.new';
    
    // Copy original file
    copy($file_path, $temp_original);
    
    // Create modified version
    $content = file_get_contents($file_path);
    foreach ($changes as $change) {
      $content = $this->applyChange($content, $change);
    }
    file_put_contents($temp_modified, $content);
    
    // Generate diff
    $patch_file = $temp_dir . '/' . basename($file_path) . '.patch';
    $diff_command = [
      'diff',
      $patch_format === 'unified' ? '-u' : '-c',
      $temp_original,
      $temp_modified,
    ];
    
    $process = new Process($diff_command);
    $process->run();
    
    // diff returns 1 if files are different, which is expected
    $patch_content = $process->getOutput();
    if ($process->getExitCode() !== 1 || empty($patch_content)) {
      $this->loggerFactory->get('ai_upgrade_assistant')
        ->error('Failed to generate patch for @file', ['@file' => $file_path]);
      return FALSE;
    }
    
    // Save patch file
    $patches_dir = 'public://ai_upgrade_assistant/patches';
    $this->fileSystem->prepareDirectory($patches_dir, FileSystemInterface::CREATE_DIRECTORY);
    
    $patch_name = basename($file_path) . '-' . date('Y-m-d-His') . '.patch';
    $patch_uri = $patches_dir . '/' . $patch_name;
    
    $this->fileSystem->saveData($patch_content, $patch_uri, FileSystemInterface::EXISTS_REPLACE);
    
    // Clean up temp files
    unlink($temp_original);
    unlink($temp_modified);
    
    return [
      'patch_uri' => $patch_uri,
      'patch_content' => $patch_content,
      'changes' => $changes,
    ];
  }

  /**
   * Applies a patch to a file.
   *
   * @param string $patch_uri
   *   URI of the patch file.
   * @param string $target_file
   *   Path to the file to patch.
   *
   * @return bool
   *   TRUE if patch was applied successfully, FALSE otherwise.
   */
  public function applyPatch($patch_uri, $target_file) {
    $patch_path = $this->fileSystem->realpath($patch_uri);
    
    // Create backup
    $backup_file = $target_file . '.bak';
    copy($target_file, $backup_file);
    
    // Apply patch
    $process = new Process(['patch', '-p0', $target_file, $patch_path]);
    $process->run();
    
    if ($process->isSuccessful()) {
      unlink($backup_file);
      return TRUE;
    }
    
    // Restore backup if patch failed
    copy($backup_file, $target_file);
    unlink($backup_file);
    
    $this->loggerFactory->get('ai_upgrade_assistant')
      ->error('Failed to apply patch to @file: @error', [
        '@file' => $target_file,
        '@error' => $process->getErrorOutput(),
      ]);
    
    return FALSE;
  }

  /**
   * Applies a single change to file content.
   *
   * @param string $content
   *   Original file content.
   * @param array $change
   *   Change to apply.
   *
   * @return string
   *   Modified content.
   */
  protected function applyChange($content, array $change) {
    if (empty($change['code_example'])) {
      return $content;
    }

    $lines = explode("\n", $content);
    $original_code = $change['current_code'];
    $new_code = $change['code_example'];
    
    // If we have line numbers, use them for more precise replacement
    if (!empty($change['start_line']) && !empty($change['end_line'])) {
      $start = $change['start_line'] - 1; // Convert to 0-based index
      $length = $change['end_line'] - $change['start_line'] + 1;
      
      // Verify the original code matches
      $original_lines = array_slice($lines, $start, $length);
      $original_block = implode("\n", $original_lines);
      
      if (trim($original_block) === trim($original_code)) {
        // Replace the lines
        array_splice($lines, $start, $length, explode("\n", $new_code));
        return implode("\n", $lines);
      }
    }
    
    // Fallback to string replacement if line numbers don't match
    // Use regular expressions to handle whitespace variations
    $escaped_original = preg_quote($original_code, '/');
    $pattern = "/^[ \t]*" . str_replace("\n", "\\n[ \t]*", $escaped_original) . "[ \t]*$/m";
    
    return preg_replace($pattern, $new_code, $content);
  }

  /**
   * Validates changes before applying them.
   *
   * @param array $changes
   *   Array of changes to validate.
   * @param string $file_path
   *   Path to the file being changed.
   *
   * @return array
   *   Array of validation results with 'valid' boolean and 'errors' array.
   */
  public function validateChanges(array $changes, $file_path) {
    $results = [
      'valid' => TRUE,
      'errors' => [],
    ];
    
    $content = file_get_contents($file_path);
    if ($content === FALSE) {
      $results['valid'] = FALSE;
      $results['errors'][] = "Could not read file: $file_path";
      return $results;
    }
    
    foreach ($changes as $i => $change) {
      // Check required fields
      if (empty($change['current_code']) || empty($change['code_example'])) {
        $results['valid'] = FALSE;
        $results['errors'][] = "Change $i is missing required fields";
        continue;
      }
      
      // Verify current code exists in file
      if (strpos($content, $change['current_code']) === FALSE) {
        $results['valid'] = FALSE;
        $results['errors'][] = "Original code for change $i not found in file";
        continue;
      }
      
      // Validate line numbers if provided
      if (!empty($change['start_line']) && !empty($change['end_line'])) {
        $lines = explode("\n", $content);
        if ($change['start_line'] < 1 || $change['end_line'] > count($lines)) {
          $results['valid'] = FALSE;
          $results['errors'][] = "Invalid line numbers for change $i";
          continue;
        }
      }
      
      // Basic syntax validation for PHP files
      if (pathinfo($file_path, PATHINFO_EXTENSION) === 'php') {
        if (!$this->validatePhpSyntax($change['code_example'])) {
          $results['valid'] = FALSE;
          $results['errors'][] = "Invalid PHP syntax in change $i";
          continue;
        }
      }
    }
    
    return $results;
  }

  /**
   * Validates PHP syntax.
   *
   * @param string $code
   *   PHP code to validate.
   *
   * @return bool
   *   TRUE if syntax is valid, FALSE otherwise.
   */
  protected function validatePhpSyntax($code) {
    // Create a temporary file
    $temp_file = tempnam(sys_get_temp_dir(), 'php_syntax_check');
    file_put_contents($temp_file, "<?php\n" . $code);
    
    // Check syntax
    $process = new Process(['php', '-l', $temp_file]);
    $process->run();
    
    // Clean up
    unlink($temp_file);
    
    return $process->isSuccessful();
  }

  /**
   * Checks if a patch can be safely applied.
   *
   * @param string $patch_uri
   *   URI of the patch file.
   * @param string $target_file
   *   Path to the file to patch.
   *
   * @return bool
   *   TRUE if patch can be safely applied, FALSE otherwise.
   */
  public function isSafePatch($patch_uri, $target_file) {
    $patch_path = $this->fileSystem->realpath($patch_uri);
    
    // Check if patch can be applied with --dry-run
    $process = new Process(['patch', '--dry-run', '-p0', $target_file, $patch_path]);
    $process->run();
    
    return $process->isSuccessful();
  }

}
